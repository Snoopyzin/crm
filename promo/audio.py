"""Trilha e efeitos do vídeo de apresentação do Volmaster CRM.

Tudo é sintetizado aqui (sem samples nem direitos autorais): base musical
(pad + baixo + arpejo + bateria leve) e efeitos sincronizados com as cenas
de video.html. Uso: python3 audio.py saida.wav
"""
import sys
import numpy as np

SR = 44100
DUR = 31.0
N = int(SR * DUR)
rng = np.random.default_rng(7)

L = np.zeros(N, dtype=np.float64)
R = np.zeros(N, dtype=np.float64)


def add(start, sig, gain=1.0, pan=0.0):
    """Soma um sinal mono no estéreo. pan: -1 (esq.) a 1 (dir.)."""
    i = int(start * SR)
    if i >= N or i + len(sig) <= 0:
        return
    a = max(0, -i)
    sig = sig[a:]
    i = max(0, i)
    n = min(len(sig), N - i)
    gl = gain * np.cos((pan + 1) * np.pi / 4)
    gr = gain * np.sin((pan + 1) * np.pi / 4)
    L[i:i + n] += sig[:n] * gl
    R[i:i + n] += sig[:n] * gr


def t_(d):
    return np.arange(int(d * SR)) / SR


def lp(x, a):
    """Passa-baixa de um polo (a perto de 1 = corta mais)."""
    k = (1 - a) * a ** np.arange(min(len(x), 600))
    return np.convolve(x, k)[: len(x)]


def hp(x, a):
    return x - lp(x, a)


def midi(m):
    return 440.0 * 2 ** ((m - 69) / 12)


def pluck(freq, d=0.45, decay=9.0, bright=1.0):
    t = t_(d)
    s = (np.sin(2 * np.pi * freq * t)
         + 0.45 * bright * np.sin(2 * np.pi * 2 * freq * t)
         + 0.2 * bright * np.sin(2 * np.pi * 3 * freq * t))
    env = np.exp(-decay * t) * np.minimum(1, t / 0.004)
    return s * env


def pad(freqs, d, fade=0.8):
    t = t_(d)
    s = np.zeros_like(t)
    for f in freqs:
        for det in (-0.6, 0.0, 0.6):
            ff = f * 2 ** (det / 1200 * 8)
            for k, amp in ((1, 1.0), (2, 0.5), (3, 0.25), (4, 0.12)):
                s += amp * np.sin(2 * np.pi * ff * k * t + rng.uniform(0, 6.28))
    s = lp(s, 0.80)
    env = np.minimum(1, t / fade) * np.minimum(1, (d - t) / fade)
    return s * env / max(1, len(freqs) * 3) * 2.2


def kick():
    t = t_(0.32)
    f = 45 + 95 * np.exp(-t * 28)
    ph = 2 * np.pi * np.cumsum(f) / SR
    return np.sin(ph) * np.exp(-t * 11)


def hat(open_=False):
    t = t_(0.14 if open_ else 0.05)
    n = rng.standard_normal(len(t))
    return hp(n, 0.55) * np.exp(-t * (28 if open_ else 90))


def clap():
    t = t_(0.2)
    n = rng.standard_normal(len(t))
    b = lp(hp(n, 0.7), 0.35)
    e = np.exp(-t * 24) + 0.6 * np.exp(-((t - 0.012) * 90) ** 2)
    return b * e


def whoosh(d=0.8):
    t = t_(d)
    n = rng.standard_normal(len(t))
    bp = lp(n, 0.55) - lp(n, 0.93)
    env = np.sin(np.pi * np.clip(t / d, 0, 1)) ** 2
    return bp * env * 3.0


def riser(d):
    t = t_(d)
    n = rng.standard_normal(len(t))
    x = hp(lp(n, 0.5), 0.8) * (t / d) ** 2.2
    f = 220 * 2 ** (3.2 * t / d)
    tone = np.sin(2 * np.pi * np.cumsum(f) / SR) * (t / d) ** 2 * 0.35
    return (x * 2.0 + tone) * np.minimum(1, (d - t) / 0.03 + 0.0)


def click():
    t = t_(0.06)
    n = rng.standard_normal(len(t)) * np.exp(-t * 260)
    return n * 0.6 + np.sin(2 * np.pi * 1800 * t) * np.exp(-t * 120)


def pop(f0=720):
    t = t_(0.12)
    f = f0 * np.exp(-t * 9)
    return np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 32)


def chime(notes, gap=0.085, decay=5.0):
    out = np.zeros(int(SR * (gap * len(notes) + 1.2)))
    for k, m in enumerate(notes):
        t = t_(1.2)
        f = midi(m)
        s = (np.sin(2 * np.pi * f * t) + 0.3 * np.sin(2 * np.pi * 2 * f * t)) * np.exp(-decay * t)
        i = int(k * gap * SR)
        out[i:i + len(s)] += s[: len(out) - i]
    return out


def ding(f=880, d=0.9):
    t = t_(d)
    return (np.sin(2 * np.pi * f * t) + 0.5 * np.sin(2 * np.pi * f * 1.5 * t)) * np.exp(-6 * t) * np.minimum(1, t / 0.003)


