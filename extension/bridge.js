// Runs only on facebookstats.fullstackpartner.com: tells the page the plugin is installed,
// lets the page's "Scan now" button start a scan, and takes the owner's key from the page when they are logged in
// (so a plugin installed from the Chrome Web Store connects by itself). Nothing else.
(() => {
  const ver = chrome.runtime.getManifest().version;
  document.documentElement.dataset.fbgExt = ver;
  const post = (data) => window.postMessage({ from: 'fbg-ext', ...data }, location.origin);
  const status = () => chrome.runtime.sendMessage({ type: 'status' }).then((s) => post({ type: 'status', status: s })).catch(() => {});
  window.addEventListener('message', (e) => {
    if (e.source !== window || e.origin !== location.origin || !e.data || e.data.from !== 'fbg-page') return;
    if (e.data.type === 'scan') chrome.runtime.sendMessage({ type: 'start' }).then((r) => post({ type: 'started', error: r && r.error })).then(status);
    if (e.data.type === 'status') status();
    if (e.data.type === 'pair' && typeof e.data.token === 'string') chrome.runtime.sendMessage({ type: 'pair', token: e.data.token, server: location.origin }).then(status);
  });
  post({ type: 'hello', version: ver });
  status();
  setInterval(status, 3000);
})();
