// Builds /var/lib/fbgroups/video-meta.json (file -> title + description) from the project cards on fullstackpartner.com.
// Run as root after adding projects: node bin/video-meta.cjs
const fs = require('fs');
const html = fs.readFileSync(process.argv[2] || '/var/www/fullstackpartner/en/index.html', 'utf8');
const start = html.indexOf('const S = "https://%s.fullstackpartner.com";');
const end = html.indexOf('\n];', start) + 3;
const P = new Function(html.slice(start, end) + '\nreturn P;')();
const out = {};
for (const p of P) {
  if (!p.video) continue;
  const en = p.en || {};
  const lines = [en.pitch || ''];
  if (en.pts && en.pts.length) lines.push('', ...en.pts.map(s => '• ' + s));
  const links = (p.links || []).map(([label, url]) => url).filter(u => u.startsWith('http'));
  if (links.length) lines.push('', 'Try it: ' + links.join(' '));
  lines.push('', 'Built by fullstackpartner.com, we build apps like this for you: https://fullstackpartner.com');
  out[p.video] = {
    title: (p.name + (en.kind ? ' | ' + en.kind : '')).replace(/—/g, ','),
    description: lines.join('\n').replace(/ ?— ?/g, ', ').trim(),
  };
}
fs.writeFileSync('/var/lib/fbgroups/video-meta.json', JSON.stringify(out, null, 1));
console.log(Object.keys(out).length + ' videos described');
