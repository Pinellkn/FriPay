/**
 * FriPay QR Service Worker
 *
 * Stratégies de cache :
 * - App Shell (HTML/CSS/JS)  → Cache-First (stale-while-revalidate)
 * - CDN libs (Tailwind/Alpine) → Cache-First avec fallback réseau
 * - API (GET)                → Network-First avec fallback cache
 * - API (POST/PUT/DELETE)    → Pass-through (traité par qr-sync.js)
 * - QR Code images           → Cache-First
 */

const CACHE_NAME = 'fripay-qr-v1';
const CACHE_VERSION = 1;

// Ressources à cacher lors de l'installation (App Shell)
const APP_SHELL = [
    '/qr',
    '/js/qr-offline.js',
    '/js/qr-sync.js',
    '/manifest.json',
];

// CDN libs à cacher
const CDN_LIBS = [
    'https://cdn.tailwindcss.com',
    'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
    'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js',
    'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
];

// ─── INSTALL ──────────────────────────────────────────

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            console.log('[SW] Caching App Shell...');
            // Cache les ressources locales (ignorer les erreurs)
            for (const url of APP_SHELL) {
                try {
                    await cache.add(url);
                } catch (err) {
                    console.warn(`[SW] Failed to cache: ${url}`, err);
                }
            }
            // Cache les CDN libs
            for (const url of CDN_LIBS) {
                try {
                    await cache.add(url);
                } catch (err) {
                    console.warn(`[SW] Failed to cache CDN: ${url}`, err);
                }
            }
            console.log('[SW] App Shell cached.');
        })
    );
    // Prend le contrôle immédiatement
    self.skipWaiting();
});

// ─── ACTIVATE ─────────────────────────────────────────

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => {
                        console.log(`[SW] Deleting old cache: ${key}`);
                        return caches.delete(key);
                    })
            );
        })
    );
    // Prend le contrôle de toutes les pages
    self.clients.claim();
});

// ─── FETCH ────────────────────────────────────────────

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Ignorer les requêtes non-GET pour l'API (laisser qr-sync gérer)
    if (url.pathname.startsWith('/api/') && request.method !== 'GET') {
        return; // Pass-through
    }

    // Ignorer les requêtes vers des origines tierces non-CDN
    if (url.origin !== self.location.origin && !isCDNLib(request.url)) {
        return;
    }

    // Strategy: API GET → Network-First
    if (url.pathname.startsWith('/api/') && request.method === 'GET') {
        event.respondWith(networkFirst(request));
        return;
    }

    // Strategy: CDN libs → Cache-First
    if (isCDNLib(request.url)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Strategy: App Shell → Stale-While-Revalidate
    event.respondWith(staleWhileRevalidate(request));
});

// ─── CACHE STRATEGIES ────────────────────────────────

/**
 * Cache-First : cherche en cache, sinon réseau + met en cache.
 */
async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        return new Response('Offline', { status: 503 });
    }
}

/**
 * Network-First : essaie le réseau, fallback cache.
 */
async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        const cached = await caches.match(request);
        if (cached) return cached;
        return new Response(JSON.stringify({
            error: 'OFFLINE',
            message: 'Pas de connexion réseau. Les données affichées peuvent ne pas être à jour.',
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

/**
 * Stale-While-Revalidate : sert du cache, met à jour en arrière-plan.
 */
async function staleWhileRevalidate(request) {
    const cached = await caches.match(request);

    const fetchPromise = fetch(request).then(async (response) => {
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    }).catch(() => cached);

    return cached || fetchPromise;
}

// ─── BACKGROUND SYNC ──────────────────────────────────

self.addEventListener('sync', (event) => {
    if (event.tag === 'fripay-qr-sync') {
        event.waitUntil(syncPendingOps());
    }
});

/**
 * Synchronise les opérations en attente via le Background Sync API.
 */
async function syncPendingOps() {
    // Notifier tous les clients que la sync commence
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
        client.postMessage({ type: 'SYNC_START' });
    });

    try {
        // La logique est dans qr-sync.js côté client
        // Ici on juste notifie les clients
        clients.forEach(client => {
            client.postMessage({ type: 'SYNC_REQUEST' });
        });
    } catch (err) {
        console.error('[SW] Sync failed:', err);
    }
}

// ─── MESSAGE HANDLER ──────────────────────────────────

self.addEventListener('message', (event) => {
    const { type } = event.data;

    switch (type) {
        case 'SKIP_WAITING':
            self.skipWaiting();
            break;

        case 'CACHE_CLEAR':
            caches.delete(CACHE_NAME).then(() => {
                console.log('[SW] Cache cleared.');
            });
            break;

        case 'CACHE_URLS':
            // Cache des URLs spécifiques (ex: QR codes générés)
            caches.open(CACHE_NAME).then((cache) => {
                (event.data.urls || []).forEach(url => cache.add(url));
            });
            break;

        case 'FORCE_SYNC':
            // Déclenche un Background Sync
            if ('sync' in self.registration) {
                self.registration.sync.register('fripay-qr-sync');
            }
            break;
    }
});

// ─── PUSH NOTIFICATIONS ───────────────────────────────

self.addEventListener('push', (event) => {
    const data = event.data?.json() || {};
    const title = data.title || 'FriPay';
    const options = {
        body: data.body || 'Nouvelle notification',
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        tag: data.tag || 'fripay-notification',
        data: data.url || '/qr',
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        self.clients.openWindow(event.notification.data)
    );
});

// ─── HELPERS ──────────────────────────────────────────

function isCDNLib(url) {
    return url.includes('cdn.tailwindcss.com') ||
           url.includes('cdn.jsdelivr.net/npm/alpinejs') ||
           url.includes('cdn.jsdelivr.net/npm/qrcode') ||
           url.includes('unpkg.com/html5-qrcode');
}
