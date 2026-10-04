const CACHE_NAME = 'deepro-pwa-v2';

self.addEventListener('install', event => {
    console.log('Service Worker installed');
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    console.log('Service Worker activated');
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key === 'exchange-pwa-v1').map(key => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
    // 先不缓存接口和动态数据，避免交易所数据错乱
});