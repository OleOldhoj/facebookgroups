<?php
// AI review of captured posts via the Claude API.
declare(strict_types=1);

const AI_CATEGORIES = ['lead', 'opportunity', 'question', 'discussion', 'announcement', 'complaint', 'spam', 'rule_violation', 'other'];

const AI_DEFAULT_INSTRUCTIONS = "I run a web development agency (websites, apps, SaaS). Flag posts where someone needs a website, app, developer or digital help as leads with high priority. Flag spam, scams and posts that break normal group rules. Keep replies short, friendly and helpful, never pushy.";

function ai_ready(): bool
{
    return conf('ANTHROPIC_API_KEY') !== '';
}

function ai_instructions(): string
{
    return setting('ai_instructions') ?: AI_DEFAULT_INSTRUCTIONS;
}

/** Review one post; stores the verdict on the row. */
function ai_review(array $post): void
{
    $system = "You review Facebook posts for the owner of a social media dashboard.\n"
        . "Owner's instructions:\n" . ai_instructions() . "\n\n"
        . "Reply with ONLY a JSON object, no other text:\n"
        . '{"priority": 1-5 (5 = act now), "category": one of ' . json_encode(AI_CATEGORIES)
        . ', "summary": "one sentence", "action": "what the owner should do, short", '
        . '"reply": "a suggested comment, or empty string if none is needed", "flags": ["short warnings, e.g. scam, off-topic, rude"]}' . "\n"
        . "Write summary, action and reply in the same language as the post. Never use the em dash character.";
    $where = $post['source'] === 'group' ? 'Facebook group' : 'Facebook page';
    $user = "$where: {$post['source_name']}\nAuthor: {$post['author']}\n\nPost:\n{$post['text']}";

    $r = http_json('POST', 'https://api.anthropic.com/v1/messages', [
        'model' => conf('AI_MODEL', 'claude-haiku-5-5'),
        'max_tokens' => 600,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $user]],
    ], [
        'Content-Type: application/json',
        'x-api-key: ' . conf('ANTHROPIC_API_KEY'),
        'anthropic-version: 2023-06-01',
    ], 60);

    if ($r['code'] !== 200 || !$r['json']) {
        $msg = $r['json']['error']['message'] ?? ('HTTP ' . $r['code']);
        throw new RuntimeException("Claude API: $msg");
    }
    $text = '';
    foreach ($r['json']['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    if (!preg_match('/\{.*\}/s', $text, $m) || !is_array($v = json_decode($m[0], true))) {
        throw new RuntimeException('AI answer was not JSON');
    }
    $clean = fn($s) => str_replace('—', ', ', trim((string)$s));
    $cat = in_array($v['category'] ?? '', AI_CATEGORIES, true) ? $v['category'] : 'other';
    db()->prepare("UPDATE posts SET ai_status='done', ai_priority=?, ai_category=?, ai_summary=?, ai_action=?, ai_reply=?, ai_flags=?, ai_error=NULL WHERE id=?")
        ->execute([max(1, min(5, (int)($v['priority'] ?? 1))), $cat, $clean($v['summary'] ?? ''), $clean($v['action'] ?? ''),
            $clean($v['reply'] ?? ''), implode(', ', array_map($clean, (array)($v['flags'] ?? []))), $post['id']]);
}

/** Review up to $limit pending posts. Returns [done, failed]. */
function ai_review_pending(int $limit = 25): array
{
    if (!ai_ready()) return [0, 0];
    $s = db()->prepare("SELECT * FROM posts WHERE ai_status = 'pending' ORDER BY id DESC LIMIT ?");
    $s->execute([$limit]);
    $done = $failed = 0;
    foreach ($s->fetchAll() as $post) {
        try {
            ai_review($post);
            $done++;
        } catch (Throwable $e) {
            $failed++;
            db()->prepare("UPDATE posts SET ai_status='error', ai_error=? WHERE id=?")->execute([$e->getMessage(), $post['id']]);
            app_log('ai', $e->getMessage());
            if (str_contains($e->getMessage(), 'HTTP 401') || str_contains($e->getMessage(), 'credit')) break;
        }
    }
    return [$done, $failed];
}
