/* Espace pro – service worker : notifications sur le téléphone (le contenu est relu sur le serveur à chaque réception) */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('push', e => e.waitUntil((async () => {
  let l = [];
  try { const s = await self.registration.pushManager.getSubscription(); const r = await fetch('push.php?a=suivant&ep=' + encodeURIComponent(s ? s.endpoint : ''), {credentials: 'omit', cache: 'no-store'}); l = (await r.json()).notifications || []; } catch (err) {}
  if (!l.length) l = [{titre: 'Élagage Figeacois', corps: 'Ton planning a changé.', url: 'mon-planning.php'}];
  for (const n of l) await self.registration.showNotification(n.titre, {body: n.corps, icon: 'icone-192.png', badge: 'icone-192.png', data: {url: n.url || 'mon-planning.php'}, tag: 'ef-' + (n.id || Date.now())});
})()));
self.addEventListener('notificationclick', e => { e.notification.close(); const u = new URL(e.notification.data.url || 'mon-planning.php', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(ws => { for (const w of ws) if ('focus' in w) { w.navigate(u); return w.focus(); } return self.clients.openWindow(u); })); });
