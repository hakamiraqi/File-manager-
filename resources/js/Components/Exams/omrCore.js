// The bubble-sheet reader's core (no browser needed, so it can be measured on test images): finds the four corner squares,
// maps the sheet's millimetres onto the picture, reads the QR (whose sheet) and every bubble.
import { PAGE, MARK, MARKS, slot, BUBBLE_R } from './omrLayout';

export function gray(img) {
    const { data, width, height } = img;
    const g = new Uint8ClampedArray(width * height);
    for (let i = 0, j = 0; i < data.length; i += 4, j++) g[j] = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000;
    return g;
}

export function otsu(g) {
    const hist = new Array(256).fill(0);
    for (const v of g) hist[v]++;
    const total = g.length;
    let sum = 0;
    for (let i = 0; i < 256; i++) sum += i * hist[i];
    let sumB = 0, wB = 0, best = 0, threshold = 128;
    for (let i = 0; i < 256; i++) {
        wB += hist[i];
        if (!wB) continue;
        const wF = total - wB;
        if (!wF) break;
        sumB += i * hist[i];
        const mB = sumB / wB, mF = (sum - sumB) / wF;
        const between = wB * wF * (mB - mF) ** 2;
        if (between > best) { best = between; threshold = i; }
    }
    return threshold;
}

/** The dark blob in a corner region that looks most like the printed square. */
export function findMark(g, w, h, x0, y0, x1, y1, t, expect) {
    const seen = new Uint8Array((x1 - x0) * (y1 - y0));
    const idx = (x, y) => (y - y0) * (x1 - x0) + (x - x0);
    let best = null;
    for (let y = y0; y < y1; y += 2) {
        for (let x = x0; x < x1; x += 2) {
            if (g[y * w + x] > t || seen[idx(x, y)]) continue;
            // Flood fill this dark blob.
            const stack = [[x, y]];
            seen[idx(x, y)] = 1;
            let n = 0, sx = 0, sy = 0, minX = x, maxX = x, minY = y, maxY = y;
            while (stack.length) {
                const [cx, cy] = stack.pop();
                n++; sx += cx; sy += cy;
                if (cx < minX) minX = cx; if (cx > maxX) maxX = cx; if (cy < minY) minY = cy; if (cy > maxY) maxY = cy;
                for (const [nx, ny] of [[cx + 1, cy], [cx - 1, cy], [cx, cy + 1], [cx, cy - 1]]) {
                    if (nx < x0 || ny < y0 || nx >= x1 || ny >= y1) continue;
                    const k = idx(nx, ny);
                    if (seen[k] || g[ny * w + nx] > t) continue;
                    seen[k] = 1;
                    stack.push([nx, ny]);
                }
                if (n > expect * expect * 6) break;
            }
            const bw = maxX - minX + 1, bh = maxY - minY + 1;
            const fill = n / (bw * bh), aspect = bw / bh, size = Math.sqrt(n);
            if (fill < 0.7 || aspect < 0.6 || aspect > 1.6 || size < expect * 0.5 || size > expect * 1.8) continue;
            const score = Math.abs(size - expect) / expect + (1 - fill);
            if (!best || score < best.score) best = { x: sx / n, y: sy / n, score };
        }
    }
    return best;
}

/** The 3×3 perspective map from four point pairs (sheet mm → picture px). */
export function homography(src, dst) {
    const A = [], b = [];
    for (let i = 0; i < 4; i++) {
        const [x, y] = src[i], [u, v] = dst[i];
        A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u);
        A.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v);
    }
    // Gaussian elimination.
    for (let c = 0; c < 8; c++) {
        let p = c;
        for (let r = c + 1; r < 8; r++) if (Math.abs(A[r][c]) > Math.abs(A[p][c])) p = r;
        [A[c], A[p]] = [A[p], A[c]]; [b[c], b[p]] = [b[p], b[c]];
        for (let r = 0; r < 8; r++) {
            if (r === c) continue;
            const f = A[r][c] / A[c][c];
            for (let k = c; k < 8; k++) A[r][k] -= f * A[c][k];
            b[r] -= f * b[c];
        }
    }
    const h = b.map((v, i) => v / A[i][i]);
    return ([x, y]) => {
        const d = h[6] * x + h[7] * y + 1;
        return [(h[0] * x + h[1] * y + h[2]) / d, (h[3] * x + h[4] * y + h[5]) / d];
    };
}

/**
 * Reads one sheet from its pixels ({ data, width, height }, RGBA). `decodeQr(crop, full)` reads a
 * QR from an ImageData-like crop (and, failing that, the whole picture), and returns its text.
 * Returns { code, answers: {qid: [i…]}, doubtful: [qid…] } or { error }.
 */
