// «خريطة المكتبة»: walking route from the entrance to a bookcase, and Waze-style directions.
// Plan coordinates are metres, x to the right, y towards the entrance (down on the plan).

export const CELL = 0.25; // grid resolution in metres
const CLEARANCE = 0.3; // keep the walker this far from furniture

/** The four corners of an item's footprint after rotation. */
export function footprint(item, grow = 0) {
    const hw = item.width / 2 + grow;
    const hd = item.depth / 2 + grow;
    const a = ((item.rotation || 0) * Math.PI) / 180;
    const c = Math.cos(a), s = Math.sin(a);
    return [[-hw, -hd], [hw, -hd], [hw, hd], [-hw, hd]].map(([px, py]) => ({ x: item.x + px * c - py * s, y: item.y + px * s + py * c }));
}

/** Unit vector out of the bookcase's front face (towards +y when not rotated). */
export function frontNormal(item) {
    const a = ((item.rotation || 0) * Math.PI) / 180;
    return { x: -Math.sin(a), y: Math.cos(a) };
}

function inside(px, py, item, grow) {
    const a = ((item.rotation || 0) * Math.PI) / 180;
    const dx = px - item.x, dy = py - item.y;
    const lx = dx * Math.cos(a) + dy * Math.sin(a);
    const ly = -dx * Math.sin(a) + dy * Math.cos(a);
    return Math.abs(lx) <= item.width / 2 + grow && Math.abs(ly) <= item.depth / 2 + grow;
}

export function buildGrid(floor, items) {
    const cols = Math.max(1, Math.ceil(floor.width / CELL));
    const rows = Math.max(1, Math.ceil(floor.depth / CELL));
    const blocked = new Uint8Array(cols * rows);
    for (const it of items) {
        const grow = it.grow ?? CLEARANCE;
        const pts = footprint(it, grow);
        const minX = Math.max(0, Math.floor(Math.min(...pts.map((p) => p.x)) / CELL));
        const maxX = Math.min(cols - 1, Math.ceil(Math.max(...pts.map((p) => p.x)) / CELL));
        const minY = Math.max(0, Math.floor(Math.min(...pts.map((p) => p.y)) / CELL));
        const maxY = Math.min(rows - 1, Math.ceil(Math.max(...pts.map((p) => p.y)) / CELL));
        for (let r = minY; r <= maxY; r++) {
            for (let c = minX; c <= maxX; c++) {
                if (inside((c + 0.5) * CELL, (r + 0.5) * CELL, it, grow)) blocked[r * cols + c] = 1;
            }
        }
    }
    return { cols, rows, blocked };
}

const toCell = (g, p) => ({
    c: Math.min(g.cols - 1, Math.max(0, Math.floor(p.x / CELL))),
    r: Math.min(g.rows - 1, Math.max(0, Math.floor(p.y / CELL))),
});
const center = (c, r) => ({ x: (c + 0.5) * CELL, y: (r + 0.5) * CELL });

/** Nearest free cell (breadth-first), for points that fall inside furniture or a wall. */
function nearestFree(g, cell) {
    const start = cell.r * g.cols + cell.c;
    if (!g.blocked[start]) return cell;
    const seen = new Uint8Array(g.cols * g.rows);
    const queue = [start];
    seen[start] = 1;
    while (queue.length) {
        const i = queue.shift();
        const c = i % g.cols, r = (i - c) / g.cols;
        if (!g.blocked[i]) return { c, r };
        for (const [dc, dr] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
            const nc = c + dc, nr = r + dr;
            if (nc < 0 || nr < 0 || nc >= g.cols || nr >= g.rows) continue;
            const n = nr * g.cols + nc;
            if (!seen[n]) { seen[n] = 1; queue.push(n); }
        }
    }
    return null;
}

