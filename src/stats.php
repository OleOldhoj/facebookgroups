<?php
// Group statistics: facebookstats.fullstackpartner.com (and /stats on the groups dashboard).
// Members come from the group header the extension reads on every visit (one snapshot per group per day);
// posts are counted on the day the extension first saw them, because Facebook shows no machine-readable post date.
declare(strict_types=1);

const STATS_DAYS = 30;
const STATS_PLUGIN_VERSION = '1.4.0';

function stats_base(): string
{
    return is_stats_host() ? '' : '/stats';
}

function stats_days(int $n): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) $out[] = gmdate('Y-m-d', time() - $i * 86400);
    return $out;
}

/** Fill a day => value map forward over $days, starting from the last value before the range. */
function stats_carry(array $snap, array $days): array
{
    ksort($snap);
    $out = [];
    $last = null;
    foreach ($snap as $d => $v) if ($d < $days[0]) $last = $v;
    foreach ($days as $d) {
        if (isset($snap[$d])) $last = $snap[$d];
        $out[$d] = $last;
    }
    return $out;
}

/** Everything the overview needs, from the database. */
function stats_real(): array
{
    $days = stats_days(STATS_DAYS);
    $from = $days[0] . ' 00:00:00';
    $from7 = gmdate('Y-m-d', time() - 6 * 86400) . ' 00:00:00';
    $prev7 = gmdate('Y-m-d', time() - 13 * 86400) . ' 00:00:00';
    $groups = db()->query('SELECT * FROM groups ORDER BY name')->fetchAll();

    $snaps = [];
    foreach (db()->query('SELECT group_id, day, members FROM group_stats ORDER BY day') as $r) $snaps[$r['group_id']][$r['day']] = (int)$r['members'];

    $per = [];
    $s = db()->prepare("SELECT source_id, substr(captured_at, 1, 10) d, COUNT(*) n FROM posts WHERE source = 'group' AND captured_at >= ? GROUP BY source_id, d");
    $s->execute([$from]);
    foreach ($s as $r) $per[$r['source_id']][$r['d']] = (int)$r['n'];

    $agg = [];
    $s = db()->prepare("SELECT source_id, COUNT(DISTINCT NULLIF(author, '')) authors,
        SUM(ai_category IN ('lead', 'opportunity')) leads, SUM(ai_category IN ('spam', 'rule_violation')) spam,
        SUM(captured_at >= ?) p7, SUM(captured_at >= ? AND captured_at < ?) prev7
        FROM posts WHERE source = 'group' AND captured_at >= ? GROUP BY source_id");
    $s->execute([$from7, $prev7, $from7, $from]);
    foreach ($s as $r) $agg[$r['source_id']] = $r;
    $last = [];
    foreach (db()->query("SELECT source_id, MAX(captured_at) m FROM posts WHERE source = 'group' GROUP BY source_id") as $r) $last[$r['source_id']] = $r['m'];

    $rows = [];
    foreach ($groups as $g) {
        $id = $g['id'];
        $series = stats_carry($snaps[$id] ?? [], $days);
        $a = $agg[$id] ?? [];
        $rows[] = [
            'id' => $id, 'name' => $g['name'] ?: $id, 'url' => $g['url'], 'privacy' => $g['privacy'],
            'members' => $g['members'] !== null ? (int)$g['members'] : null,
            'members_series' => $series,
            'posts_series' => array_map(fn($d) => $per[$id][$d] ?? 0, array_combine($days, $days)),
            'posts7' => (int)($a['p7'] ?? 0), 'prev7' => (int)($a['prev7'] ?? 0),
            'authors30' => (int)($a['authors'] ?? 0), 'leads30' => (int)($a['leads'] ?? 0), 'spam30' => (int)($a['spam'] ?? 0),
            'last_post' => $last[$id] ?? null, 'last_scan' => $g['last_scan_at'],
        ];
    }

    $s = db()->prepare("SELECT author, COUNT(*) n, COUNT(DISTINCT source_id) g FROM posts
        WHERE source = 'group' AND captured_at >= ? AND author != '' GROUP BY author ORDER BY n DESC LIMIT 10");
    $s->execute([$from]);
    $authors = $s->fetchAll();
    $s = db()->prepare("SELECT COALESCE(ai_category, 'not reviewed') c, COUNT(*) n FROM posts
        WHERE source = 'group' AND captured_at >= ? GROUP BY c ORDER BY n DESC");
    $s->execute([$from]);
    $cats = array_column($s->fetchAll(), 'n', 'c');

    return ['demo' => false, 'days' => $days, 'groups' => $rows, 'pages' => stats_pages_real($days), 'authors' => $authors, 'categories' => $cats, 'ext_last_seen' => setting('ext_last_seen')];
}

/** Pages you run or follow, with daily follower + like snapshots. */
function stats_pages_real(array $days): array
{
    $snapF = $snapL = [];
    foreach (db()->query('SELECT page_id, day, followers, likes FROM page_stats ORDER BY day') as $r) {
        if ($r['followers'] !== null) $snapF[$r['page_id']][$r['day']] = (int)$r['followers'];
        if ($r['likes'] !== null) $snapL[$r['page_id']][$r['day']] = (int)$r['likes'];
    }
    $rows = [];
    foreach (db()->query('SELECT * FROM tracked_pages ORDER BY name') as $p) {
        $rows[] = [
            'id' => $p['id'], 'name' => $p['name'] ?: $p['id'], 'url' => $p['url'], 'kind' => $p['kind'],
            'followers' => $p['followers'] !== null ? (int)$p['followers'] : null, 'likes' => $p['likes'] !== null ? (int)$p['likes'] : null,
            'followers_series' => stats_carry($snapF[$p['id']] ?? [], $days), 'likes_series' => stats_carry($snapL[$p['id']] ?? [], $days),
            'last_scan' => $p['last_scan_at'],
        ];
    }
    return $rows;
}

/** Latest posts + top authors for one group (real data). */
function stats_group_posts(string $id): array
{
    $s = db()->prepare("SELECT author, text, url, captured_at, ai_category, ai_summary FROM posts WHERE source = 'group' AND source_id = ? ORDER BY captured_at DESC LIMIT 15");
    $s->execute([$id]);
    $posts = $s->fetchAll();
    $s = db()->prepare("SELECT author, COUNT(*) n FROM posts WHERE source = 'group' AND source_id = ? AND author != '' AND captured_at >= ? GROUP BY author ORDER BY n DESC LIMIT 10");
    $s->execute([$id, stats_days(STATS_DAYS)[0]]);
    return [$posts, $s->fetchAll()];
}

/** Example dashboard for visitors who are not the owner. Fixed seed, so it looks the same on every visit. */
function stats_demo(): array
{
    mt_srand(4207);
    $days = stats_days(STATS_DAYS);
    $names = [
        ['Cebu Small Business Owners', 'public', 48200], ['Remote Jobs Philippines', 'public', 131500],
        ['Copenhagen Startup Network', 'private', 9400], ['Freelance Web Developers DK', 'private', 6120],
        ['PHP & Laravel Developers', 'public', 72300], ['Iværksættere i Danmark', 'private', 22800],
        ['Digital Marketing Asia', 'public', 58900], ['Real Estate Cebu Buy and Sell', 'public', 204000],
        ['SaaS Founders Europe', 'private', 15300], ['Flutter Developers', 'public', 96100],
        ['Expats in Mactan', 'private', 11700], ['No-Code Builders', 'public', 33600],
    ];
    $people = ['Maria Santos', 'Jens Holm', 'Ana Reyes', 'Mikkel Lund', 'John Cruz', 'Sofie Berg', 'Mark Villanueva', 'Lars Nielsen', 'Grace Tan', 'Peter Kragh', 'Joy Ramos', 'Emil Sørensen'];
    $rows = [];
    foreach ($names as $i => [$name, $priv, $m]) {
        $growth = (mt_rand(-5, 40) / 10000);   // daily
        $act = max(1, (int)round(sqrt($m) / mt_rand(18, 40)));
        $ms = $ps = [];
        $cur = (int)round($m / (1 + $growth) ** STATS_DAYS);
        foreach ($days as $k => $d) {
            $cur = (int)round($cur * (1 + $growth + mt_rand(-8, 8) / 100000));
            $ms[$d] = $cur;
            $wd = (int)gmdate('N', strtotime($d));
            $ps[$d] = max(0, (int)round($act * ($wd >= 6 ? .6 : 1.1) * mt_rand(40, 160) / 100));
        }
        $vals = array_values($ps);
        $p7 = array_sum(array_slice($vals, -7));
        $rows[] = [
            'id' => 'demo-' . ($i + 1), 'name' => $name, 'url' => '', 'privacy' => $priv, 'members' => end($ms),
            'members_series' => $ms, 'posts_series' => $ps, 'posts7' => $p7, 'prev7' => array_sum(array_slice($vals, -14, 7)),
            'authors30' => (int)round(array_sum($vals) * mt_rand(45, 75) / 100), 'leads30' => mt_rand(0, (int)ceil($act * 2)),
            'spam30' => mt_rand(0, (int)ceil($act * 1.5)),
            'last_post' => gmdate('Y-m-d H:i:s', time() - mt_rand(600, 30000)), 'last_scan' => gmdate('Y-m-d H:i:s', time() - mt_rand(600, 20000)),
        ];
    }
    $total = array_sum(array_map(fn($r) => array_sum($r['posts_series']), $rows));
    $authors = [];
    foreach ($people as $k => $p) $authors[] = ['author' => $p, 'n' => max(3, (int)round(46 / ($k + 1.4)) + mt_rand(0, 3)), 'g' => mt_rand(1, 5)];
    usort($authors, fn($a, $b) => $b['n'] <=> $a['n']);
    $cats = ['question' => (int)($total * .31), 'discussion' => (int)($total * .22), 'lead' => (int)($total * .12), 'promotion' => (int)($total * .14),
        'job' => (int)($total * .09), 'spam' => (int)($total * .07), 'news' => (int)($total * .05)];
    arsort($cats);
    $posts = [];
    $samples = [
        ['question', 'Anyone know a good accountant who works with small businesses? Need help with quarterly filing.'],
        ['lead', 'Looking for a developer to build a booking site for our resort. Budget ready, please send portfolio.'],
        ['discussion', 'What tools are you all using for invoicing this year? We are moving away from spreadsheets.'],
        ['job', 'Hiring: part-time virtual assistant, 20 h/week, remote. Comment below or DM.'],
        ['spam', 'Earn 5000 a day from home, no experience!!! Click the link in my profile.'],
        ['lead', 'Need someone to fix our Facebook ads, they stopped converting last month. Who can help?'],
    ];
    foreach ($samples as $k => [$c, $t]) $posts[] = ['author' => $people[$k * 2 % 12], 'text' => $t, 'url' => '', 'captured_at' => gmdate('Y-m-d H:i:s', time() - ($k + 1) * 4100), 'ai_category' => $c, 'ai_summary' => null];
    $pages = [];
    foreach ([['Full Stack Partner', 'own', 2400], ['Bubble Laundry Cebu', 'own', 8900], ['Calma Massage', 'own', 1350], ['Cebu Business Week', 'liked', 64000],
        ['Startup Digest DK', 'liked', 21700], ['Laravel News', 'liked', 182000], ['Flutter Community', 'liked', 97500], ['Visit Cebu', 'liked', 410000]] as $i => [$name, $kind, $f]) {
        $growth = mt_rand(kind_growth_min($kind), 60) / 10000;
        $cur = $f / (1 + $growth) ** STATS_DAYS;
        $fs = $ls = [];
        foreach ($days as $d) { $cur *= 1 + $growth + mt_rand(-5, 5) / 100000; $fs[$d] = (int)round($cur); $ls[$d] = (int)round($cur * .82); }
        $pages[] = ['id' => 'demo-page-' . ($i + 1), 'name' => $name, 'url' => '', 'kind' => $kind, 'followers' => end($fs), 'likes' => end($ls),
            'followers_series' => $fs, 'likes_series' => $ls, 'last_scan' => gmdate('Y-m-d H:i:s', time() - mt_rand(900, 40000))];
    }
    mt_srand();
    return ['demo' => true, 'days' => $days, 'groups' => $rows, 'pages' => $pages, 'authors' => $authors, 'categories' => $cats, 'ext_last_seen' => null, 'demo_posts' => $posts];
}

function kind_growth_min(string $kind): int
{
    return $kind === 'own' ? 10 : -3;
}

// ---- small helpers --------------------------------------------------------

function fmt_n(?int $n): string
{
    if ($n === null) return '–';
    return match (true) {
        abs($n) >= 1e6 => rtrim(rtrim(number_format($n / 1e6, 1), '0'), '.') . 'M',
        abs($n) >= 1e4 => rtrim(rtrim(number_format($n / 1e3, 1), '0'), '.') . 'K',
        default => number_format($n),
    };
}

function fmt_delta(?int $d, string $suffix = ''): string
{
    if ($d === null) return '<span class="muted">–</span>';
    if ($d === 0) return '<span class="muted">0' . $suffix . '</span>';
    return '<span class="' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '−') . fmt_n(abs($d)) . $suffix . '</span>';
}

/** Member change over the last $n days, or null when there is no snapshot that old. */
function member_delta(array $series, int $n): ?int
{
    // Oldest snapshot in the window when tracking started less than $n days ago.
    $vals = array_values($series);
    $last = count($vals) - 1;
    $now = $vals[$last] ?? null;
    for ($i = max(0, $last - $n); $i < $last; $i++) if ($vals[$i] !== null) return $now === null ? null : $now - $vals[$i];
    return null;
}

function svg_bars(array $series, string $label): string
{
    $vals = array_values($series);
    $keys = array_keys($series);
    $max = max(1, ...$vals);
    $w = 640; $h = 160; $n = count($vals); $bw = $w / $n;
    $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" role="img" aria-label="' . h($label) . '">';
    foreach ([0.5, 1] as $f) {
        $y = $h - $h * $f;
        $out .= '<line x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" class="grid"/><text x="0" y="' . ($y + 11) . '" class="ax">' . fmt_n((int)round($max * $f)) . '</text>';
    }
    foreach ($vals as $i => $v) {
        $bh = $v ? max(2, $h * $v / $max) : 0;
        $out .= '<rect x="' . round($i * $bw + 1.5, 1) . '" y="' . round($h - $bh, 1) . '" width="' . round($bw - 3, 1) . '" height="' . round($bh, 1) . '" rx="2" class="bar"><title>' . h(date('D j M', strtotime($keys[$i]))) . ': ' . $v . '</title></rect>';
    }
    $out .= '<line x1="0" x2="' . $w . '" y1="' . $h . '" y2="' . $h . '" class="base"/>';
    foreach ([0, intdiv($n, 2), $n - 1] as $i) {
        $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
        $out .= '<text x="' . round($i * $bw + ($i === 0 ? 0 : ($i === $n - 1 ? $bw : $bw / 2)), 1) . '" y="' . ($h + 16) . '" text-anchor="' . $anchor . '" class="ax">' . h(date('j M', strtotime($keys[$i]))) . '</text>';
    }
    return $out . '</svg>';
}

function svg_line(array $series, string $label): string
{
    $pts = array_filter($series, fn($v) => $v !== null);
    if (count($pts) < 2) return '<div class="empty small">Member counts appear after the extension has visited your groups on two different days.</div>';
    $keys = array_keys($series);
    $min = min($pts); $max = max($pts);
    $pad = max(1, ($max - $min) * .15); $lo = max(0, $min - $pad); $hi = $max + $pad;
    $w = 640; $h = 160; $n = count($series);
    $xy = [];
    foreach ($keys as $i => $d) if ($series[$d] !== null) $xy[] = [round($i * $w / ($n - 1), 1), round($h - $h * ($series[$d] - $lo) / ($hi - $lo), 1), $d, $series[$d]];
    $line = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $xy));
    $first = $xy[0]; $last = end($xy);
    $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" role="img" aria-label="' . h($label) . '">'
        . '<polygon points="' . $first[0] . ',' . $h . ' ' . $line . ' ' . $last[0] . ',' . $h . '" class="area"/>'
        . '<polyline points="' . $line . '" class="line"/>'
        . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="4" class="dot"/>'
        . '<text x="' . ($last[0] - 8) . '" y="' . max(12, $last[1] - 10) . '" text-anchor="end" class="ax strong">' . fmt_n($last[3]) . '</text>'
        . '<text x="0" y="' . ($h + 16) . '" class="ax">' . h(date('j M', strtotime($keys[0]))) . '</text>'
        . '<text x="' . $w . '" y="' . ($h + 16) . '" text-anchor="end" class="ax">' . h(date('j M', strtotime(end($keys)))) . '</text>';
    foreach ($xy as $p) $out .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="6" class="hit"><title>' . h(date('D j M', strtotime($p[2]))) . ': ' . number_format($p[3]) . ' members</title></circle>';
    return $out . '</svg>';
}

