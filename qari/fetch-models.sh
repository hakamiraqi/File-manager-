#!/bin/bash
# Downloads the two 8-bit Qari models (about 7.5 GB) into /opt/qari/models, once.
set -euo pipefail
dir=/opt/qari/models
mkdir -p "$dir"
get() { # url name
  if [ -s "$dir/$2" ]; then echo "have $2"; return; fi
  curl -fL --retry 5 --retry-delay 10 -C - -o "$dir/$2.part" "$1"
  mv "$dir/$2.part" "$dir/$2"
  echo "got $2 ($(du -h "$dir/$2" | cut -f1))"
}
hf=https://huggingface.co
get "$hf/mradermacher/Qari-OCR-0.2.2.1-VL-2B-Instruct-merged-GGUF/resolve/main/Qari-OCR-0.2.2.1-VL-2B-Instruct-merged.Q8_0.gguf" qari-2b.Q8_0.gguf
get "$hf/mradermacher/Qari-OCR-0.2.2.1-VL-2B-Instruct-merged-GGUF/resolve/main/Qari-OCR-0.2.2.1-VL-2B-Instruct-merged.mmproj-Q8_0.gguf" qari-2b.mmproj-Q8_0.gguf
get "$hf/marwan-osama/Qari-OCR-0.4.0-VL-4B-Instruct-GGUF/resolve/main/Qwen3-VL-4B-Instruct.Q8_0.gguf" qari-4b.Q8_0.gguf
get "$hf/marwan-osama/Qari-OCR-0.4.0-VL-4B-Instruct-GGUF/resolve/main/Qwen3-VL-4B-Instruct.F16-mmproj.gguf" qari-4b.mmproj-F16.gguf
ls -la "$dir"
