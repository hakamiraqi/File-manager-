/**
 * A small document scanner in plain JavaScript (2026-11-14) — no downloads, so it also
 * works on the offline Windows server. Pictures are {data: RGBA bytes, width, height}.
 *
 *  detectQuad(img)            the four corners of the book/page in a (small) picture
 *  warp(img, quad, w, h)      straightens the tilted quadrilateral into a w×h rectangle
 *  applyFilter(img, name, o)  light / colour filters: original, auto, light, magic, gray, bw
 */

const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);

export function toGray(img) {
    const { data, width, height } = img;
    const g = new Float32Array(width * height);
    for (let i = 0, p = 0; i < g.length; i++, p += 4) g[i] = 0.299 * data[p] + 0.587 * data[p + 1] + 0.114 * data[p + 2];
    return g;
}

function boxBlur(src, w, h, r) {
    const tmp = new Float32Array(src.length);
    const out = new Float32Array(src.length);
    for (let y = 0; y < h; y++) {
        let sum = 0;
        const row = y * w;
        for (let x = -r; x <= r; x++) sum += src[row + clamp(x, 0, w - 1)];
        for (let x = 0; x < w; x++) {
            tmp[row + x] = sum / (2 * r + 1);
            sum += src[row + clamp(x + r + 1, 0, w - 1)] - src[row + clamp(x - r, 0, w - 1)];
        }
    }
    for (let x = 0; x < w; x++) {
        let sum = 0;
        for (let y = -r; y <= r; y++) sum += tmp[clamp(y, 0, h - 1) * w + x];
        for (let y = 0; y < h; y++) {
            out[y * w + x] = sum / (2 * r + 1);
            sum += tmp[clamp(y + r + 1, 0, h - 1) * w + x] - tmp[clamp(y - r, 0, h - 1) * w + x];
        }
    }
    return out;
}

export function defaultQuad(width, height, margin = 0.06) {
    const mx = width * margin;
    const my = height * margin;
    return [
        { x: mx, y: my },
        { x: width - mx, y: my },
        { x: width - mx, y: height - my },
        { x: mx, y: height - my },
    ];
}

const cross = (o, a, b) => (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);

function convexHull(points) {
    const pts = points.slice().sort((a, b) => a.x - b.x || a.y - b.y);
    if (pts.length < 3) return pts;
    const lower = [];
    for (const p of pts) {
        while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop();
        lower.push(p);
    }
    const upper = [];
    for (let i = pts.length - 1; i >= 0; i--) {
        const p = pts[i];
        while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop();
        upper.push(p);
    }
    lower.pop();
    upper.pop();
    return lower.concat(upper);
}

export function polygonArea(poly) {
    let a = 0;
    for (let i = 0; i < poly.length; i++) {
        const p = poly[i];
        const q = poly[(i + 1) % poly.length];
        a += p.x * q.y - q.x * p.y;
    }
    return Math.abs(a) / 2;
}

/** Top-left, top-right, bottom-right, bottom-left. */
export function orderQuad(q) {
    const cx = q.reduce((s, p) => s + p.x, 0) / 4;
    const cy = q.reduce((s, p) => s + p.y, 0) / 4;
    const sorted = q.slice().sort((a, b) => Math.atan2(a.y - cy, a.x - cx) - Math.atan2(b.y - cy, b.x - cx));
    // Start from the corner nearest the top-left.
    let start = 0;
    let best = Infinity;
    sorted.forEach((p, i) => {
        const d = p.x + p.y;
        if (d < best) {
            best = d;
            start = i;
        }
    });
    return [0, 1, 2, 3].map((i) => sorted[(start + i) % 4]);
}

export function isConvex(q) {
    let sign = 0;
    for (let i = 0; i < 4; i++) {
        const c = cross(q[i], q[(i + 1) % 4], q[(i + 2) % 4]);
        if (Math.abs(c) < 1e-6) return false;
        if (sign === 0) sign = Math.sign(c);
        else if (Math.sign(c) !== sign) return false;
    }
    return true;
}

/** Drops hull corners one at a time, always the one that costs the least area, until four remain. */
function reduceToQuad(hull) {
    const poly = hull.slice();
    while (poly.length > 4) {
        let best = 0;
        let cost = Infinity;
        for (let i = 0; i < poly.length; i++) {
            const a = poly[(i + poly.length - 1) % poly.length];
            const c = Math.abs(cross(a, poly[i], poly[(i + 1) % poly.length]));
            if (c < cost) {
                cost = c;
                best = i;
            }
        }
        poly.splice(best, 1);
    }
    return poly;
}