/** A* over the grid with diagonal moves; returns cell centres or null. */
function astar(g, from, to) {
    const N = g.cols * g.rows;
    const gScore = new Float32Array(N).fill(Infinity);
    const came = new Int32Array(N).fill(-1);
    const closed = new Uint8Array(N);
    const goal = to.r * g.cols + to.c;
    const h = (i) => { const c = i % g.cols, r = (i - c) / g.cols; const dx = Math.abs(c - to.c), dy = Math.abs(r - to.r); return Math.max(dx, dy) + 0.414 * Math.min(dx, dy); };
    const start = from.r * g.cols + from.c;
    gScore[start] = 0;
    // Binary heap of [f, index].
    const heap = [[h(start), start]];
    const push = (item) => { heap.push(item); let k = heap.length - 1; while (k > 0) { const p = (k - 1) >> 1; if (heap[p][0] <= heap[k][0]) break; [heap[p], heap[k]] = [heap[k], heap[p]]; k = p; } };
    const pop = () => { const top = heap[0]; const last = heap.pop(); if (heap.length) { heap[0] = last; let k = 0; for (;;) { const l = 2 * k + 1, r = l + 1; let m = k; if (l < heap.length && heap[l][0] < heap[m][0]) m = l; if (r < heap.length && heap[r][0] < heap[m][0]) m = r; if (m === k) break; [heap[m], heap[k]] = [heap[k], heap[m]]; k = m; } } return top; };
    while (heap.length) {
        const [, i] = pop();
        if (closed[i]) continue;
        if (i === goal) break;
        closed[i] = 1;
        const c = i % g.cols, r = (i - c) / g.cols;
        for (const [dc, dr, cost] of [[1, 0, 1], [-1, 0, 1], [0, 1, 1], [0, -1, 1], [1, 1, 1.414], [1, -1, 1.414], [-1, 1, 1.414], [-1, -1, 1.414]]) {
            const nc = c + dc, nr = r + dr;
            if (nc < 0 || nr < 0 || nc >= g.cols || nr >= g.rows) continue;
            const n = nr * g.cols + nc;
            if (g.blocked[n] || closed[n]) continue;
            // No corner cutting past furniture.
            if (dc && dr && (g.blocked[r * g.cols + nc] || g.blocked[nr * g.cols + c])) continue;
            const t = gScore[i] + cost;
            if (t < gScore[n]) { gScore[n] = t; came[n] = i; push([t + h(n), n]); }
        }
    }
    if (gScore[goal] === Infinity) return null;
    const out = [];
    for (let i = goal; i !== -1; i = came[i]) { const c = i % g.cols; out.unshift(center(c, (i - c) / g.cols)); }
    return out;
}

function clearLine(g, a, b) {
    const steps = Math.ceil(Math.hypot(b.x - a.x, b.y - a.y) / (CELL / 3));
    for (let k = 0; k <= steps; k++) {
        const x = a.x + ((b.x - a.x) * k) / steps, y = a.y + ((b.y - a.y) * k) / steps;
        const c = Math.floor(x / CELL), r = Math.floor(y / CELL);
        if (c < 0 || r < 0 || c >= g.cols || r >= g.rows || g.blocked[r * g.cols + c]) return false;
    }
    return true;
}

/** Keeps only the corners: straight walks between turns. */
function simplify(g, pts) {
    if (pts.length < 3) return pts;
    const out = [pts[0]];
    let i = 0;
    while (i < pts.length - 1) {
        let j = pts.length - 1;
        while (j > i + 1 && !clearLine(g, pts[i], pts[j])) j--;
        out.push(pts[j]);
        i = j;
    }
    return out;
}

const length = (pts) => pts.reduce((s, p, i) => (i ? s + Math.hypot(p.x - pts[i - 1].x, p.y - pts[i - 1].y) : 0), 0);

/** Where a reader stands to take a book from this bookcase (each usable face). */
// «بوابة مانعة للسرقة» (2026-11-06, by request): only its two posts block the walk; readers
// pass between them, so the posts keep a smaller clearance than furniture.
export const GATE_POST = 0.15;
export function gatePillars(gate) {
    const a = ((gate.rotation || 0) * Math.PI) / 180;
    const off = gate.width / 2 - GATE_POST / 2;
    return [1, -1].map((side) => ({
        x: gate.x + Math.cos(a) * off * side, y: gate.y + Math.sin(a) * off * side,
        width: GATE_POST, depth: gate.depth, rotation: gate.rotation, grow: 0.1,
    }));
}