function spark(array $series): string
{
    $vals = array_slice(array_values($series), -14);
    $max = max(1, ...$vals);
    $out = '<svg class="spark" viewBox="0 0 70 20" aria-hidden="true">';
    foreach ($vals as $i => $v) {
        $bh = $v ? max(1.5, 20 * $v / $max) : 0;
        $out .= '<rect x="' . ($i * 5) . '" y="' . round(20 - $bh, 1) . '" width="3.6" height="' . round($bh, 1) . '"/>';
    }
    return $out . '</svg>';
}

function tile(string $label, string $value, string $sub = ''): string
{
    return '<div class="tile"><div class="tl">' . h($label) . '</div><div class="tv">' . $value . '</div>' . ($sub ? '<div class="ts">' . $sub . '</div>' : '') . '</div>';
}

// ---- pages ----------------------------------------------------------------

function stats_layout(string $title, array $data, string $body): void
{
    $base = stats_base();
    $groupsUrl = is_stats_host() ? 'https://facebookgroups.fullstackpartner.com/' : '/';
    $right = $data['demo']
        ? (is_admin() ? '<a class="btn" href="' . ($base ?: '/') . '">Your groups</a>' : '<a class="btn primary" href="/login">Log in</a>')
        : '<a class="btn" href="' . h($groupsUrl) . '">Posts dashboard</a><a class="btn" href="' . $base . '/demo">Example</a>';
    $flash = flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' | Facebook Stats</title><meta name="description" content="Member growth, activity and top posters across all your Facebook groups.">'
        . '<link rel="icon" href="/assets/icon.svg"><link rel="stylesheet" href="/assets/app.css?v=1"><link rel="stylesheet" href="/assets/stats.css?v=5"></head><body>'
        . '<header class="top"><div class="wrap"><a class="brand" href="' . ($base ?: '/') . '"><img src="/assets/icon.svg" alt=""> Facebook Stats</a><nav></nav><div class="actions">' . $right . '</div></div></header><main class="wrap">';
    if ($flash) echo '<div class="flash">' . h($flash) . '</div>';
    if ($data['demo']) echo '<div class="card note demo-note"><b>Example data.</b> This is what the dashboard looks like with 12 groups and 8 pages. '
        . (is_admin() ? 'Your own numbers are on <a href="' . ($base ?: '/') . '">Your groups</a>.' : 'The owner logs in to see their own groups.') . '</div>';
    echo $body . '</main><script src="/assets/stats.js?v=4"></script></body></html>';
}

