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
