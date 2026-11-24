// Gera a cena 3D do caminhão na rua para as 15 cores.
//
//   Padrão Volmaster (letreiro "OFICINA VOLMASTER TECH"):
//     node gerar-hero.js [cor ...]
//
//   Oficina de um cliente (a foto da fachada vai colada na parede da oficina, o nome no letreiro):
//     node gerar-hero.js --fachada /caminho/foto-fachada.jpg --nome "Auto Center Silva" --saida "../../volmaster crm/public/img/oficinas/<id-da-oficina>" [cor ...]
//
//   Só refazer as fotos da chegada: acrescente --so-chegada
//
// Saída por cor: rua-<cor>.webp (animação em loop) + rua-<cor>.jpg (capa) + chegou-<cor>.jpg (chegada na oficina).
const { chromium } = require('playwright'); const { execFileSync } = require('child_process'); const fs = require('fs'), path = require('path'), os = require('os');
const ROOT = __dirname;
const CORES = { branco: '#F1F3F5', prata: '#C2C9D0', cinza: '#808B96', grafite: '#3B434C', preto: '#1F2329', vermelho: '#D1262C', vinho: '#6E1B2B', laranja: '#F27A1A', amarelo: '#F2BE1A', verde: '#2F9D4F', azul: '#1F6FD6', 'azul-escuro': '#16367F', celeste: '#6DB8EE', marrom: '#7A4A2B', bege: '#D8C6A0' };
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.webp': 'image/webp' };
const W = 800, H = 450, FPS = 20, T = 6;

// argumentos
const args = process.argv.slice(2); const opt = {}; const lista0 = [];
for (let i = 0; i < args.length; i++) { if (args[i].startsWith('--')) { const k = args[i].slice(2); opt[k] = ['so-chegada'].includes(k) ? true : args[++i]; } else lista0.push(args[i]); }
const SAIDA = path.resolve(ROOT, opt.saida || '../../volmaster crm/public/img/caminhoes');
const FACHADA = opt.fachada ? path.resolve(opt.fachada) : null;
const NOME = opt.nome || 'Volmaster Tech';
if (FACHADA && !fs.existsSync(FACHADA)) { console.error('Foto da fachada não encontrada:', FACHADA); process.exit(1); }

(async () => {
  fs.mkdirSync(SAIDA, { recursive: true });
  const lista = lista0.length ? lista0 : Object.keys(CORES);
  const b = await chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
  const abrir = async (pagina, cor) => {
    const p = await b.newPage({ viewport: { width: W, height: H } });
    await p.route('http://cena.local/**', r => {
      const u = decodeURIComponent(new URL(r.request().url()).pathname);
      const f = u === '/_fachada' ? FACHADA : path.join(ROOT, u);
      if (!f || (u !== '/_fachada' && !f.startsWith(ROOT)) || !fs.existsSync(f)) return r.fulfill({ status: 404, body: '' });
      r.fulfill({ status: 200, contentType: MIME[path.extname(f).toLowerCase()] || 'text/plain', body: fs.readFileSync(f) });
    });
    const extra = (FACHADA ? '&fachada=/_fachada' : '') + '&nome=' + encodeURIComponent(NOME);
    await p.goto(`http://cena.local/${pagina}?cor=${encodeURIComponent(cor)}&w=${W}&h=${H}${extra}`);
    await p.waitForFunction(() => window.ready === true, null, { timeout: 120000 });
    return p;
  };
  const grade = 'vignette=PI/6,eq=contrast=1.05:saturation=1.08';
  for (const nome of lista) {
    if (!CORES[nome]) { console.error('Cor desconhecida:', nome); continue; }
    if (!opt['so-chegada']) {
      const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'hero-'));
      const p = await abrir('rua-loop.html', CORES[nome]);
      for (let i = 0; i < FPS * T; i++) { await p.evaluate(t => window.renderT(t), i / FPS); await p.screenshot({ path: path.join(tmp, `f${String(i).padStart(4, '0')}.png`) }); }
      await p.close();
      execFileSync('ffmpeg', ['-v', 'error', '-y', '-framerate', String(FPS), '-i', path.join(tmp, 'f%04d.png'), '-vf', `${grade},scale=640:-2:flags=lanczos`, '-c:v', 'libwebp_anim', '-quality', '60', '-compression_level', '5', '-loop', '0', '-an', path.join(SAIDA, `rua-${nome}.webp`)]);
      execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', path.join(tmp, 'f0000.png'), '-vf', grade, '-q:v', '4', path.join(SAIDA, `rua-${nome}.jpg`)]);
      fs.rmSync(tmp, { recursive: true, force: true });
    }
    const p2 = await abrir('rua3d.html', CORES[nome]);       // chegada: caminhão parado ao lado da fachada, mecânicos acenando
    await p2.evaluate(() => window.renderT(9.6)); const png = path.join(os.tmpdir(), `chegou-${nome}.png`); await p2.screenshot({ path: png }); await p2.close();
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', png, '-vf', grade, '-q:v', '4', path.join(SAIDA, `chegou-${nome}.jpg`)]);
    console.log('ok', nome, new Date().toISOString());
  }
  await b.close();
})();
