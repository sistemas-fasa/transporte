const CACHE = 'gdv-v1';
const CACHE_FILES = [
'/sistema/Logo/Logo_App.png',
'/sistema/manifest.json',
];

self.addEventListener('install', (e) => {
e.waitUntil(
caches.open(CACHE).then((cache) => cache.addAll(CACHE_FILES))
);
self.skipWaiting();
});

self.addEventListener('activate', (e) => {
e.waitUntil(clients.claim());
});

self.addEventListener('fetch', (e) => {
if (e.request.method !== 'GET') return;
e.respondWith(
caches.match(e.request).then((r) => r || fetch(e.request))
);
});
