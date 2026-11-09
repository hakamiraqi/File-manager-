import { readFileSync, writeFileSync } from 'fs';
import { readPixels } from './js/omrCore.mjs';
const sheets = JSON.parse(readFileSync('sheets.json'));
const results = [];
for (const s of sheets) {
    const data = new Uint8ClampedArray(readFileSync(s.file));
    let crop = null;
    const t0 = Date.now();
    const r = readPixels({ data, width: s.w, height: s.h }, s.questions, (c) => { crop = c; return s.code; });
    const ms = Date.now() - t0;
    if (crop) writeFileSync(s.file.replace('.raw', '.qr.raw'), Buffer.from(crop.data.buffer)), s.qr = [crop.width, crop.height];
    const row = { case: s.case, ms, error: r.error ?? null, right: 0, flagged: 0, wrongSilent: 0, ambiguousCaught: 0, ambiguousMissed: 0, n: s.questions.length, qr: s.qr };
    if (!r.error) {
        for (const q of s.questions) {
            const truth = s.truth[q.id];
            const got = (r.answers[q.id] ?? []).slice().sort();
            const flagged = r.doubtful.includes(q.id);
            if (truth === null) { flagged ? row.ambiguousCaught++ : row.ambiguousMissed++; continue; }
            const ok = JSON.stringify(got) === JSON.stringify(truth.slice().sort());
            if (ok && !flagged) row.right++;
            else if (flagged) row.flagged++;
            else row.wrongSilent++;
        }
    }
    results.push(row);
}
writeFileSync('results.json', JSON.stringify(results));
const by = {};
for (const r of results) { const b = (by[r.case] ??= { sheets: 0, failed: 0, n: 0, right: 0, flagged: 0, wrong: 0, ambC: 0, ambM: 0, ms: 0 }); b.sheets++; b.ms += r.ms; if (r.error) { b.failed++; continue; } b.n += r.n - r.ambiguousCaught - r.ambiguousMissed; b.right += r.right; b.flagged += r.flagged; b.wrong += r.wrongSilent; b.ambC += r.ambiguousCaught; b.ambM += r.ambiguousMissed; }
console.log('case            sheets fail  questions  right%   flagged%  WRONG%   ambiguous(caught/missed)  ms/sheet');
for (const [k, b] of Object.entries(by)) console.log(k.padEnd(15), String(b.sheets).padStart(6), String(b.failed).padStart(5), String(b.n).padStart(10), (100 * b.right / Math.max(1, b.n)).toFixed(2).padStart(8), (100 * b.flagged / Math.max(1, b.n)).toFixed(2).padStart(9), (100 * b.wrong / Math.max(1, b.n)).toFixed(3).padStart(8), `${b.ambC}/${b.ambM}`.padStart(16), String(Math.round(b.ms / b.sheets)).padStart(10));
