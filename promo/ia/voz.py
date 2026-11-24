"""Gera as falas do dono (voz neural pt-BR, Piper "faber" via sherpa-onnx) e mede cada uma.
  python3 voz.py <pasta-do-modelo vits-piper-pt_BR-faber-medium> <pasta-de-saida>
Escreve voz.json ao lado do ia.html com a duração de cada fala."""
import json, sys, wave, os
import numpy as np

modelo, saida = sys.argv[1], sys.argv[2]
os.makedirs(saida, exist_ok=True)
# texto falado (a pronúncia é ajustada); o texto da tela fica no ia.html
FALAS = [
    "Abre uma O S para a Transportes Alvorada. Scania vermelha, placa Q W E quatro F cinco cinco.",
    "O motorista disse que a embreagem está patinando. Monta o diagnóstico.",
    "Gostei. Monta o orçamento: embreagem, kit de filtros e mão de obra.",
    "Manda no uótsapi do cliente.",
    "Agora mostra pro cliente onde o caminhão está.",
    "Como foi o mês?",
]
from piper import PiperVoice, SynthesisConfig
voz = PiperVoice.load(f"{modelo}/pt_BR-faber-medium.onnx", config_path=f"{modelo}/pt_BR-faber-medium.onnx.json")
info = []
for i, texto in enumerate(FALAS):
    bruto = f"{saida}/bruto{i}.wav"
    with wave.open(bruto, "wb") as w:
        voz.synthesize_wav(texto, w, syn_config=SynthesisConfig(length_scale=0.93, noise_scale=0.7, noise_w_scale=0.8))
    with wave.open(bruto) as w:
        sr = w.getframerate(); x = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768
    nz = np.where(np.abs(x) > 0.012)[0]
    x = x[max(0, nz[0] - int(.03 * sr)): nz[-1] + int(.12 * sr)]
    x = x / max(1e-6, np.abs(x).max()) * 0.9
    arq = f"{saida}/fala{i}.wav"
    with wave.open(arq, "wb") as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(sr); w.writeframes((x * 32767).astype(np.int16).tobytes())
    info.append({"arquivo": arq, "dur": round(len(x) / sr, 3), "sr": sr})
    print(i, round(len(x) / sr, 2), "s", texto[:50])
json.dump(info, open(os.path.join(os.path.dirname(__file__), "voz.json"), "w"), indent=1)
