<?php
// Bulk video upload to a Facebook page: scan a folder on the server, queue, then cron uploads one every N minutes.
declare(strict_types=1);

const VID_DIR_DEFAULT = '/var/www/demos/media/fullstackpartner';
const VID_META = '/var/lib/fbgroups/video-meta.json';

function vid_dir(): string
{
    return rtrim(setting('vid_dir') ?? VID_DIR_DEFAULT, '/');
}

function vid_gap(): int
{
    return max(5, (int)(setting('vid_gap') ?? 60));
}

/** Add new video files from the folder. Files with a title from video-meta.json are queued, the rest wait for you. */
function vid_scan(): int
{
    $meta = is_readable(VID_META) ? (json_decode((string)file_get_contents(VID_META), true) ?: []) : [];
    $ins = db()->prepare("INSERT OR IGNORE INTO videos(file, title, description, status, created_at) VALUES(?,?,?,?,?)");
    $added = 0;
    foreach (glob(vid_dir() . '/*.{mp4,mov,webm,m4v}', GLOB_BRACE) ?: [] as $path) {
        $file = basename($path);
        $m = $meta[$file] ?? null;
        $title = $m['title'] ?? ucwords(str_replace(['-', '_'], ' ', pathinfo($file, PATHINFO_FILENAME)));
        $desc = $m['description'] ?? "Made by fullstackpartner.com: https://fullstackpartner.com";
        $ins->execute([$file, $title, $desc, $m ? 'queued' : 'new', now()]);
        $added += $ins->rowCount();
    }
    return $added;
}

/** Called by cron: upload the next queued video if uploading is on and the gap since the last upload has passed. */
function vid_tick(): void
{
    if (setting('vid_running') !== '1' || !setting('vid_page')) return;
    $last = setting('vid_last_at');
    if ($last && strtotime($last . ' UTC') > time() - vid_gap() * 60 + 30) return;
    $v = db()->query("SELECT * FROM videos WHERE status = 'queued' ORDER BY sort, id LIMIT 1")->fetch();
    if (!$v) { set_setting('vid_running', '0'); app_log('video', 'Queue empty, uploading stopped.'); return; }
    vid_upload($v);
}