function fitLine(points) {
    let mx = 0;
    let my = 0;
    for (const p of points) {
        mx += p.x;
        my += p.y;
    }
    mx /= points.length;
    my /= points.length;
    let sxx = 0;
    let sxy = 0;
    let syy = 0;
    for (const p of points) {
        sxx += (p.x - mx) ** 2;
        sxy += (p.x - mx) * (p.y - my);
        syy += (p.y - my) ** 2;
    }
    const angle = 0.5 * Math.atan2(2 * sxy, sxx - syy);
    return { x: mx, y: my, dx: Math.cos(angle), dy: Math.sin(angle) };
}

function intersect(a, b) {
    const det = a.dx * b.dy - a.dy * b.dx;
    if (Math.abs(det) < 1e-9) return null;
    const t = ((b.x - a.x) * b.dy - (b.y - a.y) * b.dx) / det;
    return { x: a.x + a.dx * t, y: a.y + a.dy * t };
}

function distToSegment(p, a, b) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len = dx * dx + dy * dy || 1;
    const t = clamp(((p.x - a.x) * dx + (p.y - a.y) * dy) / len, 0, 1);
    return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
}

/** The reduced quad cuts its corners a little: each side is refitted through the outline points near it. */
function refine(quad, outline) {
    const groups = [[], [], [], []];
    for (const p of outline) {
        let best = 0;
        let d = Infinity;
        for (let i = 0; i < 4; i++) {
            const di = distToSegment(p, quad[i], quad[(i + 1) % 4]);
            if (di < d) {
                d = di;
                best = i;
            }
        }
        if (d < 4) groups[best].push(p);
    }
    if (groups.some((g) => g.length < 6)) return quad;
    const lines = groups.map(fitLine);
    const out = [];
    for (let i = 0; i < 4; i++) {
        const p = intersect(lines[(i + 3) % 4], lines[i]);
        if (!p || Math.hypot(p.x - quad[i].x, p.y - quad[i].y) > 25) return quad;
        out.push(p);
    }
    return out;
}

/**
 * Finds the page: strong edges are walls, everything reachable from the picture's border
 * without crossing a wall is background, and the biggest thing left is the book.
 * Returns { quad, found } with quad in the picture's own pixels (TL, TR, BR, BL).
 */
export function detectQuad(img) {
    const { width: w, height: h } = img;
    if (w < 24 || h < 24) return { quad: defaultQuad(w, h), found: false };

    const gray = boxBlur(boxBlur(toGray(img), w, h, 2), w, h, 1);
    const mag = new Float32Array(w * h);
    let sum = 0;
    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            const gx = gray[i - w + 1] + 2 * gray[i + 1] + gray[i + w + 1] - gray[i - w - 1] - 2 * gray[i - 1] - gray[i + w - 1];
            const gy = gray[i + w - 1] + 2 * gray[i + w] + gray[i + w + 1] - gray[i - w - 1] - 2 * gray[i - w] - gray[i - w + 1];
            mag[i] = Math.hypot(gx, gy);
            sum += mag[i];
        }
    }
    const mean = sum / (w * h);
    let varSum = 0;
    for (let i = 0; i < mag.length; i++) varSum += (mag[i] - mean) ** 2;
    const sigma = Math.sqrt(varSum / mag.length);

    // A grainy table or cloth makes weak edges everywhere: when nothing is found, only
    // stronger and stronger edges are kept as walls.
    let whole = null;
    for (const k of [0.9, 1.6, 2.4, 3.2]) {
        const r = quadFromEdges(mag, w, h, Math.max(18, mean + k * sigma));
        if (r.found) return r;
        if (r.whole) whole = r;
    }

    return whole || { quad: defaultQuad(w, h), found: false };
}

