// BirdSuite field companion: kept on the phone so it opens without any network (US-120)
var CACHE = 'birdsuite-field-v1';
self.addEventListener('install', function (e) { e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(['./', './index.html', './manifest.json']); })); });
self.addEventListener('activate', function (e) { e.waitUntil(caches.keys().then(function (k) { return Promise.all(k.filter(function (n) { return n !== CACHE; }).map(function (n) { return caches.delete(n); })); })); });
self.addEventListener('fetch', function (e) {
  e.respondWith(fetch(e.request).then(function (r) { var copy = r.clone(); caches.open(CACHE).then(function (c) { c.put(e.request, copy); }); return r; })
    .catch(function () { return caches.match(e.request); }));
});
