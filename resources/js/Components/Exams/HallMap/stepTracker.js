// «تتبّع المشي» (2026-11-06, by request): count steps with the phone's motion sensor and read the
// compass, so the arrow on the map moves as the reader walks. GPS does not work indoors; the
// walk is kept on the route (each step moves the reader along it) and every QR sticker scanned
// on the way puts them back at an exact spot.

const STEP_KEY = 'hallmap.stepLength';
const OFFSET_KEY = 'hallmap.compassOffset';

const read = (k, d) => { try { const v = parseFloat(localStorage.getItem(k)); return Number.isFinite(v) ? v : d; } catch { return d; } };
const write = (k, v) => { try { localStorage.setItem(k, String(v)); } catch {} };

export const stepLength = { get: () => read(STEP_KEY, 0.65), set: (v) => write(STEP_KEY, Math.min(0.95, Math.max(0.4, v))) };
export const compassOffset = { get: () => { try { const v = localStorage.getItem(OFFSET_KEY); return v === null ? null : parseFloat(v); } catch { return null; } }, set: (v) => write(OFFSET_KEY, v) };

/** Plan bearing of a vector, degrees clockwise from the plan's "up" (towards y = 0). */
export const planBearing = (dx, dy) => ((Math.atan2(dx, -dy) * 180) / Math.PI + 360) % 360;
export const toPlanHeading = (deg) => { const r = (deg * Math.PI) / 180; return Math.atan2(-Math.cos(r), Math.sin(r)); };
/** Signed difference a − b in degrees, in (−180, 180]. */
export const angleDiff = (a, b) => { const d = (((a - b) % 360) + 540) % 360 - 180; return d; };
export function circularMean(list) {
    const s = list.reduce((acc, a) => [acc[0] + Math.sin((a * Math.PI) / 180), acc[1] + Math.cos((a * Math.PI) / 180)], [0, 0]);
    return ((Math.atan2(s[0], s[1]) * 180) / Math.PI + 360) % 360;
}

export const sensorsSupported = () => typeof window !== 'undefined' && 'DeviceMotionEvent' in window;

/**
 * Asks for motion / orientation access (iPhone shows a prompt; it must follow a tap).
 * @returns {Promise<boolean>}
 */
export async function requestSensors() {
    try {
        if (typeof DeviceMotionEvent !== 'undefined' && typeof DeviceMotionEvent.requestPermission === 'function') {
            // 'denied' is final; anything else lets the events through once the reader allows them.
            if ((await DeviceMotionEvent.requestPermission()) === 'denied') return false;
        }
        if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
            await DeviceOrientationEvent.requestPermission().catch(() => null);
        }
        return sensorsSupported();
    } catch {
        return false;
    }
}

/**
 * Peak detection on the acceleration magnitude: a step is a peak over the running average,
 * at least 280 ms after the last one.
 */
export function createStepTracker({ onStep, onHeading }) {
    let avg = 9.81;
    let smooth = 9.81;
    let above = false;
    let peak = 0;
    let lastStep = 0;
    let heading = null;

    function motion(e) {
        const a = e.accelerationIncludingGravity;
        if (!a || a.x == null) return;
        const mag = Math.sqrt(a.x * a.x + a.y * a.y + a.z * a.z);
        smooth = smooth * 0.7 + mag * 0.3; // light low-pass against hand shake
        avg = avg * 0.97 + smooth * 0.03; // slow baseline (gravity + posture)
        const now = e.timeStamp || performance.now();
        if (!above && smooth > avg + 1.1) { above = true; peak = smooth; }
        else if (above) {
            peak = Math.max(peak, smooth);
            if (smooth < avg + 0.3) {
                above = false;
                if (peak > avg + 1.4 && now - lastStep > 280) {
                    lastStep = now;
                    onStep?.({ heading });
                }
            }
        }
    }
    function orientation(e) {
        let h = null;
        let accuracy = null; // degrees of doubt (iPhone only); negative = not calibrated
        if (typeof e.webkitCompassHeading === 'number') { h = e.webkitCompassHeading; accuracy = e.webkitCompassAccuracy ?? null; } // iPhone
        else if (e.absolute && typeof e.alpha === 'number') h = (360 - e.alpha) % 360; // Android
        if (h === null) return;
        // A phone lying face down gives a heading that jumps about.
        const tilt = typeof e.beta === 'number' ? Math.abs(e.beta) : 45;
        // Screen turned sideways changes what "forward" is.
        const screenAngle = (screen.orientation?.angle ?? window.orientation ?? 0) || 0;
        heading = (h + screenAngle + 360) % 360;
        onHeading?.(heading, { accuracy, tilt });
    }

    const orientationEvent = 'ondeviceorientationabsolute' in window ? 'deviceorientationabsolute' : 'deviceorientation';
    window.addEventListener('devicemotion', motion);
    window.addEventListener(orientationEvent, orientation);

    return {
        stop() {
            window.removeEventListener('devicemotion', motion);
            window.removeEventListener(orientationEvent, orientation);
        },
        get heading() { return heading; },
    };
}