export function readPixels(img, questions, decodeQr) {
    const { width: w, height: h } = img;
    const g = gray(img);
    const t = Math.min(otsu(g), 150);
    const expect = (MARK / PAGE.w) * w;
    const rw = Math.round(w * 0.2), rh = Math.round(h * 0.15);
    const marks = [
        findMark(g, w, h, 0, 0, rw, rh, t, expect),
        findMark(g, w, h, w - rw, 0, w, rh, t, expect),
        findMark(g, w, h, 0, h - rh, rw, h, t, expect),
        findMark(g, w, h, w - rw, h - rh, w, h, t, expect),
    ];
    if (marks.some((m) => !m)) return { error: 'corners' };
    const map = homography(MARKS, marks.map((m) => [m.x, m.y]));

    // Whose sheet: the QR, read where it was printed.
    const [qx0, qy0] = map([140, 18]), [qx1, qy1] = map([182, 60]);
    const bx = Math.max(0, Math.floor(Math.min(qx0, qx1))), by = Math.max(0, Math.floor(Math.min(qy0, qy1)));
    const bw = Math.min(w - bx, Math.ceil(Math.abs(qx1 - qx0))), bh = Math.min(h - by, Math.ceil(Math.abs(qy1 - qy0)));
    const crop = { data: new Uint8ClampedArray(bw * bh * 4), width: bw, height: bh };
    for (let y = 0; y < bh; y++) crop.data.set(img.data.subarray(((by + y) * w + bx) * 4, ((by + y) * w + bx + bw) * 4), y * bw * 4);
    const code = decodeQr(crop, img);
    if (!code) return { error: 'qr' };

    return { code, ...readBubbles(g, w, h, map, questions) };
}

/**
 * Each bubble's darkness against the paper right around it (so shadows and uneven light do not
 * count as ink), compared with how dark an empty printed bubble is on this sheet.
 */
export function readBubbles(g, w, h, map, questions) {
    const at = (x, y) => {
        const [px, py] = map([x, y]);
        const xi = Math.round(px), yi = Math.round(py);
        return xi < 0 || yi < 0 || xi >= w || yi >= h ? null : g[yi * w + xi];
    };
    const mean = (cx, cy, r0, r1) => {
        let sum = 0, n = 0;
        for (let dy = -r1; dy <= r1; dy += 0.35) {
            for (let dx = -r1; dx <= r1; dx += 0.35) {
                const d2 = dx * dx + dy * dy;
                if (d2 > r1 * r1 || d2 < r0 * r0) continue;
                const v = at(cx + dx, cy + dy);
                if (v !== null) { sum += v; n++; }
            }
        }
        return n ? sum / n : 255;
    };
    // Inside the circle, and the paper in a ring just outside it.
    const ink = (cx, cy) => {
        const inside = mean(cx, cy, 0, BUBBLE_R * 0.72);
        const paper = Math.max(40, mean(cx, cy, BUBBLE_R * 1.3, BUBBLE_R * 1.75));
        return Math.max(0, (paper - inside) / paper);
    };
    const all = questions.map((q, k) => slot(k, q.options).bubbles.map(([x, y]) => ink(x, y)));
    // An empty bubble (its letter and outline): most bubbles are empty, so the lower half's middle.
    const sorted = all.flat().sort((a, b) => a - b);
    const empty = sorted.length ? sorted[Math.floor(sorted.length * 0.3)] : 0.1;
    const FULL = empty + 0.26, LIGHT = empty + 0.12;

    const answers = {}, doubtful = [];
    questions.forEach((q, k) => {
        const d = all[k];
        const filled = d.map((v, i) => (v > FULL ? i : -1)).filter((i) => i >= 0);
        if (q.type !== 'multiple' && filled.length > 1) {
            doubtful.push(q.id);
            answers[q.id] = [d.indexOf(Math.max(...d))];
        } else {
            answers[q.id] = filled;
        }
        // A mark between empty and full (a light pencil, a tick, a half-erased answer) is worth a look.
        if (d.some((v) => v > LIGHT && v <= FULL)) doubtful.push(q.id);
        // Nothing filled, but one bubble clearly darker than the others (a tick, a cross): look too.
        if (!filled.length && d.length > 1 && Math.max(...d) - Math.min(...d) > 0.07 && Math.max(...d) > empty + 0.06) doubtful.push(q.id);
    });

    return { answers, doubtful: [...new Set(doubtful)] };
}