function quadFromEdges(mag, w, h, threshold) {
    const fallback = { quad: defaultQuad(w, h), found: false };

    // Walls, thickened by one pixel so small gaps in an edge do not leak.
    const wall = new Uint8Array(w * h);
    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            if (mag[y * w + x] > threshold) {
                const i = y * w + x;
                wall[i] = wall[i - 1] = wall[i + 1] = wall[i - w] = wall[i + w] = 1;
            }
        }
    }

    // Background: flood from the border.
    const state = new Uint8Array(w * h); // 0 unknown, 1 background, 2 object seen
    const stack = [];
    const push = (i) => {
        if (!wall[i] && !state[i]) {
            state[i] = 1;
            stack.push(i);
        }
    };
    for (let x = 0; x < w; x++) {
        push(x);
        push((h - 1) * w + x);
    }
    for (let y = 0; y < h; y++) {
        push(y * w);
        push(y * w + w - 1);
    }
    while (stack.length) {
        const i = stack.pop();
        const x = i % w;
        if (x > 0) push(i - 1);
        if (x < w - 1) push(i + 1);
        if (i >= w) push(i - w);
        if (i < w * (h - 1)) push(i + w);
    }

    // The biggest connected piece that is not background (walls belong to the object).
    let bestPixels = null;
    for (let s = 0; s < state.length; s++) {
        if (state[s]) continue;
        const pixels = [];
        state[s] = 2;
        const st = [s];
        while (st.length) {
            const i = st.pop();
            pixels.push(i);
            const x = i % w;
            const tryPush = (j) => {
                if (!state[j]) {
                    state[j] = 2;
                    st.push(j);
                }
            };
            if (x > 0) tryPush(i - 1);
            if (x < w - 1) tryPush(i + 1);
            if (i >= w) tryPush(i - w);
            if (i < w * (h - 1)) tryPush(i + w);
        }
        if (!bestPixels || pixels.length > bestPixels.length) bestPixels = pixels;
    }
    if (!bestPixels || bestPixels.length < w * h * 0.08) return fallback;

    // Its outline: the first and last pixel of every row and column.
    const inObj = new Uint8Array(w * h);
    for (const i of bestPixels) inObj[i] = 1;
    const outline = [];
    for (let y = 0; y < h; y++) {
        let a = -1;
        let b = -1;
        for (let x = 0; x < w; x++) {
            if (inObj[y * w + x]) {
                if (a < 0) a = x;
                b = x;
            }
        }
        if (a >= 0) outline.push({ x: a, y }, { x: b, y });
    }
    for (let x = 0; x < w; x++) {
        let a = -1;
        let b = -1;
        for (let y = 0; y < h; y++) {
            if (inObj[y * w + x]) {
                if (a < 0) a = y;
                b = y;
            }
        }
        if (a >= 0) outline.push({ x, y: a }, { x, y: b });
    }

    const hull = convexHull(outline);
    if (hull.length < 4) return fallback;
    let quad = orderQuad(reduceToQuad(hull));
    quad = orderQuad(refine(quad, outline));
    // The blur and the thickened walls push the outline outwards by about three pixels.
    const cx = quad.reduce((t, p) => t + p.x, 0) / 4;
    const cy = quad.reduce((t, p) => t + p.y, 0) / 4;
    quad = quad.map((p) => ({
        x: clamp(p.x + Math.sign(cx - p.x) * 3, 0, w - 1),
        y: clamp(p.y + Math.sign(cy - p.y) * 3, 0, h - 1),
    }));

    const area = polygonArea(quad);
    if (!isConvex(quad) || area < w * h * 0.1) return fallback;
    // A "page" that is the whole picture means nothing was really found.
    if (area > w * h * 0.97) return { quad: defaultQuad(w, h, 0), found: false, whole: true };

    return { quad, found: true };
}

/** The 3×3 matrix taking the w×h rectangle onto the quad (TL, TR, BR, BL). */
export function homography(quad, w, h) {
    const src = [
        [0, 0],
        [w, 0],
        [w, h],
        [0, h],
    ];
    const A = [];
    for (let i = 0; i < 4; i++) {
        const [x, y] = src[i];
        const { x: u, y: v } = quad[i];
        A.push([x, y, 1, 0, 0, 0, -u * x, -u * y, u]);
        A.push([0, 0, 0, x, y, 1, -v * x, -v * y, v]);
    }
    for (let c = 0; c < 8; c++) {
        let pivot = c;
        for (let r = c + 1; r < 8; r++) if (Math.abs(A[r][c]) > Math.abs(A[pivot][c])) pivot = r;
        [A[c], A[pivot]] = [A[pivot], A[c]];
        const d = A[c][c] || 1e-12;
        for (let k = c; k < 9; k++) A[c][k] /= d;
        for (let r = 0; r < 8; r++) {
            if (r === c) continue;
            const f = A[r][c];
            if (f) for (let k = c; k < 9; k++) A[r][k] -= f * A[c][k];
        }
    }
    return [...A.map((row) => row[8]), 1];
}

