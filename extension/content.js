// Runs on facebook.com. Reads group posts on screen, group member counts and page follower counts, and hands them to background.js.
// Read only: never posts, likes or comments. Facebook's markup changes often, so every selector has a fallback.
(() => {
  const RESERVED = new Set(['feed', 'discover', 'joins', 'create', 'notifications', 'search', 'you', 'your_groups', 'learn', 'categories', 'requests']);
  let groups = new Map();
  let posts = [];
  const sent = new Map(); // key -> text length already sent

  const groupFromPath = (path) => {
    const m = path.match(/^\/groups\/([^/?#]+)/);
    return m && !RESERVED.has(m[1]) ? m[1] : null;
  };
  const clean = (s) => (s || '').replace(/ /g, ' ').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();

  function pageGroup() {
    const id = groupFromPath(location.pathname);
    if (!id) return null;
    const h1 = document.querySelector('[role="main"] h1') || document.querySelector('h1');
    const name = (h1 && h1.innerText.trim()) || document.title.replace(/^\(\d+\)\s*/, '').replace(/\s*\|\s*Facebook$/, '');
    return { id, name, ...groupHeader() };
  }

  const headText = () => ((document.querySelector('[role="main"]') || document.body).innerText || '').slice(0, 3000);

  // "12.3K members", "1,2 t. medlemmer", "845 followers": the number in front of one of the words.
  function countBefore(head, words) {
    const m = head.match(new RegExp('(\\d[\\d.,\\s]*)\\s*(k|m|t\\.|tusind|mio\\.?|million(?:er)?)?\\s*(?:' + words + ')(?![\\wæøå])', 'i'));
    if (!m) return null;
    const unit = (m[2] || '').toLowerCase();
    const mult = /^(k|t\.|tusind)$/.test(unit) ? 1e3 : unit ? 1e6 : 1;
    const raw = m[1].replace(/\s/g, '');
    const n = mult > 1 ? parseFloat(raw.replace(',', '.')) * mult : parseInt(raw.replace(/[.,]/g, ''), 10);
    return n > 0 && n < 5e9 ? Math.round(n) : null;
  }

  // "Public group · 12.3K members" / "Privat gruppe · 1,2 t. medlemmer" in the group header.
  function groupHeader() {
    const head = headText();
    const out = {};
    const members = countBefore(head, 'members|medlemmer|medlem');
    if (members) out.members = members;
    const p = head.match(/\b(public|private|offentlig|privat)\s+(?:group|gruppe)/i);
    if (p) out.privacy = /^(public|offentlig)$/i.test(p[1]) ? 'public' : 'private';
    return out;
  }

  function topArticle(el) {
    return el.matches('[role="article"]') ? el : el.querySelector('[role="article"]');
  }

  function postText(el) {
    const msg = el.querySelector('[data-ad-preview="message"], [data-ad-comet-preview="message"]');
    if (msg) return clean(msg.innerText);
    const top = topArticle(el);
    const parts = [];
    for (const d of el.querySelectorAll('div[dir="auto"]')) {
      const art = d.closest('[role="article"]');
      if (art && art !== top) continue;                 // inside a comment
      if (d.parentElement.closest('div[dir="auto"]')) continue; // nested, parent already counted
      const t = clean(d.innerText);
      if (t && !parts.includes(t)) parts.push(t);
    }
    return clean(parts.join('\n'));
  }

  function scanPosts() {
    const here = pageGroup();
    let items = [];
    document.querySelectorAll('[role="feed"]').forEach((f) => items.push(...f.children));
    if (!items.length) {
      document.querySelectorAll('[role="article"]').forEach((a) => {
        if (!a.parentElement.closest('[role="article"]')) items.push(a);
      });
    }
    for (const el of items) {
      if (!here && !location.pathname.startsWith('/groups/')) continue; // only group pages and the groups feed
      const text = postText(el);
      if (text.length < 3) continue;
      let gid = here && here.id, gname = here && here.name, url = '', ext = '';
      for (const a of el.querySelectorAll('a[href*="/groups/"]')) {
        let u;
        try { u = new URL(a.href, location.href); } catch (e) { continue; }
        const m = u.pathname.match(/^\/groups\/([^/]+)\/(?:posts|permalink)\/(\d+)/);
        if (m) { url = `https://www.facebook.com/groups/${m[1]}/posts/${m[2]}/`; ext = m[2]; gid = gid || m[1]; break; }
        const g = groupFromPath(u.pathname);
        if (!gid && g && u.pathname.replace(/\/$/, '') === '/groups/' + g && a.innerText.trim()) { gid = g; gname = a.innerText.trim(); }
      }
      if (!gid) continue;
      const authorEl = el.querySelector('h2 a, h3 a, h4 a, strong a, h2 strong, h3 strong, h4 strong');
      const author = authorEl ? authorEl.innerText.trim().split('\n')[0] : '';
      const key = ext || gid + '|' + text.slice(0, 120);
      if ((sent.get(key) || 0) >= text.length) continue;
      sent.set(key, text.length);
      posts.push({ group_id: gid, group_name: gname || '', ext_id: ext, author, text: text.slice(0, 8000), url });
      if (gname && !groups.has(gid)) groups.set(gid, { id: gid, name: gname });
    }
    if (here) groups.set(here.id, here);
  }

  // facebook.com/groups/joins lists every group you are in.
  // ---- pages ----
  const NOT_PAGES = new Set(['groups', 'pages', 'watch', 'marketplace', 'events', 'gaming', 'friends', 'settings', 'help', 'notifications', 'messages',
    'bookmarks', 'saved', 'memories', 'search', 'stories', 'reel', 'reels', 'photo', 'photo.php', 'photos', 'videos', 'hashtag', 'login', 'privacy',
    'policies', 'ads', 'business', 'fundraisers', 'jobs', 'live', 'news', 'feeds', 'home.php', 'me', 'share', 'sharer', 'story.php', 'permalink.php',
    'l.php', 'checkpoint', 'recover', 'places', 'media', 'latest', 'dialog', 'plugins', 'composer', 'onthisday', 'weather', 'crisisresponse']);

  // Page id from a facebook.com URL: "id:123" for profile.php?id= and /people/Name/123, otherwise the lower-case username.
  function pageFromUrl(u) {
    if (!/(^|\.)facebook\.com$/.test(u.hostname)) return null;
    const segs = u.pathname.split('/').filter(Boolean);
    if (u.pathname === '/profile.php' && /^\d+$/.test(u.searchParams.get('id') || '')) return 'id:' + u.searchParams.get('id');
    if (segs[0] === 'people' && /^\d+$/.test(segs[2] || '')) return 'id:' + segs[2];
    if (segs.length === 1 && /^[\w.\-]{3,80}$/.test(segs[0]) && !NOT_PAGES.has(segs[0].toLowerCase())) return segs[0].toLowerCase();
    return null;
  }
  const pageUrl = (id) => id.startsWith('id:') ? 'https://www.facebook.com/profile.php?id=' + id.slice(3) : 'https://www.facebook.com/' + id;

  function pageHere() {
    const id = pageFromUrl(location);
    if (!id) return null;
    const head = headText();
    const followers = countBefore(head, 'followers|følgere|follower|følger');
    const likes = countBefore(head, 'likes|synes godt om');
    if (!followers && !likes) return null; // a person, or not loaded yet
    const h1 = document.querySelector('[role="main"] h1') || document.querySelector('h1');
    return { id, name: h1 ? h1.innerText.trim().split('\n')[0] : '', url: pageUrl(id), followers, likes };
  }

  // facebook.com/pages/?category=your_pages (pages you run) and ?category=liked (pages you like or follow).
  let pagesFound = new Map();
  function scanPageList() {
    if (!location.pathname.startsWith('/pages')) return;
    const cat = new URLSearchParams(location.search).get('category') || '';
    const kind = /your_pages|managed/.test(cat) ? 'own' : 'liked';
    for (const a of (document.querySelector('[role="main"]') || document).querySelectorAll('a[href]')) {
      let u;
      try { u = new URL(a.href, location.href); } catch (e) { continue; }
      const id = pageFromUrl(u);
      const name = a.innerText.trim().split('\n')[0];
      if (id && name.length > 1 && name.length < 120 && !/^(see all|se alle|like|synes godt om|follow|følg)$/i.test(name)) {
        if (!pagesFound.has(id)) pagesFound.set(id, { id, name, url: pageUrl(id), kind });
      }
    }
  }

  function scanGroupList() {
    if (!location.pathname.startsWith('/groups/joins')) return;
    for (const a of document.querySelectorAll('a[href*="/groups/"]')) {
      let u;
      try { u = new URL(a.href, location.href); } catch (e) { continue; }
      const id = groupFromPath(u.pathname);
      const name = a.innerText.trim().split('\n')[0];
      if (id && u.pathname.replace(/\/$/, '') === '/groups/' + id && name.length > 1 && name.length < 120 && !/^(view group|se gruppe)$/i.test(name)) {
        groups.set(id, { id, name });
      }
    }
  }

  function expandSeeMore() {
    for (const b of document.querySelectorAll('[role="feed"] div[role="button"]')) {
      if (/^(see more|se mere|vis mere)$/i.test(b.innerText.trim()) && !b.dataset.fbgClicked) { b.dataset.fbgClicked = '1'; b.click(); }
    }
  }

  function flush(extra = {}) {
    if (!posts.length && !groups.size && !pagesFound.size && !extra.scanned_group && !extra.page_visit) return Promise.resolve();
    const payload = { groups: [...groups.values()], posts, pages: [...pagesFound.values()], ...extra };
    groups = new Map();
    pagesFound = new Map();
    posts = [];
    return chrome.runtime.sendMessage({ type: 'capture', payload }).catch(() => {});
  }

  const tick = () => { scanGroupList(); scanPageList(); scanPosts(); flush(); };
  let timer;
  new MutationObserver(() => { clearTimeout(timer); timer = setTimeout(tick, 2500); }).observe(document.body, { childList: true, subtree: true });
  setTimeout(tick, 3000);

  // When opened by "Scan all my groups": scroll a few screens, then report back.
  chrome.runtime.sendMessage({ type: 'isScanTab' }).then(async (isScan) => {
    if (isScan !== true) return;
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    await wait(4000);
    // A page: the header is all we need.
    if (!location.pathname.startsWith('/groups') && !location.pathname.startsWith('/pages')) {
      let pg = pageHere();
      if (!pg) { await wait(3000); pg = pageHere(); }
      const id = pageFromUrl(location);
      await flush(id ? { page_visit: pg || { id }, scanned_page: id } : {});
      chrome.runtime.sendMessage({ type: 'scanDone' });
      return;
    }
    const screens = location.pathname.startsWith('/groups/joins') || location.pathname.startsWith('/pages') ? 15 : 8;
    for (let i = 0; i < screens; i++) {
      expandSeeMore();
      window.scrollBy(0, window.innerHeight * 1.5);
      await wait(2200 + Math.random() * 1500);
    }
    expandSeeMore();
    await wait(1500);
    scanGroupList();
    scanPageList();
    scanPosts();
    const here = pageGroup();
    await flush(here ? { scanned_group: here.id } : {});
    chrome.runtime.sendMessage({ type: 'scanDone' });
  }).catch(() => {});
})();
