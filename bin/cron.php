<?php
// Every 5 min: hide insulting comments on pages, upload the next queued video. Every 15 min: refresh pages + page posts, then AI-review new posts.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/fb.php';
require __DIR__ . '/../src/ai.php';
require __DIR__ . '/../src/moderation.php';
require __DIR__ . '/../src/videos.php';

$lock = fopen('/var/lib/fbgroups/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;

try {
    if (fb_ready()) mod_run();
} catch (Throwable $e) {
    app_log('mod', $e->getMessage());
}
try {
    vid_scan();
    vid_tick();
} catch (Throwable $e) {
    app_log('video', $e->getMessage());
}
if ((int)date('i') % 15 >= 5) exit;

try {
    if (setting('fb_user_token') && fb_ready()) {
        // The page list rarely changes; refresh it hourly.
        if ((int)date('i') < 5) fb_sync_pages();
        fb_sync_page_posts();
    }
} catch (Throwable $e) {
    app_log('cron', $e->getMessage());
}
ai_review_pending(60);
// Keep 90 days of seen posts.
db()->exec("DELETE FROM posts WHERE seen = 1 AND captured_at < datetime('now', '-90 days')");