/** The natural size of the straightened page, from the lengths of the quad's sides. */
export function quadSize(quad, maxSide = 1600) {
    const d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    let w = Math.max(d(quad[0], quad[1]), d(quad[3], quad[2]));
    let h = Math.max(d(quad[0], quad[3]), d(quad[1], quad[2]));
    const scale = Math.min(1, maxSide / Math.max(w, h, 1));
    w = Math.max(8, Math.round(w * scale));
    h = Math.max(8, Math.round(h * scale));
    return { width: w, height: h };
}

export function warp(img, quad, outW, outH) {
    const { data, width: w, height: h } = img;
    const H = homography(quad, outW, outH);
    const out = new Uint8ClampedArray(outW * outH * 4);
    for (let y = 0; y < outH; y++) {
        for (let x = 0; x < outW; x++) {
            const px = x + 0.5;
            const py = y + 0.5;
            const z = H[6] * px + H[7] * py + H[8];
            const u = clamp((H[0] * px + H[1] * py + H[2]) / z - 0.5, 0, w - 1);
            const v = clamp((H[3] * px + H[4] * py + H[5]) / z - 0.5, 0, h - 1);
            const x0 = Math.floor(u);
            const y0 = Math.floor(v);
            const x1 = Math.min(w - 1, x0 + 1);
            const y1 = Math.min(h - 1, y0 + 1);
            const fx = u - x0;
            const fy = v - y0;
            const a = (y0 * w + x0) * 4;
            const b = (y0 * w + x1) * 4;
            const c = (y1 * w + x0) * 4;
            const d = (y1 * w + x1) * 4;
            const o = (y * outW + x) * 4;
            for (let k = 0; k < 3; k++) {
                out[o + k] = (data[a + k] * (1 - fx) + data[b + k] * fx) * (1 - fy) + (data[c + k] * (1 - fx) + data[d + k] * fx) * fy;
            }
            out[o + 3] = 255;
        }
    }
    return { data: out, width: outW, height: outH };
}

// ---------------------------------------------------------------- filters

function percentile(hist, total, p) {
    const target = total * p;
    let acc = 0;
    for (let i = 0; i < 256; i++) {
        acc += hist[i];
        if (acc >= target) return i;
    }
    return 255;
}

/** Stretches each colour between its dark and bright ends: fixes a colour cast and flat contrast. */
function autoLevels(d, strength = 1) {
    for (let k = 0; k < 3; k++) {
        const hist = new Uint32Array(256);
        for (let i = k; i < d.length; i += 4) hist[d[i]]++;
        const total = d.length / 4;
        // A dark cover is meant to be dark: the low end is only pulled down a little.
        const lo = Math.min(40, percentile(hist, total, 0.01)) * strength;
        const hi = 255 - (255 - percentile(hist, total, 0.99)) * strength;
        if (hi - lo < 24) continue;
        const scale = 255 / (hi - lo);
        for (let i = k; i < d.length; i += 4) d[i] = clamp((d[i] - lo) * scale, 0, 255);
    }
}

/** The middle brightness of each cell of a coarse grid over the picture (print is ignored: it is the minority). */
function lightGrid(d, w, h, cells = 8) {
    const tile = Math.max(4, Math.ceil(Math.max(w, h) / cells));
    const tw = Math.ceil(w / tile);
    const th = Math.ceil(h / tile);
    const grid = new Float32Array(tw * th);
    const step = Math.max(1, Math.floor(tile / 24));
    for (let ty = 0; ty < th; ty++) {
        for (let tx = 0; tx < tw; tx++) {
            const hist = new Uint32Array(256);
            let n = 0;
            for (let y = ty * tile; y < Math.min(h, (ty + 1) * tile); y += step) {
                for (let x = tx * tile; x < Math.min(w, (tx + 1) * tile); x += step) {
                    const i = (y * w + x) * 4;
                    hist[Math.round(0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2])]++;
                    n++;
                }
            }
            grid[ty * tw + tx] = Math.max(12, percentile(hist, n, 0.5));
        }
    }
    return { grid, tw, th, tile };
}

/**
 * Evens out the light. Only the slow change of brightness across the picture is corrected
 * (a hand's or a phone's shadow, a lamp on one side), each area being brought to the
 * picture's usual brightness — so it works on dark and colourful covers as well as on
 * white paper, and leaves no halo around the letters.
 */
