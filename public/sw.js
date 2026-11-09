// The invigilator app's service worker: it lets the site be installed on a phone, shows the
// monitor's alerts as system notifications, and opens the right page when one is tapped.
// Pages are always fetched from the network (exam data must never come from a cache).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/exams/monitor';
    event.waitUntil((async () => {
        const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of all) {
            if (c.url.includes(url) && 'focus' in c) return c.focus();
        }
        return self.clients.openWindow(url);
    })());
});