function view_stats(?array $data): void
{
    $data ??= stats_real();
    $base = stats_base();
    $groups = $data['groups'];
    $pages = $data['pages'] ?? [];
    $days = $data['days'];

    if (!$groups && !$pages) {
        $out = plugin_card(true) . '<p class="center"><a class="btn" href="' . $base . '/demo">See what it looks like with 12 groups and 8 pages</a></p>';
        stats_layout('Overview', $data, $out);
        return;
    }

    $totalSeries = [];
    foreach ($days as $d) {
        $sum = null;
        foreach ($groups as $g) if (($v = $g['members_series'][$d]) !== null) $sum = ($sum ?? 0) + $v;
        $totalSeries[$d] = $sum;
    }
    $postsSeries = array_fill_keys($days, 0);
    foreach ($groups as $g) foreach ($g['posts_series'] as $d => $v) $postsSeries[$d] += $v;
    $members = array_sum(array_map(fn($g) => (int)$g['members'], $groups));
    $known = count(array_filter($groups, fn($g) => $g['members'] !== null));
    $p7 = array_sum(array_column($groups, 'posts7'));
    $prev7 = array_sum(array_column($groups, 'prev7'));
    $p30 = array_sum($postsSeries);
    $leads = array_sum(array_column($groups, 'leads30'));
    $d30 = member_delta($totalSeries, STATS_DAYS - 1);
    $active = count(array_filter($groups, fn($g) => $g['posts7'] > 0));

    $fSeries = [];
    foreach ($days as $d) {
        $sum = null;
        foreach ($pages as $pg) if (($v = $pg['followers_series'][$d]) !== null) $sum = ($sum ?? 0) + $v;
        $fSeries[$d] = $sum;
    }
    $followers = array_sum(array_map(fn($pg) => (int)$pg['followers'], $pages));
    $own = count(array_filter($pages, fn($pg) => $pg['kind'] === 'own'));

    $out = ($data['demo'] ? '' : plugin_card(false)) . '<div class="head"><h1>All your groups and pages</h1><span class="muted">Last ' . STATS_DAYS . ' days'
        . ($data['ext_last_seen'] ? ' · extension last seen ' . h(ago($data['ext_last_seen'])) : '') . '</span></div>';
    $out .= '<div class="tiles">'
        . tile('Groups', number_format(count($groups)), $active . ' active this week')
        . tile('Members, all groups', $known ? fmt_n($members) : '–', $known ? fmt_delta($d30) . ' in 30 days' : 'not recorded yet')
        . tile('Posts this week', number_format($p7), $prev7 ? fmt_delta($p7 - $prev7) . ' vs last week' : 'last 7 days')
        . tile('Leads found', number_format($leads), 'posts flagged by AI')
        . tile('Pages', number_format(count($pages)), $own . ' yours, ' . (count($pages) - $own) . ' followed')
        . tile('Followers, all pages', $followers ? fmt_n($followers) : '–', $followers ? fmt_delta(member_delta($fSeries, STATS_DAYS - 1)) . ' in 30 days' : 'not recorded yet')
        . '</div>';
    if ($groups) $out .= stats_groups_html($data, $groups, $postsSeries, $totalSeries, $base);
    if ($pages) $out .= stats_pages_html($pages, $fSeries, $base);
    stats_layout('Overview', $data, $out);
}