function vid_upload(array $v): void
{
    $s = db()->prepare('SELECT token FROM pages WHERE id = ? AND can_post = 1');
    $s->execute([setting('vid_page')]);
    $token = $s->fetchColumn();
    $set = db()->prepare('UPDATE videos SET status = ?, fb_id = ?, error = ?, uploaded_at = ?, page_id = ? WHERE id = ?');
    if (!$token) { $set->execute(['failed', null, 'You cannot post on the chosen page.', now(), setting('vid_page'), $v['id']]); return; }
    $path = vid_dir() . '/' . $v['file'];
    if (!is_readable($path)) { $set->execute(['failed', null, "File missing: $path", now(), setting('vid_page'), $v['id']]); return; }
    set_setting('vid_last_at', now());
    $set->execute(['uploading', null, null, null, setting('vid_page'), $v['id']]);

    $fields = [
        'access_token' => $token,
        'title' => mb_substr($v['title'], 0, 255),
        'description' => $v['description'],
        'published' => 'true',
        'source' => new CURLFile($path, 'video/' . (pathinfo($path, PATHINFO_EXTENSION) === 'webm' ? 'webm' : 'mp4'), $v['file']),
    ];
    $thumb = preg_replace('/\.\w+$/', '.jpg', $path);
    if (is_readable($thumb)) $fields['thumb'] = new CURLFile($thumb, 'image/jpeg', basename($thumb));

    $ch = curl_init('https://graph-video.facebook.com/' . conf('FB_GRAPH_VERSION', 'v24.0') . '/' . setting('vid_page') . '/videos');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_TIMEOUT => 900]);
    $body = curl_exec($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if (!empty($json['id'])) {
        $set->execute(['done', $json['id'], null, now(), setting('vid_page'), $v['id']]);
        app_log('video', "Uploaded {$v['file']} as video {$json['id']}");
        return;
    }
    $err = $json['error']['message'] ?? (curl_error($ch) ?: 'HTTP ' . curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    $set->execute(['failed', null, mb_substr($err, 0, 500), now(), setting('vid_page'), $v['id']]);
    app_log('video', "{$v['file']}: $err");
    // A token or permission problem will fail every video, so stop instead of burning through the queue.
    if (isset($json['error']['code']) && in_array((int)$json['error']['code'], [10, 100, 190, 200], true)) set_setting('vid_running', '0');
}

function view_videos(): void
{
    vid_scan();
    $pages = db()->query('SELECT id, name FROM pages WHERE can_post = 1 ORDER BY name')->fetchAll();
    $f = (string)($_GET['f'] ?? 'queued');
    $where = match ($f) { 'new' => "status = 'new'", 'done' => "status = 'done'", 'failed' => "status = 'failed'", 'skipped' => "status = 'skipped'", 'all' => '1', default => "status IN ('queued','uploading')" };
    $items = db()->query("SELECT * FROM videos WHERE $where ORDER BY sort, id")->fetchAll();
    $c = db()->query("SELECT status, COUNT(*) n FROM videos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $running = setting('vid_running') === '1';

    $out = status_strip();
    if (!$pages) {
        $out .= '<div class="card note"><p>Connect Facebook first (<a href="/settings#facebook">Settings</a>), so the fullstackpartner.com page shows up here. '
            . 'Your page (id 61596155216172) is preselected and appears here once connected. Video upload needs the <b>publish_video</b> permission, so press Reconnect once if you connected before.</p></div>';
    }
    $out .= '<section class="stats">' . stat_box((int)($c['queued'] ?? 0), 'queued') . stat_box((int)($c['done'] ?? 0), 'uploaded')
        . stat_box((int)($c['failed'] ?? 0), 'failed') . stat_box((int)($c['new'] ?? 0), 'waiting for you') . '</section>';

    $opts = '';
    foreach ($pages as $p) {
        $sel = setting('vid_page') === $p['id'] || (!setting('vid_page') && stripos($p['name'], 'fullstack') !== false);
        $opts .= '<option value="' . h($p['id']) . '"' . ($sel ? ' selected' : '') . '>' . h($p['name']) . '</option>';
    }
    $gaps = '';
    foreach ([5 => 'every 5 minutes', 30 => 'every 30 minutes', 60 => 'every hour', 180 => 'every 3 hours', 360 => 'every 6 hours', 1440 => 'once a day'] as $m => $label) {
        $gaps .= '<option value="' . $m . '"' . (vid_gap() === $m ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $queued = (int)($c['queued'] ?? 0);
    $out .= '<div class="card"><h2>Upload videos ' . ($running ? '<span class="tag ok">running</span>' : '<span class="tag">stopped</span>') . '</h2>'
        . '<p>Videos in <code>' . h(vid_dir()) . '</code> are posted to the page one at a time. Title and text come from the project cards on fullstackpartner.com; edit them below before they go out.'
        . ($running && $queued ? ' Next upload ' . h(setting('vid_last_at') ? 'about ' . max(0, intdiv(strtotime(setting('vid_last_at') . ' UTC') + vid_gap() * 60 - time(), 60)) . ' min from now' : 'within 5 minutes') . ', all ' . $queued . ' done in about ' . h(vid_eta($queued)) . '.' : '') . '</p>'
        . '<form method="post" action="/videos/settings" class="form">' . hidden_csrf()
        . '<label>Page<select name="vid_page"' . ($pages ? '' : ' disabled') . '>' . ($opts ?: '<option>No pages yet</option>') . '</select></label>'
        . '<label>Post one video<select name="vid_gap">' . $gaps . '</select></label>'
        . '<div class="actions"><button class="primary" name="run" value="' . ($running ? '0' : '1') . '"' . ($pages ? '' : ' disabled') . '>' . ($running ? 'Stop uploading' : 'Start uploading') . '</button>'
        . '<button name="run" value="keep">Save</button></div></form></div>';

    $chips = '';
    foreach (['queued' => 'Queued', 'new' => 'Waiting for you', 'done' => 'Uploaded', 'failed' => 'Failed', 'skipped' => 'Skipped', 'all' => 'All'] as $k => $label) {
        $chips .= '<a class="chip' . ($f === $k ? ' on' : '') . '" href="/videos?f=' . $k . '">' . h($label) . '</a>';
    }
    $out .= '<div class="toolbar"><div class="chips">' . $chips . '</div>'
        . ($f === 'new' && $items ? '<form method="post" action="/videos/queue-all">' . hidden_csrf() . '<button>Queue all ' . count($items) . '</button></form>' : '') . '</div>';
    if (!$items) $out .= '<div class="empty">Nothing here.</div>';
    foreach ($items as $v) {
        $tag = match ($v['status']) {
            'done' => '<span class="tag ok">Uploaded ' . h(ago($v['uploaded_at'])) . '</span>',
            'failed' => '<span class="tag warn">Failed</span>', 'uploading' => '<span class="tag new">Uploading</span>',
            'queued' => '<span class="tag">Queued</span>', 'skipped' => '<span class="tag">Skipped</span>', default => '<span class="tag warn">Not queued</span>',
        };
        $id = (int)$v['id'];
        $btns = '';
        if ($v['status'] !== 'done' && $v['status'] !== 'uploading') {
            $btns .= '<button name="status" value="queued" class="primary">' . ($v['status'] === 'queued' ? 'Save' : 'Save + queue') . '</button>'
                . ($v['status'] !== 'skipped' ? '<button name="status" value="skipped">Skip</button>' : '')
                . ($pages ? '<button name="now" value="1" data-confirm="Upload this video to Facebook right now?">Upload now</button>' : '');
        }
        $out .= '<article class="post"><div class="meta"><b>' . h($v['file']) . '</b> ' . $tag
            . ' <a href="https://fullstackpartner.com/media/' . rawurlencode($v['file']) . '" target="_blank" rel="noopener">preview</a>'
            . ($v['fb_id'] ? ' <a href="https://www.facebook.com/' . h($v['fb_id']) . '" target="_blank" rel="noopener">open on Facebook</a>' : '') . '</div>'
            . ($v['error'] ? '<div class="ai err">Facebook: ' . h($v['error']) . '</div>' : '')
            . ($v['status'] === 'done' ? '<p><b>' . h($v['title']) . '</b></p><div class="text clamp">' . nl2br(h($v['description'])) . '</div>'
                : '<form method="post" action="/videos/save" class="form">' . hidden_csrf() . '<input type="hidden" name="id" value="' . $id . '">'
                . '<label>Title<input name="title" maxlength="255" value="' . h($v['title']) . '"></label>'
                . '<label>Text<textarea name="description" rows="6">' . h($v['description']) . '</textarea></label>'
                . '<div class="actions">' . $btns . '</div></form>')
            . '</article>';
    }
    layout('Videos', '/videos', $out);
}

function vid_eta(int $n): string
{
    $min = max(0, $n - 1) * vid_gap();
    return $min < 120 ? max(5, $min) . ' min' : ($min < 2880 ? round($min / 60) . ' hours' : round($min / 1440) . ' days');
}
