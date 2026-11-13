<?php
// Page moderation: read comments + visitor posts on every page, let Claude spot insults, hide them on Facebook.
// Hiding (is_hidden=true) keeps the item visible to its author and their friends, so they rarely notice; it can be undone.
declare(strict_types=1);

const MOD_DEFAULT_RULES = "Hide comments and posts that insult, harass, threaten or use slurs against anyone (the page, staff, other commenters, any group of people), contain heavy swearing aimed at someone, or are obvious scams/spam links. Do NOT hide normal criticism, complaints, bad reviews or questions, even if they are negative or angry, as long as they are not insulting.";

function mod_rules(): string
{
    return setting('mod_rules') ?: MOD_DEFAULT_RULES;
}

/** 'auto' = hide straight away, 'review' = only flag, 'off' = do nothing. */
function mod_mode(): string
{
    return setting('mod_mode') ?: 'auto';
}

function mod_save(string $kind, array $page, array $item, ?string $parentId): void
{
    $text = trim((string)($item['message'] ?? ''));
    if ($text === '') return;
    // The page's own posts and replies are never moderated.
    if (($item['from']['id'] ?? '') === $page['id']) return;
    db()->prepare('INSERT INTO moderation(kind, page_id, page_name, ext_id, parent_id, author, text, url, created_at, captured_at, hidden, hidden_by)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(ext_id) DO NOTHING')
        ->execute([$kind, $page['id'], $page['name'], $item['id'], $parentId, $item['from']['name'] ?? '', mb_substr($text, 0, 4000),
            $item['permalink_url'] ?? '', isset($item['created_time']) ? gmdate('Y-m-d H:i:s', strtotime($item['created_time'])) : null, now(),
            !empty($item['is_hidden']) ? 1 : 0, !empty($item['is_hidden']) ? 'facebook' : null]);
}

/** Pull recent comments (on page posts and visitor posts) and visitor posts into the moderation table. */
function mod_fetch(): void
{
    $commentFields = 'comments.limit(100).order(reverse_chronological).filter(stream){id,message,from{id,name},created_time,permalink_url,is_hidden}';
    foreach (db()->query("SELECT * FROM pages WHERE token != ''")->fetchAll() as $page) {
        foreach (['/feed' => 'comment', '/visitor_posts' => 'post'] as $edge => $kind) {
            try {
                $r = fb_call('GET', '/' . $page['id'] . $edge, [
                    'fields' => "id,message,from{id,name},created_time,permalink_url,is_hidden,$commentFields",
                    'limit' => 25, 'access_token' => $page['token'],
                ]);
            } catch (RuntimeException $e) {
                app_log('mod', "{$page['name']} $edge: {$e->getMessage()}");
                continue;
            }
            foreach ($r['data'] ?? [] as $post) {
                if ($kind === 'post') mod_save('post', $page, $post, null);
                foreach ($post['comments']['data'] ?? [] as $c) mod_save('comment', $page, $c, $post['id']);
            }
        }
    }
}

/** Ask Claude about up to 40 pending items in one call. Returns how many got a verdict. */
function mod_classify(): int
{
    if (!ai_ready()) return 0;
    $items = db()->query("SELECT * FROM moderation WHERE verdict = 'pending' ORDER BY id LIMIT 40")->fetchAll();
    if (!$items) return 0;
    $list = '';
    foreach ($items as $i => $it) $list .= "[$i] ({$it['kind']} on page \"{$it['page_name']}\" by {$it['author']}): " . str_replace("\n", ' ', $it['text']) . "\n";
    $system = "You moderate comments on Facebook pages for the page owner. Any language.\nRules:\n" . mod_rules() . "\n\n"
        . 'Reply with ONLY a JSON array, one object per item: [{"i": number, "hide": true|false, "severity": 0-3 (0 = fine, 3 = very abusive), "reason": "max 8 words, English"}]';
    $r = http_json('POST', 'https://api.anthropic.com/v1/messages', [
        'model' => conf('AI_MODEL', 'claude-haiku-5-5'),
        'max_tokens' => 4000,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $list]],
    ], ['Content-Type: application/json', 'x-api-key: ' . conf('ANTHROPIC_API_KEY'), 'anthropic-version: 2023-06-01'], 90);
    if ($r['code'] !== 200 || !$r['json']) throw new RuntimeException('Claude API: ' . ($r['json']['error']['message'] ?? 'HTTP ' . $r['code']));
    $text = '';
    foreach ($r['json']['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    if (!preg_match('/\[.*\]/s', $text, $m) || !is_array($verdicts = json_decode($m[0], true))) throw new RuntimeException('Moderation answer was not JSON');

    $upd = db()->prepare('UPDATE moderation SET verdict = ?, severity = ?, reason = ? WHERE id = ?');
    $n = 0;
    foreach ($verdicts as $v) {
        $it = $items[(int)($v['i'] ?? -1)] ?? null;
        if (!$it) continue;
        $upd->execute([!empty($v['hide']) ? 'insult' : 'clean', max(0, min(3, (int)($v['severity'] ?? 0))),
            str_replace('—', ', ', mb_substr(trim((string)($v['reason'] ?? '')), 0, 200)), $it['id']]);
        $n++;
    }
    return $n;
}

function mod_page_token(string $pageId): string
{
    $s = db()->prepare('SELECT token FROM pages WHERE id = ?');
    $s->execute([$pageId]);
    return (string)$s->fetchColumn();
}

/** Hide or unhide one item on Facebook and record it. */
function mod_set_hidden(int $id, bool $hide, string $by): void
{
    $s = db()->prepare('SELECT * FROM moderation WHERE id = ?');
    $s->execute([$id]);
    if (!$it = $s->fetch()) throw new RuntimeException('Item not found.');
    try {
        fb_call('POST', '/' . $it['ext_id'], ['is_hidden' => $hide ? 'true' : 'false', 'access_token' => mod_page_token($it['page_id'])]);
    } catch (RuntimeException $e) {
        db()->prepare('UPDATE moderation SET error = ? WHERE id = ?')->execute([$e->getMessage(), $id]);
        throw $e;
    }
    db()->prepare('UPDATE moderation SET hidden = ?, hidden_by = ?, hidden_at = ?, error = NULL WHERE id = ?')
        ->execute([$hide ? 1 : 0, $hide ? $by : null, $hide ? now() : null, $id]);
}

/** Full pass: fetch, classify, auto-hide. Returns [new verdicts, hidden now]. */
function mod_run(): array
{
    if (mod_mode() === 'off' || !setting('fb_user_token')) return [0, 0];
    mod_fetch();
    $classified = 0;
    for ($round = 0; $round < 5; $round++) {
        $n = mod_classify();
        $classified += $n;
        if ($n < 40) break;
    }
    $hidden = 0;
    if (mod_mode() === 'auto') {
        foreach (db()->query("SELECT id FROM moderation WHERE verdict = 'insult' AND hidden = 0 AND error IS NULL")->fetchAll() as $row) {
            try {
                mod_set_hidden((int)$row['id'], true, 'ai');
                $hidden++;
            } catch (RuntimeException $e) {
                app_log('mod', 'hide failed: ' . $e->getMessage());
            }
        }
    }
    db()->exec("DELETE FROM moderation WHERE verdict = 'clean' AND captured_at < datetime('now', '-30 days')");
    return [$classified, $hidden];
}
