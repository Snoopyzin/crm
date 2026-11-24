"""Trilha do video "Volmaster IA": ambiente leve e futurista + sons de interface
sincronizados com events.json (gerado pelo renderizar-ia.js). Sintetizada, sem
direitos autorais. Uso: python3 audio-ia.py events.json saida.wav"""
import json
import sys
import wave
import numpy as np

SR = 44100
EV = json.load(open(sys.argv[1]))
DUR = 43.0
N = int(SR * DUR)
rng = np.random.default_rng(3)
L = np.zeros(N)
R = np.zeros(N)


def add(start, sig, gain=1.0, pan=0.0):
    i = int(start * SR)
    if i >= N or i + len(sig) <= 0:
        return
    sig = sig[max(0, -i):]
    i = max(0, i)
    n = min(len(sig), N - i)
    L[i:i + n] += sig[:n] * gain * np.cos((pan + 1) * np.pi / 4)
    R[i:i + n] += sig[:n] * gain * np.sin((pan + 1) * np.pi / 4)


def t_(d):
    return np.arange(int(d * SR)) / SR


def lp(x, a):
    k = (1 - a) * a ** np.arange(min(len(x), 700))
    return np.convolve(x, k)[: len(x)]


def hp(x, a):
    return x - lp(x, a)


def midi(m):
    return 440.0 * 2 ** ((m - 69) / 12)


def noise(d):
    return rng.standard_normal(int(d * SR))


def bell(f, d=1.6, decay=3.2, bright=0.5):
    t = t_(d)
    s = np.sin(2 * np.pi * f * t) + bright * np.sin(2 * np.pi * f * 2.01 * t) * np.exp(-t * 2) + 0.25 * np.sin(2 * np.pi * f * 3.97 * t) * np.exp(-t * 5)
    return s * np.exp(-decay * t) * np.minimum(1, t / 0.004)


def pad(freqs, d, fade=1.5):
    t = t_(d)
    s = np.zeros_like(t)
    for f in freqs:
        for det in (-7, -2, 3, 8):
            ff = f * 2 ** (det / 1200)
            for k, a in ((1, 1.0), (2, .35), (3, .15)):
                s += a * np.sin(2 * np.pi * ff * k * t + rng.uniform(0, 6.28))
    s = lp(s, 0.88)
    return s * np.minimum(1, t / fade) * np.minimum(1, (d - t) / fade) / (len(freqs) * 3)


def whoosh(d=0.9):
    t = t_(d)
    n = noise(d)
    return (lp(n, 0.5) - lp(n, 0.95)) * np.sin(np.pi * np.clip(t / d, 0, 1)) ** 2 * 3.0


def click():
    t = t_(0.06)
    return noise(0.06) * np.exp(-t * 260) * .6 + np.sin(2 * np.pi * 1800 * t) * np.exp(-t * 120)


def pop(f0=700):
    t = t_(0.14)
    return np.sin(2 * np.pi * np.cumsum(f0 * np.exp(-t * 8)) / SR) * np.exp(-t * 30)


def tick():
    t = t_(0.03)
    return np.sin(2 * np.pi * 2600 * t) * np.exp(-t * 150)


def bubble(f):
    t = t_(0.07)
    return np.sin(2 * np.pi * np.cumsum(f * (1 + .9 * t / 0.07)) / SR) * np.exp(-t * 45)


# ---------------- base ambiente ----------------
CH = [([48, 55, 59, 64, 71], 36), ([45, 52, 57, 64, 69], 33), ([41, 48, 55, 60, 69], 29), ([43, 50, 55, 62, 67], 31)]
t0, i = 0.0, 0
while t0 < 39.4:
    notes, root = CH[i % 4]
    g = 0.34 if t0 > 2 else 0.24
    add(t0, pad(notes, 6.4), g, -0.6)
    add(t0, pad(notes, 6.4), g, 0.6)
    t = t_(6.6)
    sub = np.sin(2 * np.pi * midi(root - 12) * t) * np.minimum(1, t / 1.2) * np.minimum(1, (6.6 - t) / 1.2)
    add(t0, sub, 0.05, 0)
    # brilhos agudos esparsos (sensação de IA)
    scale = [notes[2] + 12, notes[3] + 12, notes[4] + 12, notes[1] + 24]
    for k in range(6):
        add(t0 + 0.6 + k * 0.95, bell(midi(scale[(k * 2 + i) % 4]), 2.2, 2.6, .35), 0.16, rng.uniform(-.8, .8))
    t0 += 6.0
    i += 1
