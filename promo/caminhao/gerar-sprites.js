// Gera o caminhão 3D com fundo transparente em cada cor (public/img/caminhoes/caminhao-<cor>.png).
//   node gerar-sprites.js [cor ...]     (sem argumentos: todas as cores)
// Depois recorta as bordas transparentes (Pillow) e reduz para 760 px de largura.
const { chromium } = require('playwright'); const { execFileSync } = require('child_process'); const fs = require('fs'), path = require('path');
const ROOT = __dirname, SAIDA = path.resolve(ROOT, '../../volmaster crm/public/img/caminhoes');
const CORES = { branco: '#F1F3F5', prata: '#C2C9D0', cinza: '#808B96', grafite: '#3B434C', preto: '#1F2329', vermelho: '#D1262C', vinho: '#6E1B2B', laranja: '#F27A1A', amarelo: '#F2BE1A', verde: '#2F9D4F', azul: '#1F6FD6', 'azul-escuro': '#16367F', celeste: '#6DB8EE', marrom: '#7A4A2B', bege: '#D8C6A0' };
const MIME = { '.html': 'text/html', '.js': 'text/javascript' };
(async () => {
  fs.mkdirSync(SAIDA, { recursive: true });
  const lista = process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(CORES);
  const b = await chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
  const p = await b.newPage({ viewport: { width: 1400, height: 800 } });
  await p.route('http://cena.local/**', r => { const f = path.join(ROOT, decodeURIComponent(new URL(r.request().url()).pathname));
    if (!f.startsWith(ROOT) || !fs.existsSync(f)) return r.fulfill({ status: 404, body: '' }); r.fulfill({ status: 200, contentType: MIME[path.extname(f)] || 'text/plain', body: fs.readFileSync(f) }); });
  for (const nome of lista) {
    await p.goto(`http://cena.local/sprite3d.html?cor=${encodeURIComponent(CORES[nome])}`);
    await p.waitForFunction(() => window.ready === true, null, { timeout: 90000 });
    const bruto = path.join(SAIDA, `_${nome}.png`);
    await p.screenshot({ path: bruto, omitBackground: true });
    execFileSync('python3', ['-c', `
from PIL import Image
im = Image.open(r"${bruto}").convert("RGBA"); bb = im.getbbox(); im = im.crop(bb)
w = 760; im = im.resize((w, round(im.height * w / im.width)), Image.LANCZOS); im.save(r"${path.join(SAIDA, `caminhao-${nome}.png`)}", optimize=True)`]);
    fs.unlinkSync(bruto); console.log('ok', nome);
  }
  await b.close();
})();
