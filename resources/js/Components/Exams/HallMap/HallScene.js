// «خريطة القاعات» (exam halls, from FileNest's library map): the 3D halls and their computers (three.js). Kept outside Vue's reactivity on purpose:
// the page hands it plain data and calls its methods.
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { sectionColor } from './colors.js';
import { footprint, outline } from './route.js';

const LEVEL_H = 0.36; // height of one shelf level, metres
const BOOK_COLORS = ['#7f1d1d', '#9a3412', '#a16207', '#3f6212', '#065f46', '#155e75', '#1e3a8a', '#4c1d95', '#831843', '#44403c', '#b45309', '#0f766e', '#1d4ed8', '#be123c', '#57534e', '#d6d3d1'];

function inPoly(x, y, poly) {
    let hit = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
        const a = poly[i], b = poly[j];
        if ((a.y > y) !== (b.y > y) && x < ((b.x - a.x) * (y - a.y)) / (b.y - a.y) + a.x) hit = !hit;
    }
    return hit;
}

export function shelfHeight(item) {
    if (item.kind === 'pc') return 1.2;
    if (item.kind === 'desk') return 0.8;
    if (item.kind === 'table') return 0.75;
    if (item.kind === 'wall') return 2.6;
    if (item.kind === 'gate') return 1.6;
    if (item.kind === 'obstacle') return item.height || 1;
    if (item.kind === 'stairs') return item.double_sided ? 2.4 : 1.4; // a lift stands higher
    return item.levels * LEVEL_H + 0.12;
}

// A small seeded random so a bookcase always gets the same books.
function rng(seed) {
    let s = (seed * 9301 + 49297) % 233280 || 1;
    return () => ((s = (s * 9301 + 49297) % 233280) / 233280);
}

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

/** A label that always faces the camera: the bookcase code and, smaller, its section. */
function labelSprite(title, subtitle, color, { scale = 1 } = {}) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const font = "'Cairo', 'Figtree', system-ui, sans-serif";
    ctx.font = `700 64px ${font}`;
    const tw = ctx.measureText(title).width;
    ctx.font = `600 34px ${font}`;
    const sub = subtitle && subtitle.length > 28 ? `…${subtitle.slice(-27)}` : subtitle;
    const sw = sub ? ctx.measureText(sub).width : 0;
    const w = Math.ceil(Math.max(tw, sw) + 56);
    const h = sub ? 136 : 92;
    canvas.width = w;
    canvas.height = h + 18;
    ctx.fillStyle = 'rgba(15,23,42,0.18)';
    roundRect(ctx, 4, 8, w - 8, h, 26);
    ctx.fill();
    ctx.fillStyle = color;
    roundRect(ctx, 2, 2, w - 4, h, 26);
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(w / 2 - 14, h);
    ctx.lineTo(w / 2, h + 16);
    ctx.lineTo(w / 2 + 14, h);
    ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.direction = 'rtl';
    ctx.font = `700 64px ${font}`;
    ctx.fillText(title, w / 2, sub ? 46 : h / 2 + 2);
    if (sub) {
        ctx.font = `600 34px ${font}`;
        ctx.globalAlpha = 0.9;
        ctx.fillText(sub, w / 2, 104);
    }
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 4;
    const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, depthWrite: false, transparent: true }));
    const k = 0.0042 * scale;
    sprite.scale.set(canvas.width * k, canvas.height * k, 1);
    sprite.center.set(0.5, 0);
    sprite.renderOrder = 10;
    return sprite;
}

/** Repeating chevrons for the route ribbon (scrolled every frame, like a satnav). */
function chevronTexture(colour = '#2563eb') {
    const c = document.createElement('canvas');
    c.width = 64;
    c.height = 64;
    const ctx = c.getContext('2d');
    ctx.fillStyle = colour;
    ctx.fillRect(0, 0, 64, 64);
    ctx.strokeStyle = 'rgba(255,255,255,0.95)';
    ctx.lineWidth = 9;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo(18, 44);
    ctx.lineTo(32, 22);
    ctx.lineTo(46, 44);
    ctx.stroke();
    const tex = new THREE.CanvasTexture(c);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.colorSpace = THREE.SRGBColorSpace;
    return tex;
}

/** A QR-looking square for the sticker plates (the real codes are on the printed stickers). */
function qrTexture() {
    const n = 21, px = 6, c = document.createElement('canvas');
    c.width = c.height = (n + 2) * px;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#111';
    const r = rng(7);
    const finder = (x, y) => (x < 7 && y < 7) || (x >= n - 7 && y < 7) || (x < 7 && y >= n - 7);
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            let on;
            if (finder(x, y)) {
                const fx = x < 7 ? x : x - (n - 7), fy = y < 7 ? y : y - (n - 7);
                on = fx === 0 || fx === 6 || fy === 0 || fy === 6 || (fx >= 2 && fx <= 4 && fy >= 2 && fy <= 4);
            } else on = r() < 0.48;
            if (on) ctx.fillRect((x + 1) * px, (y + 1) * px, px, px);
        }
    }
    const tex = new THREE.CanvasTexture(c);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.magFilter = THREE.NearestFilter;
    return tex;
}

function floorTexture(dark) {
    const c = document.createElement('canvas');
    c.width = c.height = 128;
    const ctx = c.getContext('2d');
    ctx.fillStyle = dark ? '#1f2937' : '#efe9df';
    ctx.fillRect(0, 0, 128, 128);
    ctx.strokeStyle = dark ? 'rgba(255,255,255,0.06)' : 'rgba(120,100,70,0.16)';
    ctx.lineWidth = 2;
    ctx.strokeRect(0, 0, 128, 128);
    const tex = new THREE.CanvasTexture(c);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.colorSpace = THREE.SRGBColorSpace;
    return tex;
}

