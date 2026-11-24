#!/bin/sh
# Prepara um clipe de video real para uma cena do video.
#   ./preparar-clipes.sh clipe-do-mecanico.mp4 6    (cena "No celular")
#   ./preparar-clipes.sh clipe-do-dono.mp4 7        (cena "No computador")
# Usa os primeiros 5 segundos do clipe, a 30 quadros por segundo.
set -e
[ -f "$1" ] && [ -n "$2" ] || { echo "uso: $0 clipe.mp4 6|7"; exit 1; }
cd "$(dirname "$0")"
rm -rf "fotos/seq-$2" && mkdir -p "fotos/seq-$2"
ffmpeg -v error -y -i "$1" -t 5 -vf "fps=30,scale=1200:-2" -q:v 3 "fotos/seq-$2/%04d.jpg"
echo "ok: $(ls fotos/seq-$2 | wc -l) quadros em fotos/seq-$2"
