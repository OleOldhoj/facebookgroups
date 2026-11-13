<?php
// Facebook Graph API: login, page list, page feeds and posting.
// Groups have no Graph API since April 2024; they come from the browser extension (api/capture).
declare(strict_types=1);

const FB_SCOPES = 'pages_show_list,pages_read_engagement,pages_read_user_content,pages_manage_posts,pages_manage_engagement,publish_video';

function fb_ready(): bool
{
    return conf('FB_APP_ID') !== '' && conf('FB_APP_SECRET') !== '';
}

function fb_graph(): string
{
    return 'https://graph.facebook.com/' . conf('FB_GRAPH_VERSION', 'v24.0');
}

function fb_redirect_uri(): string
{
    return env('APP_URL') . '/fb/callback';
}

function fb_login_url(): string
{
    $_SESSION['fb_state'] = bin2hex(random_bytes(12));
    return 'https://www.facebook.com/' . conf('FB_GRAPH_VERSION', 'v24.0') . '/dialog/oauth?' . http_build_query([
        'client_id' => conf('FB_APP_ID'),
        'redirect_uri' => fb_redirect_uri(),
        'state' => $_SESSION['fb_state'],
        'scope' => FB_SCOPES,
        'auth_type' => 'rerequest',
    ]);
}

function fb_call(string $method, string $path, array $params = []): array
{
    $r = http_json($method, fb_graph() . $path, $params);
    if (!$r['json'] || isset($r['json']['error'])) {
        $msg = $r['json']['error']['message'] ?? ('HTTP ' . $r['code']);
        throw new RuntimeException("Facebook: $msg");
    }
    return $r['json'];
}

/** OAuth callback: code -> long-lived user token -> store pages. */
function fb_handle_callback(string $code): int
{
    $short = fb_call('GET', '/oauth/access_token', [
        'client_id' => conf('FB_APP_ID'), 'client_secret' => conf('FB_APP_SECRET'),
        'redirect_uri' => fb_redirect_uri(), 'code' => $code,
    ])['access_token'];
    $long = fb_call('GET', '/oauth/access_token', [
        'grant_type' => 'fb_exchange_token', 'client_id' => conf('FB_APP_ID'),
        'client_secret' => conf('FB_APP_SECRET'), 'fb_exchange_token' => $short,
    ])['access_token'];
    $me = fb_call('GET', '/me', ['fields' => 'id,name', 'access_token' => $long]);
    set_setting('fb_user_token', $long);
    set_setting('fb_user_name', $me['name'] ?? '');
    set_setting('fb_connected_at', now());
    return fb_sync_pages();
}

/** Page tokens fetched with a long-lived user token do not expire. */
function fb_sync_pages(): int
{
    $token = setting('fb_user_token');
    if (!$token) return 0;
    $url = '/me/accounts';
    $params = ['fields' => 'id,name,category,link,tasks,access_token,fan_count,picture{url}', 'limit' => 100, 'access_token' => $token];
    $seen = [];
    do {
        $r = fb_call('GET', $url, $params);
        foreach ($r['data'] ?? [] as $p) {
            $tasks = $p['tasks'] ?? [];
            db()->prepare('INSERT INTO pages(id, name, category, link, picture, can_post, tasks, token, fan_count, updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name, category=excluded.category,
                link=excluded.link, picture=excluded.picture, can_post=excluded.can_post, tasks=excluded.tasks,
                token=excluded.token, fan_count=excluded.fan_count, updated_at=excluded.updated_at')
                ->execute([$p['id'], $p['name'], $p['category'] ?? '', $p['link'] ?? "https://www.facebook.com/{$p['id']}",
                    $p['picture']['data']['url'] ?? '', in_array('CREATE_CONTENT', $tasks, true) ? 1 : 0,
                    implode(',', $tasks), $p['access_token'] ?? '', $p['fan_count'] ?? null, now()]);
            $seen[] = $p['id'];
        }
        $next = $r['paging']['next'] ?? null;
        if ($next) { $url = substr($next, strlen(fb_graph())); $params = []; }
    } while ($next);
    // Pages you no longer manage drop off the list.
    if ($seen) {
        $in = implode(',', array_fill(0, count($seen), '?'));
        db()->prepare("DELETE FROM pages WHERE id NOT IN ($in)")->execute($seen);
    }
    return count($seen);
}

/** Pull the latest posts on every page (own posts + visitor posts) into the posts table. */
function fb_sync_page_posts(): int
{
    $added = 0;
    foreach (db()->query("SELECT * FROM pages WHERE token != ''")->fetchAll() as $page) {
        foreach (['/feed', '/visitor_posts'] as $edge) {
            try {
                $r = fb_call('GET', '/' . $page['id'] . $edge, [
                    'fields' => 'id,message,story,created_time,permalink_url,from{name}',
                    'limit' => 25, 'access_token' => $page['token'],
                ]);
            } catch (RuntimeException $e) {
                if ($edge === '/feed') app_log('fb', "{$page['name']}: {$e->getMessage()}");
                continue;
            }
            foreach ($r['data'] ?? [] as $p) {
                $text = $p['message'] ?? $p['story'] ?? '';
                if ($text === '') continue;
                $added += save_post('page', $page['id'], $page['name'], $p['id'], $p['from']['name'] ?? $page['name'],
                    $text, $p['permalink_url'] ?? '', isset($p['created_time']) ? gmdate('Y-m-d H:i:s', strtotime($p['created_time'])) : null);
            }
        }
    }
    return $added;
}

function fb_publish(string $pageId, string $message, string $link = ''): string
{
    $s = db()->prepare('SELECT token FROM pages WHERE id = ? AND can_post = 1');
    $s->execute([$pageId]);
    $token = $s->fetchColumn();
    if (!$token) throw new RuntimeException('You cannot post on that page.');
    $params = ['message' => $message, 'access_token' => $token];
    if ($link !== '') $params['link'] = $link;
    return fb_call('POST', "/$pageId/feed", $params)['id'];
}

/** Insert a post once; returns 1 if it was new. */
function save_post(string $source, string $sourceId, string $sourceName, string $extId, string $author, string $text, string $url, ?string $postedAt): int
{
    $s = db()->prepare('INSERT INTO posts(source, source_id, source_name, ext_id, author, text, url, posted_at, captured_at)
        VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(source, ext_id) DO UPDATE SET
        text = CASE WHEN length(excluded.text) > length(posts.text) THEN excluded.text ELSE posts.text END,
        url = COALESCE(NULLIF(posts.url, \'\'), excluded.url)');
    $exists = db()->prepare('SELECT 1 FROM posts WHERE source = ? AND ext_id = ?');
    $exists->execute([$source, $extId]);
    $isNew = !$exists->fetchColumn();
    $s->execute([$source, $sourceId, $sourceName, $extId, $author, mb_substr($text, 0, 8000), $url, $postedAt, now()]);
    return $isNew ? 1 : 0;
}
