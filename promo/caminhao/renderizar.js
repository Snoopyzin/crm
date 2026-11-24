// Renderiza a cena 3D do caminhão em loop e gera GIF + MP4.
//   node renderizar.js "#D1262C" vermelho            -> saida/caminhao-vermelho.gif/.mp4
// Requer: npm i playwright (Chromium) e ffmpeg.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs'), path = require('path');
const ROOT = __dirname;
const cor = process.argv[2] || '#D1262C', nome = process.argv[3] || 'vermelho';
const cena = process.argv[4] || 'cena3d';              // cena3d (estrada, loop) | rua3d (rua até a oficina)
const modo = process.argv[5] || 'h';                    // h = 16:9 | v = vertical 9:16
const W = modo === 'v' ? 1080 : 1280, H = modo === 'v' ? 1920 : 720, FPS = 25;
const LOOP = cena === 'rua3d' ? 10 : 4;                 // duração em segundos
const MIME = { '.html': 'text/html', '.js': 'text/javascript' };
(async () => {
  const out = path.join(ROOT, 'saida'); fs.mkdirSync(out, { recursive: true });
  const tmp = fs.mkdtempSync(path.join(require('os').tmpdir(), 'cam-'));
  const b = await chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
  const p = await b.newPage({ viewport: { width: W, height: H } });
  await p.route('http://cena.local/**', r => {            // serve a pasta sem abrir servidor
    const f = path.join(ROOT, decodeURIComponent(new URL(r.request().url()).pathname));
    if (!f.startsWith(ROOT) || !fs.existsSync(f)) return r.fulfill({ status: 404, body: '' });
    r.fulfill({ status: 200, contentType: MIME[path.extname(f)] || 'text/plain', body: fs.readFileSync(f) });
  });
  await p.goto(`http://cena.local/${cena}.html?cor=${encodeURIComponent(cor)}&w=${W}&h=${H}&modo=${modo}`);
  await p.waitForFunction(() => window.ready === true, null, { timeout: 120000 });
  const N = FPS * LOOP;
  for (let i = 0; i < N; i++) {
    await p.evaluate(t => window.renderT(t), i / FPS);
    await p.screenshot({ path: path.join(tmp, `f${String(i).padStart(4, '0')}.png`) });
  }
  await b.close();
  const grade = 'vignette=PI/5.5,eq=contrast=1.06:saturation=1.08';
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-framerate', String(FPS), '-i', path.join(tmp, 'f%04d.png'),
    '-vf', `${grade},format=yuv420p`, '-c:v', 'libx264', '-crf', '17', '-movflags', '+faststart', path.join(out, `${cena === 'rua3d' ? 'rua' : 'caminhao'}-${nome}${modo === 'v' ? '-vertical' : ''}.mp4`)]);
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-framerate', String(FPS), '-i', path.join(tmp, 'f%04d.png'),
    '-vf', `${grade},scale=720:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=220:stats_mode=full[p];[b][p]paletteuse=dither=sierra2_4a`,
    '-loop', '0', path.join(out, `${cena === 'rua3d' ? 'rua' : 'caminhao'}-${nome}${modo === 'v' ? '-vertical' : ''}.gif`)]);
  fs.rmSync(tmp, { recursive: true, force: true });
  console.log('ok', nome);
})();