def tick():
    t = t_(0.03)
    return np.sin(2 * np.pi * 2600 * t) * np.exp(-t * 150)


# ---------------- música ----------------
BPM = 108
BEAT = 60 / BPM
BAR = 4 * BEAT
# progressão: C  G  Am  F  (acordes em MIDI)
CHORDS = [
    ([48, 55, 64, 67, 72], 36),   # C
    ([43, 55, 62, 67, 71], 31),   # G
    ([45, 57, 64, 67, 72], 33),   # Am
    ([41, 53, 60, 65, 69], 29),   # F
]
ARP = [
    [0, 2, 3, 2],  # índices dentro das notas altas do acorde
    [0, 2, 3, 4],
]
MUSIC_END = 27.0
t0 = 0.0
bar = 0
while t0 < DUR:
    notes, root = CHORDS[bar % 4]
    last = t0 >= MUSIC_END
    fade_out = t0 >= 28.8
    d = BAR + 0.6
    gain_pad = 0.30 if t0 > 2.6 else 0.34
    if last:
        # resolve em C maior e segura
        add(t0, pad([48, 55, 64, 67, 72], DUR - t0 + 0.2, fade=1.2), 0.34, 0)
        break
    add(t0, pad(notes, d), gain_pad, 0)

    # baixo
    if t0 >= 3.0:
        for beat_pos, ln in ((0, 0.5), (1.5, 0.4), (2.5, 0.4)):
            f = midi(root)
            b = pluck(f, 0.5, 7.0, 0.35)
            add(t0 + beat_pos * BEAT, b, 0.5, 0)
    # arpejo em colcheias
    hi = [n for n in notes if n >= 60] + [notes[2] + 12]
    base = ARP[bar % 2]
    for k in range(8):
        n = hi[base[k % 4] % len(hi)] + (12 if k % 4 == 3 else 0)
        g = 0.20 if t0 > 3.0 else 0.22
        add(t0 + k * BEAT / 2, pluck(midi(n), 0.5, 8.0, 1.0), g, -0.4 if k % 2 == 0 else 0.4)
    # bateria
    if 3.0 <= t0 < MUSIC_END:
        for k in range(4):
            add(t0 + k * BEAT, kick(), 0.85, 0)
            add(t0 + k * BEAT + BEAT / 2, hat(), 0.34, 0.2)
        add(t0 + BEAT, clap(), 0.38, 0)
        add(t0 + 3 * BEAT, clap(), 0.38, 0)
    t0 += BAR
    bar += 1

# ---------------- efeitos sincronizados com as cenas ----------------
for ts in (3.2, 7.0, 11.4, 14.8, 18.0, 22.6):
    add(ts - 0.28, whoosh(0.85), 0.30, 0.0)
add(2.45, riser(0.78), 0.40, 0)
add(26.1, riser(0.9), 0.5, 0)
add(26.95, whoosh(0.9), 0.32, 0)

# cena 3: WhatsApp (clique 10,5s) + "orçamento enviado"
add(10.5, click(), 0.8)
add(10.62, chime([84, 88, 91], 0.09), 0.38)
# cena 4: alerta crítico
add(13.3, ding(932, 0.8), 0.42, 0.1)
add(13.55, ding(932, 0.8), 0.38, 0.1)
# cena 5: números subindo
for k in range(14):
    add(15.45 + k * 0.085, tick(), 0.13 + 0.01 * k, -0.3 + 0.05 * k)
add(16.7, chime([79, 83, 86], 0.07), 0.22)
# cena 6: celular
add(19.8, pop(760), 0.55, -0.3)
add(20.7, pop(820), 0.55, -0.3)
add(21.55, click(), 0.8)
add(21.95, pop(900), 0.6, -0.3)
add(21.98, chime([84, 88, 91, 96], 0.08), 0.4)
# cena 7: computador
add(24.5, pop(700), 0.55, 0.3)
add(25.2, pop(760), 0.55, 0.3)
add(24.9, tick(), 0.0)
# final: impacto do logotipo
t = t_(1.4)
boom = np.sin(2 * np.pi * np.cumsum(55 + 60 * np.exp(-t * 14)) / SR) * np.exp(-3.2 * t)
add(27.0, boom, 0.9, 0)
add(27.0, chime([72, 76, 79, 84], 0.0, 2.2), 0.28, 0)

# ---------------- fechamento ----------------
fade = np.ones(N)
tail = int(2.0 * SR)
fade[-tail:] = np.linspace(1, 0, tail) ** 1.5
head = int(0.15 * SR)
fade[:head] = np.linspace(0, 1, head)
L *= fade
R *= fade

# reverb leve (eco curto) feito à mão
def room(x):
    y = x.copy()
    for d, g in ((0.043, 0.28), (0.071, 0.2), (0.113, 0.14)):
        k = int(d * SR)
        y[k:] += lp(x, 0.6)[:-k] * g
    return y

L, R = room(L), room(R)
mx = max(np.abs(L).max(), np.abs(R).max())
out = np.stack([L, R], axis=1) / mx * 0.9
pcm = (out * 32767).astype(np.int16)

import wave
with wave.open(sys.argv[1], "wb") as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes(pcm.tobytes())
print("ok", sys.argv[1], round(len(pcm) / SR, 2), "s")
