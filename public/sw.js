// Safari/iOS иногда возвращает «unknown error» до сетевого запроса, когда Web Clip
// контролируется service worker. Этот worker — kill switch: удаляет старый кэш и
// саморегистрируется. PWA manifest и standalone-режим продолжают работать без него.
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => {
  event.waitUntil(Promise.all([
    caches.keys().then(keys => Promise.all(keys.map(key => caches.delete(key)))),
    self.registration.unregister(),
  ]));
});
