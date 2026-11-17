#!/bin/bash
# Gloss a video's Chinese subtitles word by word -> <srt name>.gloss.json (load with "+ Gloss").
#   ./run_gloss.sh RmL8mEMebgo          # picks ../youtube/<ID>/<ID>_zh_en_shifted.srt (or _zh_en.srt)
#   ./run_gloss.sh path/to/file.srt     # any Chinese srt
# Needs OPENAI_API_KEY (from ~/.zshrc). Re-run after a failure: it only redoes missing lines.
set -e
cd "$(dirname "$0")"
PY=~/anaconda3/bin/python3   # the python with jieba / pypinyin / opencc

ARG="$1"
if [ -z "$ARG" ]; then echo "usage: $0 <YouTube ID | file.srt>"; exit 1; fi

if [ -f "$ARG" ]; then
  SRT="$ARG"
else
  D="../youtube/$ARG"
  for f in "$D/${ARG}_zh_en_shifted.srt" "$D/${ARG}_zh_en.srt" "$D/${ARG}_zh_shifted.srt" "$D/${ARG}_zh.srt"; do
    [ -f "$f" ] && SRT="$f" && break
  done
  [ -z "$SRT" ] && { echo "No Chinese srt in $D"; exit 1; }
fi

echo "Glossing: $SRT"
"$PY" gloss_srt.py "$SRT"
