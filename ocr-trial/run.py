"""One-off trial (2026-11-06): Tesseract vs Qari-OCR on the user's sample pages, on CPU."""
import glob, os, subprocess, time

import torch
from PIL import Image, ImageOps
from transformers import AutoProcessor, Qwen2VLForConditionalGeneration
from qwen_vl_utils import process_vision_info

torch.set_num_threads(os.cpu_count())
os.makedirs('out', exist_ok=True)
pages = []
for src in sorted(glob.glob('ocr-trial/*.jpg')):
    img = ImageOps.exif_transpose(Image.open(src)).convert('RGB').rotate(-90, expand=True)
    dst = 'out/' + os.path.basename(src).replace('.jpg', '.png')
    img.save(dst)
    pages.append(dst)
    print('page', dst, img.size, flush=True)

print('\n========== Tesseract', subprocess.run(['tesseract', '--version'], capture_output=True, text=True).stdout.splitlines()[0], flush=True)
for p in pages:
    t = time.time()
    r = subprocess.run(['tesseract', p, 'stdout', '-l', 'ara+eng', '--psm', '3'], capture_output=True, text=True)
    print(f'--- {p} ({time.time() - t:.1f}s)\n{r.stdout.strip()}\n', flush=True)

PROMPT = ('Below is the image of one page of a document, as well as some raw textual content that was previously '
          'extracted for it. Just return the plain text representation of this document as if you were reading it '
          'naturally. Do not hallucinate.')
for name in ['NAMAA-Space/Qari-OCR-0.2.2.1-VL-2B-Instruct', 'NAMAA-Space/Qari-OCR-v0.3-VL-2B-Instruct']:
    t = time.time()
    try:
        model = Qwen2VLForConditionalGeneration.from_pretrained(name, torch_dtype=torch.float32, device_map='cpu')
        processor = AutoProcessor.from_pretrained(name, min_pixels=256 * 28 * 28, max_pixels=1280 * 28 * 28)
    except Exception as e:
        print(f'\n========== {name}: load failed: {e}', flush=True)
        continue
    print(f'\n========== {name} (loaded in {time.time() - t:.0f}s)', flush=True)
    for p in pages:
        t = time.time()
        messages = [{'role': 'user', 'content': [{'type': 'image', 'image': f'file://{os.path.abspath(p)}'}, {'type': 'text', 'text': PROMPT}]}]
        text = processor.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
        image_inputs, video_inputs = process_vision_info(messages)
        inputs = processor(text=[text], images=image_inputs, videos=video_inputs, padding=True, return_tensors='pt')
        with torch.no_grad():
            ids = model.generate(**inputs, max_new_tokens=400)
        out = processor.batch_decode([ids[0][inputs.input_ids.shape[1]:]], skip_special_tokens=True)[0]
        print(f'--- {p} ({time.time() - t:.0f}s on {os.cpu_count()} CPU cores)\n{out.strip()}\n', flush=True)
    del model
