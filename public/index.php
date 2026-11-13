<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/fb.php';
require __DIR__ . '/../src/ai.php';
require __DIR__ . '/../src/moderation.php';
require __DIR__ . '/../src/videos.php';
require __DIR__ . '/../src/stats.php';
require __DIR__ . '/../src/views.php';

$path = rtrim(rawurldecode(strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/'), '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---- extension API (token auth, no session) -------------------------------
if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json');
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!hash_equals('Bearer ' . env('EXTENSION_TOKEN'), $auth)) {
        http_response_code(401);
        exit(json_encode(['error' => 'bad token']));
    }
    if ($path === '/api/groups') {
        exit(json_encode(['groups' => db()->query('SELECT id, name, url, last_scan_at FROM groups ORDER BY last_scan_at IS NOT NULL, last_scan_at')->fetchAll()]));
    }
    if ($path === '/api/targets') {
        // What one scan visits: every group and every tracked page, the ones scanned longest ago first.
        $t = db()->query("SELECT url FROM (
            SELECT 'https://www.facebook.com/groups/' || id || '/?sorting_setting=CHRONOLOGICAL' url, last_scan_at FROM groups
            UNION ALL SELECT url, last_scan_at FROM tracked_pages) ORDER BY last_scan_at IS NOT NULL, last_scan_at")->fetchAll(PDO::FETCH_COLUMN);
        exit(json_encode(['targets' => $t], JSON_UNESCAPED_SLASHES));
    }
    if ($path === '/api/capture' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $g = $p = 0;
        $upsert = db()->prepare('INSERT INTO groups(id, name, url, created_at) VALUES(?,?,?,?)
            ON CONFLICT(id) DO UPDATE SET name = COALESCE(NULLIF(excluded.name, \'\'), groups.name)');
        foreach (array_slice((array)($in['groups'] ?? []), 0, 500) as $grp) {
            $id = preg_replace('/[^\w.\-]/', '', (string)($grp['id'] ?? ''));
            if ($id === '') continue;
            $upsert->execute([$id, mb_substr(trim((string)($grp['name'] ?? '')), 0, 200), "https://www.facebook.com/groups/$id/", now()]);
            $members = (int)($grp['members'] ?? 0);
            $privacy = in_array($grp['privacy'] ?? '', ['public', 'private'], true) ? $grp['privacy'] : null;
            if ($members > 0 && $members < 500000000) {
                db()->prepare('UPDATE groups SET members = ?, privacy = COALESCE(?, privacy) WHERE id = ?')->execute([$members, $privacy, $id]);
                db()->prepare('INSERT INTO group_stats(group_id, day, members) VALUES(?, ?, ?)
                    ON CONFLICT(group_id, day) DO UPDATE SET members = excluded.members')->execute([$id, gmdate('Y-m-d'), $members]);
            } elseif ($privacy) {
                db()->prepare('UPDATE groups SET privacy = ? WHERE id = ?')->execute([$privacy, $id]);
            }
            $g++;
        }
        foreach (array_slice((array)($in['posts'] ?? []), 0, 200) as $post) {
            $gid = preg_replace('/[^\w.\-]/', '', (string)($post['group_id'] ?? ''));
            $text = trim((string)($post['text'] ?? ''));
            if ($gid === '' || mb_strlen($text) < 3) continue;
            $gname = mb_substr(trim((string)($post['group_name'] ?? '')), 0, 200);
            $upsert->execute([$gid, $gname, "https://www.facebook.com/groups/$gid/", now()]);
            $url = (string)($post['url'] ?? '');
            if (!preg_match('#^https://(www|m|web)\.facebook\.com/#', $url)) $url = '';
            $ext = (string)($post['ext_id'] ?? '') ?: 'h' . substr(sha1($gid . '|' . mb_substr($text, 0, 300)), 0, 20);
            $p += save_post('group', $gid, $gname ?: $gid, mb_substr($ext, 0, 80), mb_substr(trim((string)($post['author'] ?? '')), 0, 120), $text, $url, null);
        }
        // Pages from the lists "Your pages" and "Liked": own wins over liked.
        $pg = 0;
        $pageUp = db()->prepare("INSERT INTO tracked_pages(id, name, url, kind, created_at) VALUES(?,?,?,?,?)
            ON CONFLICT(id) DO UPDATE SET name = COALESCE(NULLIF(excluded.name, ''), tracked_pages.name),
            kind = CASE WHEN excluded.kind = 'own' THEN 'own' ELSE tracked_pages.kind END");
        foreach (array_slice((array)($in['pages'] ?? []), 0, 1000) as $page) {
            $id = clean_page_id((string)($page['id'] ?? ''));
            if ($id === '') continue;
            $pageUp->execute([$id, mb_substr(trim((string)($page['name'] ?? '')), 0, 200), page_url($id), ($page['kind'] ?? '') === 'own' ? 'own' : 'liked', now()]);
            $pg++;
        }
        // A visit to a page we track: followers + likes, one snapshot per day.
        if (is_array($v = $in['page_visit'] ?? null) && ($id = clean_page_id((string)($v['id'] ?? ''))) !== '') {
            $f = (int)($v['followers'] ?? 0); $l = (int)($v['likes'] ?? 0);
            $f = $f > 0 && $f < 5000000000 ? $f : null; $l = $l > 0 && $l < 5000000000 ? $l : null;
            $name = mb_substr(trim((string)($v['name'] ?? '')), 0, 200);
            $s = db()->prepare("UPDATE tracked_pages SET followers = COALESCE(?, followers), likes = COALESCE(?, likes), name = COALESCE(NULLIF(?, ''), name), last_scan_at = ? WHERE id = ?");
            $s->execute([$f, $l, $name, now(), $id]);
            if ($s->rowCount() && ($f || $l)) {
                db()->prepare('INSERT INTO page_stats(page_id, day, followers, likes) VALUES(?,?,?,?)
                    ON CONFLICT(page_id, day) DO UPDATE SET followers = COALESCE(excluded.followers, page_stats.followers), likes = COALESCE(excluded.likes, page_stats.likes)')
                    ->execute([$id, gmdate('Y-m-d'), $f, $l]);
            }
        }
        if (!empty($in['scanned_group'])) {
            db()->prepare('UPDATE groups SET last_scan_at = ? WHERE id = ?')->execute([now(), (string)$in['scanned_group']]);
        }
        set_setting('ext_last_seen', now());
        exit(json_encode(['ok' => true, 'groups' => $g, 'pages' => $pg, 'new_posts' => $p]));
    }
    http_response_code(404);
    exit(json_encode(['error' => 'not found']));
}

if ($path === '/privacy') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Privacy | Facebook Groups</title><link rel="stylesheet" href="/assets/app.css?v=1">'
        . '<main class="wrap"><div class="card"><h1>Privacy policy</h1><p>This is a private dashboard used by one person (the site owner) to manage their own Facebook pages and read the groups they are a member of. '
        . 'It stores page names, page access tokens and posts from those pages and groups on a server in Germany, only to show them to the owner and to have them summarised by an AI model (Anthropic Claude). '
        . 'Nothing is sold or shared with anyone else. To have data removed, email ole.oldhoj@gmail.com.</p></div></main>';
    exit;
}

start_session();

// ---- login ----------------------------------------------------------------
if ($path === '/login') {
    // One-tap URL login: /login?login=<email>&password=<pw>, then redirect so the password leaves the address bar.
    $email = $_POST['login'] ?? $_GET['login'] ?? null;
    $pw = $_POST['password'] ?? $_GET['password'] ?? null;
    if ($email !== null && $pw !== null) {
        if (attempt_login((string)$email, (string)$pw)) redirect('/');
        flash('Wrong email or password.');
        redirect('/login');
    }
    view_login();
    exit;
}
if ($path === '/logout') {
    session_destroy();
    redirect('/login');
}
if (is_stats_host() || $path === '/stats' || str_starts_with($path, '/stats/')) {
    // facebookstats.fullstackpartner.com: owner sees their groups, everyone else an example dashboard.
    $sub = is_stats_host() ? $path : (substr($path, 6) ?: '/');
    if ($sub === '/' || $sub === '/demo') { view_stats(is_admin() && $sub !== '/demo' ? null : stats_demo()); exit; }
    if (preg_match('#^/group/([\w.\-]+)$#', $sub, $m)) {
        if (str_starts_with($m[1], 'demo-')) view_stats_group($m[1], stats_demo());
        elseif (is_admin()) view_stats_group($m[1], null);
        else redirect('/login');
        exit;
    }
    if (preg_match('#^/page/([\w.:%\-]+)$#', $sub, $m)) {
        $pid = rawurldecode($m[1]);
        if (str_starts_with($pid, 'demo-page-')) view_stats_page($pid, stats_demo());
        elseif (is_admin()) view_stats_page($pid, null);
        else redirect('/login');
        exit;
    }
    if (is_stats_host() && !is_admin()) redirect('/');
}
if (!is_admin()) redirect('/login');

if ($method === 'POST') check_csrf();

try {
    switch ("$method $path") {
        case 'GET /':
            view_posts('new');
            break;
        case 'GET /all':
            view_posts('all');
            break;
        case 'GET /pages':
            view_pages();
            break;
        case 'GET /groups':
            view_groups();
            break;
        case 'GET /settings':
            view_settings();
            break;
        case 'GET /moderation':
            view_moderation();
            break;
        case 'GET /videos':
            view_videos();
            break;
        case 'POST /videos/settings':
            if (!empty($_POST['vid_page'])) set_setting('vid_page', (string)$_POST['vid_page']);
            if (!empty($_POST['vid_gap'])) set_setting('vid_gap', (string)max(5, (int)$_POST['vid_gap']));
            if (in_array($_POST['run'] ?? '', ['0', '1'], true)) set_setting('vid_running', $_POST['run']);
            flash(setting('vid_running') === '1' ? 'Uploading is on. The first video goes out within 5 minutes.' : 'Saved. Uploading is stopped.');
            redirect('/videos');
        case 'POST /videos/save':
            $id = (int)$_POST['id'];
            $title = trim(str_replace('—', ', ', (string)($_POST['title'] ?? '')));
            $desc = trim(str_replace('—', ', ', (string)($_POST['description'] ?? '')));
            $status = in_array($_POST['status'] ?? '', ['queued', 'skipped'], true) ? $_POST['status'] : null;
            db()->prepare("UPDATE videos SET title = ?, description = ?, status = COALESCE(?, status), error = CASE WHEN ? IS NULL THEN error ELSE NULL END
                WHERE id = ? AND status NOT IN ('done', 'uploading')")->execute([$title, $desc, $status, $status, $id]);
            if (!empty($_POST['now'])) {
                if (!setting('vid_page')) throw new RuntimeException('Choose a page first.');
                set_time_limit(900);
                $s = db()->prepare('SELECT * FROM videos WHERE id = ?');
                $s->execute([$id]);
                if ($v = $s->fetch()) vid_upload($v);
                $s->execute([$id]);
                $v = $s->fetch();
                flash($v['status'] === 'done' ? 'Uploaded to Facebook.' : 'Upload failed: ' . $v['error']);
            } else {
                flash($status === 'skipped' ? 'Skipped.' : 'Saved.');
            }
            redirect($_POST['back'] ?? '/videos');
        case 'POST /videos/queue-all':
            db()->exec("UPDATE videos SET status = 'queued' WHERE status = 'new'");
            flash('All queued.');
            redirect('/videos');
        case 'GET /extension.zip':
            send_extension_zip();
            break;

        case 'POST /posts/seen':
            if (!empty($_POST['id'])) {
                db()->prepare('UPDATE posts SET seen = 1 WHERE id = ?')->execute([(int)$_POST['id']]);
            } else {
                db()->exec('UPDATE posts SET seen = 1 WHERE seen = 0');
                flash('All marked as seen.');
            }
            redirect($_POST['back'] ?? '/');
        case 'POST /posts/review':
            db()->prepare("UPDATE posts SET ai_status = 'pending' WHERE id = ?")->execute([(int)$_POST['id']]);
            $s = db()->prepare('SELECT * FROM posts WHERE id = ?');
            $s->execute([(int)$_POST['id']]);
            if ($post = $s->fetch()) ai_review($post);
            redirect($_POST['back'] ?? '/');
        case 'POST /sync':
            $msg = [];
            if (setting('fb_user_token')) {
                $msg[] = fb_sync_pages() . ' pages';
                $msg[] = fb_sync_page_posts() . ' new page posts';
            }
            [$done, $failed] = ai_review_pending(40);
            $msg[] = "$done reviewed by AI" . ($failed ? ", $failed failed (see Settings, Log)" : '');
            flash('Synced: ' . implode(', ', $msg) . '.');
            redirect($_POST['back'] ?? '/');
        case 'POST /moderation/run':
            [$n, $hid] = mod_run();
            flash("Checked $n new comments, hid $hid.");
            redirect('/moderation');
        case 'POST /moderation/hide':
            mod_set_hidden((int)$_POST['id'], true, 'owner');
            flash('Hidden on Facebook.');
            redirect($_POST['back'] ?? '/moderation');
        case 'POST /moderation/unhide':
            mod_set_hidden((int)$_POST['id'], false, 'owner');
            // Owner overruled the AI: never auto-hide this one again.
            db()->prepare("UPDATE moderation SET verdict = 'approved' WHERE id = ?")->execute([(int)$_POST['id']]);
            flash('Visible again on Facebook.');
            redirect($_POST['back'] ?? '/moderation');
        case 'POST /moderation/settings':
            if (in_array($_POST['mod_mode'] ?? '', ['auto', 'review', 'off'], true)) set_setting('mod_mode', $_POST['mod_mode']);
            if (isset($_POST['mod_rules'])) set_setting('mod_rules', trim(str_replace('—', ', ', (string)$_POST['mod_rules'])) ?: null);
            flash('Moderation settings saved.');
            redirect('/moderation');
        case 'POST /pages/post':
            $id = fb_publish((string)$_POST['page_id'], trim((string)$_POST['message']), trim((string)($_POST['link'] ?? '')));
            flash("Posted. Post id $id.");
            redirect('/pages');

        case 'GET /fb/connect':
            if (!fb_ready()) { flash('Add your Facebook App ID and secret first.'); redirect('/settings'); }
            redirect(fb_login_url());
        case 'GET /fb/callback':
            if (isset($_GET['error'])) { flash('Facebook said: ' . ($_GET['error_description'] ?? $_GET['error'])); redirect('/settings'); }
            if (!hash_equals($_SESSION['fb_state'] ?? '', (string)($_GET['state'] ?? ''))) { flash('Login expired, try again.'); redirect('/settings'); }
            $n = fb_handle_callback((string)$_GET['code']);
            $new = fb_sync_page_posts();
            flash("Facebook connected: $n pages found, $new posts loaded.");
            redirect('/pages');
        case 'POST /fb/disconnect':
            foreach (['fb_user_token', 'fb_user_name', 'fb_connected_at'] as $k) set_setting($k, null);
            db()->exec('DELETE FROM pages');
            flash('Facebook disconnected.');
            redirect('/settings');

        case 'POST /settings':
            foreach (['FB_APP_ID', 'FB_APP_SECRET', 'ANTHROPIC_API_KEY'] as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '') set_setting($k, $v);          // blank = keep the saved value
            }
            if (isset($_POST['AI_MODEL'])) set_setting('AI_MODEL', trim((string)$_POST['AI_MODEL']) ?: null);
            if (isset($_POST['ai_instructions'])) set_setting('ai_instructions', trim(str_replace('—', ', ', (string)$_POST['ai_instructions'])) ?: null);
            flash('Settings saved.');
            redirect('/settings');

        default:
            http_response_code(404);
            layout('Not found', '', '<div class="empty">Page not found.</div>');
    }
} catch (RuntimeException $e) {
    app_log('error', $e->getMessage());
    flash($e->getMessage());
    redirect($method === 'POST' ? ($_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? '/') : '/settings');
}
