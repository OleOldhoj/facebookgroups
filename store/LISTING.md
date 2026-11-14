# Chrome Web Store listing: Group & Page Stats for Facebook

Upload `dist/facebook-stats-plugin-<version>.zip` (build it with `bin/build-plugin.sh`, or take it from the GitHub release).
The zip has no key inside; the plugin connects to the owner's account the first time they open facebookstats.fullstackpartner.com logged in.

## Store listing tab

**Name** (from manifest): Group & Page Stats for Facebook

**Summary** (max 132 chars):
Member growth, followers and new posts for every Facebook group you are in and every page you run or follow, on one dashboard.

**Category:** Productivity (Workflow & Planning) or Social & Communication

**Language:** English

**Description:**
See the numbers for all your Facebook groups and pages in one place.

Facebook has no way for websites to read groups anymore, not even with Login with Facebook. This plugin reads them for you while you are logged in to Facebook, and sends the numbers to your dashboard at facebookstats.fullstackpartner.com.

What you get
- Members of every group you are in, with growth over 7 and 30 days
- Followers and likes of every page you run or follow, with growth
- Posts per day in each group, the most active posters, and what people post about
- Leads and spam flagged by AI (optional)
- A sortable table: find your fastest growing or most active group in one click

How it works
1. Add the plugin to Chrome.
2. Open facebookstats.fullstackpartner.com and log in. The plugin connects by itself.
3. Press Scan now. A small window visits your group and page lists, then each group and page, about one every 20 seconds.
4. After that it scans by itself once a day while Chrome is open. You can turn this off in the plugin.

Read only: the plugin never posts, likes, comments or sends messages. It only reads facebook.com pages you can already see, and sends the numbers only to your own dashboard.

Not made by or affiliated with Meta or Facebook.

**Images** (in this folder):
- Icon: `icon-128.png` (128x128)
- Screenshots (1280x800): `screenshot-1-overview.png`, `screenshot-2-groups.png`, `screenshot-3-pages.png`, `screenshot-4-group.png`
- Small promo tile: `promo-small-440x280.png`
- Video (optional, YouTube link): the walkthrough at https://facebookstats.fullstackpartner.com/assets/howto.mp4 uploaded to YouTube

**Homepage URL:** https://facebookstats.fullstackpartner.com/
**Support URL:** https://github.com/OleOldhoj/facebookgroups/issues

## Privacy practices tab

**Single purpose:** Show statistics (members, followers, posts) for the Facebook groups and pages the user belongs to or follows, on the user's own dashboard.

**Permission justifications:**
- `storage`: remembers the connection key, scan progress and the daily-scan setting.
- `tabs`: opens and moves the one small scan window from group to group and page to page.
- `alarms`: a timer for the once-a-day scan and a watchdog that skips a group that does not load.
- Host `https://www.facebook.com/*`: reads group names, member counts, page follower/like counts and on-screen group posts.
- Host `https://facebookstats.fullstackpartner.com/*` (and the facebookgroups dashboard): sends the numbers to the user's dashboard and shows the Scan now button there.

**Remote code:** No. All code is in the package.

**Data usage** (tick these): Personally identifiable information (names of post authors), Website content (group posts, group and page names and counts).
Certify: not sold to third parties; not used for unrelated purposes; not used for creditworthiness or lending.

**Privacy policy URL:** https://facebookstats.fullstackpartner.com/privacy

## After it is approved
Put the store link in `env.php` as `'CHROME_STORE_URL' => 'https://chromewebstore.google.com/detail/...'` (or set it on /settings).
The stats page then shows one big **Add to Chrome** button instead of the manual steps.
