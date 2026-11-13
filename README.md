# facebookgroups.fullstackpartner.com

Private dashboard: Facebook pages you manage (Graph API, with posting), new posts in your groups
(Chrome extension in `extension/`, since Facebook has no Groups API since 2024), and an AI review of every post (Claude API).

- Code: `/var/www/facebookgroups` (plain PHP, no framework). Data: `/var/lib/fbgroups/app.sqlite`.
- Config: `env.php` (template `env.example.php`). FB app + Claude key can also be set on /settings.
- PHP-FPM pool `fbgroups` (user `fbgroups`), nginx `/etc/nginx/conf.d/facebookgroups.fullstackpartner.com.conf`.
- Cron `/etc/cron.d/fbgroups`: `bin/cron.php` every 5 min runs page moderation (`src/moderation.php`); page sync + AI review every 15 min.
- Moderation (/moderation): reads comments + visitor posts on every page, Claude classifies them in batches of 40 against the owner's rules, insults are hidden with `POST /{id}?is_hidden=true` (needs `pages_manage_engagement` + MODERATE task). Unhide by the owner sets verdict `approved` so the AI never re-hides it. Modes: auto / review / off.
- Extension zip is built on the fly at `/extension.zip` with the server URL and token inside.

## facebookstats.fullstackpartner.com

Same app, chosen by host (`is_stats_host()`), code in `src/stats.php`, also at `/stats` on the groups dashboard.
Visitors see fixed example data; the owner (same URL login) sees their own groups: members (+7d/30d), posts per day,
top posters, AI categories, per-group pages at `/group/<id>`. Extension 1.1 reads "N members" + public/private from the
group header and the server keeps one snapshot per group per day in `group_stats`. Posts are dated by first capture.
Own LE cert (webroot), nginx `conf.d/facebookstats.fullstackpartner.com.conf`.
