const CACHE_NAME = 'flowork-cache-v4';   // bump to drop older caches (v3 also cached PHP data requests)
const OFFLINE_URL = 'offline.html';
const FONT_URL = 'https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300&display=swap';

const ASSETS_TO_CACHE = [
    OFFLINE_URL,
    'icon.svg',
    'manifest.json',
    FONT_URL,
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js'
];

// Install Event
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        }).then(() => self.skipWaiting())
    );
});

// Activate Event
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch Event
self.addEventListener('fetch', (event) => {
    // Only intercept GET requests
    if (event.request.method !== 'GET') return;

    const requestUrl = new URL(event.request.url);

    // 1. Handle HTML page navigations first (to guarantee offline fallback page works)
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => {
                return caches.open(CACHE_NAME).then((cache) => {
                    return cache.match(OFFLINE_URL);
                });
            })
        );
        return;
    }

    // 2. Bypass cache for dynamic actions, downloads, and SSE streams
    const isBypass = requestUrl.searchParams.has('dl') 
        || requestUrl.searchParams.has('export') 
        || requestUrl.searchParams.has('ajax')
        || requestUrl.pathname.includes('sse.php');

    if (isBypass) {
        event.respondWith(fetch(event.request));
        return;
    }

    // 3. Only static files are cached: scripts, styles, images, fonts, the manifest.
    //    Everything else (PHP pages and data endpoints such as pay_ajax.php?action=…)
    //    always goes to the network, so the app never shows stale figures.
    const isStaticFile = /\.(js|css|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|json|webmanifest)$/i.test(requestUrl.pathname);
    const isAllowedOrigin = (requestUrl.origin === self.location.origin && isStaticFile)
        || requestUrl.origin === 'https://fonts.googleapis.com'
        || requestUrl.origin === 'https://fonts.gstatic.com'
        || requestUrl.origin === 'https://cdnjs.cloudflare.com';

    if (isAllowedOrigin) {
        event.respondWith(
            caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    // Stale-while-revalidate for local assets when online
                    if (requestUrl.origin === self.location.origin) {
                        fetch(event.request).then((networkResponse) => {
                            if (networkResponse.status === 200) {
                                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, networkResponse));
                            }
                        }).catch(() => {/* Ignore network update errors offline */});
                    }
                    return cachedResponse;
                }

                return fetch(event.request).then((response) => {
                    if (response.status === 200 || response.type === 'opaque') {
                        const responseCopy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, responseCopy);
                        });
                    }
                    return response;
                }).catch(() => {
                    return new Response('', { status: 408, statusText: 'Offline Asset Unavailable' });
                });
            })
        );
    }
});

// Push Notification Event
self.addEventListener('push', (event) => {
    let payload = { title: 'Flowork Alert', body: 'New updates available on the floor.', icon: 'icon.svg' };
    if (event.data) {
        try {
            payload = event.data.json();
        } catch (e) {
            payload.body = event.data.text();
        }
    }
    const options = {
        body: payload.body,
        icon: payload.icon || 'icon.svg',
        badge: 'icon.svg',
        vibrate: [100, 50, 100],
        data: {
            dateOfArrival: Date.now(),
            primaryKey: '1'
        }
    };
    event.waitUntil(
        self.registration.showNotification(payload.title, options)
    );
});

// Notification Click Event
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow('dashboard.php')
    );
});
