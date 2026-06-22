const CACHE = 'intercity-shell-v1';
const SHELL = ['/offline.html','/assets/panel.css','/assets/panel.js','/assets/icons/app-icon.svg','/assets/icons/app-icon-192.png','/assets/icons/app-icon-512.png','/assets/icons/apple-touch-icon.png'];
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== location.origin) return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
    return;
  }
  if (url.pathname.startsWith('/assets/')) {
    const network = fetch(request).then(response => {
      if (response.ok) caches.open(CACHE).then(cache => cache.put(request, response.clone()));
      return response;
    });
    event.waitUntil(network.catch(() => undefined));
    event.respondWith(caches.match(request, {ignoreSearch:true}).then(cached => cached || network));
  }
});