# pulso suave a partir da primeira fala
for k in range(int((39.2 - 2.6) / 1.0)):
    t = t_(0.5)
    kick = np.sin(2 * np.pi * np.cumsum(55 + 40 * np.exp(-t * 20)) / SR) * np.exp(-t * 10)
    add(2.6 + k * 1.0, kick, 0.05, 0)

# ---------------- eventos ----------------
for e in EV:
    k, t = e['k'], e['t']
    if k == 'listen':
        add(t, bell(midi(81), .5, 9, .2), 0.16, 0)
        add(t + .09, bell(midi(88), .6, 8, .2), 0.14, 0)
    elif k == 'word':
        add(t, bubble(rng.choice([520, 610, 700, 800])), 0.07, rng.uniform(-.6, .6))
    elif k == 'chip':
        add(t, bell(midi(79), 1.0, 5, .4), 0.26, -.1)
        add(t + .08, bell(midi(84), 1.2, 4.5, .4), 0.24, .1)
        add(t + .17, bell(midi(88), 1.4, 4, .4), 0.2, 0)
    elif k == 'pop':
        add(t, pop(rng.choice([640, 720, 800])), 0.42, rng.uniform(-.5, .5))
    elif k == 'tick':
        add(t, tick(), 0.12, rng.uniform(-.4, .4))
    elif k == 'success':
        for j, m in enumerate((84, 88, 91, 96)):
            add(t + j * .085, bell(midi(m), 1.3, 4.5, .3), 0.2, -.2 + .13 * j)
    elif k == 'click':
        add(t, click(), 0.7, 0)
    elif k == 'step':
        add(t, pop(560), 0.26, 0)
        add(t + .03, bell(midi(72 + (int(t * 3) % 5) * 2), .7, 7, .3), 0.12, 0.2)
    elif k == 'notify':
        add(t, bell(midi(91), 1.2, 5, .5), 0.3, .1)
        add(t + .2, bell(midi(91), 1.2, 5, .5), 0.26, .1)
    elif k == 'final':
        for j, m in enumerate((72, 76, 79, 84, 88)):
            add(t + j * .06, bell(midi(m), 3.4, 1.4, .5), 0.22, -.5 + .25 * j)
        add(t - .1, whoosh(1.1), 0.4, 0)
        tt = t_(2.6)
        add(t, np.sin(2 * np.pi * np.cumsum(60 + 70 * np.exp(-tt * 12)) / SR) * np.exp(-2.3 * tt), 0.55, 0)

# transições de cena
for ts in (5.8, 26.6, 27.5, 34.2, 39.3):
    add(ts - .25, whoosh(.9), 0.22, 0)

# ---------------- fechamento ----------------
def room(x, wet=.22):
    y = x.copy()
    for d, g in ((.047, .3), (.083, .22), (.131, .15), (.197, .1)):
        k = int(d * SR)
        y[k:] += lp(x, .62)[:-k] * g * wet / .22
    return y


L, R = room(L), room(R)
L = L - lp(L, .9965)
R = R - lp(R, .9965)
fade = np.ones(N)
fade[-int(2.2 * SR):] = np.linspace(1, 0, int(2.2 * SR)) ** 1.5
fade[:int(.2 * SR)] = np.linspace(0, 1, int(.2 * SR))
L *= fade
R *= fade
mx = max(abs(L).max(), abs(R).max())
pcm = (np.stack([L, R], 1) / mx * .9 * 32767).astype(np.int16)
with wave.open(sys.argv[2], 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes(pcm.tobytes())
print('ok', sys.argv[2])