function stats_groups_html(array $data, array $groups, array $postsSeries, array $totalSeries, string $base): string
{
    $out = '<h2 class="h">Groups <span class="muted">' . count($groups) . '</span></h2>';
    $out .= '<div class="grid2"><section class="card"><h2>Posts per day</h2><p class="muted">Counted on the day the extension first saw them.</p>' . svg_bars($postsSeries, 'Posts per day, all groups') . '</section>'
        . '<section class="card"><h2>Members, all groups</h2><p class="muted">From the member count in each group\'s header.</p>' . svg_line($totalSeries, 'Total members over time') . '</section></div>';

    // Table, sortable in stats.js.
    $out .= '<section class="card"><h2>Every group <span class="muted">' . count($groups) . ' · click a column to sort</span></h2><div class="table"><table class="sortable"><thead><tr>'
        . '<th data-t="s">Group</th><th data-t="n" class="num">Members</th><th data-t="n" class="num">7 days</th><th data-t="n" class="num">30 days</th>'
        . '<th data-t="n" class="num">Posts 7d</th><th>Last 14 days</th><th data-t="n" class="num">Posters 30d</th><th data-t="n" class="num">Leads</th><th data-t="n" class="num">Spam</th><th data-t="n">Last post</th></tr></thead><tbody>';
    usort($groups, fn($a, $b) => [$b['posts7'], $b['members']] <=> [$a['posts7'], $a['members']]);
    foreach ($groups as $g) {
        $d7 = member_delta($g['members_series'], 7);
        $d30g = member_delta($g['members_series'], STATS_DAYS - 1);
        $lp = $g['last_post'] ? strtotime($g['last_post'] . ' UTC') : 0;
        $out .= '<tr><td data-v="' . h(mb_strtolower($g['name'])) . '"><a href="' . $base . '/group/' . urlencode($g['id']) . '">' . h($g['name']) . '</a>'
            . ($g['privacy'] ? ' <span class="tag">' . h($g['privacy']) . '</span>' : '') . '</td>'
            . '<td class="num" data-v="' . (int)$g['members'] . '">' . fmt_n($g['members']) . '</td>'
            . '<td class="num" data-v="' . (int)$d7 . '">' . fmt_delta($d7) . '</td>'
            . '<td class="num" data-v="' . (int)$d30g . '">' . fmt_delta($d30g) . '</td>'
            . '<td class="num" data-v="' . $g['posts7'] . '">' . $g['posts7'] . '</td>'
            . '<td>' . spark($g['posts_series']) . '</td>'
            . '<td class="num" data-v="' . $g['authors30'] . '">' . $g['authors30'] . '</td>'
            . '<td class="num" data-v="' . $g['leads30'] . '">' . ($g['leads30'] ?: '<span class="muted">0</span>') . '</td>'
            . '<td class="num" data-v="' . $g['spam30'] . '">' . ($g['spam30'] ?: '<span class="muted">0</span>') . '</td>'
            . '<td class="muted" data-v="' . $lp . '">' . h($g['last_post'] ? ago($g['last_post']) : 'none yet') . '</td></tr>';
    }
    $out .= '</tbody></table></div></section>';

    $out .= '<div class="grid2">' . authors_card($data['authors'], 'Top posters across your groups') . categories_card($data['categories']) . '</div>';
    return $out;
}

