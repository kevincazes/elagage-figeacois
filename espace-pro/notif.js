/* Activation des notifications sur ce téléphone (bouton #notif) */
(function () {
  const b = document.getElementById('notif'); if (!b || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
  const csrf = document.querySelector('meta[name=csrf]')?.content || '';
  const b64 = s => { const p = '='.repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...r].map(c => c.charCodeAt(0))); };
  const maj = async () => { const reg = await navigator.serviceWorker.ready; const s = await reg.pushManager.getSubscription(); b.hidden = false; b.innerHTML = '<svg class="pi" viewBox="0 0 24 24" style="width:1em;height:1em;vertical-align:-2px;fill:none;stroke:currentColor;stroke-width:2"><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg> ' + (s && Notification.permission === 'granted' ? 'Notifications activées' : 'Activer les notifications'); b.dataset.on = s ? '1' : ''; };
  b.onclick = async () => {
    try {
      const reg = await navigator.serviceWorker.ready;
      if (b.dataset.on) { const s = await reg.pushManager.getSubscription(); if (s) { await fetch('push.php?a=desabonner', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': csrf}, body: JSON.stringify({endpoint: s.endpoint})}); await s.unsubscribe(); } return maj(); }
      if (await Notification.requestPermission() !== 'granted') { alert("Les notifications sont bloquées pour ce site : autorise-les dans les réglages du navigateur. Sur iPhone, ajoute d'abord l'espace pro à l'écran d'accueil (Partager › Sur l'écran d'accueil) puis ouvre-le depuis l'icône."); return; }
      const cle = (await (await fetch('push.php?a=cle')).json()).cle;
      const s = await reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: b64(cle)});
      const r = await fetch('push.php?a=abonner', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': csrf}, body: JSON.stringify({endpoint: s.endpoint, appareil: navigator.userAgent.slice(0, 120)})});
      if (!r.ok) throw new Error('Enregistrement refusé');
      maj();
    } catch (e) { alert("Impossible d'activer les notifications sur cet appareil : " + e.message); }
  };
  maj();
})();
