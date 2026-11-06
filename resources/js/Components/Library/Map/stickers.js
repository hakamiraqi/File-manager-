// «ملصقات QR» (2026-11-06, by request): every bookcase has its sticker, and stickers on the walls
// (by each door, by the stairs, along corridors and empty halls) let readers say where they are
// anywhere in the building. They are placed automatically and shown on the map so readers can
// find the nearest one to scan.
import { outline, isHall, storeyOf, footprint } from './route.js';

/** Things a reader can scan: a bookcase's sticker or a sticker on a wall. */
export const isSticker = (it) => it?.kind === 'qr' || it?.kind === 'shelf';

const OFF = 0.06; // a wall sticker's point stands this far off the wall's middle line

function inPoly(x, y, poly) {
    let hit = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const a = poly[i], b = poly[j];
        if ((a.y > y) !== (b.y > y) && x < ((b.x - a.x) * (y - a.y)) / (b.y - a.y) + a.x) hit = !hit;
    }
    return hit;
}

/** The walls of one floor: its halls and corridors, or the room's own four walls. */
export function wallPolygons(floor, items, storey) {
    const halls = items.filter((i) => storeyOf(i) === storey && isHall(i));
    if (halls.length) return halls.map(outline);
    return [[{ x: 0, y: 0 }, { x: floor.width, y: 0 }, { x: floor.width, y: floor.depth }, { x: 0, y: floor.depth }]];
}

/** A sticker on the wall nearest to `p`, facing the side `p` is on (into the room when on it). */
export function wallSpot(polys, p) {
    let best = null;
    for (const poly of polys) {
        for (let i = 0; i < poly.length; i++) {
            const a = poly[i], b = poly[(i + 1) % poly.length];
            const dx = b.x - a.x, dy = b.y - a.y, len2 = dx * dx + dy * dy;
            if (len2 < 0.01) continue;
            const t = Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / len2));
            const q = { x: a.x + t * dx, y: a.y + t * dy };
            const d = Math.hypot(p.x - q.x, p.y - q.y);
            if (!best || d < best.distance - 1e-6) best = { q, a, b, poly, distance: d };
        }
    }
    if (!best) return null;
    const { q, a, b, poly } = best;
    const len = Math.hypot(b.x - a.x, b.y - a.y);
    let n = { x: -(b.y - a.y) / len, y: (b.x - a.x) / len };
    const side = (p.x - q.x) * n.x + (p.y - q.y) * n.y;
    if (Math.abs(side) > 0.05) { if (side < 0) n = { x: -n.x, y: -n.y }; }
    else if (!inPoly(q.x + n.x * 0.3, q.y + n.y * 0.3, poly)) n = { x: -n.x, y: -n.y };
    return {
        x: +(q.x + n.x * OFF).toFixed(2),
        y: +(q.y + n.y * OFF).toFixed(2),
        rotation: ((Math.round((Math.atan2(-n.x, n.y) * 180) / Math.PI) % 360) + 360) % 360,
        distance: best.distance,
        along: { x: (b.x - a.x) / len, y: (b.y - a.y) / len },
        normal: n,
    };
}

function inRect(p, item, grow) {
    const a = ((item.rotation || 0) * Math.PI) / 180;
    const dx = p.x - item.x, dy = p.y - item.y;
    const lx = dx * Math.cos(a) + dy * Math.sin(a), ly = -dx * Math.sin(a) + dy * Math.cos(a);
    return Math.abs(lx) <= item.width / 2 + grow && Math.abs(ly) <= item.depth / 2 + grow;
}

/**
 * New wall stickers for one floor, where none is yet: beside every door (on both sides), by the
 * stairs, by the entrance, and every `spacing` metres along corridors and halls with no
 * bookcases (bookcases carry their own stickers). Never behind furniture or in a doorway.
 * @returns {{x:number, y:number, rotation:number, near:string}[]}
 */