function stats_pages_html(array $pages, array $fSeries, string $base): string
{
    $out = '<h2 class="h">Pages <span class="muted">' . count($pages) . '</span></h2>';
    $growth = [];
    foreach ($pages as $pg) if (($d = member_delta($pg['followers_series'], STATS_DAYS - 1)) !== null) $growth[] = ['author' => $pg['name'], 'n' => $d];
    usort($growth, fn($a, $b) => $b['n'] <=> $a['n']);
    $growth = array_values(array_filter(array_slice($growth, 0, 8), fn($g) => $g['n'] > 0));
    $out .= '<div class="grid2"><section class="card"><h2>Followers, all pages</h2><p class="muted">From the follower count in each page\'s header.</p>' . svg_line($fSeries, 'Total followers over time') . '</section>'
        . authors_card($growth, 'Most new followers, 30 days', true) . '</div>';
    $out .= '<section class="card"><h2>Every page <span class="muted">' . count($pages) . ' · click a column to sort</span></h2><div class="table"><table class="sortable"><thead><tr>'
        . '<th data-t="s">Page</th><th data-t="n" class="num">Followers</th><th data-t="n" class="num">7 days</th><th data-t="n" class="num">30 days</th>'
        . '<th data-t="n" class="num">Likes</th><th data-t="n">Last scanned</th></tr></thead><tbody>';
    usort($pages, fn($a, $b) => [$a['kind'] !== 'own', -(int)$a['followers']] <=> [$b['kind'] !== 'own', -(int)$b['followers']]);
    foreach ($pages as $pg) {
        $d7 = member_delta($pg['followers_series'], 7);
        $d30 = member_delta($pg['followers_series'], STATS_DAYS - 1);
        $ls = $pg['last_scan'] ? strtotime($pg['last_scan'] . ' UTC') : 0;
        $out .= '<tr><td data-v="' . h(mb_strtolower($pg['name'])) . '"><a href="' . $base . '/page/' . rawurlencode($pg['id']) . '">' . h($pg['name']) . '</a> '
            . ($pg['kind'] === 'own' ? '<span class="tag ok">yours</span>' : '<span class="tag">followed</span>') . '</td>'
            . '<td class="num" data-v="' . (int)$pg['followers'] . '">' . fmt_n($pg['followers']) . '</td>'
            . '<td class="num" data-v="' . (int)$d7 . '">' . fmt_delta($d7) . '</td>'
            . '<td class="num" data-v="' . (int)$d30 . '">' . fmt_delta($d30) . '</td>'
            . '<td class="num" data-v="' . (int)$pg['likes'] . '">' . fmt_n($pg['likes']) . '</td>'
            . '<td class="muted" data-v="' . $ls . '">' . h($pg['last_scan'] ? ago($pg['last_scan']) : 'not yet') . '</td></tr>';
    }
    return $out . '</tbody></table></div></section>';
}

