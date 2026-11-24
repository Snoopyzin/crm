"""Trilha imersiva do video do Volmaster CRM (sintetizada, sem direitos autorais).

Duas versoes:
  python3 audio2.py cinematico saida.wav   -> orquestral/ambiente, grave profundo, reverb de sala
  python3 audio2.py eletronico saida.wav   -> batida moderna com 'pumping', sweeps e arpejo

As duas levam a mesma camada de AMBIENTE e EFEITOS (foley) sincronizada com as
cenas: motor diesel e freio a ar, ferramentas de oficina, teclado e mouse do
escritorio, notificacoes. Tudo em estereo largo com reverb de convolucao.
"""
import sys
import wave
import numpy as np

SR = 44100
DUR = 31.0
N = int(SR * DUR)
rng = np.random.default_rng(11)
STYLE = sys.argv[1]
OUT = sys.argv[2]


# ---------------------------------------------------------------- utilidades
class Bus:
    def __init__(self):
        self.L = np.zeros(N)
        self.R = np.zeros(N)

    def add(self, start, sig, gain=1.0, pan=0.0):
        i = int(start * SR)
        if i >= N or i + len(sig) <= 0:
            return
        a = max(0, -i)
        sig = sig[a:]
        i = max(0, i)
        n = min(len(sig), N - i)
        gl = gain * np.cos((pan + 1) * np.pi / 4)
        gr = gain * np.sin((pan + 1) * np.pi / 4)
        self.L[i:i + n] += sig[:n] * gl
        self.R[i:i + n] += sig[:n] * gr

    def add_moving(self, start, sig, gain, pan0, pan1):
        """Som que atravessa o campo estereo (da esquerda p/ direita ou o inverso)."""
        i = int(start * SR)
        n = min(len(sig), N - i)
        if n <= 0:
            return
        pan = np.linspace(pan0, pan1, n)
        self.L[i:i + n] += sig[:n] * gain * np.cos((pan + 1) * np.pi / 4)
        self.R[i:i + n] += sig[:n] * gain * np.sin((pan + 1) * np.pi / 4)


def t_(d):
    return np.arange(int(d * SR)) / SR


def lp(x, a):
    k = (1 - a) * a ** np.arange(min(len(x), 800))
    return np.convolve(x, k)[: len(x)]


def hp(x, a):
    return x - lp(x, a)


def midi(m):
    return 440.0 * 2 ** ((m - 69) / 12)


def noise(d):
    return rng.standard_normal(int(d * SR))


def saw(f, t, kmax=10):
    s = np.zeros_like(t)
    for k in range(1, kmax + 1):
        if f * k < 9000:
            s += np.sin(2 * np.pi * f * k * t) / k
    return s


def fftconv(x, ir):
    n = len(x) + len(ir)
    m = 1 << (n - 1).bit_length()
    y = np.fft.irfft(np.fft.rfft(x, m) * np.fft.rfft(ir, m), m)
    return y[: len(x)]


def make_ir(seconds, seed):
    r = np.random.default_rng(seed)
    t = t_(seconds)
    ir = r.standard_normal(len(t)) * np.exp(-t * (6.9 / seconds))
    # as altas frequencias morrem antes (sala real)
    ir = lp(ir, 0.72) * 0.6 + lp(ir, 0.93) * 0.9
    ir[: int(0.012 * SR)] *= np.linspace(0, 1, int(0.012 * SR))
    return ir / np.sqrt((ir ** 2).sum())


IR_L = make_ir(2.4, 1)
IR_R = make_ir(2.4, 2)


def with_reverb(bus, wet):
    wl = fftconv(bus.L, IR_L) * wet
    wr = fftconv(bus.R, IR_R) * wet
    bus.L = bus.L + wl
    bus.R = bus.R + wr


# ---------------------------------------------------------------- sons
def kick(soft=False):
    t = t_(0.38)
    f = 42 + (85 if soft else 115) * np.exp(-t * 26)
    return np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * (9 if soft else 11))


