/**
 * Service Worker for Offline Support
 * Version: 2026-01-22 (Fixed supplier edit/delete buttons)
 */

const CACHE_NAME = 'fis-cache-v8'; // Updated - fixed supplier buttons
// BASE_PATH is determined dynamically from the registration scope
const BASE_PATH = self.registration.scope;

const FILES_TO_CACHE = [
    BASE_PATH,
    BASE_PATH + 'assets/css/style.css',
    BASE_PATH + 'assets/js/main.js',
    BASE_PATH + 'assets/js/offline-db.js',
    BASE_PATH + 'assets/js/sync-manager.js',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
    'https://code.jquery.com/jquery-3.7.1.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js'
];

// PHP pages that should work offline (cached with network-first strategy)
// These are relative paths that will be matched against the request pathname
const OFFLINE_PAGES = [
    'modules/transactions/sales_management.php',
    'modules/transactions/sales.php',
    'modules/transactions/purchases.php'
];

// Install event - cache files
self.addEventListener('install', (event) => {
    console.log('[Service Worker] Installing...');
    
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[Service Worker] Caching app shell');
                return cache.addAll(FILES_TO_CACHE);
            })
            .then(() => {
                console.log('[Service Worker] Skip waiting');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[Service Worker] Install failed:', error);
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('[Service Worker] Activating...');
    
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((cacheName) => {
                            return cacheName !== CACHE_NAME;
                        })
                        .map((cacheName) => {
                            console.log('[Service Worker] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        })
                );
            })
            .then(() => {
                console.log('[Service Worker] Claiming clients');
                return self.clients.claim();
            })
    );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Skip cross-origin requests (except CDN resources)
    if (!event.request.url.startsWith(self.location.origin) &&
        !event.request.url.startsWith('https://cdn.jsdelivr.net') &&
        !event.request.url.startsWith('https://code.jquery.com')) {
        return;
    }

    // Check if this is an offline-capable page
    const isOfflinePage = OFFLINE_PAGES.some(page => url.pathname.includes(page));
    const isApiCall = url.pathname.includes('/api/') || url.pathname.includes('/ajax/');
    const isPhpPage = url.pathname.endsWith('.php') || url.pathname.endsWith('/');

    // Handle offline-capable PHP pages with network-first strategy
    if (isOfflinePage && !isApiCall) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    // Only cache successful responses (200), not redirects (3xx) or errors
                    if (response.status === 200) {
                        // Clone the response before caching
                        const responseClone = response.clone();

                        // Cache the fresh response for offline use
                        caches.open(CACHE_NAME)
                            .then((cache) => {
                                cache.put(event.request, responseClone);
                            });

                        console.log('[Service Worker] Network-first: Serving fresh page (200) and caching');
                    } else {
                        console.log('[Service Worker] Not caching - Status:', response.status);
                    }

                    return response;
                })
                .catch((error) => {
                    console.log('[Service Worker] Network failed, serving cached page');
                    // Network failed, try to serve from cache
                    return caches.match(event.request)
                        .then((cachedResponse) => {
                            if (cachedResponse) {
                                return cachedResponse;
                            }
                            // No cache available, return error page or base path
                            return caches.match(BASE_PATH);
                        });
                })
        );
        return;
    }

    // API calls and other PHP pages - always fetch from network, never cache
    if (isPhpPage || isApiCall) {
        event.respondWith(
            fetch(event.request)
                .catch((error) => {
                    console.log('[Service Worker] Network failed for dynamic content');
                    // For navigation requests, try to return cached shell
                    if (event.request.mode === 'navigate') {
                        return caches.match(BASE_PATH);
                    }
                    throw error;
                })
        );
        return;
    }

    // For static assets, use cache-first strategy
    event.respondWith(
        caches.match(event.request)
            .then((response) => {
                // Return cached version or fetch from network
                return response || fetch(event.request)
                    .then((fetchResponse) => {
                        // Cache new responses for GET requests (static assets only)
                        if (event.request.method === 'GET' &&
                            fetchResponse.status === 200) {
                            const responseClone = fetchResponse.clone();

                            caches.open(CACHE_NAME)
                                .then((cache) => {
                                    cache.put(event.request, responseClone);
                                });
                        }

                        return fetchResponse;
                    })
                    .catch((error) => {
                        console.log('[Service Worker] Fetch failed for static asset');
                        throw error;
                    });
            })
    );
});

// Background sync event
self.addEventListener('sync', (event) => {
    console.log('[Service Worker] Background sync triggered:', event.tag);
    
    if (event.tag.startsWith('sync-')) {
        const module = event.tag.replace('sync-', '');
        
        event.waitUntil(
            syncModule(module)
                .then(() => {
                    console.log('[Service Worker] Background sync completed:', module);
                    
                    // Notify clients
                    return self.clients.matchAll();
                })
                .then((clients) => {
                    clients.forEach((client) => {
                        client.postMessage({
                            type: 'SYNC_SUCCESS',
                            module: module
                        });
                    });
                })
                .catch((error) => {
                    console.error('[Service Worker] Background sync failed:', module, error);
                })
        );
    }
});

// Sync module function (placeholder - actual sync happens in sync-manager.js)
async function syncModule(module) {
    // This is just a placeholder
    // Actual sync logic is in sync-manager.js which runs in the page context
    console.log(`[Service Worker] Sync requested for: ${module}`);
    return Promise.resolve();
}

// Message event - communication from page
self.addEventListener('message', (event) => {
    console.log('[Service Worker] Message received:', event.data);
    
    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys()
                .then((cacheNames) => {
                    return Promise.all(
                        cacheNames.map((cacheName) => caches.delete(cacheName))
                    );
                })
                .then(() => {
                    console.log('[Service Worker] All caches cleared');
                    return self.clients.matchAll();
                })
                .then((clients) => {
                    clients.forEach((client) => {
                        client.postMessage({
                            type: 'CACHE_CLEARED'
                        });
                    });
                })
        );
    }
});

console.log('[Service Worker] Script loaded - Version 2026-01-22 - Fixed supplier buttons');
