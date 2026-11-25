/* ============================================================
   Service worker de la app de terreno

   Guarda los archivos de la app en el teléfono para que abra sin
   señal. Los datos del campo NO pasan por acá: viven en IndexedDB,
   y la API nunca se cachea (va siempre directo a la red).

   Al publicar una versión nueva de la app, cambiar VERSION: así
   los teléfonos descartan la copia vieja.
   ============================================================ */

const VERSION = 'terreno-2.7.0';
const ARCHIVOS = ['./', 'index.html', 'app.css', 'app.js', 'lector.js', 'manifest.webmanifest', 'icono-192.png', 'icono-512.png'];

self.addEventListener('install', (evento) => {
  evento.waitUntil(
    caches.open(VERSION)
      .then((cache) => cache.addAll(ARCHIVOS))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches.keys()
      .then((claves) => Promise.all(claves.filter((clave) => clave !== VERSION).map((clave) => caches.delete(clave))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (evento) => {
  const pedido = evento.request;
  if (pedido.method !== 'GET') return;

  // Solo los archivos de la app. La API y el resto del sistema van a la red.
  const url = new URL(pedido.url);
  if (url.origin !== self.location.origin || !url.href.startsWith(self.registration.scope)) return;

  // Primero la copia guardada (abre al instante y sin señal); en paralelo se
  // busca la versión de la red para la próxima vez.
  evento.respondWith(
    caches.open(VERSION).then(async (cache) => {
      const guardada = (await cache.match(pedido, { ignoreSearch: true }))
        || (pedido.mode === 'navigate' ? await cache.match('index.html') : undefined);

      const deRed = fetch(pedido)
        .then((respuesta) => {
          if (respuesta.ok) cache.put(pedido, respuesta.clone());
          return respuesta;
        })
        .catch(() => guardada);

      return guardada || deRed;
    }),
  );
});
