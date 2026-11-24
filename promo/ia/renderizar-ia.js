// Gera os quadros do vídeo "Volmaster IA" e o events.json (para a trilha).
//   node renderizar-ia.js <pasta-de-saida-dos-quadros>
const { chromium } = require('playwright'); const fs = require('fs'), path = require('path');
const ROOT = __dirname, OUT = process.argv[2] || path.join(ROOT, 'quadros');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.jpg': 'image/jpeg', '.png': 'image/png' };
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1080, height: 1920 } });
  await p.route('http://ia.local/**', r => { const f = path.join(ROOT, decodeURIComponent(new URL(r.request().url()).pathname));
    if (!fs.existsSync(f)) return r.fulfill({ status: 404, body: '' }); r.fulfill({ status: 200, contentType: MIME[path.extname(f)] || 'text/plain', body: fs.readFileSync(f) }); });
  await p.goto('http://ia.local/ia.html'); await p.evaluate(() => document.fonts.ready); await p.evaluate(() => window.carregar); await p.waitForTimeout(800);
  fs.writeFileSync(path.join(ROOT, 'events.json'), JSON.stringify(await p.evaluate(() => ({ dur: window.DURATION, eventos: window.EVENTS, voz: window.VOZ })), null, 1));
  const FPS = 30, N = Math.round(await p.evaluate(() => window.DURATION) * FPS);
  for (let i = 0; i < N; i++) { await p.evaluate(t => window.renderT(t), i / FPS); await p.screenshot({ path: path.join(OUT, `f${String(i).padStart(4, '0')}.jpg`), type: 'jpeg', quality: 92 }); }
  await b.close(); console.log('ok', N);
})();
