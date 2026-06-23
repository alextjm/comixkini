const CACHE_NAME = 'comixkini-cache-v3';
const urlsToCache = [
  '/manifest.json'
];

self.addEventListener('install', event => {
  // Activate immediately
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(urlsToCache);
    })
  );
});

self.addEventListener('activate', event => {
  // Clear old caches
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  // Use Network First for navigation (HTML) and PHP requests
  if (event.request.mode === 'navigate' || event.request.url.includes('.php') || event.request.url.endsWith('/')) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
           // Clone the response and save it to the cache so the offline version is always the latest state!
           const responseToCache = response.clone();
           caches.open(CACHE_NAME).then(cache => {
             cache.put(event.request, responseToCache);
           });
           return response;
        })
        .catch(() => {
           // Fallback to cache if offline
           return caches.match(event.request);
        })
    );
    return;
  }

  // Use Cache First for everything else (images, CSS, JS)
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        return response || fetch(event.request);
      })
  );
});