def hat(open_=False):
    t = t_(0.2 if open_ else 0.05)
    return hp(noise(len(t) / SR), 0.5) * np.exp(-t * (20 if open_ else 95))


def clap():
    t = t_(0.24)
    b = lp(hp(noise(0.24), 0.7), 0.4)
    return b * (np.exp(-t * 20) + 0.7 * np.exp(-((t - 0.011) * 80) ** 2))


def pluck(f, d=0.6, decay=8.0, bright=1.0):
    t = t_(d)
    s = np.sin(2 * np.pi * f * t) + 0.4 * bright * np.sin(2 * np.pi * 2 * f * t) + 0.18 * bright * np.sin(2 * np.pi * 3 * f * t)
    return s * np.exp(-decay * t) * np.minimum(1, t / 0.004)


def piano(f, d=2.2):
    t = t_(d)
    s = (np.sin(2 * np.pi * f * t) + 0.5 * np.sin(2 * np.pi * 2.001 * f * t) * np.exp(-t * 3)
         + 0.25 * np.sin(2 * np.pi * 3.002 * f * t) * np.exp(-t * 5) + 0.1 * np.sin(2 * np.pi * 4.01 * f * t) * np.exp(-t * 8))
    return s * np.exp(-1.6 * t) * np.minimum(1, t / 0.006)


def pad(freqs, d, fade=1.2, bright=0.85):
    t = t_(d)
    s = np.zeros_like(t)
    for f in freqs:
        for det in (-9, -3, 3, 9):
            ff = f * 2 ** (det / 1200)
            s += saw(ff, t, 8) * 0.5
        s += np.sin(2 * np.pi * f * t) * 0.6
    s = lp(s, bright)
    env = np.minimum(1, t / fade) * np.minimum(1, (d - t) / fade)
    return s * env / (len(freqs) * 2.2)


def whoosh(d=0.9, dark=False):
    t = t_(d)
    n = noise(d)
    bp = lp(n, 0.5) - lp(n, 0.95 if not dark else 0.985)
    env = np.sin(np.pi * np.clip(t / d, 0, 1)) ** 2
    return bp * env * 3.0


def riser(d, f0=180, octaves=3.4):
    t = t_(d)
    x = hp(lp(noise(d), 0.55), 0.8) * (t / d) ** 2.2
    f = f0 * 2 ** (octaves * t / d)
    tone = np.sin(2 * np.pi * np.cumsum(f) / SR) * (t / d) ** 2 * 0.3
    return x * 2.0 + tone


def boom(d=2.0):
    t = t_(d)
    f = 48 + 70 * np.exp(-t * 12)
    return np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-2.4 * t) + 0.15 * lp(noise(d), 0.97) * np.exp(-3 * t)


def chime(notes, gap=0.085, decay=5.0):
    out = np.zeros(int(SR * (gap * len(notes) + 1.4)))
    for k, m in enumerate(notes):
        t = t_(1.4)
        f = midi(m)
        s = (np.sin(2 * np.pi * f * t) + 0.3 * np.sin(2 * np.pi * 2 * f * t)) * np.exp(-decay * t)
        i = int(k * gap * SR)
        out[i:i + len(s)] += s[: len(out) - i]
    return out


# ---------- foley de oficina e escritorio ----------
def diesel(d):
    t = t_(d)
    fire = 0.55 + 0.45 * np.sin(2 * np.pi * 11.5 * t + 0.6 * np.sin(2 * np.pi * 0.7 * t)) ** 2
    base = np.zeros_like(t)
    for k, a in ((1, 1.0), (2, 0.7), (3, 0.5), (4, 0.3), (6, 0.15)):
        base += a * np.sin(2 * np.pi * 36 * k * t + k)
    rumble = lp(noise(d), 0.985) * 5.0
    return (base * fire * 0.8 + rumble) * np.minimum(1, t / 1.2) * np.minimum(1, (d - t) / 0.8)