function evenLight(d, w, h) {
    const { grid, tw, th, tile } = lightGrid(d, w, h);
    const sorted = Array.from(grid).sort((x, y) => x - y);
    // Aim at the brighter areas: the shaded ones are lifted up to them.
    const target = sorted[Math.floor(sorted.length * 0.75)];
    const smooth = boxBlur(grid, tw, th, 1);
    for (let y = 0; y < h; y++) {
        const gy = clamp(y / tile - 0.5, 0, th - 1);
        const y0 = Math.floor(gy);
        const y1 = Math.min(th - 1, y0 + 1);
        const fy = gy - y0;
        for (let x = 0; x < w; x++) {
            const gx = clamp(x / tile - 0.5, 0, tw - 1);
            const x0 = Math.floor(gx);
            const x1 = Math.min(tw - 1, x0 + 1);
            const fx = gx - x0;
            const bg = (smooth[y0 * tw + x0] * (1 - fx) + smooth[y0 * tw + x1] * fx) * (1 - fy) + (smooth[y1 * tw + x0] * (1 - fx) + smooth[y1 * tw + x1] * fx) * fy;
            const gain = clamp(target / bg, 0.6, 2.4);
            const i = (y * w + x) * 4;
            d[i] = clamp(d[i] * gain, 0, 255);
            d[i + 1] = clamp(d[i + 1] * gain, 0, 255);
            d[i + 2] = clamp(d[i + 2] * gain, 0, 255);
        }
    }
}

/** A dim photo is lifted so that its brightest parts are bright (never darkened). */
function expose(d, aim = 242) {
    const hist = new Uint32Array(256);
    for (let i = 0; i < d.length; i += 4) hist[Math.max(d[i], d[i + 1], d[i + 2])]++;
    const top = percentile(hist, d.length / 4, 0.97);
    const gain = clamp(aim / Math.max(top, 1), 1, 1.9);
    if (gain > 1.02) for (let i = 0; i < d.length; i++) if (i % 4 !== 3) d[i] = clamp(d[i] * gain, 0, 255);
}

function saturate(d, amount) {
    for (let i = 0; i < d.length; i += 4) {
        const g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
        d[i] = clamp(g + (d[i] - g) * amount, 0, 255);
        d[i + 1] = clamp(g + (d[i + 1] - g) * amount, 0, 255);
        d[i + 2] = clamp(g + (d[i + 2] - g) * amount, 0, 255);
    }
}

function grayscale(d) {
    for (let i = 0; i < d.length; i += 4) {
        const g = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
        d[i] = d[i + 1] = d[i + 2] = g;
    }
}

export const FILTERS = ['original', 'auto', 'light', 'magic', 'gray', 'bw'];

/**
 * A new picture with the filter applied, then brightness and contrast (each −100…100).
 */
export function applyFilter(img, name = 'original', { brightness = 0, contrast = 0 } = {}) {
    const d = new Uint8ClampedArray(img.data);
    const { width: w, height: h } = img;

    if (name === 'auto') {
        // Smart default: a dim photo is brightened and a colour cast removed; the cover's own look is kept.
        expose(d);
        autoLevels(d, 0.6);
        saturate(d, 1.08);
    } else if (name === 'light') {
        evenLight(d, w, h);
        expose(d);
    } else if (name === 'magic') {
        evenLight(d, w, h);
        expose(d);
        autoLevels(d, 0.7);
        saturate(d, 1.3);
    } else if (name === 'gray') {
        expose(d);
        autoLevels(d, 0.9);
        grayscale(d);
    } else if (name === 'bw') {
        evenLight(d, w, h);
        grayscale(d);
        autoLevels(d);
        // A steep curve around the middle: print and paper part cleanly, edges stay smooth.
        for (let i = 0; i < d.length; i += 4) {
            const v = clamp((d[i] - 128) * 3 + 128, 0, 255);
            d[i] = d[i + 1] = d[i + 2] = v;
        }
    }

    if (brightness || contrast) {
        const b = brightness * 1.2;
        const c = contrast >= 0 ? 1 + contrast / 50 : 1 + contrast / 100;
        for (let i = 0; i < d.length; i += 4) {
            d[i] = clamp((d[i] - 128) * c + 128 + b, 0, 255);
            d[i + 1] = clamp((d[i + 1] - 128) * c + 128 + b, 0, 255);
            d[i + 2] = clamp((d[i + 2] - 128) * c + 128 + b, 0, 255);
        }
    }

    return { data: d, width: w, height: h };
}

/** True when one part of the picture is clearly darker than another (a shadow, a lamp on one side). */
export function unevenLight(img) {
    const { grid } = lightGrid(img.data, img.width, img.height, 3);
    return Math.max(...grid) / Math.min(...grid) > 1.3;
}
