/* 问界 H5 静态资源缓存：动态业务请求绝不经过此缓存。 */
const CACHE_NAME = 'wj-static-v20260821-adsfix1';
const CORE_ASSETS = [
  '/Style/plus.js',
  '/Style/newcss/common.css',
  '/Style/newjs/jquery-1.10.1.min.js',
  '/Style/newimg/laba.png',
  '/Style/newimg/leftar.png',
  '/Style/newimg/gamebg.png',
  '/Style/newimg/ic_game_list_top_bg.webp',
  '/Style/newimg/ic_game_list_back.webp'
];

function sameOriginStatic(request) {
  if (!request || request.method !== 'GET') return false;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return false;
  return /^(?:\/Style\/(?:newimg|newcss|newjs|pop|images)\/|\/Templates\/user\/(?:images|css|js)\/|\/assets\/web\/banners\/|\/openimg\/|\/(?:favicon\.png|apple-touch-icon\.png|bgMusic\.mp3)$)/.test(url.pathname);
}

async function putIfUsable(cache, request, response) {
  if (response && response.ok && response.type === 'basic') {
    await cache.put(request, response.clone());
  }
  return response;
}

async function warmAssets() {
  const cache = await caches.open(CACHE_NAME);
  let manifest;
  try {
    const response = await fetch('/static-assets-manifest.php', { cache: 'no-store', credentials: 'same-origin' });
    manifest = await response.json();
  } catch (e) {
    return;
  }
  const assets = Array.isArray(manifest && manifest.assets) ? manifest.assets : [];
  // 限制并发为 4：充分利用服务器和网络，但不抢首屏、聊天和直播的连接。
  let cursor = 0;
  async function worker() {
    while (cursor < assets.length) {
      const path = assets[cursor++];
      try {
        const request = new Request(path, { credentials: 'same-origin', cache: 'reload' });
        const cached = await cache.match(request);
        if (!cached) await putIfUsable(cache, request, await fetch(request));
      } catch (e) {}
    }
  }
  await Promise.all([worker(), worker(), worker(), worker()]);
}

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await Promise.all(CORE_ASSETS.map(async path => {
      try { await putIfUsable(cache, new Request(path), await fetch(path, { cache: 'reload' })); } catch (e) {}
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.indexOf('wj-static-') === 0 && key !== CACHE_NAME).map(key => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', event => {
  if (event && event.data && event.data.type === 'FN_WARM_STATIC_ASSETS') {
    event.waitUntil(warmAssets());
  }
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (!sameOriginStatic(request)) return;
  const work = (async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    const refresh = fetch(request).then(response => putIfUsable(cache, request, response)).catch(() => null);
    // 缓存命中立即返回；后台悄悄更新，下一次导航就是最新静态素材。
    if (cached) {
      return { response: cached, refresh: refresh };
    }
    const fresh = await refresh;
    return { response: fresh || new Response('', { status: 504, statusText: 'Static asset unavailable' }), refresh: null };
  })();
  // waitUntil 必须在事件同步阶段注册；不能在异步回调内再调用，否则部分 Android WebView 会丢弃后台更新。
  event.waitUntil(work.then(result => result.refresh || undefined).catch(() => undefined));
  event.respondWith(work.then(result => result.response));
});
