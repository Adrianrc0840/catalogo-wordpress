/* ── Florería Monarca — Service Worker ── */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

// ── Recibir notificación push ──
self.addEventListener('push', function (event) {
    if (!event.data) return;

    let data = {};
    try { data = event.data.json(); } catch (e) { data = { title: 'Florería Monarca', body: event.data.text() }; }

    const options = {
        body:               data.body || '',
        icon:               '/wp-content/plugins/floreria-catalogo/assets/images/icon-192.png',
        badge:              '/wp-content/plugins/floreria-catalogo/assets/images/icon-192.png',
        vibrate:            [200, 100, 200],
        requireInteraction: true,
        data:               { url: data.url || '/panel-florista/' },
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Florería Monarca', options)
    );
});

// ── Clic en la notificación — abrir / enfocar el panel ──
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data?.url || '/panel-florista/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (const client of list) {
                if (client.url.includes('/panel-florista') && 'focus' in client) return client.focus();
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});
