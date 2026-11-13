import { ref, computed } from 'vue';

/**
 * Zooming into a playing video (2026-11-13): pick a ratio, pinch with two fingers,
 * Ctrl + mouse wheel, and drag to move around once zoomed in. Works on the <video>
 * element itself, so Plyr's controls, captions and fullscreen are untouched.
 */
export const ZOOM_PRESETS = [1, 1.25, 1.5, 2, 3];
const MIN = 1;
const MAX = 5;

export function useVideoZoom(boxRef, videoRef) {
    const zoom = ref(1);
    const fill = ref(false);
    const x = ref(0);
    const y = ref(0);
    const pointers = new Map();
    let pinch = null;
    let drag = null;
    let swallowClick = false;

    const percent = computed(() => (fill.value ? null : Math.round(zoom.value * 100)));

    function clampPan() {
        const box = boxRef.value?.getBoundingClientRect();
        if (!box) return;
        const mx = ((zoom.value - 1) * box.width) / 2;
        const my = ((zoom.value - 1) * box.height) / 2;
        x.value = Math.max(-mx, Math.min(mx, x.value));
        y.value = Math.max(-my, Math.min(my, y.value));
    }

    function apply() {
        const v = videoRef.value;
        if (!v) return;
        v.style.objectFit = fill.value ? 'cover' : '';
        v.style.transformOrigin = 'center center';
        v.style.transform = zoom.value === 1 && !x.value && !y.value ? '' : `translate(${x.value}px, ${y.value}px) scale(${zoom.value})`;
        v.style.transition = pinch || drag ? 'none' : 'transform 0.15s ease-out';
        if (boxRef.value) boxRef.value.style.touchAction = zoom.value > 1 ? 'none' : 'pan-x pan-y';
    }

    function setZoom(z, keepPan = false) {
        fill.value = false;
        zoom.value = Math.max(MIN, Math.min(MAX, Math.round(z * 100) / 100));
        if (!keepPan) {
            x.value = 0;
            y.value = 0;
        }
        clampPan();
        apply();
    }

    function setFill() {
        zoom.value = 1;
        x.value = 0;
        y.value = 0;
        fill.value = true;
        apply();
    }

    function reset() {
        fill.value = false;
        setZoom(1);
    }

    const step = (dir) => setZoom(zoom.value + dir * 0.25, true);

    // Plyr's bar, menus and our own buttons keep their own taps and drags.
    const ignore = (e) => !!e.target.closest?.('.plyr__controls, .plyr__menu, .fn-zoom, button, input');

    function distance() {
        const [a, b] = [...pointers.values()];
        return Math.hypot(a.x - b.x, a.y - b.y);
    }
    function center() {
        const [a, b] = [...pointers.values()];
        return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    }

    function onPointerDown(e) {
        if (ignore(e)) return;
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pointers.size === 2) {
            drag = null;
            fill.value = false;
            pinch = { d: distance() || 1, z: zoom.value, c: center(), x: x.value, y: y.value };
        } else if (pointers.size === 1 && zoom.value > 1) {
            drag = { sx: e.clientX, sy: e.clientY, x: x.value, y: y.value, moved: false };
        }
    }

    function onPointerMove(e) {
        if (!pointers.has(e.pointerId)) return;
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pinch && pointers.size >= 2) {
            const c = center();
            zoom.value = Math.max(MIN, Math.min(MAX, (pinch.z * distance()) / pinch.d));
            x.value = pinch.x + (c.x - pinch.c.x);
            y.value = pinch.y + (c.y - pinch.c.y);
            clampPan();
            apply();
            e.preventDefault();
        } else if (drag) {
            const dx = e.clientX - drag.sx;
            const dy = e.clientY - drag.sy;
            if (!drag.moved && Math.hypot(dx, dy) < 6) return;
            drag.moved = true;
            x.value = drag.x + dx;
            y.value = drag.y + dy;
            clampPan();
            apply();
            e.preventDefault();
        }
    }

    function onPointerUp(e) {
        if (!pointers.has(e.pointerId)) return;
        pointers.delete(e.pointerId);
        if (pinch && pointers.size < 2) {
            pinch = null;
            swallowClick = true;
            if (zoom.value < 1.05) setZoom(1);
        }
        if (drag && pointers.size === 0) {
            if (drag.moved) swallowClick = true;
            drag = null;
        }
        apply();
    }

    // A pinch or a drag is not a tap: Plyr would pause or play on it.
    function onClickCapture(e) {
        if (swallowClick) {
            swallowClick = false;
            e.stopPropagation();
            e.preventDefault();
        }
    }

    function onWheel(e) {
        if (!e.ctrlKey || ignore(e)) return;
        e.preventDefault();
        setZoom(zoom.value * (e.deltaY < 0 ? 1.1 : 1 / 1.1), true);
    }

    function forget() {
        pointers.clear();
        pinch = null;
        drag = null;
        zoom.value = 1;
        fill.value = false;
        x.value = 0;
        y.value = 0;
        apply();
    }

    return { zoom, fill, percent, setZoom, setFill, reset, step, onPointerDown, onPointerMove, onPointerUp, onClickCapture, onWheel, forget };
}
