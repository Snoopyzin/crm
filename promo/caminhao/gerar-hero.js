// Gera, para cada cor: vídeo em loop do caminhão na rua (rua-<cor>.mp4 + .jpg de capa)
// e a foto da chegada na oficina (chegou-<cor>.jpg). Saída: volmaster crm/public/img/caminhoes/
//   node gerar-hero.js [cor ...]
const { chromium } = require('playwright'); const { execFileSync } = require('child_process'); const fs = require('fs'), path = require('path'), os = require('os');
const ROOT = __dirname, SAIDA = path.resolve(ROOT, '../../volmaster crm/public/img/caminhoes');
const CORES = { branco: '#F1F3F5', prata: '#C2C9D0', cinza: '#808B96', grafite: '#3B434C', preto: '#1F2329', vermelho: '#D1262C', vinho: '#6E1B2B', laranja: '#F27A1A', amarelo: '#F2BE1A', verde: '#2F9D4F', azul: '#1F6FD6', 'azul-escuro': '#16367F', celeste: '#6DB8EE', marrom: '#7A4A2B', bege: '#D8C6A0' };
const MIME = { '.html': 'text/html', '.js': 'text/javascript' };
const W = 800, H = 450, FPS = 20, T = 6;
(async () => {
  fs.mkdirSync(SAIDA, { recursive: true });
  const lista = process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(CORES);
  const b = await chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
  const abrir = async (pagina, cor) => {
    const p = await b.newPage({ viewport: { width: W, height: H } });
    await p.route('http://cena.local/**', r => { const f = path.join(ROOT, decodeURIComponent(new URL(r.request().url()).pathname));
      if (!f.startsWith(ROOT) || !fs.existsSync(f)) return r.fulfill({ status: 404, body: '' }); r.fulfill({ status: 200, contentType: MIME[path.extname(f)] || 'text/plain', body: fs.readFileSync(f) }); });
    await p.goto(`http://cena.local/${pagina}?cor=${encodeURIComponent(cor)}&w=${W}&h=${H}`);
    await p.waitForFunction(() => window.ready === true, null, { timeout: 120000 });
    return p;
  };
  for (const nome of lista) {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'hero-'));
    let p = await abrir('rua-loop.html', CORES[nome]);
    for (let i = 0; i < FPS * T; i++) { await p.evaluate(t => window.renderT(t), i / FPS); await p.screenshot({ path: path.join(tmp, `f${String(i).padStart(4, '0')}.png`) }); }
    await p.close();
    const grade = 'vignette=PI/6,eq=contrast=1.05:saturation=1.08';
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-framerate', String(FPS), '-i', path.join(tmp, 'f%04d.png'), '-vf', `${grade},format=yuv420p`, '-c:v', 'libx264', '-crf', '26', '-preset', 'slow', '-movflags', '+faststart', '-an', path.join(SAIDA, `rua-${nome}.mp4`)]);
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', path.join(tmp, 'f0000.png'), '-vf', grade, '-q:v', '4', path.join(SAIDA, `rua-${nome}.jpg`)]);
    fs.rmSync(tmp, { recursive: true, force: true });
    p = await abrir('rua3d.html', CORES[nome]);                 // chegada na oficina (caminhão parado, mecânicos acenando)
    await p.evaluate(() => window.renderT(9.6)); const png = path.join(os.tmpdir(), `chegou-${nome}.png`); await p.screenshot({ path: png }); await p.close();
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', png, '-vf', grade, '-q:v', '4', path.join(SAIDA, `chegou-${nome}.jpg`)]);
    console.log('ok', nome, new Date().toISOString()); 
  }
  await b.close();
})();
