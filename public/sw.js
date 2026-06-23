const CACHE_NAME = 'pwa-cache-v4';
const urlsToCache = [
  '/manifest.json'
];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) return caches.delete(cacheName);
        })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  // Completely bypass Service Worker for POST requests, navigation (HTML), and PHP files.
  // This guarantees that login sessions, cookies, and dynamic state are perfectly accurate.
  if (
    event.request.method !== 'GET' || 
    event.request.mode === 'navigate' || 
    event.request.url.includes('.php') || 
    event.request.url.endsWith('/')
  ) {
    return; // Fall back to default browser network behavior!
  }

  // Use Cache First for everything else (images, CSS, JS)
  event.respondWith(
    caches.match(event.request).then(response => {
      return response || fetch(event.request);
    })
  );
});