function view_stats_page(string $id, ?array $data): void
{
    $data ??= stats_real();
    $base = stats_base();
    $pg = null;
    foreach ($data['pages'] as $row) if ($row['id'] === $id) $pg = $row;
    if (!$pg) {
        http_response_code(404);
        stats_layout('Not found', $data, '<div class="empty">That page is not in your list. <a href="' . ($base ?: '/') . '">Back</a></div>');
        return;
    }
    $back = $data['demo'] && is_admin() ? $base . '/demo' : ($base ?: '/');
    $out = '<p><a href="' . $back . '">← All groups and pages</a></p>'
        . '<div class="head"><h1>' . h($pg['name']) . '</h1>' . ($pg['kind'] === 'own' ? '<span class="tag ok">yours</span>' : '<span class="tag">followed</span>')
        . ($pg['url'] ? ' <a class="btn small" href="' . h($pg['url']) . '" target="_blank" rel="noopener">Open on Facebook</a>' : '') . '</div>';
    $out .= '<div class="tiles">'
        . tile('Followers', fmt_n($pg['followers']), fmt_delta(member_delta($pg['followers_series'], STATS_DAYS - 1)) . ' in 30 days')
        . tile('This week', fmt_delta(member_delta($pg['followers_series'], 7)), 'new followers')
        . tile('Likes', fmt_n($pg['likes']), fmt_delta(member_delta($pg['likes_series'], STATS_DAYS - 1)) . ' in 30 days')
        . tile('Last scanned', h($pg['last_scan'] ? ago($pg['last_scan']) : 'not yet'), 'by the Chrome plugin')
        . '</div>';
    $out .= '<div class="grid2"><section class="card"><h2>Followers</h2>' . svg_line($pg['followers_series'], 'Followers over time') . '</section>'
        . '<section class="card"><h2>Likes</h2>' . svg_line($pg['likes_series'], 'Likes over time') . '</section></div>';
    if ($pg['kind'] === 'own') $out .= '<div class="card note">Reach, impressions and post engagement for pages you run need Facebook\'s page insights, which only work with your own Facebook app connected on the posts dashboard.</div>';
    stats_layout($pg['name'], $data, $out);
}