def air_brake(d=0.9):
    t = t_(d)
    return hp(noise(d), 0.35) * (np.exp(-t * 5.5) * (1 - np.exp(-t * 90)))


def impact_wrench(d=0.7):
    t = t_(d)
    gate = (np.sin(2 * np.pi * 26 * t) > -0.2).astype(float)
    gate = lp(gate, 0.6)
    f = 260 * (1 - 0.18 * (t / d))
    car = saw(260, t, 6) * 0 + np.sin(np.cumsum(2 * np.pi * f / SR))
    car += 0.6 * np.sin(np.cumsum(2 * np.pi * f * 2.01 / SR))
    grit = hp(noise(d), 0.6) * 0.5
    env = np.minimum(1, t / 0.02) * np.minimum(1, (d - t) / 0.12)
    return (car * 0.7 + grit) * gate * env


def clink(f=3100):
    t = t_(0.7)
    s = (np.sin(2 * np.pi * f * t) * 1.0 + np.sin(2 * np.pi * f * 1.62 * t) * 0.6 + np.sin(2 * np.pi * f * 2.31 * t) * 0.35 + np.sin(2 * np.pi * f * 3.7 * t) * 0.2)
    return s * np.exp(-t * 14) * np.minimum(1, t / 0.001) + hp(noise(0.7), 0.5) * np.exp(-t * 180) * 0.4


def hammer():
    t = t_(0.5)
    body = np.sin(2 * np.pi * 480 * t) * np.exp(-t * 22) + 0.6 * np.sin(2 * np.pi * 1210 * t) * np.exp(-t * 28)
    return body + hp(noise(0.5), 0.5) * np.exp(-t * 150) * 0.7


def key():
    t = t_(0.07)
    return lp(noise(0.07), 0.75) * np.exp(-t * 120) * 1.5 + np.sin(2 * np.pi * 170 * t) * np.exp(-t * 70) * 0.7


def mouse():
    out = np.zeros(int(0.2 * SR))
    for off in (0.0, 0.07):
        t = t_(0.025)
        s = (np.sin(2 * np.pi * 1500 * t) + 0.5 * hp(noise(0.025), 0.4)) * np.exp(-t * 200)
        i = int(off * SR)
        out[i:i + len(s)] += s
    return out


def room_shop(d):
    """Ambiente de oficina: zumbido grave + compressor + ruido de fundo."""
    t = t_(d)
    hum = np.sin(2 * np.pi * 60 * t) * 0.3 + np.sin(2 * np.pi * 120 * t) * 0.15
    bed = lp(noise(d), 0.97) * 3.0
    comp = lp(noise(d), 0.9) * (0.5 + 0.5 * np.sin(2 * np.pi * 0.35 * t)) ** 3 * 0.8
    return (hum + bed + comp) * np.minimum(1, t / 0.8) * np.minimum(1, (d - t) / 0.8)


def room_office(d):
    t = t_(d)
    hum = np.sin(2 * np.pi * 120 * t) * 0.07 + np.sin(2 * np.pi * 240 * t) * 0.03
    bed = lp(noise(d), 0.985) * 2.2
    return (hum + bed) * np.minimum(1, t / 0.8) * np.minimum(1, (d - t) / 0.8)


def blip(f):
    t = t_(0.09)
    return np.sin(2 * np.pi * f * t) * np.exp(-t * 38) * np.minimum(1, t / 0.003)


def tick():
    t = t_(0.03)
    return np.sin(2 * np.pi * 2600 * t) * np.exp(-t * 150)


def click():
    t = t_(0.06)
    return noise(0.06) * np.exp(-t * 260) * 0.6 + np.sin(2 * np.pi * 1800 * t) * np.exp(-t * 120)


def pop(f0=720):
    t = t_(0.12)
    return np.sin(2 * np.pi * np.cumsum(f0 * np.exp(-t * 9)) / SR) * np.exp(-t * 32)


