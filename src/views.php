<?php
// HTML views. Every value goes through h().
declare(strict_types=1);

function layout(string $title, string $active, string $body): void
{
    $nav = '';
    $newCount = (int)db()->query('SELECT COUNT(*) FROM posts WHERE seen = 0')->fetchColumn();
    foreach (['/' => "What's new", '/all' => 'All posts', '/groups' => 'Groups', '/stats' => 'Stats', '/pages' => 'Pages', '/moderation' => 'Moderation', '/videos' => 'Videos', '/settings' => 'Settings'] as $href => $label) {
        $badge = $href === '/' && $newCount ? ' <span class="badge">' . $newCount . '</span>' : '';
        $nav .= '<a href="' . $href . '"' . ($active === $href ? ' class="on"' : '') . '>' . h($label) . $badge . '</a>';
    }
    $flash = flash();
    $csrf = h(csrf());
    $back = h($_SERVER['REQUEST_URI'] ?? '/');
    echo <<<HTML
    <!doctype html>
    <html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} | Facebook Groups</title>
    <link rel="icon" href="/assets/icon.svg">
    <link rel="stylesheet" href="/assets/app.css?v=1">
    </head><body>
    <header class="top"><div class="wrap">
      <a class="brand" href="/"><img src="/assets/icon.svg" alt=""> Facebook Groups</a>
      <nav>{$nav}</nav>
      <form method="post" action="/sync" class="sync"><input type="hidden" name="csrf" value="{$csrf}"><input type="hidden" name="back" value="{$back}"><button>Sync + AI review</button></form>
    </div></header>
    <main class="wrap">
    HTML;
    if ($flash) echo '<div class="flash">' . h($flash) . '</div>';
    echo $body;
    echo '</main><script src="/assets/app.js?v=1"></script></body></html>';
}

function hidden_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="back" value="' . h($_SERVER['REQUEST_URI'] ?? '/') . '">';
}

function view_login(): void
{
    $flash = flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Log in | Facebook Groups</title><link rel="icon" href="/assets/icon.svg"><link rel="stylesheet" href="/assets/app.css?v=1"></head><body class="login">'
        . '<form method="post" action="/login" class="card loginbox"><h1><img src="/assets/icon.svg" alt=""> Facebook Groups</h1>'
        . ($flash ? '<div class="flash">' . h($flash) . '</div>' : '')
        . '<label>Email<input name="login" type="email" required autocomplete="username"></label>'
        . '<label>Password<input name="password" type="password" required autocomplete="current-password"></label>'
        . '<button class="primary">Log in</button></form></body></html>';
}

function status_strip(): string
{
    $fb = setting('fb_user_token') ? '<span class="ok">Facebook: ' . h(setting('fb_user_name')) . '</span>' : '<a class="warn" href="/settings#facebook">Facebook not connected</a>';
    $ai = ai_ready() ? '<span class="ok">AI review on</span>' : '<a class="warn" href="/settings#ai">AI key missing</a>';
    $last = setting('ext_last_seen');
    $ext = $last ? '<span class="ok">Extension: last capture ' . h(ago($last)) . '</span>' : '<a class="warn" href="/settings#extension">Extension not installed</a>';
    return "<div class=\"strip\">$fb $ai $ext</div>";
}