/**
 * The Chrome plugin is the only way to read groups (Facebook has no groups API since 2024).
 * stats.js shows #plugin-missing when the plugin's bridge.js is not on the page, otherwise #plugin-ok.
 */
function plugin_card(bool $noGroups): string
{
    return '<section class="card plugin" id="plugin" data-need="' . STATS_PLUGIN_VERSION . '"' . ($noGroups ? ' data-nogroups="1"' : '') . '>'
        . '<div id="plugin-missing"' . ($noGroups ? '' : ' hidden') . '><div class="plugin-grid"><div>'
        . '<h1>Install the Chrome plugin</h1>'
        . '<p>Facebook does not let any website read groups, not even with Login with Facebook. A small Chrome plugin reads your groups and pages while you are logged in to Facebook and sends the numbers here. It only reads: it never posts, likes or comments.</p>'
        . (($store = conf('CHROME_STORE_URL')) !== ''
            ? '<p><a class="btn primary big" href="' . h($store) . '" target="_blank" rel="noopener">Add to Chrome</a></p>'
              . '<ol class="steps"><li>Click <b>Add to Chrome</b>, then <b>Add extension</b>.</li><li>Come back to this page. The plugin connects to your account by itself.</li>'
              . '<li>Press <b>Scan now</b>. It finds all your groups and pages.</li></ol>'
              . '<details><summary class="muted">Install without the Chrome Web Store</summary>' . plugin_manual_steps() . '</details>'
            : plugin_manual_steps())
        . '<p class="muted">After the first scan it updates by itself once a day while Chrome is open.</p></div>'
        . '<video class="howto" src="/assets/howto.mp4?v=2" poster="/assets/howto.jpg?v=2" controls playsinline preload="none"></video></div></div>'
        . '<div id="plugin-old" hidden><b>Your plugin is out of date.</b> <a href="/extension.zip">Download the new version</a>, remove the old one in <code>chrome://extensions</code> and load the new folder.</div>'
        . '<div id="plugin-ok" hidden><div class="plugin-row"><span class="tag ok">Plugin installed</span> <span id="plugin-status" class="muted"></span>'
        . '<span class="grow"></span><button id="btn-scan" type="button" class="primary">Scan now</button></div></div>'
        . '</section>';
}

function plugin_manual_steps(): string
{
    return '<ol class="steps">'
        . '<li><a class="btn primary" href="/extension.zip">Download the plugin</a> and unzip it.</li>'
        . '<li>Open <code>chrome://extensions</code>, turn on <b>Developer mode</b> (top right).</li>'
        . '<li>Click <b>Load unpacked</b> and pick the unzipped folder.</li>'
        . '<li>Come back to this page and press <b>Scan now</b>. It finds all your groups and pages by itself.</li></ol>';
}

