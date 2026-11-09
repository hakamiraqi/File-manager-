# Bubble-sheet reader: accuracy check

Synthetic sheets drawn with the printed sheet's geometry, marked like students do (full fills,
pencil, half fills, ticks, two answers, erasure residue) and distorted like scanners and phones
do (rotation, perspective, blur, noise, uneven light, shadows, dark tables, JPEG, low resolution,
97 % printing). The real reader (`resources/js/Components/Exams/omrCore.js`) reads them in Node.

```sh
cd /tmp/omr && python3 /path/to/tests/omr/generate_sheets.py      # needs numpy, opencv-python, pillow
mkdir -p js && cp resources/js/Components/Exams/omrLayout.js js/omrLayout.mjs
sed "s#from './omrLayout'#from './omrLayout.mjs'#" resources/js/Components/Exams/omrCore.js > js/omrCore.mjs
node /path/to/tests/omr/measure.mjs
```

It reports, per case: sheets the reader could not place (no corners), questions read right,
questions flagged for the teacher, and questions read **wrong without a flag** (the number that
matters: it must stay 0).

2026-11-09, 308 sheets / 17,247 questions over two runs: 0 wrong without a flag; 1 sheet refused
(a shadow over a corner); everything doubtful (two answers, ticks, light marks) was flagged.

2026-11-09, after widening the sheet to 4 columns (120 questions): 168 sheets / 12,604 questions,
0 wrong without a flag, no sheet refused.
