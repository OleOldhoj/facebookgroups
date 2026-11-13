// Click a column header to sort the groups table (numbers read from data-v).
document.querySelectorAll('table.sortable').forEach((t) => {
  t.querySelectorAll('th[data-t]').forEach((th) => th.addEventListener('click', () => {
    const i = [...th.parentNode.children].indexOf(th);
    const desc = !th.classList.contains('desc');
    t.querySelectorAll('th').forEach((x) => x.classList.remove('asc', 'desc'));
    th.classList.add(desc ? 'desc' : 'asc');
    const num = th.dataset.t === 'n';
    const rows = [...t.tBodies[0].rows];
    rows.sort((a, b) => {
      const x = a.cells[i].dataset.v ?? a.cells[i].innerText, y = b.cells[i].dataset.v ?? b.cells[i].innerText;
      const r = num ? (+x) - (+y) : String(x).localeCompare(String(y));
      return desc ? -r : r;
    });
    rows.forEach((r) => t.tBodies[0].appendChild(r));
  }));
});

// Chrome plugin: bridge.js marks <html data-fbg-ext="version"> and answers window messages.
(() => {
  const card = document.getElementById('plugin');
  if (!card) return;
  const $ = (id) => document.getElementById(id);
  const newer = (a, b) => { const x = a.split('.').map(Number), y = b.split('.').map(Number); for (let i = 0; i < 3; i++) if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0); return false; };
  const send = (type) => window.postMessage({ from: 'fbg-page', type }, location.origin);
  function show() {
    const v = document.documentElement.dataset.fbgExt;
    $('plugin-missing').hidden = !!v;
    $('plugin-ok').hidden = !v;
    $('plugin-old').hidden = !v || !newer(card.dataset.need, v);
    if (!v && !card.dataset.nogroups) $('plugin-missing').hidden = false;
  }
  window.addEventListener('message', (e) => {
    if (e.source !== window || !e.data || e.data.from !== 'fbg-ext') return;
    show();
    if (e.data.type === 'started' && e.data.error) $('plugin-status').textContent = e.data.error;
    if (e.data.type === 'status' && e.data.status) {
      const t = e.data.status.tour, last = e.data.status.lastCapture;
      $('btn-scan').disabled = !!(t && t.running);
      $('plugin-status').textContent = t && t.running ? `Visiting ${t.done + 1} of ${t.total}, ${t.newPosts} new posts so far. Reload the page when it is done.`
        : t && t.last ? t.last : last ? 'Last data sent ' + new Date(last).toLocaleString() : 'No data sent yet.';
    }
  });
  $('btn-scan').onclick = () => { $('plugin-status').textContent = 'Starting...'; send('scan'); };
  setTimeout(show, 600);
})();