function authors_card(array $authors, string $title, bool $plus = false): string
{
    $out = '<section class="card"><h2>' . h($title) . '</h2>';
    if (!$authors) return $out . '<div class="empty small">No posts in the last ' . STATS_DAYS . ' days.</div></section>';
    $max = max(1, (int)$authors[0]['n']);
    $out .= '<ol class="hbars">';
    foreach ($authors as $a) {
        $out .= '<li><span class="hl">' . h($a['author']) . (isset($a['g']) && $a['g'] > 1 ? ' <span class="muted">in ' . (int)$a['g'] . ' groups</span>' : '') . '</span>'
            . '<span class="hb"><i style="width:' . round(100 * $a['n'] / $max) . '%"></i></span><span class="hn">' . ($plus ? '+' . fmt_n((int)$a['n']) : (int)$a['n']) . '</span></li>';
    }
    return $out . '</ol></section>';
}

function categories_card(array $cats): string
{
    $out = '<section class="card"><h2>What people post about</h2><p class="muted">AI review of each post (needs the Claude key on the posts dashboard).</p>';
    if (!$cats) return $out . '<div class="empty small">Nothing reviewed yet.</div></section>';
    $total = max(1, array_sum($cats));
    $out .= '<ol class="hbars">';
    foreach ($cats as $c => $n) {
        $out .= '<li><span class="hl">' . h(ucfirst(str_replace('_', ' ', (string)$c))) . '</span><span class="hb"><i style="width:' . round(100 * $n / max($cats)) . '%"></i></span>'
            . '<span class="hn">' . round(100 * $n / $total) . '%</span></li>';
    }
    return $out . '</ol></section>';
}

function view_stats_group(string $id, ?array $data): void
{
    $data ??= stats_real();
    $g = null;
    foreach ($data['groups'] as $row) if ($row['id'] === $id) $g = $row;
    $base = stats_base();
    if (!$g) {
        http_response_code(404);
        stats_layout('Not found', $data, '<div class="empty">That group is not in your list. <a href="' . ($base ?: '/') . '">Back</a></div>');
        return;
    }
    if ($data['demo']) {
        mt_srand(crc32($id));
        $authors = array_map(fn($a) => ['author' => $a['author'], 'n' => max(1, intdiv($a['n'], mt_rand(2, 4)))], array_slice($data['authors'], 0, 8));
        usort($authors, fn($a, $b) => $b['n'] <=> $a['n']);
        $posts = $data['demo_posts'];
        mt_srand();
    } else {
        [$posts, $authors] = stats_group_posts($id);
    }
    $p30 = array_sum($g['posts_series']);
    $back = $data['demo'] && is_admin() ? $base . '/demo' : ($base ?: '/');
    $out = '<p><a href="' . $back . '">← All groups</a></p>'
        . '<div class="head"><h1>' . h($g['name']) . '</h1>' . ($g['privacy'] ? '<span class="tag">' . h($g['privacy']) . '</span>' : '')
        . ($g['url'] ? ' <a class="btn small" href="' . h($g['url']) . '" target="_blank" rel="noopener">Open on Facebook</a>' : '') . '</div>';
    $out .= '<div class="tiles">'
        . tile('Members', fmt_n($g['members']), fmt_delta(member_delta($g['members_series'], STATS_DAYS - 1)) . ' in 30 days')
        . tile('Posts this week', (string)$g['posts7'], $g['prev7'] ? fmt_delta($g['posts7'] - $g['prev7']) . ' vs last week' : 'last 7 days')
        . tile('Posts, 30 days', (string)$p30, round($p30 / STATS_DAYS, 1) . ' per day')
        . tile('Different posters', (string)$g['authors30'], 'last 30 days')
        . tile('Leads found', (string)$g['leads30'], $g['spam30'] . ' spam')
        . '</div>';
    $out .= '<div class="grid2"><section class="card"><h2>Posts per day</h2>' . svg_bars($g['posts_series'], 'Posts per day') . '</section>'
        . '<section class="card"><h2>Members</h2>' . svg_line($g['members_series'], 'Members over time') . '</section></div>';
    $out .= '<div class="grid2">' . authors_card($authors, 'Top posters, 30 days') . '<section class="card"><h2>Latest posts</h2>';
    if (!$posts) $out .= '<div class="empty small">No posts captured yet.</div>';
    foreach ($posts as $p) {
        $txt = mb_strimwidth(preg_replace('/\s+/', ' ', (string)$p['text']), 0, 180, '…');
        $out .= '<div class="lp"><div class="muted">' . h($p['author'] ?: 'Someone') . ' · ' . h(ago($p['captured_at']))
            . ($p['ai_category'] ? ' · <span class="tag">' . h($p['ai_category']) . '</span>' : '') . '</div>'
            . ($p['url'] ? '<a href="' . h($p['url']) . '" target="_blank" rel="noopener">' . h($txt) . '</a>' : '<div>' . h($txt) . '</div>') . '</div>';
    }
    $out .= '</section></div>';
    stats_layout($g['name'], $data, $out);
}
