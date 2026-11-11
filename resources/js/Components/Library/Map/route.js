// «خريطة المكتبة»: walking route from the entrance to a bookcase, and Waze-style directions.
// Plan coordinates are metres, x to the right, y towards the entrance (down on the plan).

export const CELL = 0.25; // grid resolution in metres
const CLEARANCE = 0.3; // keep the walker this far from furniture
// Narrow aisles (2026-11-11: 65–80 cm round a big reading table): when no walk keeps the
// usual distance, the route is looked for again keeping only this much.
const TIGHT = 0.1;

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

/** «عائق»: an obstacle's drawn outline on the plan (its points are relative to x/y, before rotation). */
export function outline(item) {
    const a = ((item.rotation || 0) * Math.PI) / 180;
    const c = Math.cos(a), s = Math.sin(a);
    return item.points.map(([px, py]) => ({ x: item.x + px * c - py * s, y: item.y + px * s + py * c }));
}

function inPolygon(px, py, poly) {
    let hit = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const a = poly[i], b = poly[j];
        if ((a.y > py) !== (b.y > py) && px < ((b.x - a.x) * (py - a.y)) / (b.y - a.y) + a.x) hit = !hit;
    }
    return hit;
}
function nearPolygon(px, py, poly, grow) {
    if (inPolygon(px, py, poly)) return true;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const a = poly[j], b = poly[i];
        const dx = b.x - a.x, dy = b.y - a.y;
        const t = Math.max(0, Math.min(1, ((px - a.x) * dx + (py - a.y) * dy) / (dx * dx + dy * dy || 1)));
        if (Math.hypot(px - (a.x + t * dx), py - (a.y + t * dy)) <= grow) return true;
    }
    return false;
}

const HALL_WALL = 0.3; // keep the walker this far from a hall's walls
const DOOR_EDGE = 0.1; // the door frame narrows the opening a little on each side

/** «قاعة / غرفة»: the sides of each hall's outline, as wall segments on the plan. */
export function hallWalls(halls) {
    return halls.flatMap((h) => { const p = outline(h); return p.map((a, i) => [a, p[(i + 1) % p.length]]); });
}

/** Area of a polygon (for "the smallest hall a point is in" when rooms sit inside a hall). */
function area(poly) {
    let s = 0;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) s += (poly[j].x + poly[i].x) * (poly[j].y - poly[i].y);
    return Math.abs(s / 2);
}

/** The hall or room a plan point is in (the smallest one, so a room inside a hall wins). */
export function hallAt(halls, p) {
    let best = null, bestArea = Infinity;
    for (const h of halls) {
        if (!(h.points?.length >= 3)) continue;
        const poly = outline(h);
        if (!inPolygon(p.x, p.y, poly)) continue;
        const a = area(poly);
        if (a < bestArea) { best = h; bestArea = a; }
    }
    return best;
}

/**
 * Snaps a door to the nearest hall side: on the wall, along it.
 * @returns {{x:number, y:number, rotation:number, distance:number}|null}
 */
export function snapToWall(halls, p) {
    let best = null;
    for (const [a, b] of hallWalls(halls)) {
        const dx = b.x - a.x, dy = b.y - a.y, len2 = dx * dx + dy * dy;
        if (len2 < 0.01) continue;
        const t = Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / len2));
        const q = { x: a.x + t * dx, y: a.y + t * dy };
        const d = Math.hypot(p.x - q.x, p.y - q.y);
        if (!best || d < best.distance) {
            let rot = Math.round((Math.atan2(dy, dx) * 180) / Math.PI);
            rot = ((rot % 180) + 180) % 180; // a door looks the same both ways
            best = { x: +q.x.toFixed(2), y: +q.y.toFixed(2), rotation: rot, distance: d };
        }
    }
    return best;
}

