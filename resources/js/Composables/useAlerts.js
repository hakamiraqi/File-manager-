import { ref } from 'vue';

// Alerts for the invigilator: a sound, a vibration and, once allowed, a system notification
// (shown by the service worker, so it appears on a phone with the screen elsewhere).
export function useAlerts() {
    const supported = typeof window !== 'undefined' && 'Notification' in window;
    const permission = ref(supported ? Notification.permission : 'unsupported');
    const enabled = ref((() => { try { return localStorage.getItem('monitor-alerts') !== '0'; } catch { return true; } })());

    async function ask() {
        if (!supported) return;
        permission.value = await Notification.requestPermission();
    }
    function toggle() {
        enabled.value = !enabled.value;
        try { localStorage.setItem('monitor-alerts', enabled.value ? '1' : '0'); } catch {}
    }
    function beep(times = 2) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            for (let i = 0; i < times; i++) {
                const o = ctx.createOscillator(); const g = ctx.createGain();
                o.frequency.value = 880; g.gain.value = 0.15; o.connect(g); g.connect(ctx.destination);
                o.start(ctx.currentTime + i * 0.35); o.stop(ctx.currentTime + i * 0.35 + 0.2);
            }
            setTimeout(() => ctx.close(), 1500);
        } catch {}
    }
    async function notify(title, body, url, tag) {
        if (!enabled.value) return;
        beep();
        try { navigator.vibrate?.([200, 100, 200]); } catch {}
        if (!supported || permission.value !== 'granted' || !document.hidden) return;
        try {
            const reg = await navigator.serviceWorker?.getRegistration();
            const options = { body, tag, renotify: true, icon: '/icon-192.png', badge: '/icon-192.png', data: { url }, dir: 'rtl', lang: 'ar' };
            if (reg) await reg.showNotification(title, options);
            else new Notification(title, options);
        } catch {}
    }

    return { supported, permission, enabled, ask, toggle, notify, beep };
}