def ding(f=880, d=1.0):
    t = t_(d)
    return (np.sin(2 * np.pi * f * t) + 0.5 * np.sin(2 * np.pi * f * 1.5 * t)) * np.exp(-6 * t) * np.minimum(1, t / 0.003)


def rattle(d=0.5):
    t = t_(d)
    return np.sin(2 * np.pi * 900 * t) * (np.sin(2 * np.pi * 38 * t) > 0) * np.exp(-t * 7) * 0.5 + hp(noise(d), 0.6) * np.exp(-t * 9) * 0.25


# ---------------------------------------------------------------- buses
tonal = Bus()   # musica harmonica (recebe 'pumping' no estilo eletronico)
drums = Bus()   # percussao
fol = Bus()     # ambiente e efeitos

# ============ MUSICA ============
if STYLE == "cinematico":
    BPM = 72
    BEAT = 60 / BPM
    BAR = 4 * BEAT
    # Dm  Bb  F  C  ...  final em D maior
    CH = [([50, 57, 62, 65, 69], 38), ([46, 53, 58, 62, 65], 34), ([53, 57, 60, 65, 69], 41), ([48, 55, 60, 64, 67], 36)]
    MEL = [74, 72, 69, 67, 65, 69, 72, 74]  # motivo suave
    t0, bar = 0.0, 0
    while t0 < 27.0:
        notes, root = CH[bar % 4]
        build = min(1.0, 0.45 + t0 / 12)
        tonal.add(t0, pad([n for n in notes if n >= 55] or notes, BAR + 1.6), 0.5 * build, 0)
        tonal.add(t0 + 0.012, pad([n + 12 for n in notes[1:4]], BAR + 1.6, bright=0.9), 0.5 * build, 0)
        # sub grave contínuo
        t = t_(BAR + 0.8)
        sub = np.sin(2 * np.pi * midi(root - 12) * t) * np.minimum(1, t / 0.5) * np.minimum(1, (BAR + 0.8 - t) / 0.6)
        tonal.add(t0, sub, 0.16 * build, 0)
        # piano esparso (motivo) a cada compasso
        for k in (0, 2):
            n = MEL[(bar * 2 + k // 2) % len(MEL)]
            tonal.add(t0 + k * BEAT, piano(midi(n)), 0.55 * build, -0.25 + 0.5 * ((bar + k) % 2))
        tonal.add(t0 + 3 * BEAT, piano(midi(notes[3] + 12), 1.6), 0.35 * build, 0.35)
        # pulso grave (batimento) entra com o corte para a cena 2
        if t0 >= 3.0:
            for k in range(4):
                drums.add(t0 + k * BEAT, kick(soft=True), 0.30 * min(1.0, (t0 - 2.0) / 8), 0)
            drums.add(t0 + BEAT, clap(), 0.10, 0.1)
            drums.add(t0 + 3 * BEAT, clap(), 0.10, -0.1)
            for k in range(8):
                drums.add(t0 + k * BEAT / 2 + BEAT / 4, hat(), 0.05, 0.3 if k % 2 else -0.3)
        t0 += BAR
        bar += 1
    # final: resolve em D maior, longo
    tonal.add(27.0, pad([57, 62, 66, 69, 74], 5.0, fade=0.6), 0.7, 0)
    tonal.add(27.0, np.sin(2 * np.pi * midi(26) * t_(4.6)) * np.exp(-t_(4.6) * 0.5), 0.18, 0)
    for k, n in enumerate((74, 78, 81, 86)):
        tonal.add(27.3 + k * 0.18, piano(midi(n), 3.0), 0.28, -0.4 + 0.27 * k)

else:  # eletronico
    BPM = 120
    BEAT = 60 / BPM
    BAR = 4 * BEAT
    CH = [([57, 60, 64, 67], 33), ([53, 57, 60, 65], 29), ([48, 52, 55, 60], 36), ([55, 59, 62, 67], 31)]  # Am F C G
    kicks = []
    t0, bar = 0.0, 0
    while t0 < 27.0:
        notes, root = CH[bar % 4]
        tonal.add(t0, pad(notes, BAR + 0.5, fade=0.15, bright=0.88), 0.62 if t0 >= 3.0 else 0.4, 0)
        if t0 >= 3.0:
            for k in range(4):
                kicks.append(t0 + k * BEAT)
                drums.add(t0 + k * BEAT, kick(), 0.95, 0)
                drums.add(t0 + k * BEAT + BEAT / 2, hat(True), 0.10, 0.25)
            for k in range(16):
                drums.add(t0 + k * BEAT / 4, hat(), 0.05 + 0.03 * (k % 2), -0.3 if k % 2 else 0.3)
            drums.add(t0 + BEAT, clap(), 0.40, 0)
            drums.add(t0 + 3 * BEAT, clap(), 0.40, 0)
            # baixo sincopado
            for pos in (0, 0.75, 1.5, 2.5, 3.25):
                tonal.add(t0 + pos * BEAT, pluck(midi(root), 0.45, 6.5, 0.3), 0.75, 0)
        # arpejo 16-avos
        hi = [n + 12 for n in notes] + [notes[0] + 24]
        for k in range(16):
            n = hi[(k * 3 + bar) % len(hi)]
            tonal.add(t0 + k * BEAT / 4, pluck(midi(n), 0.3, 11.0, 1.0), 0.17 if t0 >= 3.0 else 0.2, -0.5 + (k % 8) / 8)
        t0 += BAR
        bar += 1
    tonal.add(27.0, pad([57, 60, 64, 69, 72, 76], 5.0, fade=0.5), 0.7, 0)
    for k, n in enumerate((81, 84, 88, 93)):
        tonal.add(27.25 + k * 0.12, pluck(midi(n), 2.0, 3.0), 0.3, -0.4 + 0.27 * k)
    # pumping: o conjunto harmônico "respira" com o bumbo
    duck = np.ones(N)
    for kt in kicks:
        i = int(kt * SR)
        seg = min(int(0.35 * SR), N - i)
        duck[i:i + seg] *= 1 - 0.72 * np.exp(-np.arange(seg) / SR * 9)
    tonal.L *= duck
    tonal.R *= duck

# ============ AMBIENTE E EFEITOS (comuns às duas versões) ============
# abertura: motor diesel + freio a ar
fol.add(0.0, diesel(3.6), 0.14, 0)
fol.add_moving(1.2, whoosh(1.4, dark=True), 0.35, -0.9, 0.9)
fol.add(2.85, air_brake(1.0), 0.40, 0.2)

# transições entre cenas (varrendo o campo estéreo)
for i, ts in enumerate((3.2, 7.0, 11.4, 14.8, 18.0, 22.6)):
    p0, p1 = (-0.9, 0.9) if i % 2 == 0 else (0.9, -0.9)
    fol.add_moving(ts - 0.3, whoosh(0.9), 0.36, p0, p1)
fol.add(2.4, riser(0.8), 0.45, 0)
fol.add(26.1, riser(0.9, 150, 3.6), 0.55, 0)
fol.add_moving(26.9, whoosh(1.0, dark=True), 0.4, -0.9, 0.9)

# cena 2: o caminhão passa de etapa em etapa
for k in range(9):
    fol.add(4.2 + k * 0.27, blip(520 * 1.0595 ** (k * 2)), 0.28, -0.5 + k * 0.12)
fol.add(3.6, hammer(), 0.18, -0.6)

# cena 3: itens do orçamento, WhatsApp e confirmação
for i in range(4):
    t = 8.0 + i * 0.5
    fol.add(t, blip(1568), 0.22, 0.2)
    fol.add(t + 0.05, blip(2093), 0.2, 0.2)
fol.add(10.5, click(), 0.75, 0)
fol.add_moving(10.55, whoosh(0.4), 0.30, -0.2, 0.9)
fol.add(10.7, chime([84, 88, 91], 0.09), 0.34, 0.1)

# cena 4: pátio e alerta crítico
for k in range(3):
    fol.add_moving(11.9 + k * 0.2, whoosh(0.45), 0.2, -0.8, 0.8)
fol.add(13.3, ding(932, 0.9), 0.42, 0.1)
fol.add(13.55, ding(932, 0.9), 0.36, 0.1)
fol.add(13.9, rattle(0.6), 0.40, 0.15)

# cena 5: números subindo
for k in range(14):
    fol.add(15.45 + k * 0.085, tick(), 0.12 + 0.01 * k, -0.4 + 0.06 * k)
fol.add(16.7, chime([79, 83, 86], 0.07), 0.2, 0)

# cena 6: oficina de verdade (ambiente + ferramentas)
fol.add(18.0, room_shop(5.0), 0.28, 0)
fol.add(18.95, impact_wrench(0.55), 0.38, -0.6)
fol.add(19.55, clink(3100), 0.22, -0.4)
fol.add(19.8, pop(760), 0.55, -0.3)
fol.add(20.1, impact_wrench(0.8), 0.40, -0.7)
fol.add(20.7, pop(820), 0.55, -0.3)
fol.add(21.0, hammer(), 0.22, -0.7)
fol.add(21.55, click(), 0.75, 0.3)
fol.add(21.95, pop(900), 0.6, 0.3)
fol.add(21.98, chime([84, 88, 91, 96], 0.08), 0.38, 0.2)

# cena 7: escritório (ambiente calmo, teclado, mouse)
fol.add(22.6, room_office(4.8), 0.30, 0)
for burst, n in ((23.4, 9), (24.05, 7)):
    tt = burst
    for _ in range(n):
        fol.add(tt, key(), 0.30, rng.uniform(-0.3, 0.3))
        tt += rng.uniform(0.06, 0.13)
fol.add(24.3, mouse(), 0.7, 0.4)
fol.add(24.5, pop(700), 0.55, 0.3)
fol.add(25.2, pop(760), 0.55, 0.3)
fol.add(25.7, ding(1175, 0.8), 0.22, 0.4)

# final: impacto do logotipo
fol.add(27.0, boom(2.2), 0.6, 0)
fol.add(27.0, chime([74, 78, 81, 86], 0.0, 2.0), 0.22, 0)
fol.add(28.0, room_office(2.4), 0.25, 0)

# ---------------------------------------------------------------- mix
with_reverb(tonal, 0.38 if STYLE == "cinematico" else 0.20)
with_reverb(drums, 0.10)
with_reverb(fol, 0.30)

g_tonal, g_drums, g_fol = (1.0, 1.0, 1.0) if STYLE == "cinematico" else (0.85, 0.95, 1.0)
L = tonal.L * g_tonal + drums.L * g_drums + fol.L * g_fol
R = tonal.R * g_tonal + drums.R * g_drums + fol.R * g_fol

# alarga a imagem estéreo (mid/side) para dar sensação de espaço
mid = (L + R) / 2
side = (L - R) / 2 * 0.95
L, R = mid + side, mid - side

L = L - lp(L, 0.9965)
R = R - lp(R, 0.9965)
fade = np.ones(N)
tail = int(2.2 * SR)
fade[-tail:] = np.linspace(1, 0, tail) ** 1.5
head = int(0.2 * SR)
fade[:head] = np.linspace(0, 1, head)
L *= fade
R *= fade

mx = max(np.abs(L).max(), np.abs(R).max())
pcm = (np.stack([L, R], axis=1) / mx * 0.9 * 32767).astype(np.int16)
with wave.open(OUT, "wb") as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes(pcm.tobytes())
print("ok", STYLE, OUT, round(len(pcm) / SR, 2), "s")
