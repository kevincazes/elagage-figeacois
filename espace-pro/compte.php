<?php
// Fiche d'un compte : la sienne (Mon compte) ou celle d'un autre (administrateur, via ?id=)
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_login(); require_planning();
$moi = user();
$id = isset($_GET['id']) && $moi['role'] === 'admin' ? (int)$_GET['id'] : (int)$moi['id'];
$cible = $id === (int)$moi['id'];
$u = q("SELECT * FROM utilisateurs WHERE id = ?", [$id])->fetch();
if (!$u) { flash('Compte introuvable.', 'err'); redirect('utilisateurs.php'); }
$retour = $cible ? 'compte.php' : 'compte.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'infos') {
        $prenom = trim($_POST['prenom'] ?? ''); $nom = trim($_POST['nom'] ?? ''); $email = trim($_POST['email'] ?? '');
        if ($cible) $email = $u['email']; // l'identifiant de connexion ne se change pas soi-même (un administrateur peut le corriger depuis Comptes)
        if ($prenom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Prénom et e-mail valide sont obligatoires.', 'err');
        elseif (q("SELECT id FROM utilisateurs WHERE email = ? AND id <> ?", [$email, $id])->fetch()) flash('Cet e-mail est déjà utilisé par un autre compte.', 'err');
        else {
            q("UPDATE utilisateurs SET prenom = ?, nom = ?, email = ? WHERE id = ?", [$prenom, $nom, $email, $id]);
            if ($cible) { $_SESSION['user']['prenom'] = $prenom; $_SESSION['user']['email'] = $email; }
            flash('Informations enregistrées.');
        }
    } elseif ($a === 'caldav_mdp') { // mot de passe propre à l'agenda du téléphone (jamais le mot de passe du compte)
        $pp = q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1", [$id])->fetch();
        if (!$pp) flash("Ce compte n'est relié à personne du personnel : l'agenda est celui d'une personne du planning.", 'err');
        elseif (($_POST['op'] ?? '') === 'retirer') { q("UPDATE personnel SET caldav_mdp = NULL WHERE id = ?", [$pp['id']]); flash('Synchronisation coupée : le téléphone ne pourra plus lire ni modifier le planning.'); }
        else { $car = 'abcdefghjkmnpqrstuvwxyz23456789'; $m = ''; for ($i = 0; $i < 16; $i++) $m .= $car[random_int(0, strlen($car) - 1)]; $m = implode('-', str_split($m, 4));
            q("UPDATE personnel SET caldav_mdp = ? WHERE id = ?", [password_hash($m, PASSWORD_DEFAULT), $pp['id']]); $_SESSION['caldav_mdp_vu'] = $m; flash('Mot de passe agenda créé : note-le maintenant, il ne sera plus affiché.'); }
    } elseif ($a === 'caldav_test') { // l'hébergement laisse-t-il passer l'agenda ?
        $jet = bin2hex(random_bytes(12)); set_setting('caldav_diag', $jet); set_setting('caldav_diag_t', (string)time());
        $ch = curl_init(lien_base() . '/caldav.php/c/0/planning/'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => 'PROPFIND', CURLOPT_HTTPHEADER => ['X-EF-Diag: ' . $jet, 'Depth: 0'], CURLOPT_USERPWD => 'diag:diag']);
        $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $er = curl_error($ch); curl_close($ch); set_setting('caldav_diag', '');
        $j = $r ? json_decode((string)$r, true) : null;
        if (!$j) flash("Test agenda : le serveur ne répond pas comme prévu (réponse $code" . ($er ? ", $er" : '') . "). Envoie une capture de ce message.", 'err');
        else { $pb = []; if ($j['methode'] !== 'PROPFIND') $pb[] = "l'hébergement transforme la méthode PROPFIND en {$j['methode']}"; if ($j['chemin'] !== '/c/0/planning') $pb[] = "le chemin arrive mal ({$j['chemin']})"; if (!$j['auth']) $pb[] = "l'identifiant et le mot de passe ne sont pas transmis au script";
            flash($pb ? 'Test agenda : ' . implode(' ; ', $pb) . '. Envoie une capture de ce message.' : "Test agenda : le serveur est prêt. Si l'iPhone refuse, vérifie le mot de passe agenda (format xxxx-xxxx-xxxx-xxxx) et l'adresse du serveur.", $pb ? 'err' : 'ok'); }
    } elseif ($a === 'appareil_oublier' && $cible) {
        q("DELETE FROM connexions_souvenir WHERE id = ? AND utilisateur_id = ?", [(int)($_POST['cid'] ?? 0), $id]); flash('Cet appareil devra se reconnecter.');
    } elseif ($a === 'appareils_autres' && $cible) {
        $sel = str_starts_with((string)($_COOKIE['ef_souvenir'] ?? ''), 's') ? substr(explode(':', (string)$_COOKIE['ef_souvenir'], 2)[0], 1) : '';
        q("DELETE FROM connexions_souvenir WHERE utilisateur_id = ? AND selecteur <> ?", [$id, $sel]); flash('Les autres appareils devront se reconnecter.');
    } elseif ($a === 'agenda') {
        $mp = q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1", [$id])->fetchAll(); $m = agendas_post($moi['role'] === 'admin' ? null : array_map(fn($x) => (int)$x['id'], $mp));
        if ($m !== null) flash($m, str_contains($m, 'impossible') || str_contains($m, 'invalide') || str_contains($m, 'peux pas') ? 'err' : 'ok');
    } elseif ($a === 'mdp' && $cible) {
        if (!password_verify($_POST['ancien'] ?? '', $u['mdp'])) flash('Mot de passe actuel incorrect.', 'err');
        elseif (strlen($_POST['nouveau'] ?? '') < 10) flash('Le nouveau mot de passe doit faire au moins 10 caractères.', 'err');
        elseif (($_POST['nouveau'] ?? '') !== ($_POST['nouveau2'] ?? '')) flash('Les deux nouveaux mots de passe ne sont pas identiques.', 'err');
        else { q("UPDATE utilisateurs SET mdp = ? WHERE id = ?", [password_hash($_POST['nouveau'], PASSWORD_DEFAULT), $id]); flash('Mot de passe modifié.'); }
    }
    redirect($retour);
}
page_start($cible ? 'Mon compte' : 'Compte de ' . $u['prenom'] . ' ' . $u['nom'], $cible ? 'compte.php' : 'utilisateurs.php', $cible ? null : ['utilisateurs.php', 'Comptes']);
?>
<div class="cols">
<section class="card">
  <h2>Informations</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="infos">
    <label>Prénom <input name="prenom" value="<?= e($u['prenom']) ?>" required></label>
    <label>Nom <input name="nom" value="<?= e($u['nom']) ?>"></label>
    <?php if ($cible && q("SELECT id FROM personnel WHERE utilisateur_id = ? AND actif = 1", [$id])->fetch()): ?><p style="margin:0 0 8px"><a class="mini" href="livret.php">Mon livret (titres, habilitations, formations)</a></p><?php endif; ?>
    <?php if ($cible): ?><p style="margin:6px 0 10px"><span class="small muted">E-mail (identifiant de connexion)</span><br><b><?= e($u['email']) ?></b> <span class="small muted">– ne se modifie pas ici : demande à un administrateur si besoin.</span></p>
    <?php else: ?><label>E-mail (identifiant de connexion) <input type="email" name="email" value="<?= e($u['email']) ?>" required></label><?php endif; ?>
    <p class="muted">Rôle : <?= e(role_libelle($u['role'])) ?><?= $cible ? '' : ' (modifiable depuis la liste des comptes)' ?> · Double authentification : <?= ['none' => 'à configurer', 'totp' => 'application', 'email' => 'code par e-mail'][$u['mfa_type']] ?><?= $cible ? ' – <a href="securite.php">changer</a>' : '' ?></p>
    <button class="btn" type="submit">Enregistrer</button>
    <?php if (!$cible): ?><a class="btn secondary" href="utilisateurs.php">← Retour aux comptes</a><?php endif; ?>
  </form>