export class HallScene {
    constructor(container, { dark = false, onPick = null, onMove = null, onContext = null, canDrag = null } = {}) {
        this.container = container;
        this.dark = dark;
        this.onPick = onPick;
        // In the map editor (2026-11-07, by request): bookcases and furniture can be dragged on the
        // floor in 3D too, and a right click opens the item's menu.
        this.onMove = onMove;
        this.onContext = onContext;
        this.canDrag = canDrag;
        this.items = new Map(); // id → { group, data, materials }
        this.clock = new THREE.Clock();
        this.mode = 'overview';

        const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.05;
        container.appendChild(renderer.domElement);
        renderer.domElement.style.display = 'block';
        // The drawing buffer is larger on high-density phones; the element itself fills the box.
        renderer.domElement.style.width = '100%';
        renderer.domElement.style.height = '100%';
        renderer.domElement.style.touchAction = 'none';
        this.renderer = renderer;

        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(45, 1, 0.1, 500);
        this.controls = new OrbitControls(this.camera, renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.08;
        this.controls.maxPolarAngle = Math.PI * 0.46;
        this.controls.minDistance = 2;
        this.controls.maxDistance = 200;
        this.controls.screenSpacePanning = false;

        this.root = new THREE.Group();
        this.scene.add(this.root);
        this.routeGroup = new THREE.Group();
        this.scene.add(this.routeGroup);
        this.fx = new THREE.Group();
        this.scene.add(this.fx);
        // «أين أقرب ملصق QR؟»: pulsing marks on the stickers to scan.
        this.beacons = new THREE.Group();
        this.scene.add(this.beacons);
        this.beaconIds = null;
        // «اشتباه غش» (2026-11-09): the ways from the invigilator's desk to the computers to look at.
        this.alertGroup = new THREE.Group();
        this.scene.add(this.alertGroup);

        const hemi = new THREE.HemisphereLight(0xffffff, 0x8a7f70, 1.6);
        this.scene.add(hemi);
        const sun = new THREE.DirectionalLight(0xffffff, 1.6);
        sun.castShadow = true;
        sun.shadow.mapSize.set(2048, 2048);
        sun.shadow.bias = -0.0005;
        sun.shadow.normalBias = 0.02;
        this.sun = sun;
        this.scene.add(sun, sun.target);

        this.avatar = this.makeAvatar();
        this.avatar.visible = false;
        this.scene.add(this.avatar);

        this.applyTheme(dark);

        this.raycaster = new THREE.Raycaster();
        this.pointer = new THREE.Vector2();
        this.downAt = null;
        this.onDown = (e) => (this.downAt = { x: e.clientX, y: e.clientY });
        this.onUp = (e) => {
            if (!this.downAt || Math.hypot(e.clientX - this.downAt.x, e.clientY - this.downAt.y) > 6) return;
            this.pick(e);
        };
        renderer.domElement.addEventListener('pointerdown', this.onDown);
        renderer.domElement.addEventListener('pointerup', this.onUp);
        this.dragging = null;
        this.floorPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
        // Capture phase: runs before the orbit controls, so grabbing a bookcase doesn't turn the room.
        this.onGrab = (e) => this.grab(e);
        this.onDragMove = (e) => this.dragMove(e);
        this.onDrop = (e) => this.drop(e);
        this.onMenu = (e) => {
            if (!this.onContext) return;
            e.preventDefault();
            const id = this.hitId(e);
            if (id !== null) this.onContext(id, e.clientX, e.clientY);
        };
        renderer.domElement.addEventListener('pointerdown', this.onGrab, { capture: true });
        renderer.domElement.addEventListener('pointermove', this.onDragMove);
        renderer.domElement.addEventListener('pointerup', this.onDrop);
        renderer.domElement.addEventListener('pointercancel', this.onDrop);
        renderer.domElement.addEventListener('contextmenu', this.onMenu);

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(container);
        this.resize();

        this.running = true;
        const loop = () => {
            if (!this.running) return;
            this.frame = requestAnimationFrame(loop);
            this.tick();
        };
        loop();
    }

    applyTheme(dark) {
        this.dark = dark;
        const bg = new THREE.Color(dark ? '#0b1220' : '#eef2f7');
        this.scene.background = bg;
        this.scene.fog = new THREE.Fog(bg, 40, 140);
        if (this.floorData) this.setLayout(this.floorData, [...this.items.values()].map((i) => i.data));
    }

    resize() {
        const w = this.container.clientWidth || 1;
        const h = this.container.clientHeight || 1;
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
    }

    /** Plan metres → world position (the room is centred on the origin). */
    world(x, y, height = 0) {
        return new THREE.Vector3(x - this.floorData.width / 2, height, y - this.floorData.depth / 2);
    }

    clearGroup(group) {
        group.traverse((o) => {
            o.geometry?.dispose?.();
            const mats = Array.isArray(o.material) ? o.material : o.material ? [o.material] : [];
            mats.forEach((m) => { m.map?.dispose?.(); m.dispose?.(); });
        });
        group.clear();
    }

    setLayout(floor, items) {
        this.floorData = floor;
        this.layoutItems = items;
        this.clearGroup(this.root);
        if (this.statusFx) this.clearGroup(this.statusFx);
        this.items.clear();
        this.labels = [];
        this.qrTex = null;
        const { width: W, depth: D } = floor;

        const halls = items.filter((i) => (i.kind === 'hall' || i.kind === 'corridor') && i.points?.length >= 3);
        // The overview frames what is drawn: the halls when there are any, else the room. Since
        // 2026-11-09 (by request: «more of the hall on the screen») only the halls with computers
        // in them, not the corridors round them.
        this.planFrame = { x0: 0, y0: 0, x1: W, y1: D };
        if (halls.length) {
            const pcs = items.filter((i) => i.kind === 'pc');
            const box = (h) => {
                const a = ((h.rotation || 0) * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
                const q = h.points.map(([px, py]) => ({ x: h.x + px * c - py * sn, y: h.y + px * sn + py * c }));
                return { x0: Math.min(...q.map((p) => p.x)), y0: Math.min(...q.map((p) => p.y)), x1: Math.max(...q.map((p) => p.x)), y1: Math.max(...q.map((p) => p.y)) };
            };
            const busy = halls.filter((h) => h.kind === 'hall' && pcs.some((p) => { const b = box(h); return p.x >= b.x0 && p.x <= b.x1 && p.y >= b.y0 && p.y <= b.y1; }));
            const pts = (busy.length ? busy : halls).flatMap((h) => {
                const a = ((h.rotation || 0) * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
                return h.points.map(([px, py]) => ({ x: h.x + px * c - py * sn, y: h.y + px * sn + py * c }));
            });
            this.planFrame = { x0: Math.min(...pts.map((p) => p.x)), y0: Math.min(...pts.map((p) => p.y)), x1: Math.max(...pts.map((p) => p.x)), y1: Math.max(...pts.map((p) => p.y)) };
            const changed = JSON.stringify(this.planFrame) !== this.lastFrame;
            this.lastFrame = JSON.stringify(this.planFrame);
            if (changed) this.framed = false;
            return this.setHalls(floor, items, halls);
        }

        // Floor and a low wall round the room.
        const tex = floorTexture(this.dark);
        tex.repeat.set(W / 1, D / 1);
        const ground = new THREE.Mesh(new THREE.PlaneGeometry(W, D), new THREE.MeshStandardMaterial({ map: tex, roughness: 0.92 }));
        ground.rotation.x = -Math.PI / 2;
        ground.receiveShadow = true;
        this.root.add(ground);
        const outside = new THREE.Mesh(new THREE.PlaneGeometry(W * 6, D * 6), new THREE.MeshStandardMaterial({ color: this.dark ? '#0f172a' : '#e2e8f0', roughness: 1 }));
        outside.rotation.x = -Math.PI / 2;
        outside.position.y = -0.01;
        this.root.add(outside);

        const wallMat = new THREE.MeshStandardMaterial({ color: this.dark ? '#334155' : '#cbd5e1', roughness: 0.8 });
        const wallH = 0.5, t = 0.12;
        const ent = floor.entrance;
        const addWall = (x1, y1, x2, y2) => {
            const len = Math.hypot(x2 - x1, y2 - y1);
            if (len < 0.05) return;
            const m = new THREE.Mesh(new THREE.BoxGeometry(len + t, wallH, t), wallMat);
            m.position.copy(this.world((x1 + x2) / 2, (y1 + y2) / 2, wallH / 2));
            m.rotation.y = -Math.atan2(y2 - y1, x2 - x1);
            m.castShadow = m.receiveShadow = true;
            this.root.add(m);
        };
        // Leave a 1.6 m doorway in whichever outer wall the entrance sits on.
        const gap = 0.8;
        const near = (v, e) => Math.abs(v - e) < 0.6;
        const edge = near(ent.y, D) ? 'bottom' : near(ent.y, 0) ? 'top' : near(ent.x, 0) ? 'left' : near(ent.x, W) ? 'right' : null;
        if (edge === 'top') { addWall(0, 0, ent.x - gap, 0); addWall(ent.x + gap, 0, W, 0); } else addWall(0, 0, W, 0);
        if (edge === 'bottom') { addWall(0, D, ent.x - gap, D); addWall(ent.x + gap, D, W, D); } else addWall(0, D, W, D);
        if (edge === 'left') { addWall(0, 0, 0, ent.y - gap); addWall(0, ent.y + gap, 0, D); } else addWall(0, 0, 0, D);
        if (edge === 'right') { addWall(W, 0, W, ent.y - gap); addWall(W, ent.y + gap, W, D); } else addWall(W, 0, W, D);

        this.finishLayout(floor, items);
    }

    /**
     * «قاعات وغرف» (2026-11-06, by request): a library of several halls and rooms. Each hall gets
     * its own floor, low walls along its sides with gaps where doors are, and its name.
     */
    setHalls(floor, items, halls) {
        const outside = new THREE.Mesh(new THREE.PlaneGeometry(floor.width * 6, floor.depth * 6), new THREE.MeshStandardMaterial({ color: this.dark ? '#0f172a' : '#e2e8f0', roughness: 1 }));
        outside.rotation.x = -Math.PI / 2;
        outside.position.y = -0.01;
        outside.receiveShadow = true;
        this.root.add(outside);
        const doors = items.filter((i) => i.kind === 'door');
        const wallMat = new THREE.MeshStandardMaterial({ color: this.dark ? '#475569' : '#cbd5e1', roughness: 0.8 });
        const capMat = new THREE.MeshStandardMaterial({ color: this.dark ? '#64748b' : '#94a3b8', roughness: 0.6 });
        const wallH = 1.0, t = 0.15;
        const plan = (it) => {
            const a = ((it.rotation || 0) * Math.PI) / 180, c = Math.cos(a), sn = Math.sin(a);
            return it.points.map(([px, py]) => ({ x: it.x + px * c - py * sn, y: it.y + px * sn + py * c }));
        };
        const wall = (x1, y1, x2, y2) => {
            const len = Math.hypot(x2 - x1, y2 - y1);
            if (len < 0.05) return;
            const m = new THREE.Mesh(new THREE.BoxGeometry(len + t * 0.5, wallH, t), wallMat);
            m.position.copy(this.world((x1 + x2) / 2, (y1 + y2) / 2, wallH / 2));
            m.rotation.y = -Math.atan2(y2 - y1, x2 - x1);
            m.castShadow = m.receiveShadow = true;
            this.root.add(m);
            const cap = new THREE.Mesh(new THREE.BoxGeometry(len + t * 0.5, 0.04, t + 0.02), capMat);
            cap.position.copy(m.position).setY(wallH + 0.02);
            cap.rotation.y = m.rotation.y;
            this.root.add(cap);
        };
        const tints = this.dark ? ['#1f2937', '#22303f', '#272b3a', '#1f3330', '#2d2a24'] : ['#efe9df', '#e6eef5', '#efe6ef', '#e5f0e8', '#f3ecdc'];
        // Halls first, corridors on top (a corridor is usually drawn across hall edges).
        halls.sort((a, b) => (a.kind === 'corridor') - (b.kind === 'corridor')).forEach((h, idx) => {
            const corridor = h.kind === 'corridor';
            const pts = plan(h);
            const shape = new THREE.Shape(pts.map((p) => new THREE.Vector2(p.x - floor.width / 2, -(p.y - floor.depth / 2))));
            const tex = floorTexture(this.dark);
            const geo = new THREE.ShapeGeometry(shape);
            geo.rotateX(-Math.PI / 2);
            const ground = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({ map: tex, color: corridor ? (this.dark ? '#374151' : '#d9dee5') : tints[idx % tints.length], roughness: 0.92 }));
            ground.position.y = 0.001 * (idx % 8);
            ground.receiveShadow = true;
            this.root.add(ground);
            // Walls along each side, leaving a gap for every door on it.
            pts.forEach((a, i) => {
                const b = pts[(i + 1) % pts.length];
                const len = Math.hypot(b.x - a.x, b.y - a.y);
                if (len < 0.05) return;
                const ux = (b.x - a.x) / len, uy = (b.y - a.y) / len;
                const gaps = doors.map((d) => {
                    const along = (d.x - a.x) * ux + (d.y - a.y) * uy;
                    const off = Math.abs(-(d.x - a.x) * uy + (d.y - a.y) * ux);
                    return off < 0.4 && along > -d.width / 2 && along < len + d.width / 2 ? [along - d.width / 2, along + d.width / 2] : null;
                }).filter(Boolean).sort((p, q) => p[0] - q[0]);
                let from = 0;
                for (const [g0, g1] of gaps) {
                    if (g0 > from) wall(a.x + ux * from, a.y + uy * from, a.x + ux * g0, a.y + uy * g0);
                    from = Math.max(from, g1);
                }
                if (from < len) wall(a.x + ux * from, a.y + uy * from, b.x, b.y);
            });
            const label = labelSprite(h.code || '', h.name || null, corridor ? '#64748b' : '#334155', { scale: corridor ? 0.8 : 1.3 });
            label.position.copy(this.world(h.x, h.y, corridor ? 1.6 : 2.9));
            label.userData.baseScale = label.scale.clone();
            label.userData.hall = true;
            this.root.add(label);
            this.labels.push(label);
        });
        // Doors: a green threshold and a frame post on each side.
        const sill = new THREE.MeshStandardMaterial({ color: '#10b981', roughness: 0.6, transparent: true, opacity: 0.85 });
        const frame = new THREE.MeshStandardMaterial({ color: this.dark ? '#a47148' : '#8b5e34', roughness: 0.6 });
        for (const d of doors) {
            const g = new THREE.Group();
            g.position.copy(this.world(d.x, d.y));
            g.rotation.y = -((d.rotation || 0) * Math.PI) / 180;
            const th = new THREE.Mesh(new THREE.BoxGeometry(d.width, 0.02, 0.5), sill);
            th.position.y = 0.012;
            g.add(th);
            for (const side of [1, -1]) {
                const post = new THREE.Mesh(new THREE.BoxGeometry(0.08, wallH + 0.25, t + 0.06), frame);
                post.position.set(side * (d.width / 2), (wallH + 0.25) / 2, 0);
                post.castShadow = true;
                g.add(post);
            }
            this.root.add(g);
        }
        this.finishLayout(floor, items);
    }

    finishLayout(floor, items) {
        const { width: W, depth: D } = floor;
        const ent = floor.entrance;
        // Entrance mat and label.
        const mat = new THREE.Mesh(new THREE.CircleGeometry(0.7, 40), new THREE.MeshBasicMaterial({ color: '#10b981', transparent: true, opacity: 0.85 }));
        mat.rotation.x = -Math.PI / 2;
        mat.position.copy(this.world(ent.x, ent.y, 0.012));
        this.root.add(mat);
        const entLabel = labelSprite(this.words?.entrance ?? 'المدخل', null, '#059669', { scale: 0.8 });
        entLabel.position.copy(this.world(ent.x, ent.y, 1.1));
        this.root.add(entLabel);

        // Books on every bookcase share one instanced mesh.
        const shelves = items.filter((i) => (i.kind || 'shelf') === 'shelf');
        let bookCount = 0;
        const bookSpots = [];
        // With the library's real stock (2026-11-07, by request) every copy is one book on its
        // level; copies with no level fill the levels from eye height down. A bookcase with no copies
        // on it is empty, even when no book has a place yet (by request). Only the map editor, which
        // has no stock, fills them for the look.
        const real = !!this.stock;
        for (const s of shelves) {
            const r = rng(s.id ? Number(s.id) * 13 + 7 : Math.round(s.x * 31 + s.y * 17));
            const faces = s.double_sided ? [1, -1] : [1];
            const bd = Math.min(0.24, (s.double_sided ? s.depth / 2 : s.depth) - 0.08);
            const spine = (face, lv, x, bw) => bookSpots.push({ s, face, lv, x: x + bw / 2, bw, bh: 0.2 + r() * 0.11, bd, c: BOOK_COLORS[Math.floor(r() * BOOK_COLORS.length)], lean: r() < 0.04 ? (r() - 0.5) * 0.5 : 0 });
            if (real) {
                const st = this.stock[s.id];
                if (!st) continue;
                const per = Array.from({ length: s.levels }, (_, i) => st.levels?.[i + 1] ?? 0);
                const order = Array.from({ length: s.levels }, (_, i) => i).sort((a, b) => Math.abs(a - 2.5) - Math.abs(b - 2.5));
                for (let k = 0; k < (st.levels?.[0] ?? 0); k++) per[order[k % order.length]]++;
                per.forEach((n, lv) => {
                    let x = -s.width / 2 + 0.06, f = 0;
                    for (let k = 0; k < n && f < faces.length; k++) {
                        const bw = 0.025 + r() * 0.045;
                        if (x + bw > s.width / 2 - 0.06) { f++; x = -s.width / 2 + 0.06; if (f >= faces.length) break; }
                        spine(faces[f], lv, x, bw);
                        x += bw + 0.002;
                    }
                });
                continue;
            }
            for (const face of faces) {
                for (let lv = 0; lv < s.levels; lv++) {
                    let x = -s.width / 2 + 0.06;
                    while (x < s.width / 2 - 0.08) {
                        const bw = 0.025 + r() * 0.045;
                        if (r() < 0.06) { x += bw * 2; continue; } // a gap now and then
                        spine(face, lv, x, bw);
                        x += bw + 0.002;
                    }
                }
            }
        }
        bookCount = bookSpots.length;

        for (const item of items) if (!['hall', 'corridor', 'door'].includes(item.kind)) this.addItem(item);
        if (this.beaconIds) this.setBeacons(this.beaconIds, this.beaconNearest);

        if (bookCount) {
            const geo = new THREE.BoxGeometry(1, 1, 1);
            geo.translate(0, 0.5, 0);
            const books = new THREE.InstancedMesh(geo, new THREE.MeshStandardMaterial({ roughness: 0.7 }), bookCount);
            const m4 = new THREE.Matrix4(), q = new THREE.Quaternion(), e = new THREE.Euler(), p = new THREE.Vector3(), sc = new THREE.Vector3(), col = new THREE.Color();
            bookSpots.forEach((b, i) => {
                const { group } = this.items.get(b.s.id ?? b.s._key);
                const local = new THREE.Vector3(b.x, 0.1 + b.lv * LEVEL_H + 0.02, b.face * ((b.s.double_sided ? b.s.depth / 4 : 0) + 0.02));
                p.copy(local).applyMatrix4(group.matrixWorld);
                e.set(0, group.rotation.y, b.lean);
                q.setFromEuler(e);
                sc.set(b.bw, b.bh, b.bd);
                m4.compose(p, q, sc);
                books.setMatrixAt(i, m4);
                books.setColorAt(i, col.set(b.c));
            });
            books.castShadow = true;
            books.receiveShadow = true;
            this.root.add(books);
        }

        // Sun and shadow camera sized to the room.
        const span = Math.max(W, D);
        this.sun.position.set(-W * 0.3, span * 1.1, D * 0.45);
        this.sun.target.position.set(0, 0, 0);
        Object.assign(this.sun.shadow.camera, { left: -span * 0.75, right: span * 0.75, top: span * 0.75, bottom: -span * 0.75, near: 0.5, far: span * 3 });
        this.sun.shadow.camera.updateProjectionMatrix();

        if (!this.framed) { this.overview(false); this.framed = true; }
        if (this.highlighted) this.highlight(this.highlighted.id, this.highlighted.level);
    }

    addItem(item) {
        const kind = item.kind || 'shelf';
        const group = new THREE.Group();
        group.position.copy(this.world(item.x, item.y));
        group.rotation.y = -((item.rotation || 0) * Math.PI) / 180;
        group.updateMatrixWorld(true);
        const accent = item.color || (kind === 'gate' ? '#dc2626' : kind === 'stairs' ? '#7c3aed' : sectionColor(item.section_id));
        const h = shelfHeight(item);
        const wood = new THREE.MeshStandardMaterial({ color: kind === 'wall' ? (this.dark ? '#475569' : '#e5e7eb') : this.dark ? '#7c5a3a' : '#a47148', roughness: 0.75 });
        const accentMat = new THREE.MeshStandardMaterial({ color: accent, roughness: 0.5, emissive: new THREE.Color(accent), emissiveIntensity: 0 });
        const box = (w, hh, d, x, y, z, m = wood) => {
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(w, hh, d), m);
            mesh.position.set(x, y, z);
            mesh.castShadow = mesh.receiveShadow = true;
            mesh.userData.itemId = item.id ?? item._key;
            group.add(mesh);
            return mesh;
        };
        const W = item.width, D = item.depth;
        if (kind === 'shelf') {
            const th = 0.03;
            box(th, h, D, -W / 2 + th / 2, h / 2, 0);
            box(th, h, D, W / 2 - th / 2, h / 2, 0);
            box(W, th, D, 0, h - th / 2, 0, accentMat); // coloured top: the section colour
            box(W, 0.1, D, 0, 0.05, 0);
            if (item.double_sided) box(W - 2 * th, h - 0.1, 0.02, 0, h / 2 + 0.05, 0);
            else box(W - 2 * th, h - 0.1, 0.02, 0, h / 2 + 0.05, -D / 2 + 0.01);
            for (let lv = 1; lv < item.levels; lv++) box(W - 2 * th, 0.022, D, 0, 0.1 + lv * LEVEL_H, 0);
            // A coloured strip on each face so the section reads from far away.
            for (const f of item.double_sided ? [1, -1] : [1]) box(W, 0.06, 0.012, 0, h - 0.06, f * (D / 2 + 0.006), accentMat);
            // Its QR sticker: on the front, in the middle, at eye level.
            const qy = Math.min(h - 0.2, 1.45);
            const plate = new THREE.Mesh(new THREE.PlaneGeometry(0.16, 0.16), new THREE.MeshBasicMaterial({ map: this.qrTexture() }));
            plate.position.set(0, qy, D / 2 + 0.008);
            group.add(plate);
        } else if (kind === 'pc') {
            // «حاسبة»: a desk, the screen (its colour is the computer's state) and the tower.
            const top = 0.75;
            box(W, 0.04, D, 0, top - 0.02, 0);
            for (const sx of [1, -1]) box(0.04, top - 0.04, D - 0.06, sx * (W / 2 - 0.04), (top - 0.04) / 2, 0);
            const frame = new THREE.MeshStandardMaterial({ color: this.dark ? '#1f2937' : '#111827', roughness: 0.4 });
            const sw = Math.min(0.56, W * 0.6);
            const screenMat = new THREE.MeshStandardMaterial({ color: '#64748b', emissive: new THREE.Color('#64748b'), emissiveIntensity: 0.25, roughness: 0.3 });
            // A two-faced computer (2026-11-09, by request): a screen, keyboard and chair on each side.
            const seat = new THREE.MeshStandardMaterial({ color: this.dark ? '#64748b' : '#3b82f6', roughness: 0.6 });
            const leg = new THREE.MeshStandardMaterial({ color: this.dark ? '#94a3b8' : '#334155', roughness: 0.5 });
            for (const f of item.double_sided ? [1, -1] : [1]) {
                const back = item.double_sided ? -f * D * 0.08 : -D * 0.2; // the screen stands behind the keyboard
                box(0.06, 0.16, 0.06, 0, top + 0.08, back, frame);
                box(sw + 0.04, 0.36, 0.04, 0, top + 0.34, back, frame);
                const screen = new THREE.Mesh(new THREE.PlaneGeometry(sw, 0.32), screenMat);
                screen.position.set(0, top + 0.34, back + f * 0.022);
                if (f < 0) screen.rotation.y = Math.PI;
                screen.userData.itemId = item.id ?? item._key;
                group.add(screen);
                box(0.42, 0.02, 0.14, 0, top + 0.01, f * D * (item.double_sided ? 0.32 : 0.18), frame);
                // The student's chair, in front of this side: seat, back and stem.
                box(0.46, 0.06, 0.44, 0, 0.46, f * (D / 2 + 0.32), seat);
                box(0.46, 0.42, 0.06, 0, 0.7, f * (D / 2 + 0.53), seat);
                box(0.06, 0.43, 0.06, 0, 0.22, f * (D / 2 + 0.32), leg);
                box(0.4, 0.03, 0.06, 0, 0.02, f * (D / 2 + 0.32), leg);
                box(0.06, 0.03, 0.4, 0, 0.02, f * (D / 2 + 0.32), leg);
            }
            box(0.18, 0.4, 0.42, W / 2 - 0.16, 0.2, item.double_sided ? 0 : -D * 0.05, frame);
            group.userData.screenMat = screenMat;
        } else if (kind === 'desk') {
            box(W, 0.05, D, 0, h - 0.025, 0, accentMat);
            box(W, h - 0.05, 0.04, 0, (h - 0.05) / 2, D / 2 - 0.05);
            box(0.05, h - 0.05, D, -W / 2 + 0.03, (h - 0.05) / 2, 0);
            box(0.05, h - 0.05, D, W / 2 - 0.03, (h - 0.05) / 2, 0);
        } else if (kind === 'table') {
            box(W, 0.05, D, 0, h - 0.025, 0);
            for (const [sx, sz] of [[1, 1], [1, -1], [-1, 1], [-1, -1]]) box(0.05, h - 0.05, 0.05, sx * (W / 2 - 0.08), (h - 0.05) / 2, sz * (D / 2 - 0.08));
        } else if (kind === 'obstacle' && item.points?.length >= 3) {
            // The outline as drawn on the plan, raised to its height (plan y runs along local +z).
            const shape = new THREE.Shape(item.points.map(([px, py]) => new THREE.Vector2(px, -py)));
            const geo = new THREE.ExtrudeGeometry(shape, { depth: h, bevelEnabled: false });
            geo.rotateX(-Math.PI / 2);
            const mat = new THREE.MeshStandardMaterial({ color: item.color || (this.dark ? '#64748b' : '#9ca3af'), roughness: 0.85 });
            const mesh = new THREE.Mesh(geo, mat);
            mesh.castShadow = mesh.receiveShadow = true;
            mesh.userData.itemId = item.id ?? item._key;
            group.add(mesh);
            // Yellow-black hazard band round the top edge.
            const band = new THREE.Mesh(new THREE.ExtrudeGeometry(shape, { depth: 0.06, bevelEnabled: false }).rotateX(-Math.PI / 2), new THREE.MeshStandardMaterial({ color: '#facc15', roughness: 0.6 }));
            band.position.y = h;
            band.userData.itemId = mesh.userData.itemId;
            group.add(band);
        } else if (kind === 'gate') {
            // Two slim posts with a light strip on top; the passage between them stays open.
            const post = new THREE.MeshStandardMaterial({ color: this.dark ? '#cbd5e1' : '#f1f5f9', roughness: 0.35, metalness: 0.3, transparent: true, opacity: 0.92 });
            const light = new THREE.MeshStandardMaterial({ color: '#ef4444', emissive: '#ef4444', emissiveIntensity: 0.8 });
            for (const side of [1, -1]) {
                const px = side * (W / 2 - 0.075);
                box(0.15, 0.06, D + 0.05, px, 0.03, 0, wood);
                box(0.08, h, D, px, h / 2, 0, post);
                box(0.1, 0.06, D + 0.02, px, h - 0.03, 0, light);
            }
        } else if (kind === 'qr') {
            // «ملصق QR» on a wall: a plate facing the room (the hall walls here are 1 m high).
            const plate = new THREE.Mesh(new THREE.BoxGeometry(0.26, 0.26, 0.02), new THREE.MeshStandardMaterial({ color: '#fff', roughness: 0.4 }));
            plate.position.set(0, 0.72, 0.03);
            const face = new THREE.Mesh(new THREE.PlaneGeometry(0.22, 0.22), new THREE.MeshBasicMaterial({ map: this.qrTexture() }));
            face.position.set(0, 0.72, 0.041);
            group.add(plate, face);
        } else if (kind === 'stairs' && item.double_sided) {
            // «مصعد»: a lift car with its doors on the front.
            const shaft = new THREE.MeshStandardMaterial({ color: this.dark ? '#475569' : '#cbd5e1', roughness: 0.35, metalness: 0.4 });
            box(W, 2.3, D, 0, 1.15, 0, shaft);
            const doorMat = new THREE.MeshStandardMaterial({ color: '#94a3b8', roughness: 0.25, metalness: 0.7 });
            box(W * 0.36, 2, 0.03, -W * 0.185, 1, D / 2 + 0.016, doorMat);
            box(W * 0.36, 2, 0.03, W * 0.185, 1, D / 2 + 0.016, doorMat);
            box(W * 0.8, 0.12, 0.04, 0, 2.15, D / 2 + 0.02, accentMat);
        } else if (kind === 'stairs') {
            // «درج / مصعد»: a flight of steps going up along the item's depth.
            const n = 8, stepMat = new THREE.MeshStandardMaterial({ color: this.dark ? '#94a3b8' : '#d6d3d1', roughness: 0.7 });
            for (let k = 0; k < n; k++) box(W, (h / n) * (k + 1), D / n, 0, ((h / n) * (k + 1)) / 2, D / 2 - (D / n) * (k + 0.5), stepMat);
            box(0.04, 0.9, D, -W / 2, h / 2 + 0.45, 0, accentMat);
        } else {
            box(W, h, D, 0, h / 2, 0);
        }

        let sticker = null;
        if (kind === 'pc') {
            const local = new THREE.Vector3(-Math.min(0.56, W * 0.6) / 2 - 0.02, 0.86, -D * 0.2 + 0.03);
            sticker = { pos: local.applyMatrix4(group.matrixWorld), normal: new THREE.Vector3(0, 0, 1).applyAxisAngle(new THREE.Vector3(0, 1, 0), group.rotation.y) };
        } else if (kind === 'qr' || kind === 'shelf') {
            const local = new THREE.Vector3(0, kind === 'qr' ? 0.72 : Math.min(h - 0.2, 1.45), kind === 'qr' ? 0.05 : D / 2 + 0.01);
            sticker = { pos: local.applyMatrix4(group.matrixWorld), normal: new THREE.Vector3(0, 0, 1).applyAxisAngle(new THREE.Vector3(0, 1, 0), group.rotation.y) };
        }
        if (kind !== 'wall' && kind !== 'obstacle' && kind !== 'qr') {
            const label = labelSprite(item.code || '', kind === 'shelf' ? item.section || item.name : item.name, accent, { scale: kind === 'shelf' ? 1 : 0.75 });
            label.position.set(0, h + 0.12, 0);
            label.userData.itemId = item.id ?? item._key;
            label.userData.baseScale = label.scale.clone();
            group.add(label);
            this.labels.push(label);
        }
        this.root.add(group);
        this.items.set(item.id ?? item._key, { group, data: item, accentMat, height: h, sticker, screenMat: group.userData.screenMat ?? null });
        if (this.statuses) this.paintStatus(item.id ?? item._key);
    }

    /**
     * The computers' states (exam halls): id → { color, alert }. The screen takes the colour;
     * a computer with a problem gets a pulsing ring on the floor round it.
     */
    setStatuses(map) {
        this.statuses = map || null;
        this.statusFx ??= new THREE.Group();
        if (!this.statusFx.parent) this.scene.add(this.statusFx);
        this.clearGroup(this.statusFx);
        for (const id of this.items.keys()) this.paintStatus(id);
    }

    paintStatus(id) {
        const it = this.items.get(id);
        if (!it?.screenMat) return;
        const st = this.statuses?.[id];
        const color = st?.color || '#64748b';
        it.screenMat.color.set(color);
        it.screenMat.emissive.set(color);
        it.screenMat.emissiveIntensity = st ? 0.55 : 0.2;
        it.accentMat.color.set(color);
        const s0 = it.data;
        // «متصلة بالسيرفر» (2026-11-09): a green light on top of the screen that beats while the
        // computer talks to the server; grey when it does not.
        if (this.statusFx && st && st.link !== undefined && st.link !== null) {
            const a = ((s0.rotation || 0) * Math.PI) / 180;
            const back = s0.double_sided ? 0 : -s0.depth * 0.2;
            const led = new THREE.Mesh(new THREE.SphereGeometry(0.07, 16, 12), new THREE.MeshBasicMaterial({ color: st.link ? '#22c55e' : '#9ca3af', transparent: true }));
            led.position.copy(this.world(s0.x - Math.sin(a) * back, s0.y + Math.cos(a) * back, 1.32));
            if (st.link) {
                led.userData.beat = { speed: 1.6 };
                const wave = new THREE.Mesh(new THREE.RingGeometry(0.08, 0.11, 24), new THREE.MeshBasicMaterial({ color: '#22c55e', transparent: true, side: THREE.DoubleSide, depthWrite: false }));
                wave.position.copy(led.position);
                wave.rotation.x = -Math.PI / 2;
                wave.userData.pulse = { base: 1, speed: 1.6, grow: 2.8 };
                this.statusFx.add(wave);
            }
            this.statusFx.add(led);
        }
        if (st?.suspect && this.statusFx) {
            // A purple marker bouncing over a computer under suspicion.
            const mark = new THREE.Mesh(new THREE.ConeGeometry(0.22, 0.45, 24), new THREE.MeshStandardMaterial({ color: '#9333ea', emissive: '#7e22ce', emissiveIntensity: 0.6 }));
            mark.rotation.x = Math.PI;
            mark.position.copy(this.world(s0.x, s0.y, 2.1));
            mark.userData.bob = 2.1;
            this.statusFx.add(mark);
        }
        if (st?.alert && this.statusFx) {
            const s = it.data;
            const ring = new THREE.Mesh(new THREE.RingGeometry(0.45, 0.62, 40), new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.8, side: THREE.DoubleSide, depthWrite: false }));
            ring.rotation.x = -Math.PI / 2;
            ring.position.copy(this.world(s.x, s.y, 0.02));
            ring.userData.pulse = { base: Math.max(s.width, s.depth) * 0.8, speed: 1.2, floor: true };
            this.statusFx.add(ring);
        }
    }

    qrTexture() {
        this.qrTex ??= qrTexture();
        return this.qrTex;
    }

    /**
     * «أين أقرب ملصق؟»: a pulsing halo round each sticker in `ids`, a ring on the floor where to
     * stand, and a bouncing arrow over the nearest one with «امسحني». Null clears them.
     */
    setBeacons(ids, nearestId = null) {
        this.clearGroup(this.beacons);
        this.beaconIds = ids;
        this.beaconNearest = nearestId;
        if (!ids || !this.floorData) return;
        for (const id of ids) {
            const it = this.items.get(id);
            if (!it?.sticker) continue;
            const near = id === nearestId;
            const color = near ? '#f97316' : '#06b6d4';
            const { pos, normal } = it.sticker;
            const g = new THREE.Group();
            g.userData.near = near;
            // Halo on the sticker, facing out of it.
            const halo = new THREE.Mesh(new THREE.RingGeometry(0.13, 0.2, 40), new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.9, side: THREE.DoubleSide, depthWrite: false, depthTest: false }));
            halo.position.copy(pos).addScaledVector(normal, 0.02);
            halo.lookAt(pos.clone().add(normal));
            halo.renderOrder = 10;
            halo.userData.pulse = { base: near ? 1.2 : 1, speed: near ? 1.1 : 1.6 };
            g.add(halo);
            // Where to stand: a ring on the floor in front of it.
            const stand = pos.clone().addScaledVector(normal, 0.6).setY(0.02);
            const ring = new THREE.Mesh(new THREE.RingGeometry(0.22, 0.3, 40), new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.7, side: THREE.DoubleSide, depthWrite: false }));
            ring.rotation.x = -Math.PI / 2;
            ring.position.copy(stand);
            ring.userData.pulse = { base: near ? 1.5 : 1, speed: 1.4, floor: true };
            g.add(ring);
            if (near) {
                const arrow = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.36, 24), new THREE.MeshStandardMaterial({ color, emissive: color, emissiveIntensity: 0.5 }));
                arrow.rotation.x = Math.PI;
                arrow.position.copy(pos).addScaledVector(normal, 0.15).setY(pos.y + 0.55);
                arrow.userData.bob = pos.y + 0.55;
                g.add(arrow);
                const label = labelSprite(this.words?.scan ?? 'امسحني', null, color, { scale: 0.6 });
                label.position.copy(pos).addScaledVector(normal, 0.15).setY(pos.y + 1.05);
                g.add(label);
            }
            this.beacons.add(g);
        }
    }

    aim(e) {
        const rect = this.renderer.domElement.getBoundingClientRect();
        this.pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
        this.raycaster.setFromCamera(this.pointer, this.camera);
    }

    hitId(e) {
        this.aim(e);
        const hit = this.raycaster.intersectObjects(this.root.children, true).find((h) => h.object.userData.itemId !== undefined);
        return hit ? hit.object.userData.itemId : null;
    }

    pick(e) {
        if (!this.onPick) return;
        this.onPick(this.hitId(e));
    }

    /** Plan point under the pointer, on the floor. */
    floorPoint(e) {
        this.aim(e);
        const v = new THREE.Vector3();
        if (!this.raycaster.ray.intersectPlane(this.floorPlane, v)) return null;
        return { x: v.x + this.floorData.width / 2, y: v.z + this.floorData.depth / 2 };
    }

    grab(e) {
        if (!this.onMove || e.button !== 0 || !e.isPrimary) return;
        const id = this.hitId(e);
        const it = id !== null ? this.items.get(id) : null;
        if (!it || ['hall', 'corridor', 'door', 'qr'].includes(it.data.kind) || (this.canDrag && !this.canDrag(id))) return;
        const p = this.floorPoint(e);
        if (!p) return;
        this.controls.enabled = false;
        // «محصور داخل الغرفة» (2026-11-07, by request): a bookcase dragged in 3D stays in the hall it
        // stands in (Alt lets it go to another room).
        const halls = (this.layoutItems ?? []).filter((h) => ['hall', 'corridor'].includes(h.kind) && h.points?.length >= 3).map((h) => ({ h, poly: outline(h) }));
        let room = halls.filter(({ poly }) => inPoly(it.data.x, it.data.y, poly)).sort((a, b) => a.h.width * a.h.depth - b.h.width * b.h.depth)[0]?.poly ?? null;
        // One already poking through a wall is not held (it could not move at all).
        if (room && !footprint(it.data).every((q) => inPoly(q.x, q.y, room))) room = null;
        this.dragging = { id, it, room, dx: it.data.x - p.x, dy: it.data.y - p.y, x: it.data.x, y: it.data.y, moved: false, start: { x: e.clientX, y: e.clientY } };
        this.renderer.domElement.setPointerCapture?.(e.pointerId);
        this.container.style.cursor = 'grabbing';
    }

    dragMove(e) {
        const d = this.dragging;
        if (!d) return;
        if (!d.moved && Math.hypot(e.clientX - d.start.x, e.clientY - d.start.y) < 5) return;
        d.moved = true;
        const p = this.floorPoint(e);
        if (!p) return;
        const snap = (v) => Math.round(v * 4) / 4;
        const nx = Math.min(this.floorData.width, Math.max(0, snap(p.x + d.dx)));
        const ny = Math.min(this.floorData.depth, Math.max(0, snap(p.y + d.dy)));
        if (d.room && !e.altKey) {
            const fits = (x, y) => footprint({ ...d.it.data, x, y }).every((q) => inPoly(q.x, q.y, d.room));
            // Slide along the wall when only one direction is blocked.
            if (fits(nx, ny)) { d.x = nx; d.y = ny; }
            else if (fits(nx, d.y)) d.x = nx;
            else if (fits(d.x, ny)) d.y = ny;
        } else {
            d.x = nx;
            d.y = ny;
        }
        d.it.group.position.copy(this.world(d.x, d.y));
        this.onDragTip?.(d.id, d.x, d.y, e.clientX, e.clientY);
    }

    drop() {
        const d = this.dragging;
        if (!d) return;
        this.dragging = null;
        this.controls.enabled = this.mode !== 'follow';
        this.container.style.cursor = '';
        this.onDragTip?.(null);
        if (d.moved) this.onMove(d.id, d.x, d.y);
    }

    /** Marks the target bookcase: section colour glows, a pin bobs over it, the level lights up. */
    highlight(id, level = null, cover = null) {
        this.clearGroup(this.fx);
        for (const it of this.items.values()) it.accentMat.emissiveIntensity = 0;
        this.highlighted = id != null ? { id, level, cover } : null;
        const it = id != null ? this.items.get(id) : null;
        if (!it) return;
        it.accentMat.emissiveIntensity = 0.6;
        const s = it.data;
        const pin = new THREE.Group();
        const head = new THREE.Mesh(new THREE.SphereGeometry(0.22, 32, 16), new THREE.MeshStandardMaterial({ color: '#ef4444', emissive: '#ef4444', emissiveIntensity: 0.35, roughness: 0.3 }));
        head.position.y = 0.55;
        const tip = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.45, 32), head.material);
        tip.rotation.x = Math.PI;
        tip.position.y = 0.25;
        const dot = new THREE.Mesh(new THREE.SphereGeometry(0.08, 16, 8), new THREE.MeshBasicMaterial({ color: '#fff' }));
        dot.position.set(0, 0.55, 0.19);
        pin.add(head, tip, dot);
        pin.position.copy(this.world(s.x, s.y, it.height + 0.85));
        this.pin = pin;
        this.fx.add(pin);

        // Pulsing ring on the floor round the bookcase.
        const ring = new THREE.Mesh(new THREE.RingGeometry(0.55, 0.75, 48), new THREE.MeshBasicMaterial({ color: '#ef4444', transparent: true, opacity: 0.6, side: THREE.DoubleSide, depthWrite: false }));
        ring.rotation.x = -Math.PI / 2;
        ring.position.copy(this.world(s.x, s.y, 0.015));
        ring.scale.setScalar(Math.max(s.width, s.depth) * 0.9);
        this.ring = ring;
        this.fx.add(ring);

        if (level) {
            const glow = new THREE.Mesh(
                new THREE.BoxGeometry(s.width + 0.04, LEVEL_H - 0.02, s.depth + 0.04),
                new THREE.MeshBasicMaterial({ color: '#facc15', transparent: true, opacity: 0.32, depthWrite: false }),
            );
            glow.position.copy(this.world(s.x, s.y, 0.1 + (level - 1) * LEVEL_H + LEVEL_H / 2));
            glow.rotation.y = it.group.rotation.y;
            this.levelGlow = glow;
            this.fx.add(glow);
        } else this.levelGlow = null;
        if (cover && (s.kind || 'shelf') === 'shelf') this.showCover(it, level, cover);
    }

    /** The book's cover stood face-out on the front of its level, so the reader knows it on sight. */
    showCover(it, level, url) {
        const s = it.data;
        const lv = Math.min(s.levels, Math.max(1, level || Math.min(s.levels, 3)));
        const want = this.highlighted;
        new THREE.TextureLoader().load(url, (tex) => {
            if (this.highlighted !== want || !this.items.has(s.id)) { tex.dispose(); return; }
            tex.colorSpace = THREE.SRGBColorSpace;
            const img = tex.image;
            const h = Math.min(0.3, LEVEL_H - 0.05);
            const w = Math.min(0.26, h * ((img?.width || 2) / (img?.height || 3)));
            const faces = s.double_sided ? [1, -1] : [1];
            for (const face of faces) {
                const g = new THREE.Group();
                const back = new THREE.Mesh(new THREE.PlaneGeometry(w + 0.025, h + 0.025), new THREE.MeshBasicMaterial({ color: '#ffffff' }));
                const front = new THREE.Mesh(new THREE.PlaneGeometry(w, h), new THREE.MeshBasicMaterial({ map: tex }));
                front.position.z = 0.002;
                g.add(back, front);
                // Leaning back a little against the books, on the front edge of the level.
                g.position.set(0, 0.1 + (lv - 1) * LEVEL_H + 0.02 + h / 2, face * (s.depth / 2 + 0.015));
                g.rotation.set(-0.08 * face, face < 0 ? Math.PI : 0, 0, 'YXZ');
                g.applyMatrix4(it.group.matrixWorld);
                this.fx.add(g);
            }
        }, undefined, () => {});
    }

    setRoute(route) {
        this.clearGroup(this.routeGroup);
        this.route = route;
        if (!route || route.points.length < 2) return;
        const pts = route.points.map((p) => this.world(p.x, p.y, 0.03));
        // A flat ribbon along the walk, with rounded joints.
        const tex = chevronTexture();
        const ribbonMat = new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthWrite: false });
        const halfW = 0.22;
        for (let i = 1; i < pts.length; i++) {
            const a = pts[i - 1], b = pts[i];
            const len = a.distanceTo(b);
            if (len < 0.01) continue;
            const t = tex.clone();
            t.needsUpdate = true;
            t.repeat.set(1, len / (halfW * 2));
            const m = new THREE.Mesh(new THREE.PlaneGeometry(halfW * 2, len), new THREE.MeshBasicMaterial({ map: t, transparent: true, depthWrite: false }));
            m.rotation.x = -Math.PI / 2;
            m.rotation.z = Math.atan2(b.x - a.x, -(b.z - a.z)) * -1;
            m.position.copy(a.clone().add(b).multiplyScalar(0.5));
            m.renderOrder = 5;
            m.userData.scroll = t;
            this.routeGroup.add(m);
        }
        const joint = new THREE.MeshBasicMaterial({ color: '#2563eb', depthWrite: false });
        for (const p of pts.slice(1, -1)) {
            const c = new THREE.Mesh(new THREE.CircleGeometry(halfW, 24), joint);
            c.rotation.x = -Math.PI / 2;
            c.position.copy(p).setY(0.028);
            this.routeGroup.add(c);
        }
        const end = new THREE.Mesh(new THREE.CircleGeometry(0.32, 32), new THREE.MeshBasicMaterial({ color: '#ef4444', depthWrite: false }));
        end.rotation.x = -Math.PI / 2;
        end.position.copy(pts[pts.length - 1]).setY(0.032);
        this.routeGroup.add(end);
        ribbonMat.dispose();
    }

    /** «اشتباه غش»: [{ id, points }] red ways from the invigilator's desk to the computers, with a light running along each. */
    setAlerts(list) {
        this.clearGroup(this.alertGroup);
        for (const [n, a] of (list ?? []).entries()) {
            if (!a.points || a.points.length < 2) continue;
            const pts = a.points.map((p) => this.world(p.x, p.y, 0.04));
            const tex = chevronTexture('#dc2626');
            let length = 0;
            for (let i = 1; i < pts.length; i++) {
                const p0 = pts[i - 1], p1 = pts[i];
                const len = p0.distanceTo(p1);
                length += len;
                if (len < 0.01) continue;
                const t = tex.clone();
                t.needsUpdate = true;
                t.repeat.set(1, len / 0.4);
                const m = new THREE.Mesh(new THREE.PlaneGeometry(0.4, len), new THREE.MeshBasicMaterial({ map: t, transparent: true, depthWrite: false }));
                m.rotation.x = -Math.PI / 2;
                m.rotation.z = Math.atan2(p1.x - p0.x, -(p1.z - p0.z)) * -1;
                m.position.copy(p0.clone().add(p1).multiplyScalar(0.5));
                m.renderOrder = 6;
                m.userData.scroll = t;
                this.alertGroup.add(m);
            }
            for (const k of [0, 1]) {
                const ball = new THREE.Mesh(new THREE.SphereGeometry(0.16, 16, 12), new THREE.MeshBasicMaterial({ color: '#dc2626' }));
                ball.userData.travel = { pts, length, phase: k * (length + 1.5) / 2 + n * 0.7 };
                this.alertGroup.add(ball);
            }
            const desk = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.25, 0.05, 24), new THREE.MeshBasicMaterial({ color: '#0f766e' }));
            desk.position.copy(pts[0]).setY(0.05);
            this.alertGroup.add(desk);
        }
    }

    makeAvatar() {
        // A Waze-like puck: white ring, blue arrow pointing where you walk.
        const g = new THREE.Group();
        const base = new THREE.Mesh(new THREE.CylinderGeometry(0.34, 0.34, 0.06, 40), new THREE.MeshStandardMaterial({ color: '#ffffff', roughness: 0.4 }));
        base.position.y = 0.05;
        const shape = new THREE.Shape();
        shape.moveTo(0, 0.26);
        shape.lineTo(0.2, -0.2);
        shape.lineTo(0, -0.1);
        shape.lineTo(-0.2, -0.2);
        shape.closePath();
        const arrow = new THREE.Mesh(new THREE.ExtrudeGeometry(shape, { depth: 0.05, bevelEnabled: false }), new THREE.MeshStandardMaterial({ color: '#2563eb', emissive: '#1d4ed8', emissiveIntensity: 0.4 }));
        arrow.rotation.x = -Math.PI / 2;
        arrow.position.y = 0.085;
        const halo = new THREE.Mesh(new THREE.CircleGeometry(0.6, 40), new THREE.MeshBasicMaterial({ color: '#3b82f6', transparent: true, opacity: 0.22, depthWrite: false }));
        halo.rotation.x = -Math.PI / 2;
        halo.position.y = 0.02;
        g.add(halo, base, arrow);
        g.traverse((o) => (o.castShadow = true));
        return g;
    }

    /** Puts the walker at plan (x, y), facing `heading` (radians, plan angle). */
    setAvatar(pos) {
        if (!pos) { this.avatar.visible = false; return; }
        this.avatar.visible = true;
        this.avatar.position.copy(this.world(pos.x, pos.y, 0));
        // Arrow points to local -Z after the extrude rotation; plan heading 0 = +x.
        this.avatar.rotation.y = -pos.heading - Math.PI / 2;
        // The camera chases the way the reader faces only when the map is set to turn with them.
        this.avatarHeading = pos.mapHeading ?? pos.routeHeading ?? pos.heading;
    }

    /** Camera modes: the whole room from above, or chasing the walker. */
    overview(animate = true) {
        this.focusBox(this.planFrame ?? { x0: 0, y0: 0, x1: this.floorData?.width ?? 20, y1: this.floorData?.depth ?? 14 }, animate);
    }

    /** The camera framing a part of the plan (a hall), as close as it fits. */
    focusBox(f, animate = true) {
        this.mode = 'overview';
        this.controls.enabled = true;
        const W = Math.max(2, f.x1 - f.x0), D = Math.max(2, f.y1 - f.y0);
        const centre = this.floorData ? this.world((f.x0 + f.x1) / 2, (f.y0 + f.y1) / 2) : new THREE.Vector3();
        // Far enough back for the whole room to fit, on a wide screen or a tall phone.
        const vf = (this.camera.fov * Math.PI) / 180;
        const hf = 2 * Math.atan(Math.tan(vf / 2) * this.camera.aspect);
        // A long building on a tall phone screen is seen from its end, so it runs up the screen
        // and fills it instead of being a thin strip far away (2026-11-06, seen in the app).
        const turn = this.camera.aspect < 0.9 && W > D * 1.2;
        const across = turn ? D : W, along = turn ? W : D;
        // Steeper on phones: the plan reads better from above.
        const steep = this.camera.aspect < 0.9 ? 0.93 : 0.86;
        const dist = Math.max((along * 0.98 * (turn ? 0.8 : 1)) / 2 / Math.tan(vf / 2), (across * 1.02) / 2 / Math.tan(hf / 2)) + 0.8;
        const flat = Math.sqrt(1 - steep * steep);
        const dir = turn ? new THREE.Vector3(-flat, steep, 0) : new THREE.Vector3(0, steep, flat);
        const target = centre.clone().add(turn ? new THREE.Vector3(-along * 0.06, 0, 0) : new THREE.Vector3(0, 0, along * 0.12));
        this.flyTo(target.clone().add(dir.multiplyScalar(dist)), target, animate);
    }

        focusShelf(id) {
        const it = this.items.get(id);
        if (!it) return;
        this.mode = 'overview';
        const c = this.world(it.data.x, it.data.y);
        const n = new THREE.Vector3(0, 0, 1).applyAxisAngle(new THREE.Vector3(0, 1, 0), it.group.rotation.y);
        const back = this.camera.aspect < 1 ? 5 / Math.max(0.45, this.camera.aspect) : 5;
        // Steep enough to look over the rows in front of it.
        const to = c.clone().add(n.multiplyScalar(back * 0.6)).add(new THREE.Vector3(0, back * 1.15, 0));
        // Aim a little in front of the bookcase so it sits above the bottom card.
        const n2 = new THREE.Vector3(0, 0, 1).applyAxisAngle(new THREE.Vector3(0, 1, 0), it.group.rotation.y);
        this.flyTo(to, c.clone().add(n2.multiplyScalar(back * 0.18)).setY(0.3), true);
    }

    follow() {
        this.mode = 'follow';
        this.controls.enabled = false;
    }

    flyTo(position, target, animate = true) {
        if (!animate) {
            this.camera.position.copy(position);
            this.controls.target.copy(target);
            this.controls.update();
            this.fly = null;
            return;
        }
        this.fly = { from: this.camera.position.clone(), to: position, tFrom: this.controls.target.clone(), tTo: target, t: 0 };
    }

    tick() {
        const raw = this.clock.getDelta();
        const dt = Math.min(0.05, raw);
        const time = this.clock.elapsedTime;
        if (this.fly) {
            this.fly.t = Math.min(1, this.fly.t + Math.min(0.2, raw) / 1.1);
            const k = 1 - Math.pow(1 - this.fly.t, 3);
            this.camera.position.lerpVectors(this.fly.from, this.fly.to, k);
            this.controls.target.lerpVectors(this.fly.tFrom, this.fly.tTo, k);
            if (this.fly.t >= 1) this.fly = null;
        }
        if (this.mode === 'follow' && this.avatar.visible && !this.fly) {
            const h = this.avatarHeading ?? 0;
            const back = new THREE.Vector3(-Math.cos(h), 0, -Math.sin(h));
            const k = this.camera.aspect < 1 ? 1.5 : 1;
            const want = this.avatar.position.clone().add(back.multiplyScalar(4.2 * k)).add(new THREE.Vector3(0, 3.4 * k, 0));
            this.camera.position.lerp(want, 1 - Math.pow(0.002, dt));
            const look = this.avatar.position.clone().add(new THREE.Vector3(Math.cos(h) * 2.2 * k, 0, Math.sin(h) * 2.2 * k));
            this.controls.target.lerp(look, 1 - Math.pow(0.002, dt));
            this.camera.lookAt(this.controls.target);
        } else this.controls.update();
        // The fog only softens what is far behind: it moves back with the camera, so a big
        // building seen from far away is not washed out.
        if (this.scene.fog) {
            const d = this.camera.position.distanceTo(this.controls.target);
            this.scene.fog.near = d + 15;
            this.scene.fog.far = d * 2.5 + 60;
        }

        // Labels shrink while walking so the aisle stays visible; the target's stays full size.
        const want = this.mode === 'follow' ? 0.45 : 1;
        this.labelScale = (this.labelScale ?? 1) + (want - (this.labelScale ?? 1)) * Math.min(1, dt * 5);
        for (const l of this.labels ?? []) {
            const k = l.userData.itemId === this.highlighted?.id ? 1 : this.labelScale;
            l.scale.set(l.userData.baseScale.x * k, l.userData.baseScale.y * k, 1);
        }
        for (const m of this.routeGroup.children) if (m.userData.scroll) m.userData.scroll.offset.y -= dt * 1.6;
        if (this.pin) { this.pin.position.y = (this.items.get(this.highlighted?.id)?.height ?? 2) + 0.85 + Math.sin(time * 3) * 0.12; this.pin.rotation.y = time * 1.5; }
        if (this.ring) { const p = (time % 1.6) / 1.6; this.ring.material.opacity = 0.65 * (1 - p); this.ring.scale.setScalar((Math.max(this.items.get(this.highlighted?.id)?.data.width ?? 1, 1) * 0.6) * (0.8 + p * 0.9)); }
        if (this.levelGlow) this.levelGlow.material.opacity = 0.22 + Math.sin(time * 4) * 0.12;
        for (const m of this.statusFx?.children ?? []) {
            const pl = m.userData.pulse;
            if (pl) {
                const p = (time % pl.speed) / pl.speed;
                m.scale.setScalar(pl.base * (1 + p * (pl.grow ?? 0.9)));
                m.material.opacity = 0.85 * (1 - p);
            }
            if (m.userData.beat) m.material.opacity = 0.55 + 0.45 * Math.abs(Math.sin((time * Math.PI) / m.userData.beat.speed));
            if (m.userData.bob !== undefined) { m.position.y = m.userData.bob + Math.abs(Math.sin(time * 3.5)) * 0.25; m.rotation.y = time * 2; }
        }
        for (const m of this.alertGroup.children) {
            if (m.userData.scroll) m.userData.scroll.offset.y -= dt * 2.4;
            const tr = m.userData.travel;
            if (tr) {
                // A red light running from the desk to the computer, again and again.
                let d = ((time * 2.2 + tr.phase) % (tr.length + 1.5));
                for (let i = 1; i < tr.pts.length; i++) {
                    const seg = tr.pts[i - 1].distanceTo(tr.pts[i]);
                    if (d <= seg || i === tr.pts.length - 1) { m.position.lerpVectors(tr.pts[i - 1], tr.pts[i], Math.min(1, d / (seg || 1))).setY(0.25); break; }
                    d -= seg;
                }
            }
        }
        for (const g of this.beacons.children) {
            for (const m of g.children) {
                const pl = m.userData.pulse;
                if (pl) {
                    const p = (time % pl.speed) / pl.speed;
                    m.scale.setScalar(pl.base * (1 + p * (pl.floor ? 1.2 : 1.6)));
                    m.material.opacity = 0.9 * (1 - p);
                }
                if (m.userData.bob !== undefined) m.position.y = m.userData.bob + Math.abs(Math.sin(time * 3.2)) * 0.18;
            }
        }
        this.renderer.render(this.scene, this.camera);
    }

    dispose() {
        this.running = false;
        cancelAnimationFrame(this.frame);
        this.resizeObserver.disconnect();
        this.renderer.domElement.removeEventListener('pointerdown', this.onDown);
        this.renderer.domElement.removeEventListener('pointerup', this.onUp);
        this.renderer.domElement.removeEventListener('pointerdown', this.onGrab, { capture: true });
        this.renderer.domElement.removeEventListener('pointermove', this.onDragMove);
        this.renderer.domElement.removeEventListener('pointerup', this.onDrop);
        this.renderer.domElement.removeEventListener('pointercancel', this.onDrop);
        this.renderer.domElement.removeEventListener('contextmenu', this.onMenu);
        this.controls.dispose();
        this.clearGroup(this.scene);
        this.renderer.dispose();
        this.renderer.domElement.remove();
    }
}