export function standingPoints(shelf) {
    const n = frontNormal(shelf);
    const off = shelf.depth / 2 + CLEARANCE + CELL;
    const pts = [{ x: shelf.x + n.x * off, y: shelf.y + n.y * off, side: 1 }];
    if (shelf.double_sided) pts.push({ x: shelf.x - n.x * off, y: shelf.y - n.y * off, side: -1 });
    return pts;
}

/**
 * The walk from the entrance to a bookcase.
 * @returns {{points: {x:number,y:number}[], length: number}|null}
 */
export function findRoute(floor, items, start, shelf) {
    const obstacles = items.flatMap((it) => (it.kind === 'gate' ? gatePillars(it) : [it]));
    const g = buildGrid(floor, obstacles);
    const from = nearestFree(g, toCell(g, start));
    if (!from) return null;
    let best = null;
    for (const goalPoint of standingPoints(shelf)) {
        const to = nearestFree(g, toCell(g, goalPoint));
        if (!to) continue;
        const raw = astar(g, from, to);
        if (!raw) continue;
        const pts = simplify(g, raw);
        pts[0] = { x: start.x, y: start.y };
        const len = length(pts);
        if (!best || len < best.length) best = { points: pts, length: len };
    }
    return best;
}

/**
 * Turn-by-turn directions for a route: "امشِ 6 م", "انعطف يميناً"... The last step says on
 * which side the bookcase is.
 * @returns {{type: string, distance: number, at: number}[]} at = metres from the start
 */
export function directions(route, shelf) {
    const pts = route.points;
    const steps = [];
    let walked = 0;
    for (let i = 1; i < pts.length; i++) {
        const seg = Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y);
        if (i === 1) steps.push({ type: 'start', distance: seg, at: 0 });
        else {
            const a = { x: pts[i - 1].x - pts[i - 2].x, y: pts[i - 1].y - pts[i - 2].y };
            const b = { x: pts[i].x - pts[i - 1].x, y: pts[i].y - pts[i - 1].y };
            const angle = (Math.atan2(a.x * b.y - a.y * b.x, a.x * b.x + a.y * b.y) * 180) / Math.PI;
            // y grows downwards on the plan, so a positive angle is a clockwise (right) turn.
            const abs = Math.abs(angle);
            const side = angle > 0 ? 'right' : 'left';
            const type = abs < 20 ? 'straight' : abs < 55 ? `slight_${side}` : abs > 150 ? 'uturn' : side;
            if (type === 'straight' && steps.length) steps[steps.length - 1].distance += seg;
            else steps.push({ type, distance: seg, at: walked });
        }
        walked += seg;
    }
    // Final: the bookcase is on the reader's left or right (or ahead).
    const last = pts[pts.length - 1];
    const prev = pts[pts.length - 2] ?? last;
    const h = { x: last.x - prev.x, y: last.y - prev.y };
    const to = { x: shelf.x - last.x, y: shelf.y - last.y };
    const cross = h.x * to.y - h.y * to.x;
    const dot = h.x * to.x + h.y * to.y;
    const ahead = Math.abs(cross) < Math.abs(dot) * 0.6;
    steps.push({ type: ahead ? 'arrive_ahead' : cross > 0 ? 'arrive_right' : 'arrive_left', distance: 0, at: walked });
    return steps;
}

/** Position and heading at `d` metres along the route. */
export function pointAt(route, d) {
    const pts = route.points;
    let left = d;
    for (let i = 1; i < pts.length; i++) {
        const seg = Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y);
        if (left <= seg || i === pts.length - 1) {
            const t = seg ? Math.min(1, left / seg) : 1;
            return {
                x: pts[i - 1].x + (pts[i].x - pts[i - 1].x) * t,
                y: pts[i - 1].y + (pts[i].y - pts[i - 1].y) * t,
                heading: Math.atan2(pts[i].y - pts[i - 1].y, pts[i].x - pts[i - 1].x),
            };
        }
        left -= seg;
    }
    return { x: pts[0].x, y: pts[0].y, heading: 0 };
}