</section>
<?php if ($cible): ?>
<section class="card">
  <h2>Changer mon mot de passe</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mdp">
    <label>Mot de passe actuel <input type="password" name="ancien" required autocomplete="current-password"></label>
    <label>Nouveau mot de passe (10 caractères minimum) <input type="password" name="nouveau" minlength="10" required autocomplete="new-password"></label>
    <label>Confirmer le nouveau mot de passe <input type="password" name="nouveau2" minlength="10" required autocomplete="new-password"></label>
    <button class="btn" type="submit">Modifier le mot de passe</button>
  </form>
</section>
<?php else: ?>
<section class="card">
  <h2>Mot de passe</h2>
  <p class="muted">Par sécurité, un administrateur ne saisit jamais le mot de passe d'un autre compte. Pour aider cette personne, envoie-lui un lien de réinitialisation depuis la liste des comptes.</p>
</section>
<?php endif; ?>
</div>
<?php $mesPers = q("SELECT * FROM personnel WHERE utilisateur_id = ? AND actif = 1 ORDER BY id", [$id])->fetchAll(); if ($mesPers): ?>
<section class="card" id="agenda"><?= agendas_bloc($mesPers, count($mesPers) > 1) ?></section>
<?php elseif ($cible): ?><section class="card"><h2>Agenda et calendriers</h2><p class="muted small">Ton compte n'est relié à aucune fiche du personnel : demande à un administrateur de le relier (Chantiers & agenda › Personnel, matériel, équipes) pour recevoir ton planning dans ton calendrier et y rattacher tes autres agendas.</p></section><?php endif; ?>
<?php $ppC = q("SELECT p.id, p.caldav_mdp, p.email, u.email AS uemail FROM personnel p LEFT JOIN utilisateurs u ON u.id = p.utilisateur_id WHERE p.utilisateur_id = ? AND p.actif = 1", [$id])->fetch(); if ($ppC): $mdpVu = $_SESSION['caldav_mdp_vu'] ?? null; unset($_SESSION['caldav_mdp_vu']); $srv = lien_base() . '/caldav.php'; $ident = $ppC['uemail'] ?: $ppC['email']; ?>
<section class="card" id="agenda-tel"><h2>Agenda du téléphone, modifiable</h2>
  <p class="muted small">Ton planning dans le calendrier de ton iPhone ou de ton Android, <strong>dans les deux sens</strong> : tu déplaces un rendez-vous ou une visite sur le téléphone, le planning suit. Les chantiers restent fixes (le téléphone affiche un refus et remet l'horaire du planning). Un rendez-vous créé sur le téléphone dans ce calendrier arrive dans le planning.</p>
  <?php if ($mdpVu): ?><div class="flash ok" style="font-size:1.05rem">Mot de passe agenda : <strong style="font-family:monospace;letter-spacing:.05em"><?= e($mdpVu) ?></strong> <button type="button" class="mini" onclick="navigator.clipboard.writeText('<?= e($mdpVu) ?>').then(()=>this.textContent='Copié')">Copier</button><br><span class="small">Note-le maintenant : il ne sera plus affiché (tu pourras en créer un nouveau).</span></div><?php endif; ?>
  <table class="tbl" style="max-width:760px"><tr><th>Serveur</th><td><code style="word-break:break-all"><?= e($srv) ?></code> <button type="button" class="mini" onclick="navigator.clipboard.writeText('<?= e($srv) ?>').then(()=>this.textContent='Copié')">Copier</button></td></tr><tr><th>Nom d'utilisateur</th><td><code><?= e($ident) ?></code></td></tr><tr><th>Mot de passe</th><td><?= $ppC['caldav_mdp'] ? 'créé (masqué)' : '<span class="muted">pas encore créé</span>' ?></td></tr></table>
  <form method="post" class="inline" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="caldav_mdp"><button class="btn"><?= $ppC['caldav_mdp'] ? 'Créer un nouveau mot de passe agenda' : 'Créer mon mot de passe agenda' ?></button><?php if ($ppC['caldav_mdp']): ?> <button class="mini" name="op" value="retirer">Couper la synchronisation</button><?php endif; ?></form>
  <form method="post" class="inline" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="caldav_test"><button class="mini">Tester le serveur agenda</button> <span class="small muted">vérifie que l'hébergement laisse passer la synchronisation</span></form>
  <details style="margin-top:10px"><summary><strong>iPhone</strong> : ajouter le compte</summary><ol class="small"><li>Réglages › Apps › Calendrier › Comptes calendrier › Ajouter un compte › Autre › <b>Ajouter un compte CalDAV</b>.</li><li>Serveur : <b><?= e(preg_replace('#^https://#', '', $srv)) ?></b> ; Nom d'utilisateur : <b><?= e($ident) ?></b> ; Mot de passe : le mot de passe agenda ; Description : « Planning Élagage ».</li><li>Touche Suivant, puis Enregistrer. Le calendrier « Élagage Figeacois – … » apparaît dans l'app Calendrier.</li><li>Si tu avais ajouté le lien d'abonnement (lecture seule), supprime-le pour ne pas voir chaque rendez-vous en double.</li></ol></details>
  <details><summary><strong>Android</strong> : ajouter le compte</summary><ol class="small"><li>Installe l'application gratuite <b>DAVx⁵</b> (F-Droid, ou Play Store).</li><li>Dans DAVx⁵ : + › <b>Connexion avec une URL et un nom d'utilisateur</b> ; URL : <b><?= e($srv) ?></b> ; utilisateur et mot de passe agenda.</li><li>Coche le calendrier « Élagage Figeacois – … » : il apparaît dans Google Agenda (ou l'agenda du téléphone), modifiable.</li></ol></details>
</section>
<?php endif; ?>
<?php if ($cible): $apps = q("SELECT * FROM connexions_souvenir WHERE utilisateur_id = ? AND expire > NOW() ORDER BY vu_le DESC", [$id])->fetchAll(); $selMoi = str_starts_with((string)($_COOKIE['ef_souvenir'] ?? ''), 's') ? substr(explode(':', (string)$_COOKIE['ef_souvenir'], 2)[0], 1) : ''; ?>
<section class="card"><h2>Appareils restés connectés</h2>
  <p class="muted small">Chaque appareil où « Rester connecté » était coché garde sa connexion 60 jours, renouvelés à chaque visite. Se connecter ailleurs ne le déconnecte pas.</p>
  <?php if (!$apps): ?><p class="muted">Aucun pour l'instant : coche « Rester connecté sur cet appareil » à la prochaine connexion.</p><?php else: ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Appareil</th><th>Dernière visite</th><th>Connecté depuis</th><th></th></tr></thead><tbody>
  <?php foreach ($apps as $c): ?><tr><td><strong><?= e($c['appareil']) ?></strong><?= $c['selecteur'] === $selMoi ? ' <span class="muted small">(cet appareil)</span>' : '' ?></td><td><?= e(date('d/m/Y H:i', strtotime($c['vu_le']))) ?></td><td><?= e(date('d/m/Y', strtotime($c['cree_le']))) ?></td>
    <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="appareil_oublier"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>"><button class="mini">Déconnecter</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if (count($apps) > 1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="appareils_autres"><button class="mini">Déconnecter tous les autres appareils</button></form><?php endif; endif; ?>
</section>
<?php endif; ?>
<?php page_end(); ?>