export function autoStickers(floor, items, storey, { spacing = 10 } = {}) {
    const here = items.filter((i) => storeyOf(i) === storey);
    const polys = wallPolygons(floor, items, storey);
    const halls = here.filter(isHall);
    const doors = here.filter((i) => i.kind === 'door');
    const blockers = here.filter((i) => !isHall(i) && !['door', 'qr'].includes(i.kind));
    const shelves = here.filter((i) => i.kind === 'shelf');
    const facing = (rot) => { const a = ((rot || 0) * Math.PI) / 180; return { x: -Math.sin(a), y: Math.cos(a) }; };
    const taken = here.filter((i) => i.kind === 'qr').map((i) => ({ x: i.x, y: i.y, n: facing(i.rotation) }));
    const out = [];

    const walkable = (pt) => (halls.length ? halls.some((h) => inPoly(pt.x, pt.y, outline(h))) : pt.x > 0 && pt.y > 0 && pt.x < floor.width && pt.y < floor.depth);
    const ok = (s) => {
        const stand = { x: s.x + s.normal.x * 0.6, y: s.y + s.normal.y * 0.6 };
        if (!walkable(stand)) return false;
        if (blockers.some((b) => (b.points?.length >= 3 ? inPoly(stand.x, stand.y, outline(b)) : inRect(stand, b, 0.15) || inRect(s, b, 0.1)))) return false;
        // Not in a doorway, nor in the entrance.
        if (doors.some((d) => Math.hypot(d.x - s.x, d.y - s.y) < d.width / 2 + 0.25)) return false;
        if (storey === 0 && Math.hypot(floor.entrance.x - s.x, floor.entrance.y - s.y) < 0.9) return false;
        return true;
    };
    /** Is there a sticker within `radius` of `p` that readers on the side `n` faces can see? */
    const covered = (p, n, radius) => [...taken, ...out].some((s) => Math.hypot(s.x - p.x, s.y - p.y) < radius && s.n.x * n.x + s.n.y * n.y > 0.3);
    const add = (spot, near, radius = 2.5) => {
        if (!spot || spot.distance > 1.6 || !ok(spot) || covered(spot, spot.normal, radius)) return false;
        out.push({ x: spot.x, y: spot.y, rotation: spot.rotation, near, n: spot.normal });
        return true;
    };
    /** Just beside an opening in a wall, on the side `n` points to (unless one is there already). */
    const beside = (c, u, n, half, near) => {
        if (covered(c, n, half + 2)) return;
        for (const along of [1, -1]) {
            const p = { x: c.x + u.x * along * (half + 0.45) + n.x * 0.3, y: c.y + u.y * along * (half + 0.45) + n.y * 0.3 };
            if (add(wallSpot(polys, p), near)) return;
        }
    };

    // The way in.
    if (storey === 0) {
        const at = wallSpot(polys, floor.entrance);
        if (at && at.distance < 1.5) beside(floor.entrance, at.along, at.normal, 0.6, 'entrance');
    }
    // Both sides of every door.
    for (const d of doors) {
        const a = ((d.rotation || 0) * Math.PI) / 180, u = { x: Math.cos(a), y: Math.sin(a) };
        for (const side of [1, -1]) beside(d, u, { x: -u.y * side, y: u.x * side }, d.width / 2, d.code);
    }
    // The stairs.
    for (const s of here.filter((i) => i.kind === 'stairs')) {
        if ([...taken, ...out].some((q) => Math.hypot(q.x - s.x, q.y - s.y) < Math.max(s.width, s.depth) / 2 + 2.5)) continue;
        for (const c of footprint(s, 0.5)) if (add(wallSpot(polys, c), s.code)) break;
    }
    // Along the walls of corridors and of halls without bookcases (or the bare room).
    const lined = halls.length
        ? halls.filter((h) => h.kind === 'corridor' || !shelves.some((s) => inPoly(s.x, s.y, outline(h)))).map(outline)
        : (shelves.length ? [] : polys);
    for (const poly of lined) {
        poly.forEach((a, i) => {
            const b = poly[(i + 1) % poly.length], len = Math.hypot(b.x - a.x, b.y - a.y);
            if (len < 4) return;
            const m = Math.max(1, Math.round(len / spacing));
            const n = { x: -(b.y - a.y) / len, y: (b.x - a.x) / len };
            const inward = inPoly((a.x + b.x) / 2 + n.x * 0.3, (a.y + b.y) / 2 + n.y * 0.3, poly) ? 1 : -1;
            for (let k = 0; k < m; k++) {
                const f = (k + 0.5) / m;
                const p = { x: a.x + (b.x - a.x) * f + n.x * 0.3 * inward, y: a.y + (b.y - a.y) * f + n.y * 0.3 * inward };
                add(wallSpot(polys, p), '', spacing * 0.6);
            }
        });
    }
    return out.map(({ n, ...s }) => s);
}

/** Where a sticker is on the plan, for «أقرب ملصق»: the spot a reader stands at to scan it. */
export function stickerStand(it) {
    const a = ((it.rotation || 0) * Math.PI) / 180, n = { x: -Math.sin(a), y: Math.cos(a) };
    const off = (it.depth || 0.1) / 2 + 0.6;
    return { x: it.x + n.x * off, y: it.y + n.y * off };
}

/** Stickers nearest first (as the crow flies; the plan has walls, so this is a hint). */
export function nearestStickers(items, p) {
    return items
        .filter(isSticker)
        .map((s) => ({ s, d: Math.hypot(stickerStand(s).x - p.x, stickerStand(s).y - p.y) }))
        .sort((a, b) => a.d - b.d);
}
