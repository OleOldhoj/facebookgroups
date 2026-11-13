// Expand clamped post text, copy suggested replies, confirm risky buttons.
document.addEventListener('click', (e) => {
  const t = e.target;
  if (t.classList.contains('clamp')) { t.classList.remove('clamp'); return; }
  if (t.matches('.copy')) {
    navigator.clipboard.writeText(t.dataset.copy).then(() => { t.textContent = 'Copied'; setTimeout(() => (t.textContent = 'Copy reply'), 1500); });
  }
  if (t.dataset.confirm && !confirm(t.dataset.confirm)) e.preventDefault();
});
