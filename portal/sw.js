/* Bastion — service worker de l'intranet (installabilité + cache + mode hors-ligne). */
const CACHE = 'bastion-intranet-v2';
const OFFLINE = '/portal/intranet/offline.html';
const SHELL = [OFFLINE, '/portal/assets/bastion-icon.svg', '/portal/assets/icon-192.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET') { return; }
  e.respondWith(
    fetch(e.request).then(function (r) {
      if (r && r.status === 200 && r.type === 'basic') {
        var copy = r.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, copy); });
      }
      return r;
    }).catch(function () {
      return caches.match(e.request).then(function (m) {
        if (m) { return m; }
        if (e.request.mode === 'navigate') { return caches.match(OFFLINE); }
        return undefined;
      });
    })
  );
});
