<?php
// Page publique : le client lit et signe son contrat d'entretien (signature électronique simple)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
planning_migrer_tout();
header('X-Robots-Tag: noindex');
$t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? $_POST['t'] ?? ''));
$c = strlen($t) === 32 ? q("SELECT * FROM contrats WHERE token = ?", [$t])->fetch() : null;
if ($c && isset($_GET['pdf'])) { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Contrat-' . $c['numero'] . '.pdf"'); echo contrat_pdf($c); exit; }
$msg = '';
if ($c && $_SERVER['REQUEST_METHOD'] === 'POST' && $c['statut'] === 'envoye') {
    $nom = mb_substr(trim((string)($_POST['signataire'] ?? '')), 0, 150); $sig = (string)($_POST['signature'] ?? '');
    if (empty($_POST['lu'])) $msg = 'Coche la case « J\'ai lu et j\'accepte ».';
    elseif ($nom === '') $msg = 'Indique ton nom et prénom.';
    elseif (!preg_match('#^data:image/png;base64,#', $sig) || strlen($sig) > 600000 || strlen($sig) < 1500) $msg = 'Signe dans le cadre avec le doigt ou la souris.';
    elseif (hash('sha256', (string)$c['texte_final']) !== $c['empreinte']) $msg = 'Le contrat a été modifié depuis l\'envoi : nous vous en adressons une nouvelle version.';
    else {
        if (q("UPDATE contrats SET statut = 'signe', signataire = ?, signature = ?, signe_le = NOW(), ip = ? WHERE id = ? AND statut = 'envoye'", [$nom, $sig, $_SERVER['REMOTE_ADDR'] ?? '', $c['id']])->rowCount() !== 1) { $c = q("SELECT * FROM contrats WHERE id = ?", [$c['id']])->fetch(); $msg = 'ok'; }
        else {
        $c = q("SELECT * FROM contrats WHERE id = ?", [$c['id']])->fetch();
        journal('contrats', "Contrat {$c['numero']} signé en ligne par $nom");
        $pdf = contrat_pdf($c);
        if ($c['client_id']) { $nf = 'doc-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.pdf'; file_put_contents(dossier_fichiers() . "/$nf", $pdf); q("INSERT INTO client_docs (client_id, dossier, nom, fichier, taille) VALUES (?,?,?,?,?)", [$c['client_id'], 'Contrats signés', "Contrat {$c['numero']} signé le " . date('d/m/Y') . '.pdf', $nf, strlen($pdf)]); }
        envoyer_mail_pj($c['client_email'], "Votre contrat d'entretien n° {$c['numero']} signé", "Bonjour,\n\nMerci ! Vous trouverez ci-joint votre contrat signé. Nous vous proposerons la date de chaque passage à l'avance.\n\nBien cordialement,\n" . setting('ent_nom') . "\n" . setting('ent_tel'), ["Contrat-{$c['numero']}-signe.pdf" => ['application/pdf', $pdf]], setting('ent_email'));
        notifier_gestionnaires("Contrat {$c['numero']} signé", "{$c['client_nom']} a signé son contrat d'entretien", "contrats.php?id={$c['id']}");
        try { contrats_generer(); } catch (Throwable $e) {}
        if (setting('pl_mandat_auto') === '1' && pennylane_pret() && $c['client_id']) try { pl_mandat_demander((int)$c['client_id']); } catch (Throwable $e) {}
        $msg = 'ok';
        }
    }
}
page_simple_start('Contrat d\'entretien');
if (!$c || in_array($c['statut'], ['brouillon'], true)) { echo '<h1>Lien expiré</h1><p>Ce contrat n\'est plus disponible. Contactez-nous : ' . e(setting('ent_tel')) . '.</p>'; page_simple_end(); exit; }
?>
<style>.box{max-width:760px}.txt{white-space:pre-wrap;background:#fff;border:1px solid #e1e5da;border-radius:10px;padding:14px;max-height:52vh;overflow:auto;font-size:.92rem;text-align:left}.sig{width:100%;height:180px;border:1px solid #c9d2bd;border-radius:10px;background:#fff;display:block}</style>
<h1>Contrat d'entretien n° <?= e($c['numero']) ?></h1>
<?php if ($msg === 'ok' || $c['statut'] === 'signe'): ?>
  <div class="flash ok">✓ Contrat signé<?= $c['signe_le'] ? ' le ' . e(date('d/m/Y à H:i', strtotime($c['signe_le']))) : '' ?>. Un exemplaire vous a été envoyé par e-mail.</div>
  <p><a class="btn secondary" href="signer.php?t=<?= e($t) ?>&pdf=1" target="_blank">Télécharger le contrat signé (PDF)</a></p>
<?php elseif (in_array($c['statut'], ['resilie', 'termine'], true)): ?><p>Ce contrat n'est plus en vigueur.</p>
<?php else: ?>
  <p>Bonjour <?= e($c['client_nom']) ?>, merci de relire votre contrat puis de le signer ci-dessous.</p>
  <div class="txt"><?= e((string)$c['texte_final']) ?></div>
  <p class="small"><a href="signer.php?t=<?= e($t) ?>&pdf=1" target="_blank">Voir en PDF</a></p>
  <?php if ($msg): ?><div class="flash err"><?= e($msg) ?></div><?php endif; ?>
  <form method="post" id="f"><input type="hidden" name="t" value="<?= e($t) ?>"><input type="hidden" name="signature" id="s">
    <label style="display:flex;gap:8px;align-items:flex-start;text-align:left"><input type="checkbox" name="lu" value="1" style="width:auto;margin:4px 0 0"> J'ai lu et j'accepte le contrat ci-dessus.</label>
    <label style="text-align:left">Nom et prénom <input name="signataire" value="<?= e($c['client_nom']) ?>" required></label>
    <p style="text-align:left;margin:8px 0 4px;font-weight:700">Signature</p><canvas class="sig" id="sig"></canvas>
    <p style="display:flex;gap:8px"><button type="button" class="mini" id="eff">Effacer</button><button class="btn" style="flex:1">Signer le contrat</button></p>
    <p class="muted small" style="text-align:left">Signature électronique simple : la date, l'heure, votre adresse IP et une empreinte du texte sont enregistrées avec votre signature.</p>
  </form>
  <script src="terrain.js?v=<?= e(app_version()) ?>"></script>
  <script>const z = zoneSignature(document.getElementById('sig')); document.getElementById('eff').onclick = () => z.effacer();
  document.getElementById('f').addEventListener('submit', ev => { if (z.vide()) { ev.preventDefault(); alert('Signez dans le cadre.'); return; } document.getElementById('s').value = z.image(); });</script>
<?php endif; ?>
<p class="muted small"><?= e(setting('ent_nom')) ?> · <?= e(setting('ent_tel')) ?></p>
<?php page_simple_end(); ?>
