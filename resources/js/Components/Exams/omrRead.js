// Reads a scanned or photographed bubble sheet in the browser: finds the four corner squares,
// maps the sheet's millimetres onto the picture, reads the QR (whose sheet) and every bubble.
import jsQR from 'jsqr';
import { PAGE, MARK, MARKS, slot, BUBBLE_R } from './omrLayout';

function gray(img) {
    const { data, width, height } = img;
    const g = new Uint8ClampedArray(width * height);
    for (let i = 0, j = 0; i < data.length; i += 4, j++) g[j] = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000;
    return g;
}

function otsu(g) {
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
function findMark(g, w, h, x0, y0, x1, y1, t, expect) {
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
function homography(src, dst) {
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
 * Reads one sheet from an image element. Returns { code, answers: {qid: [i…]}, doubtful: [qid…] }
 * or { error }.
 */
export function readSheet(image, questions) {
    const scale = Math.min(1, 1800 / Math.max(image.naturalWidth, image.naturalHeight));
    const w = Math.round(image.naturalWidth * scale), h = Math.round(image.naturalHeight * scale);
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(image, 0, 0, w, h);
    const img = ctx.getImageData(0, 0, w, h);
    const g = gray(img);
    const t = Math.min(otsu(g), 150);
    // The page in portrait: corner regions of about 17% of each side.
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
    const qrImg = ctx.getImageData(bx, by, bw, bh);
    const qr = jsQR(qrImg.data, bw, bh) || jsQR(img.data, w, h);
    if (!qr) return { error: 'qr' };

    // Each bubble's darkness: the mean over its inner disc.
    const darkness = (cx, cy) => {
        let sum = 0, n = 0;
        const r = BUBBLE_R * 0.75;
        for (let dy = -r; dy <= r; dy += 0.4) {
            for (let dx = -r; dx <= r; dx += 0.4) {
                if (dx * dx + dy * dy > r * r) continue;
                const [px, py] = map([cx + dx, cy + dy]);
                const xi = Math.round(px), yi = Math.round(py);
                if (xi < 0 || yi < 0 || xi >= w || yi >= h) continue;
                sum += 255 - g[yi * w + xi]; n++;
            }
        }
        return n ? sum / n / 255 : 0;
    };
    const answers = {}, doubtful = [];
    questions.forEach((q, k) => {
        const d = slot(k, q.options).bubbles.map(([x, y]) => darkness(x, y));
        const low = Math.min(...d);
        const filled = d.map((v, i) => (v > 0.35 && v - low > 0.2 ? i : -1)).filter((i) => i >= 0);
        if (q.type !== 'multiple' && filled.length > 1) {
            doubtful.push(q.id);
            const top = d.indexOf(Math.max(...d));
            answers[q.id] = [top];
        } else {
            answers[q.id] = filled;
        }
        // A light mark between empty and full is worth a look.
        if (d.some((v) => v > 0.25 && v - low > 0.12 && v - low <= 0.2)) doubtful.push(q.id);
    });

    return { code: qr.data, answers, doubtful: [...new Set(doubtful)] };
}
