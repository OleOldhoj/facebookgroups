// Sends captured posts to the dashboard and runs the "Scan all my groups" tour.
importScripts('config.js');

const PAUSE_MIN = 15000, PAUSE_EXTRA = 10000; // 15 to 25 s between groups

async function cfg() {
  const s = await chrome.storage.local.get(['server', 'token']);
  return { server: (s.server || FBG_CONFIG.server).replace(/\/$/, ''), token: s.token || FBG_CONFIG.token };
}

async function api(path, body) {
  const { server, token } = await cfg();
  const res = await fetch(server + path, {
    method: body ? 'POST' : 'GET',
    headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error('Dashboard answered ' + res.status);
  return res.json();
}

async function tour() { return (await chrome.storage.session.get('tour')).tour || null; }
async function saveTour(t) { await chrome.storage.session.set({ tour: t }); }

async function startTour() {
  const { groups } = await api('/api/groups');
  if (!groups.length) throw new Error('No groups yet. Open facebook.com/groups/joins first.');
  const win = await chrome.windows.create({ url: 'about:blank', focused: false, width: 900, height: 900 });
  await saveTour({ running: true, queue: groups.map((g) => g.id), total: groups.length, done: 0, newPosts: 0, windowId: win.id, tabId: win.tabs[0].id, current: null });
  next();
}

async function next() {
  const t = await tour();
  if (!t || !t.running) return;
  const gid = t.queue.shift();
  if (!gid) return stopTour('Done: ' + t.done + ' groups, ' + t.newPosts + ' new posts');
  t.current = gid;
  await saveTour(t);
  try {
    await chrome.tabs.update(t.tabId, { url: 'https://www.facebook.com/groups/' + encodeURIComponent(gid) + '/?sorting_setting=CHRONOLOGICAL' });
  } catch (e) {
    return stopTour('Scan window was closed');
  }
  chrome.alarms.create('watchdog', { delayInMinutes: 1.5 });
}

async function stopTour(msg) {
  const t = await tour();
  chrome.alarms.clear('watchdog');
  if (t && t.windowId) chrome.windows.remove(t.windowId).catch(() => {});
  await saveTour(t ? { ...t, running: false, current: null, last: msg || 'Stopped' } : null);
}

chrome.alarms.onAlarm.addListener(async (a) => {
  if (a.name !== 'watchdog') return;
  const t = await tour();
  if (t && t.running) { t.done++; await saveTour(t); next(); }
});

chrome.windows.onRemoved.addListener(async (id) => {
  const t = await tour();
  if (t && t.running && t.windowId === id) stopTour('Scan window was closed');
});

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  (async () => {
    if (msg.type === 'capture') {
      const r = await api('/api/capture', msg.payload);
      const t = await tour();
      if (t && t.running && sender.tab && sender.tab.id === t.tabId) { t.newPosts += r.new_posts || 0; await saveTour(t); }
      chrome.storage.local.set({ lastCapture: Date.now(), lastResult: r });
      return r;
    }
    if (msg.type === 'isScanTab') {
      const t = await tour();
      return !!(t && t.running && sender.tab && sender.tab.id === t.tabId);
    }
    if (msg.type === 'scanDone') {
      const t = await tour();
      if (!t || !t.running || !sender.tab || sender.tab.id !== t.tabId) return false;
      chrome.alarms.clear('watchdog');
      t.done++;
      await saveTour(t);
      setTimeout(next, PAUSE_MIN + Math.random() * PAUSE_EXTRA);
      return true;
    }
    if (msg.type === 'start') { await startTour(); return true; }
    if (msg.type === 'stop') { await stopTour('Stopped'); return true; }
    if (msg.type === 'status') return { tour: await tour(), ...(await chrome.storage.local.get(['lastCapture', 'lastResult'])) };
  })().then(reply, (e) => reply({ error: e.message }));
  return true;
});
