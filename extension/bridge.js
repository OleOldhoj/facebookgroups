// Runs only on facebookstats.fullstackpartner.com: tells the page the plugin is installed,
// and lets the page's "Scan now" / "Import my groups" buttons start a scan. Nothing else.
(() => {
  const ver = chrome.runtime.getManifest().version;
  document.documentElement.dataset.fbgExt = ver;
  const post = (data) => window.postMessage({ from: 'fbg-ext', ...data }, location.origin);
  const status = () => chrome.runtime.sendMessage({ type: 'status' }).then((s) => post({ type: 'status', status: s })).catch(() => {});
  window.addEventListener('message', (e) => {
    if (e.source !== window || e.origin !== location.origin || !e.data || e.data.from !== 'fbg-page') return;
    if (e.data.type === 'scan') chrome.runtime.sendMessage({ type: 'start' }).then((r) => post({ type: 'started', error: r && r.error })).then(status);
    if (e.data.type === 'import') chrome.runtime.sendMessage({ type: 'import' }).then(status);
    if (e.data.type === 'status') status();
  });
  post({ type: 'hello', version: ver });
  status();
  setInterval(status, 3000);
})();
