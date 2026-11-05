/* Outils de terrain : réduction des photos, annotation au doigt, signature manuscrite */
'use strict';
/** Réduit une photo (fichier) à 1600 px de côté maximum, en JPEG */
function reduirePhoto(fichier, max = 1600, qualite = 0.82) {
  return new Promise((ok, ko) => {
    const url = URL.createObjectURL(fichier), img = new Image();
    img.onload = () => { const r = Math.min(1, max / Math.max(img.width, img.height)), c = document.createElement('canvas');
      c.width = Math.round(img.width * r); c.height = Math.round(img.height * r); const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url); ok(c.toDataURL('image/jpeg', qualite)); };
    img.onerror = () => { URL.revokeObjectURL(url); ko(new Error('Image illisible')); }; img.src = url;
  });
}
/** Ouvre une photo en plein écran pour l'annoter (traits rouges au doigt). Résout avec la nouvelle image (data URL) ou null si annulé. */
function annoterPhoto(src) {
  return new Promise(ok => {
    const fond = document.createElement('div');
    fond.style.cssText = 'position:fixed;inset:0;z-index:200;background:#111;display:flex;flex-direction:column;touch-action:none';
    fond.innerHTML = `<div style="display:flex;gap:8px;padding:10px;padding-top:max(10px,env(safe-area-inset-top));background:#683c11;align-items:center;flex-wrap:wrap">
      <button type="button" data-a="annuler" class="mini">Annuler</button><span style="flex:1;color:#fff;font-weight:700;text-align:center">Dessine au doigt</span>
      <span data-a="couleurs" style="display:flex;gap:6px">${['#e6332a', '#ffd400', '#ffffff', '#8caa31'].map((c, i) => `<button type="button" data-c="${c}" style="width:28px;height:28px;border-radius:50%;border:3px solid ${i ? 'transparent' : '#fff'};background:${c}"></button>`).join('')}</span>
      <button type="button" data-a="retour" class="mini">↶</button><button type="button" data-a="ok" class="mini" style="background:#8caa31;color:#fff;border-color:#8caa31">Valider</button></div>
      <div style="flex:1;display:grid;place-items:center;overflow:hidden"><canvas style="max-width:100%;max-height:100%;touch-action:none"></canvas></div>`;
    document.body.appendChild(fond);
    const cv = fond.querySelector('canvas'), cx = cv.getContext('2d'), img = new Image(); let traits = [], cur = null, coul = '#e6332a';
    const dessiner = () => { cx.drawImage(img, 0, 0); for (const t of traits) { cx.strokeStyle = t.c; cx.lineWidth = t.w; cx.lineCap = cx.lineJoin = 'round'; cx.beginPath(); t.p.forEach((p, i) => i ? cx.lineTo(p[0], p[1]) : cx.moveTo(p[0], p[1])); cx.stroke(); } };
    img.onload = () => { cv.width = img.width; cv.height = img.height; dessiner(); };
    img.src = src;
    const pos = ev => { const r = cv.getBoundingClientRect(); return [(ev.clientX - r.left) * cv.width / r.width, (ev.clientY - r.top) * cv.height / r.height]; };
    cv.addEventListener('pointerdown', ev => { cv.setPointerCapture(ev.pointerId); cur = {c: coul, w: Math.max(4, cv.width / 160), p: [pos(ev)]}; traits.push(cur); });
    cv.addEventListener('pointermove', ev => { if (!cur) return; cur.p.push(pos(ev)); dessiner(); });
    const fin = () => { cur = null; }; cv.addEventListener('pointerup', fin); cv.addEventListener('pointercancel', fin);
    fond.addEventListener('click', ev => { const b = ev.target.closest('button'); if (!b) return;
      if (b.dataset.c) { coul = b.dataset.c; fond.querySelectorAll('[data-c]').forEach(x => x.style.borderColor = x === b ? '#fff' : 'transparent'); return; }
      if (b.dataset.a === 'retour') { traits.pop(); dessiner(); return; }
      if (b.dataset.a === 'annuler') { fond.remove(); ok(null); return; }
      if (b.dataset.a === 'ok') { const d = traits.length ? cv.toDataURL('image/jpeg', 0.85) : null; fond.remove(); ok(d); } });
  });
}
/** Zone de signature sur un canvas ; retourne {vide(), effacer(), image()} */
function zoneSignature(cv) {
  const cx = cv.getContext('2d'); let vide = true, dern = null;
  const taille = () => { const r = cv.getBoundingClientRect(), k = window.devicePixelRatio || 1; const img = vide ? null : cv.toDataURL(); cv.width = r.width * k; cv.height = r.height * k; cx.setTransform(k, 0, 0, k, 0, 0); cx.fillStyle = '#fff'; cx.fillRect(0, 0, r.width, r.height); if (img) { const i = new Image(); i.onload = () => cx.drawImage(i, 0, 0, r.width, r.height); i.src = img; } };
  taille(); window.addEventListener('resize', taille);
  const pos = ev => { const r = cv.getBoundingClientRect(); return [ev.clientX - r.left, ev.clientY - r.top]; };
  cv.style.touchAction = 'none';
  cv.addEventListener('pointerdown', ev => { cv.setPointerCapture(ev.pointerId); dern = pos(ev); vide = false; });
  cv.addEventListener('pointermove', ev => { if (!dern) return; const p = pos(ev); cx.strokeStyle = '#1d2a22'; cx.lineWidth = 2.4; cx.lineCap = 'round'; cx.beginPath(); cx.moveTo(...dern); cx.lineTo(...p); cx.stroke(); dern = p; });
  const fin = () => { dern = null; }; cv.addEventListener('pointerup', fin); cv.addEventListener('pointercancel', fin);
  return { vide: () => vide, effacer: () => { vide = true; taille(); }, image: () => cv.toDataURL('image/png') };
}