export function buildGrid(floor, items, halls = [], doors = [], clearance = CLEARANCE, hallWall = HALL_WALL) {
    const cols = Math.max(1, Math.ceil(floor.width / CELL));
    const rows = Math.max(1, Math.ceil(floor.depth / CELL));
    const blocked = new Uint8Array(cols * rows);
    // With halls drawn, readers walk only inside them, away from their walls, and through doors.
    if (halls.length) {
        blocked.fill(1);
        const walls = hallWalls(halls);
        const nearWall = (x, y) => walls.some(([a, b]) => {
            const dx = b.x - a.x, dy = b.y - a.y;
            const t = Math.max(0, Math.min(1, ((x - a.x) * dx + (y - a.y) * dy) / (dx * dx + dy * dy || 1)));
            return Math.hypot(x - (a.x + t * dx), y - (a.y + t * dy)) < hallWall;
        });
        const polys = halls.map(outline);
        for (let r = 0; r < rows; r++) {
            for (let c = 0; c < cols; c++) {
                const x = (c + 0.5) * CELL, y = (r + 0.5) * CELL;
                if (polys.some((poly) => inPolygon(x, y, poly)) && !nearWall(x, y)) blocked[r * cols + c] = 0;
            }
        }
        for (const d of doors) {
            const reach = { ...d, width: Math.max(CELL, d.width - 2 * DOOR_EDGE), depth: 2 * (hallWall + CELL) };
            for (let r = 0; r < rows; r++) {
                for (let c = 0; c < cols; c++) if (inside((c + 0.5) * CELL, (r + 0.5) * CELL, reach, 0)) blocked[r * cols + c] = 0;
            }
        }
    }
    for (const it of items) {
        const grow = Math.min(it.grow ?? clearance, clearance);
        const poly = it.points?.length >= 3 ? outline(it) : null;
        const pts = poly ? poly.flatMap((p) => [{ x: p.x - grow, y: p.y - grow }, { x: p.x + grow, y: p.y + grow }]) : footprint(it, grow);
        const minX = Math.max(0, Math.floor(Math.min(...pts.map((p) => p.x)) / CELL));
        const maxX = Math.min(cols - 1, Math.ceil(Math.max(...pts.map((p) => p.x)) / CELL));
        const minY = Math.max(0, Math.floor(Math.min(...pts.map((p) => p.y)) / CELL));
        const maxY = Math.min(rows - 1, Math.ceil(Math.max(...pts.map((p) => p.y)) / CELL));
        for (let r = minY; r <= maxY; r++) {
            for (let c = minX; c <= maxX; c++) {
                if (poly ? nearPolygon((c + 0.5) * CELL, (r + 0.5) * CELL, poly, grow) : inside((c + 0.5) * CELL, (r + 0.5) * CELL, it, grow)) blocked[r * cols + c] = 1;
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

/** Metres between two route points; going up or down the stairs counts as STAIRS_COST a floor. */
const segLength = (a, b) => (b.stairs ? b.stairs.cost : Math.hypot(b.x - a.x, b.y - a.y));
const length = (pts) => pts.reduce((s, p, i) => (i ? s + segLength(pts[i - 1], p) : 0), 0);

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

export function standingPoints(shelf, tight = false) {
    const n = frontNormal(shelf);
    const off = shelf.depth / 2 + (tight ? TIGHT + CELL / 2 : CLEARANCE + CELL);
    const pts = [{ x: shelf.x + n.x * off, y: shelf.y + n.y * off, side: 1 }];
    if (shelf.double_sided) pts.push({ x: shelf.x - n.x * off, y: shelf.y - n.y * off, side: -1 });
    return pts;
}

/**
 * The walk from the entrance to a bookcase.
 * @returns {{points: {x:number,y:number}[], length: number}|null}
 */
/** «قاعة» and «ممر» are both walkable outlines with walls round them. */
export const HALL_KINDS = ['hall', 'corridor'];
export const isHall = (it) => HALL_KINDS.includes(it.kind) && it.points?.length >= 3;
/** Which floor of the building an item (or a point) is on; 0 is the ground floor. */
export const storeyOf = (it) => it?.storey ?? 0;

// One grid per floor of the building; it only changes with the plan, so walking (which
// re-routes as you go) reuses it.
const gridCache = new WeakMap();
function storeyGrid(floor, items, storey, tight = false) {
    let byStorey = gridCache.get(items);
    if (!byStorey) { byStorey = new Map(); gridCache.set(items, byStorey); }
    const key = `${storey}:${floor.width}x${floor.depth}:${tight ? 't' : 'n'}`;
    if (byStorey.has(key)) return byStorey.get(key);
    const here = items.filter((it) => storeyOf(it) === storey);
    const halls = here.filter(isHall);
    const doors = here.filter((it) => it.kind === 'door');
    const obstacles = here
        .filter((it) => !HALL_KINDS.includes(it.kind) && !['door', 'stairs', 'qr'].includes(it.kind))
        .flatMap((it) => (it.kind === 'gate' ? gatePillars(it) : [it]));
    const g = tight ? buildGrid(floor, obstacles, halls, doors, TIGHT, TIGHT) : buildGrid(floor, obstacles, halls, doors);
    byStorey.set(key, g);
    return g;
}

/** The shortest walk on one floor from `start` to the nearest of `goals`. */
function legRoute(g, start, goals, storey) {
    const from = nearestFree(g, toCell(g, start));
    if (!from) return null;
    let best = null;
    for (const goalPoint of goals) {
        const to = nearestFree(g, toCell(g, goalPoint));
        if (!to) continue;
        const raw = astar(g, from, to);
        if (!raw) continue;
        const pts = simplify(g, raw);
        pts[0] = { x: start.x, y: start.y };
        if (goalPoint.exact) pts.push({ x: goalPoint.x, y: goalPoint.y });
        const len = length(pts);
        if (!best || len < best.length) best = { points: pts.map((p) => ({ ...p, storey })), length: len };
    }
    return best;
}

// «درج / مصعد»: stairs on two floors are one stairwell when they sit over each other.
// A lift is a «درج / مصعد» item with `double_sided` set (the column is reused for it).
export const STAIRS_LINK = 2.5; // metres apart on the plan, at most
export const STAIRS_COST = 8; // metres of walking one floor up or down is worth
export const isLift = (it) => it?.kind === 'stairs' && !!it.double_sided;
export function linkedStairs(items, stair, storey, liftOnly = false) {
    return items
        .filter((it) => it.kind === 'stairs' && (!liftOnly || isLift(it)) && storeyOf(it) === storey && Math.hypot(it.x - stair.x, it.y - stair.y) <= STAIRS_LINK)
        .sort((a, b) => Math.hypot(a.x - stair.x, a.y - stair.y) - Math.hypot(b.x - stair.x, b.y - stair.y))[0] ?? null;
}

/**
 * The walk from the entrance (or «أنت هنا») to a bookcase, on another floor through the stairs.
 * Each point carries its floor; the point that changes floor has `stairs` set.
 * @returns {{points: {x:number,y:number,storey:number,stairs?:object}[], length: number}|null}
 */
export function findRoute(floor, items, start, shelf, { liftOnly = false } = {}) {
    return routeWith(floor, items, start, shelf, liftOnly, false) ?? routeWith(floor, items, start, shelf, liftOnly, true);
}

function routeWith(floor, items, start, shelf, liftOnly, tight) {
    const from = storeyOf(start), to = storeyOf(shelf);
    const goals = standingPoints(shelf, tight);
    if (from === to) return legRoute(storeyGrid(floor, items, from, tight), start, goals, from);
    let best = null;
    for (const a of items.filter((it) => it.kind === 'stairs' && storeyOf(it) === from && (!liftOnly || isLift(it)))) {
        const b = linkedStairs(items, a, to, liftOnly);
        if (!b) continue;
        const up = legRoute(storeyGrid(floor, items, from, tight), start, [{ x: a.x, y: a.y, exact: true }], from);
        if (!up) continue;
        const down = legRoute(storeyGrid(floor, items, to, tight), { x: b.x, y: b.y }, goals, to);
        if (!down) continue;
        const climb = Math.abs(to - from) * STAIRS_COST;
        const total = up.length + climb + down.length;
        if (best && total >= best.length) continue;
        const [first, ...rest] = down.points;
        best = {
            points: [...up.points, { ...first, stairs: { from, to, code: a.code, kind: a.name || '', cost: climb, lift: isLift(a) } }, ...rest],
            length: total,
        };
    }
    return best;
}

/**
 * Turn-by-turn directions for a route: "امشِ 6 م", "انعطف يميناً"... The last step says on
 * which side the bookcase is.
 * @returns {{type: string, distance: number, at: number}[]} at = metres from the start
 */
export function directions(route, shelf, halls = []) {
    const pts = route.points;
    const steps = [];
    let walked = 0;
    for (let i = 1; i < pts.length; i++) {
        const seg = segLength(pts[i - 1], pts[i]);
        if (pts[i].stairs) {
            // «اصعد / انزل بالدرج إلى الطابق …»
            const { from, to } = pts[i].stairs;
            steps.push({ type: to > from ? 'stairs_up' : 'stairs_down', distance: seg, at: walked, storey: to, stairs: pts[i].stairs });
        } else if (i === 1 || pts[i - 1].stairs) steps.push({ type: 'start', distance: seg, at: walked });
        else {
            const a = { x: pts[i - 1].x - pts[i - 2].x, y: pts[i - 1].y - pts[i - 2].y };
            const b = { x: pts[i].x - pts[i - 1].x, y: pts[i].y - pts[i - 1].y };
            const angle = (Math.atan2(a.x * b.y - a.y * b.x, a.x * b.x + a.y * b.y) * 180) / Math.PI;
            // y grows downwards on the plan, so a positive angle is a clockwise (right) turn.
            const abs = Math.abs(angle);
            const side = angle > 0 ? 'right' : 'left';
            const type = abs < 20 ? 'straight' : abs < 55 ? `slight_${side}` : abs > 150 ? 'uturn' : side;
            if (type === 'straight' && steps.length && !steps[steps.length - 1].type.startsWith('stairs')) steps[steps.length - 1].distance += seg;
            else steps.push({ type: type === 'straight' ? 'start' : type, distance: seg, at: walked });
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
    if (halls.length) markHalls(route, steps, halls);
    // A turn of a few centimetres just before the stairs is noise.
    return steps.filter((st, k) => !(st.distance < 0.6 && steps[k + 1]?.type.startsWith('stairs') && k > 0));
}

/**
 * «ادخل قاعة …»: where the walk goes through a door into another hall or room, the step is
 * split there. A crossing right after a turn is told with that turn instead.
 */
function markHalls(route, steps, halls) {
    const total = route.length ?? length(route.points);
    const on = (p) => hallAt(halls.filter((h) => storeyOf(h) === (p.storey ?? 0)), p);
    let current = on(route.points[0])?.id ?? null;
    let storey = route.points[0].storey ?? 0;
    for (let d = 0.25; d < total; d += 0.25) {
        const p = pointAt(route, d);
        if (p.onStairs) continue;
        const h = on(p);
        // Up the stairs: you are simply there, no «ادخل».
        if (p.storey !== storey) { storey = p.storey; current = h?.id ?? null; continue; }
        if (!h || h.id === current) continue;
        current = h.id;
        const i = steps.findIndex((s, k) => !s.type.startsWith('arrive') && !s.type.startsWith('stairs') && d >= s.at && d < s.at + s.distance + 0.01 && (k === steps.length - 1 || d < steps[k + 1].at));
        if (i < 0) continue;
        const s = steps[i];
        const name = h.code;
        if (d - s.at < 1.5) { s.hall = name; continue; }
        const rest = s.at + s.distance - d;
        s.distance = d - s.at;
        steps.splice(i + 1, 0, { type: 'enter', distance: rest, at: d, hall: name });
    }
}

/** Position, heading and floor at `d` metres along the route. */
export function pointAt(route, d) {
    const pts = route.points;
    let left = d;
    let heading = 0;
    for (let i = 1; i < pts.length; i++) {
        const a = pts[i - 1], b = pts[i];
        const seg = segLength(a, b);
        if (!b.stairs && seg > 0.001) heading = Math.atan2(b.y - a.y, b.x - a.x);
        if (left <= seg || i === pts.length - 1) {
            const t = seg ? Math.min(1, Math.max(0, left / seg)) : 1;
            // On the stairs the walker stays at the stairwell and changes floor half way.
            if (b.stairs) { const q = t < 0.5 ? a : b; return { x: q.x, y: q.y, heading, storey: q.storey ?? 0, onStairs: t > 0 && t < 1 }; }
            return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t, heading, storey: b.storey ?? 0 };
        }
        left -= seg;
    }
    return { x: pts[0].x, y: pts[0].y, heading: 0, storey: pts[0].storey ?? 0 };
}

/** The part of a route on one floor, for drawing it there. */
export function routeOnStorey(route, storey) {
    if (!route) return null;
    const pts = route.points.filter((p) => (p.storey ?? 0) === storey).map(({ stairs, ...p }) => p);
    return pts.length >= 2 ? { points: pts, length: length(pts) } : null;
}
