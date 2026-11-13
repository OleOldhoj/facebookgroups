const $ = (id) => document.getElementById(id);
const send = (type) => chrome.runtime.sendMessage({ type });

async function refresh() {
  const s = await send('status');
  const t = s.tour;
  $('start').hidden = !!(t && t.running);
  $('stop').hidden = !(t && t.running);
  let txt = 'Ready.';
  if (t && t.running) txt = `Scanning group ${t.done + 1} of ${t.total} (${t.newPosts} new posts so far)`;
  else if (t && t.last) txt = t.last;
  else if (s.lastCapture) txt = 'Last capture ' + new Date(s.lastCapture).toLocaleTimeString();
  $('status').textContent = txt;
}

$('start').onclick = async () => {
  $('status').textContent = 'Starting...';
  const r = await send('start');
  if (r && r.error) $('status').textContent = r.error;
  refresh();
};
$('stop').onclick = async () => { await send('stop'); refresh(); };
$('open').onclick = async () => {
  const s = await chrome.storage.local.get('server');
  chrome.tabs.create({ url: s.server || FBG_CONFIG.server });
};
$('save').onclick = async () => {
  await chrome.storage.local.set({ server: $('server').value.trim(), token: $('token').value.trim() });
  $('status').textContent = 'Saved.';
};

chrome.storage.local.get(['server', 'token']).then((s) => {
  $('server').value = s.server || FBG_CONFIG.server;
  $('token').value = s.token || FBG_CONFIG.token;
});
refresh();
setInterval(refresh, 2000);

chrome.storage.local.get('autoScan').then((s) => { $('auto').checked = s.autoScan !== false; });
$('auto').onchange = () => chrome.storage.local.set({ autoScan: $('auto').checked });