function view_posts(string $mode): void
{
    $where = [];
    $args = [];
    if ($mode === 'new') $where[] = 'seen = 0';
    $src = (string)($_GET['src'] ?? '');
    if ($src === 'group' || $src === 'page') { $where[] = 'source = ?'; $args[] = $src; }
    elseif ($src !== '') { $where[] = 'source_id = ?'; $args[] = $src; }
    $f = (string)($_GET['f'] ?? '');
    match ($f) {
        'hot' => $where[] = 'ai_priority >= 4',
        'leads' => $where[] = "ai_category IN ('lead', 'opportunity')",
        'bad' => $where[] = "(ai_category IN ('spam', 'rule_violation') OR (ai_flags IS NOT NULL AND ai_flags != ''))",
        'questions' => $where[] = "ai_category = 'question'",
        'unreviewed' => $where[] = "ai_status != 'done'",
        default => null,
    };
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where[] = '(text LIKE ? OR author LIKE ? OR source_name LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 50;

    $count = db()->prepare("SELECT COUNT(*) FROM posts $sqlWhere");
    $count->execute($args);
    $total = (int)$count->fetchColumn();
    $s = db()->prepare("SELECT * FROM posts $sqlWhere ORDER BY COALESCE(ai_priority, 0) DESC, captured_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    if ($mode === 'all') $s = db()->prepare("SELECT * FROM posts $sqlWhere ORDER BY captured_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $s->execute($args);
    $posts = $s->fetchAll();

    $out = status_strip();
    if ($mode === 'new') {
        $stats = db()->query("SELECT COUNT(*) n, SUM(ai_priority >= 4) hot, SUM(ai_category IN ('lead','opportunity')) leads,
            SUM(ai_category IN ('spam','rule_violation')) bad, COUNT(DISTINCT source_id) sources FROM posts WHERE seen = 0")->fetch();
        $out .= '<section class="stats">'
            . stat_box((int)$stats['n'], 'new posts') . stat_box((int)$stats['hot'], 'need attention (4+)')
            . stat_box((int)$stats['leads'], 'leads + opportunities') . stat_box((int)$stats['bad'], 'spam / rule breaks')
            . stat_box((int)$stats['sources'], 'groups + pages with news') . '</section>';
        // Per-source breakdown: what is new in each group/page.
        $by = db()->query("SELECT source, source_id, source_name, COUNT(*) n, MAX(ai_priority) top FROM posts WHERE seen = 0 GROUP BY source, source_id ORDER BY top DESC, n DESC LIMIT 40")->fetchAll();
        if ($by) {
            $out .= '<section class="sources">';
            foreach ($by as $b) {
                $out .= '<a class="src' . ($src === $b['source_id'] ? ' on' : '') . '" href="/?src=' . urlencode($b['source_id']) . '"><span class="kind ' . h($b['source']) . '">' . h($b['source']) . '</span> '
                    . h($b['source_name']) . ' <b>' . (int)$b['n'] . '</b>' . ($b['top'] ? ' <span class="pri p' . (int)$b['top'] . '">' . (int)$b['top'] . '</span>' : '') . '</a>';
            }
            $out .= '</section>';
        }
    }

    $base = $mode === 'new' ? '/' : '/all';
    $chips = '';
    foreach (['' => 'All', 'hot' => 'Priority 4+', 'leads' => 'Leads', 'questions' => 'Questions', 'bad' => 'Spam / flags', 'unreviewed' => 'Not reviewed'] as $k => $label) {
        $chips .= '<a class="chip' . ($f === $k ? ' on' : '') . '" href="' . $base . '?' . h(http_build_query(array_filter(['f' => $k, 'src' => $src, 'q' => $q]))) . '">' . h($label) . '</a>';
    }
    foreach (['group' => 'Groups only', 'page' => 'Pages only'] as $k => $label) {
        $chips .= '<a class="chip' . ($src === $k ? ' on' : '') . '" href="' . $base . '?' . h(http_build_query(array_filter(['f' => $f, 'src' => $src === $k ? '' : $k, 'q' => $q]))) . '">' . h($label) . '</a>';
    }
    $out .= '<div class="toolbar"><div class="chips">' . $chips . '</div>'
        . '<form class="search" method="get" action="' . $base . '"><input type="hidden" name="f" value="' . h($f) . '"><input type="hidden" name="src" value="' . h($src) . '"><input name="q" value="' . h($q) . '" placeholder="Search posts"></form>';
    if ($mode === 'new' && $total) {
        $out .= '<form method="post" action="/posts/seen">' . hidden_csrf() . '<button>Mark all as seen</button></form>';
    }
    $out .= '</div>';

    if (!$posts) {
        $out .= '<div class="empty">' . ($mode === 'new'
            ? 'Nothing new. Open your groups with the extension installed, or press <b>Sync + AI review</b>.'
            : 'No posts yet.') . '</div>';
    }
    foreach ($posts as $p) $out .= post_card($p);
    if ($total > $per) {
        $out .= '<div class="pager">';
        if ($page > 1) $out .= '<a href="?' . h(http_build_query(array_merge($_GET, ['p' => $page - 1]))) . '">Newer</a>';
        $out .= '<span>Page ' . $page . ' of ' . ceil($total / $per) . '</span>';
        if ($page * $per < $total) $out .= '<a href="?' . h(http_build_query(array_merge($_GET, ['p' => $page + 1]))) . '">Older</a>';
        $out .= '</div>';
    }
    layout($mode === 'new' ? "What's new" : 'All posts', $base, $out);
}

function stat_box(int $n, string $label): string
{
    return '<div class="stat"><b>' . $n . '</b><span>' . h($label) . '</span></div>';
}

function post_card(array $p): string
{
    $when = $p['posted_at'] ? ago($p['posted_at']) : 'seen ' . ago($p['captured_at']);
    $ai = '';
    if ($p['ai_status'] === 'done') {
        $ai = '<div class="ai"><div class="ai-head"><span class="pri p' . (int)$p['ai_priority'] . '">' . (int)$p['ai_priority'] . '</span>'
            . '<span class="cat c-' . h($p['ai_category']) . '">' . h(str_replace('_', ' ', (string)$p['ai_category'])) . '</span>'
            . ($p['ai_flags'] ? '<span class="flags">' . h($p['ai_flags']) . '</span>' : '') . '</div>'
            . '<p class="sum">' . h($p['ai_summary']) . '</p>'
            . ($p['ai_action'] ? '<p class="act"><b>Do:</b> ' . h($p['ai_action']) . '</p>' : '')
            . ($p['ai_reply'] ? '<div class="reply"><p>' . nl2br(h($p['ai_reply'])) . '</p><button type="button" class="copy" data-copy="' . h($p['ai_reply']) . '">Copy reply</button></div>' : '')
            . '</div>';
    } elseif ($p['ai_status'] === 'error') {
        $ai = '<div class="ai err">AI review failed: ' . h($p['ai_error']) . '</div>';
    } else {
        $ai = '<div class="ai pending">' . (ai_ready() ? 'Waiting for AI review' : 'AI review off: add a Claude API key in Settings') . '</div>';
    }
    $open = $p['url'] ? '<a class="btn" href="' . h($p['url']) . '" target="_blank" rel="noopener">Open on Facebook</a>' : '';
    if (!$p['url'] && $p['source'] === 'group') {
        $open = '<a class="btn" href="https://www.facebook.com/groups/' . h(rawurlencode($p['source_id'])) . '/" target="_blank" rel="noopener">Open group</a>';
    }
    return '<article class="post' . ($p['seen'] ? ' seen' : '') . '">'
        . '<div class="meta"><span class="kind ' . h($p['source']) . '">' . h($p['source']) . '</span> <a href="/all?src=' . urlencode($p['source_id']) . '">' . h($p['source_name']) . '</a>'
        . ' <span class="dot">·</span> ' . h($p['author'] ?: 'Unknown') . ' <span class="dot">·</span> <span class="muted">' . h($when) . '</span></div>'
        . '<div class="text clamp">' . nl2br(h($p['text'])) . '</div>'
        . $ai
        . '<div class="actions">' . $open
        . (!$p['seen'] ? '<form method="post" action="/posts/seen">' . hidden_csrf() . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><button>Seen</button></form>' : '')
        . (ai_ready() ? '<form method="post" action="/posts/review">' . hidden_csrf() . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><button>' . ($p['ai_status'] === 'done' ? 'Review again' : 'Review now') . '</button></form>' : '')
        . '</div></article>';
}

function view_pages(): void
{
    $pages = db()->query('SELECT p.*, (SELECT COUNT(*) FROM posts WHERE source = \'page\' AND source_id = p.id AND seen = 0) new_posts FROM pages p ORDER BY can_post DESC, name')->fetchAll();
    $out = status_strip();
    if (!setting('fb_user_token')) {
        $out .= '<div class="card"><h2>Connect Facebook to see your pages</h2><p>Log in once with Facebook and every page you manage shows up here, with a button to post on it.</p>'
            . (fb_ready() ? '<a class="btn primary" href="/fb/connect">Connect Facebook</a>' : '<a class="btn primary" href="/settings#facebook">Set up Facebook first</a>') . '</div>';
        layout('Pages', '/pages', $out);
        return;
    }
    $postable = array_filter($pages, fn($p) => $p['can_post']);
    if ($postable) {
        $opts = '';
        $pre = (string)($_GET['to'] ?? '');
        foreach ($postable as $p) $opts .= '<option value="' . h($p['id']) . '"' . ($pre === $p['id'] ? ' selected' : '') . '>' . h($p['name']) . '</option>';
        $out .= '<form class="card compose" method="post" action="/pages/post" id="compose">' . hidden_csrf()
            . '<h2>Post on a page</h2><label>Page<select name="page_id">' . $opts . '</select></label>'
            . '<label>Message<textarea name="message" rows="4" required></textarea></label>'
            . '<label>Link (optional)<input name="link" type="url" placeholder="https://"></label>'
            . '<button class="primary" data-confirm="Publish this post now?">Publish</button></form>';
    }
    $out .= '<h2 class="h">Your pages <span class="muted">' . count($pages) . ' total, ' . count($postable) . ' you can post on</span>'
        . ' <a class="btn small" href="/fb/connect">Refresh from Facebook</a></h2><div class="grid">';
    foreach ($pages as $p) {
        $out .= '<div class="card pagecard">'
            . ($p['picture'] ? '<img src="' . h($p['picture']) . '" alt="" loading="lazy">' : '<div class="ph"></div>')
            . '<div><h3>' . h($p['name']) . '</h3><p class="muted">' . h($p['category']) . ($p['fan_count'] !== null ? ' · ' . number_format((int)$p['fan_count']) . ' followers' : '') . '</p>'
            . '<p>' . ($p['can_post'] ? '<span class="tag ok">Can post</span>' : '<span class="tag">View only</span>')
            . ($p['new_posts'] ? ' <a class="tag new" href="/?src=' . urlencode($p['id']) . '">' . (int)$p['new_posts'] . ' new</a>' : '') . '</p>'
            . '<div class="actions"><a class="btn" href="' . h($p['link']) . '" target="_blank" rel="noopener">Open</a>'
            . ($p['can_post'] ? '<a class="btn" href="/pages?to=' . urlencode($p['id']) . '#compose">Post</a>' : '')
            . '<a class="btn" href="/all?src=' . urlencode($p['id']) . '">Posts</a></div></div></div>';
    }
    $out .= '</div>';
    layout('Pages', '/pages', $out);
}

function view_moderation(): void
{
    $mode = mod_mode();
    $f = (string)($_GET['f'] ?? 'hidden');
    $where = match ($f) {
        'flagged' => "verdict = 'insult' AND hidden = 0",
        'all' => '1',
        'approved' => "verdict = 'approved'",
        default => 'hidden = 1',
    };
    $stats = db()->query("SELECT COUNT(*) n, SUM(hidden = 1) hidden, SUM(verdict = 'insult' AND hidden = 0) flagged,
        SUM(verdict = 'pending') pending, SUM(hidden = 1 AND hidden_at > datetime('now', '-1 day')) today FROM moderation")->fetch();
    $items = db()->query("SELECT * FROM moderation WHERE $where ORDER BY COALESCE(hidden_at, captured_at) DESC, id DESC LIMIT 100")->fetchAll();

    $out = status_strip();
    if (!str_contains(implode(',', array_column(db()->query("SELECT tasks FROM pages")->fetchAll(), 'tasks')), 'MODERATE') && setting('fb_user_token')) {
        $out .= '<div class="card note"><p>To hide comments the app needs the <b>pages_manage_engagement</b> permission and the MODERATE task on your pages. Press <a href="/fb/connect">Reconnect Facebook</a> once and accept.</p></div>';
    }
    $out .= '<section class="stats">' . stat_box((int)$stats['hidden'], 'hidden in total') . stat_box((int)$stats['today'], 'hidden last 24 h')
        . stat_box((int)$stats['flagged'], 'flagged, still visible') . stat_box((int)$stats['n'], 'comments checked')
        . stat_box((int)$stats['pending'], 'waiting for AI') . '</section>';

    $modes = '';
    foreach (['auto' => 'Hide automatically', 'review' => 'Only flag, I decide', 'off' => 'Off'] as $k => $label) {
        $modes .= '<option value="' . $k . '"' . ($mode === $k ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $out .= '<details class="card"' . ($stats['n'] ? '' : ' open') . '><summary><b>How moderation works</b> (mode: ' . h($mode) . ')</summary>'
        . '<p>Every 5 minutes the latest comments and visitor posts on all your pages are read and Claude checks them against your rules. '
        . 'Insulting ones are hidden on Facebook: the writer and their friends still see it, everyone else does not, so it rarely starts a fight. Press <b>Unhide</b> to undo; the AI will then leave that comment alone.</p>'
        . '<form method="post" action="/moderation/settings" class="form">' . hidden_csrf()
        . '<label>Mode<select name="mod_mode">' . $modes . '</select></label>'
        . '<label>What counts as insulting? (your rules, any language)<textarea name="mod_rules" rows="5">' . h(mod_rules()) . '</textarea></label>'
        . '<button class="primary">Save</button></form></details>';

    $chips = '';
    foreach (['hidden' => 'Hidden', 'flagged' => 'Flagged, visible', 'approved' => 'Unhidden by you', 'all' => 'Everything checked'] as $k => $label) {
        $chips .= '<a class="chip' . ($f === $k ? ' on' : '') . '" href="/moderation?f=' . $k . '">' . h($label) . '</a>';
    }
    $out .= '<div class="toolbar"><div class="chips">' . $chips . '</div><form method="post" action="/moderation/run">' . hidden_csrf() . '<button>Check now</button></form></div>';
    if (!$items) $out .= '<div class="empty">Nothing here yet.</div>';
    foreach ($items as $it) {
        $tag = match (true) {
            (bool)$it['hidden'] => '<span class="tag warn">Hidden' . ($it['hidden_by'] ? ' by ' . h($it['hidden_by'] === 'ai' ? 'AI' : $it['hidden_by']) : '') . '</span>',
            $it['verdict'] === 'insult' => '<span class="tag warn">Flagged</span>',
            $it['verdict'] === 'approved' => '<span class="tag ok">Approved by you</span>',
            $it['verdict'] === 'pending' => '<span class="tag">Waiting</span>',
            default => '<span class="tag ok">OK</span>',
        };
        $btn = $it['hidden']
            ? '<form method="post" action="/moderation/unhide">' . hidden_csrf() . '<input type="hidden" name="id" value="' . (int)$it['id'] . '"><button>Unhide</button></form>'
            : '<form method="post" action="/moderation/hide">' . hidden_csrf() . '<input type="hidden" name="id" value="' . (int)$it['id'] . '"><button>Hide</button></form>';
        $out .= '<article class="post"><div class="meta"><span class="kind page">' . h($it['kind']) . '</span> ' . h($it['page_name'])
            . ' <span class="dot">·</span> ' . h($it['author'] ?: 'Unknown') . ' <span class="dot">·</span> <span class="muted">' . h(ago($it['created_at'] ?: $it['captured_at'])) . '</span> ' . $tag . '</div>'
            . '<div class="text clamp">' . nl2br(h($it['text'])) . '</div>'
            . ($it['reason'] ? '<div class="ai"><div class="ai-head">' . ($it['severity'] ? '<span class="pri p' . min(5, (int)$it['severity'] + 2) . '">' . (int)$it['severity'] . '</span>' : '') . '<span class="sum">' . h($it['reason']) . '</span></div></div>' : '')
            . ($it['error'] ? '<div class="ai err">Facebook: ' . h($it['error']) . '</div>' : '')
            . '<div class="actions">' . ($it['url'] ? '<a class="btn" href="' . h($it['url']) . '" target="_blank" rel="noopener">Open on Facebook</a>' : '') . $btn . '</div></article>';
    }
    layout('Moderation', '/moderation', $out);
}

function view_groups(): void
{
    $groups = db()->query('SELECT g.*, COUNT(p.id) total, SUM(p.seen = 0) new_posts, MAX(p.captured_at) last_post, MAX(p.ai_priority * (p.seen = 0)) top
        FROM groups g LEFT JOIN posts p ON p.source = \'group\' AND p.source_id = g.id GROUP BY g.id ORDER BY new_posts DESC, g.name')->fetchAll();
    $out = status_strip();
    $out .= '<div class="card note"><p>Facebook closed its Groups API in 2024, so groups are read by the <a href="/settings#extension">browser extension</a> while you are logged in to Facebook. '
        . 'Use the extension button <b>Scan all my groups</b> to visit every group once, or just browse Facebook as normal and new posts are picked up.</p></div>';
    if (!$groups) {
        $out .= '<div class="empty">No groups yet. Install the extension, then open <a href="https://www.facebook.com/groups/joins/" target="_blank" rel="noopener">facebook.com/groups/joins</a> to import your groups.</div>';
        layout('Groups', '/groups', $out);
        return;
    }
    $out .= '<h2 class="h">Your groups <span class="muted">' . count($groups) . '</span></h2><div class="table"><table><thead><tr><th>Group</th><th>New</th><th>Top priority</th><th>Posts</th><th>Last scanned</th><th></th></tr></thead><tbody>';
    foreach ($groups as $g) {
        $out .= '<tr><td><a href="/all?src=' . urlencode($g['id']) . '">' . h($g['name'] ?: $g['id']) . '</a></td>'
            . '<td>' . ($g['new_posts'] ? '<a class="tag new" href="/?src=' . urlencode($g['id']) . '">' . (int)$g['new_posts'] . ' new</a>' : '<span class="muted">0</span>') . '</td>'
            . '<td>' . ($g['top'] ? '<span class="pri p' . (int)$g['top'] . '">' . (int)$g['top'] . '</span>' : '') . '</td>'
            . '<td>' . (int)$g['total'] . '</td><td class="muted">' . h($g['last_scan_at'] ? ago($g['last_scan_at']) : ($g['last_post'] ? ago($g['last_post']) : 'never')) . '</td>'
            . '<td><a class="btn small" href="' . h($g['url']) . '" target="_blank" rel="noopener">Open</a></td></tr>';
    }
    $out .= '</tbody></table></div>';
    layout('Groups', '/groups', $out);
}

function view_settings(): void
{
    $redirect = h(fb_redirect_uri());
    $app = h(env('APP_URL'));
    $fbFromEnv = env('FB_APP_ID') !== '';
    $aiFromEnv = env('ANTHROPIC_API_KEY') !== '';
    $out = '<div class="card" id="facebook"><h2>1. Facebook (your pages)</h2>';
    if (setting('fb_user_token')) {
        $out .= '<p><span class="tag ok">Connected</span> as <b>' . h(setting('fb_user_name')) . '</b> since ' . h(ago(setting('fb_connected_at'))) . '.</p>'
            . '<div class="actions"><a class="btn" href="/fb/connect">Reconnect</a><form method="post" action="/fb/disconnect">' . hidden_csrf() . '<button data-confirm="Disconnect Facebook and forget all page tokens?">Disconnect</button></form></div>';
    } elseif (fb_ready()) {
        $out .= '<p><a class="btn primary" href="/fb/connect">Connect Facebook</a></p>';
    }
    $out .= '<details' . (fb_ready() ? '' : ' open') . '><summary>How to create the Facebook app (one time, 5 minutes)</summary><ol>'
        . '<li>Go to <a href="https://developers.facebook.com/apps/creation/" target="_blank" rel="noopener">developers.facebook.com/apps/creation</a> and create an app. Use case: <b>Other</b>, app type: <b>Business</b>.</li>'
        . '<li>Add the product <b>Facebook Login for Business</b> (or Facebook Login). Under its settings, add this Valid OAuth Redirect URI:<br><code>' . $redirect . '</code></li>'
        . '<li>App settings, Basic: set App Domain to <code>facebookgroups.fullstackpartner.com</code> and the privacy policy URL to <code>' . $app . '/privacy</code>.</li>'
        . '<li>Copy the App ID and App Secret into the form below and press Save, then press Connect Facebook.</li>'
        . '<li>The app can stay in development mode: as the app admin you get all your pages without App Review.</li></ol></details>';
    if ($fbFromEnv) {
        $out .= '<p class="muted">App ID is set in env.php.</p>';
    }
    $out .= '<form method="post" action="/settings" class="form">' . hidden_csrf()
        . '<label>App ID<input name="FB_APP_ID" value="' . h(setting('FB_APP_ID')) . '"' . ($fbFromEnv ? ' disabled' : '') . '></label>'
        . '<label>App Secret<input name="FB_APP_SECRET" type="password" placeholder="' . (conf('FB_APP_SECRET') ? 'saved, leave blank to keep' : '') . '"' . ($fbFromEnv ? ' disabled' : '') . '></label>'
        . '<button class="primary">Save</button></form></div>';

    $out .= '<div class="card" id="ai"><h2>2. AI review</h2><p>Every new post is read by Claude, which gives it a priority (1 to 5), a category, a one line summary, what you should do, and a suggested reply.</p>'
        . '<form method="post" action="/settings" class="form">' . hidden_csrf()
        . '<label>Claude API key ' . (ai_ready() ? '<span class="tag ok">saved</span>' : '<span class="tag warn">missing</span>')
        . '<input name="ANTHROPIC_API_KEY" type="password" placeholder="' . (ai_ready() ? 'saved, leave blank to keep' : 'sk-ant-...') . '"' . ($aiFromEnv ? ' disabled' : '') . '></label>'
        . '<p class="muted">Get one at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>. Haiku costs roughly 0.1 to 0.2 US cents per post.</p>'
        . '<label>Model<select name="AI_MODEL">';
    foreach (['claude-haiku-5-5' => 'Claude Haiku 5.5 (fast, cheapest)', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (smarter)', 'claude-opus-5-5' => 'Claude Opus 5.5 (best, most expensive)'] as $id => $label) {
        $out .= '<option value="' . $id . '"' . (conf('AI_MODEL', 'claude-haiku-5-5') === $id ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $out .= '</select></label><label>What should the AI look for? (your instructions)<textarea name="ai_instructions" rows="5">' . h(ai_instructions()) . '</textarea></label>'
        . '<button class="primary">Save</button></form></div>';

    $token = h(env('EXTENSION_TOKEN'));
    $out .= '<div class="card" id="extension"><h2>3. Browser extension (your groups)</h2>'
        . '<p>Facebook has no API for groups anymore, so a small Chrome extension reads the posts in your groups while you are logged in, and sends them here. It only reads, it never posts or clicks Like.</p><ol>'
        . '<li><a class="btn primary" href="/extension.zip">Download extension</a> and unzip it (it already contains your server address and key).</li>'
        . '<li>In Chrome (or Edge/Brave) open <code>chrome://extensions</code>, turn on <b>Developer mode</b>, click <b>Load unpacked</b> and pick the unzipped folder.</li>'
        . '<li>Open <a href="https://www.facebook.com/groups/joins/" target="_blank" rel="noopener">facebook.com/groups/joins</a> once. Your groups are imported.</li>'
        . '<li>Click the extension icon and press <b>Scan all my groups</b>. A small window opens and visits each group, about one every 20 seconds, then closes again. Run it whenever you want an update.</li></ol>'
        . '<form method="post" action="/settings">' . hidden_csrf() . '<label>Chrome Web Store link (once the plugin is approved)<input name="CHROME_STORE_URL" placeholder="https://chromewebstore.google.com/detail/..." value="' . h(setting('CHROME_STORE_URL')) . '"></label><button>Save</button></form>'
        . '<p class="muted">Key: <code>' . $token . '</code></p>'
        . '<p class="muted">Pace is kept slow on purpose: Facebook can lock accounts that load pages very fast.</p></div>';

    $log = db()->query('SELECT * FROM log ORDER BY id DESC LIMIT 20')->fetchAll();
    $out .= '<div class="card"><h2>Log</h2>';
    $out .= $log ? '<table class="log">' . implode('', array_map(fn($l) => '<tr><td class="muted">' . h(ago($l['at'])) . '</td><td>' . h($l['kind']) . '</td><td>' . h($l['msg']) . '</td></tr>', $log)) . '</table>' : '<p class="muted">Empty.</p>';
    $out .= '<p><a href="/logout">Log out</a></p></div>';
    layout('Settings', '/settings', $out);
}

function send_extension_zip(): void
{
    $dir = APP_ROOT . '/extension';
    $tmp = tempnam('/var/lib/fbgroups', 'ext');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach (scandir($dir) as $f) {
        if ($f[0] === '.' || !is_file("$dir/$f")) continue;
        $zip->addFile("$dir/$f", "facebook-stats-plugin/$f");
    }
    $zip->addFromString('facebook-stats-plugin/config.js',
        'self.FBG_CONFIG = ' . json_encode(['server' => 'https://facebookstats.fullstackpartner.com', // valid own cert; facebookgroups. still waits for its SAN
             'token' => env('EXTENSION_TOKEN')], JSON_UNESCAPED_SLASHES) . ";\n");
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="facebook-stats-plugin.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}
