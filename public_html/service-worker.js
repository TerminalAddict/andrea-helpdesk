const APP_VERSION = '1.4.19-dev.2';
const CACHE_NAME = `andrea-helpdesk-static-${APP_VERSION}`;
const STATIC_ASSETS = [
    '/',
    '/assets/vendor/bootstrap/bootstrap.min.css',
    '/assets/vendor/bootstrap/bootstrap.bundle.min.js',
    '/assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff',
    '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    '/assets/vendor/dompurify/purify.min.js',
    '/assets/vendor/jquery/jquery.min.js',
    '/assets/vendor/quill/quill.min.js',
    '/assets/vendor/quill/quill.snow.css',
    '/assets/css/app.css',
    '/assets/css/terminal-shell.css',
    '/assets/js/api.js',
    '/assets/js/app.js',
    '/assets/js/rich-editor.js',
    '/assets/js/components/navbar.js',
    '/assets/js/components/notifications.js',
    '/assets/js/views/agents.js',
    '/assets/js/views/calendar.js',
    '/assets/js/views/customer-detail.js',
    '/assets/js/views/customers.js',
    '/assets/js/views/dashboard.js',
    '/assets/js/views/knowledge-base.js',
    '/assets/js/views/login.js',
    '/assets/js/views/portal.js',
    '/assets/js/views/reports.js',
    '/assets/js/views/settings.js',
    '/assets/js/views/ticket-detail.js',
    '/assets/js/views/ticket-new.js',
    '/assets/js/views/tickets.js',
    '/Andrea-Helpdesk-favicon.png',
    '/pwa-icon-192.png',
    '/pwa-icon-512.png',
    '/pwa-icon-maskable-192.png',
    '/pwa-icon-maskable-512.png',
    '/manifest.webmanifest',
    '/offline.html'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
            .catch(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(names
                .filter((name) => name !== CACHE_NAME)
                .map((name) => caches.delete(name))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin || url.pathname.startsWith('/api') || url.pathname.startsWith('/attachment')) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request, { cache: 'no-store' }).catch(() => caches.match('/offline.html'))
        );
        return;
    }

    const isVersionedAsset = url.searchParams.has('v') && STATIC_ASSETS.includes(url.pathname);
    if (isVersionedAsset) {
        event.respondWith(
            fetch(event.request, { cache: 'no-store' }).then((response) => {
                if (event.request.method === 'GET' && response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                }
                return response;
            }).catch(() => caches.match(event.request))
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) return cached;
            return fetch(event.request).then((response) => {
                if (event.request.method === 'GET' && response.ok && STATIC_ASSETS.includes(url.pathname)) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                }
                return response;
            });
        })
    );
});

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'Andrea Helpdesk', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Andrea Helpdesk';
    const icon = payload.icon || '/Andrea-Helpdesk-favicon.png';
    const options = {
        body: payload.body || '',
        icon,
        badge: payload.badge || icon,
        tag: payload.tag || 'andrea-helpdesk',
        data: {
            url: payload.link ? '/#' + payload.link : '/#/my-profile/notifications'
        }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil((async () => {
        const publicKey = await readStoredVapidPublicKey();
        if (!publicKey) {
            await notifyClients({ type: 'ANDREA_PUSH_SUBSCRIPTION_CHANGED' });
            return;
        }

        try {
            const subscription = await self.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey),
            });
            await notifyClients({
                type: 'ANDREA_PUSH_SUBSCRIPTION_REFRESHED',
                subscription: subscription.toJSON(),
            });
        } catch (e) {
            await notifyClients({ type: 'ANDREA_PUSH_SUBSCRIPTION_CHANGED' });
        }
    })());
});

self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type === 'ANDREA_STORE_VAPID_PUBLIC_KEY' && data.publicKey) {
        event.waitUntil(storeVapidPublicKey(String(data.publicKey)));
    }
    if (data.type === 'ANDREA_SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = new URL(event.notification.data?.url || '/#/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client && client.url.startsWith(self.location.origin)) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            return self.clients.openWindow(targetUrl);
        })
    );
});

async function notifyClients(message) {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    clients.forEach((client) => client.postMessage(message));
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function openPushDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('andrea-helpdesk-push', 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore('settings');
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function storeVapidPublicKey(publicKey) {
    const db = await openPushDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('settings', 'readwrite');
        tx.objectStore('settings').put(publicKey, 'vapidPublicKey');
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function readStoredVapidPublicKey() {
    try {
        const db = await openPushDb();
        return await new Promise((resolve, reject) => {
            const tx = db.transaction('settings', 'readonly');
            const request = tx.objectStore('settings').get('vapidPublicKey');
            request.onsuccess = () => resolve(request.result || '');
            request.onerror = () => reject(request.error);
        });
    } catch (e) {
        return '';
    }
}
