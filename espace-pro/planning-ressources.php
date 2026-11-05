<?php
// Planning : personnel, matériel (dont outils montés sur un porteur), équipes, réglages des tournées
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
require_planning();
if (planning_niveau() !== 'tout') { http_response_code(403); page_start('Accès réservé', 'planning.php'); echo '<p class="lead">La gestion du personnel, du matériel et des équipes est réservée aux comptes qui modifient tous les agendas.</p>'; page_end(); exit; }
$coul = fn($v, $d) => preg_match('/^#[0-9a-f]{6}$/i', (string)$v) ? $v : $d;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'personnel') {
        foreach ($_POST['p'] ?? [] as $id => $p) {
            $comp = implode(',', array_intersect(array_keys((array)($p['comp'] ?? [])), array_keys(COMPETENCES)));
            $vals = [trim($p['prenom'] ?? ''), trim($p['nom'] ?? ''), trim($p['poste'] ?? ''), trim($p['telephone'] ?? ''), trim($p['email'] ?? ''), $coul($p['couleur'] ?? '', '#2f6b45'), $comp, ($p['utilisateur_id'] ?? '') !== '' ? (int)$p['utilisateur_id'] : null, (int)($p['ordre'] ?? 0), !empty($p['actif']) ? 1 : 0];
            if ($id === 'nouveau') { if ($vals[0] === '') continue; q("INSERT INTO personnel (prenom, nom, poste, telephone, email, couleur, competences, utilisateur_id, ordre, actif, ics_token) VALUES (?,?,?,?,?,?,?,?,?,?,?)", array_merge($vals, [bin2hex(random_bytes(16))])); }
            else q("UPDATE personnel SET prenom = ?, nom = ?, poste = ?, telephone = ?, email = ?, couleur = ?, competences = ?, utilisateur_id = ?, ordre = ?, actif = ? WHERE id = ?", array_merge($vals, [(int)$id]));
        }
        flash('Personnel enregistré.');
    } elseif ($a === 'materiel') {
        foreach ($_POST['m'] ?? [] as $id => $m) {
            $por = ($m['porteur_id'] ?? '') !== '' && (string)$m['porteur_id'] !== (string)$id ? (int)$m['porteur_id'] : null;
            $vals = [trim($m['nom'] ?? ''), array_key_exists($m['type'] ?? '', TYPES_MAT) ? $m['type'] : 'autre', trim($m['immatriculation'] ?? ''), array_key_exists($m['competence_requise'] ?? '', COMPETENCES) ? $m['competence_requise'] : '',
                     ($m['controle_le'] ?? '') !== '' && strtotime($m['controle_le']) ? date('Y-m-d', strtotime($m['controle_le'])) : null, $coul($m['couleur'] ?? '', '#7a4fa0'), trim($m['notes'] ?? ''), (int)($m['ordre'] ?? 0), !empty($m['actif']) ? 1 : 0, $por];
            if ($id === 'nouveau') { if ($vals[0] === '') continue; q("INSERT INTO materiel (nom, type, immatriculation, competence_requise, controle_le, couleur, notes, ordre, actif, porteur_id) VALUES (?,?,?,?,?,?,?,?,?,?)", $vals); }
            else q("UPDATE materiel SET nom = ?, type = ?, immatriculation = ?, competence_requise = ?, controle_le = ?, couleur = ?, notes = ?, ordre = ?, actif = ?, porteur_id = ? WHERE id = ?", array_merge($vals, [(int)$id]));
        }
        // pas de boucle porteur → outil → porteur
        foreach (q("SELECT id, porteur_id FROM materiel WHERE porteur_id IS NOT NULL") as $m) { $x = (int)$m['porteur_id']; $n = 0; while ($x && $n++ < 10) { if ($x === (int)$m['id']) { q("UPDATE materiel SET porteur_id = NULL WHERE id = ?", [$m['id']]); break; } $x = (int)(q("SELECT porteur_id FROM materiel WHERE id = ?", [$x])->fetch()['porteur_id'] ?? 0); } }
        flash('Matériel enregistré.');
    } elseif ($a === 'equipe') {
        $id = $_POST['id'] ?? 'nouveau'; $nom = trim($_POST['nom'] ?? '');
        if ($nom === '') { flash('Donne un nom à l\'équipe.', 'err'); redirect('planning-ressources.php#equipes'); }
        $chef = ($_POST['chef_id'] ?? '') !== '' ? (int)$_POST['chef_id'] : null;
        if ($id === 'nouveau') { q("INSERT INTO equipes_pl (nom, couleur, chef_id, ordre) VALUES (?,?,?,?)", [$nom, $coul($_POST['couleur'] ?? '', '#2f6b45'), $chef, (int)($_POST['ordre'] ?? 0)]); $id = (int)db()->lastInsertId(); }
        else { $id = (int)$id; q("UPDATE equipes_pl SET nom = ?, couleur = ?, chef_id = ?, ordre = ? WHERE id = ?", [$nom, $coul($_POST['couleur'] ?? '', '#2f6b45'), $chef, (int)($_POST['ordre'] ?? 0), $id]); }
        q("DELETE FROM equipe_membres WHERE equipe_id = ?", [$id]); foreach ((array)($_POST['membres'] ?? []) as $p) q("INSERT IGNORE INTO equipe_membres (equipe_id, personnel_id) VALUES (?,?)", [$id, (int)$p]);
        if ($chef) q("INSERT IGNORE INTO equipe_membres (equipe_id, personnel_id) VALUES (?,?)", [$id, $chef]);
        q("DELETE FROM equipe_materiel WHERE equipe_id = ?", [$id]); foreach ((array)($_POST['mat'] ?? []) as $m) q("INSERT IGNORE INTO equipe_materiel (equipe_id, materiel_id) VALUES (?,?)", [$id, (int)$m]);
        journal('planning', "Équipe « $nom » enregistrée"); flash("Équipe « $nom » enregistrée.");
        redirect('planning-ressources.php#equipes');
    } elseif ($a === 'equipe_suppr') {
        q("UPDATE equipes_pl SET actif = 0 WHERE id = ?", [(int)$_POST['id']]); q("UPDATE evenements SET equipe_id = NULL WHERE equipe_id = ? AND (debut IS NULL OR debut > NOW())", [(int)$_POST['id']]); flash('Équipe retirée (les chantiers gardent leurs personnes).');
        redirect('planning-ressources.php#equipes');
    } elseif ($a === 'secteurs') {
        foreach ($_POST['s'] ?? [] as $sid => $x) {
            $jours = implode(',', array_intersect(array_keys((array)($x['j'] ?? [])), ['1', '2', '3', '4', '5']));
            $v = [trim($x['nom'] ?? ''), $coul($x['couleur'] ?? '', '#3b6ea8'), is_numeric($x['lat'] ?? '') ? (float)$x['lat'] : null, is_numeric($x['lng'] ?? '') ? (float)$x['lng'] : null, max(1, nb($x['rayon_km'] ?? 15)), $jours, !empty($x['actif']) ? 1 : 0];
            if ($sid === 'nouveau') { if ($v[0] === '') continue; q("INSERT INTO secteurs (nom, couleur, lat, lng, rayon_km, jours, actif) VALUES (?,?,?,?,?,?,?)", $v); }
            else q("UPDATE secteurs SET nom = ?, couleur = ?, lat = ?, lng = ?, rayon_km = ?, jours = ?, actif = ? WHERE id = ?", array_merge($v, [(int)$sid]));
        }
        flash('Secteurs enregistrés.'); redirect('planning-ressources.php#secteurs');
    } elseif ($a === 'agenda') { $m = agendas_post(null); if ($m !== null) flash($m, str_contains($m, 'impossible') || str_contains($m, 'invalide') ? 'err' : 'ok'); redirect('planning-ressources.php#agendas');
    } elseif ($a === 'reglages') {
        foreach (['planning_debut', 'planning_fin'] as $k) if (preg_match('/^\d{1,2}:\d{2}$/', $_POST[$k] ?? '')) set_setting($k, $_POST[$k]);
        foreach (['planning_vitesse' => [15, 120], 'planning_facteur' => [1, 2], 'planning_vent' => [20, 150]] as $k => [$mi, $ma]) set_setting($k, (string)min($ma, max($mi, nb($_POST[$k] ?? $mi))));
        set_setting('planning_depot', trim($_POST['planning_depot'] ?? ''));
        if (isset($_POST['planning_motifs'])) set_setting('planning_motifs', trim($_POST['planning_motifs']));
        if (isset($_POST['planning_pictos'])) set_setting('planning_pictos', trim($_POST['planning_pictos']));
        if (is_numeric($_POST['planning_depot_lat'] ?? '') && is_numeric($_POST['planning_depot_lng'] ?? '')) { set_setting('planning_depot_lat', (string)(float)$_POST['planning_depot_lat']); set_setting('planning_depot_lng', (string)(float)$_POST['planning_depot_lng']); }
        if (empty(setting('planning_cle_cron'))) set_setting('planning_cle_cron', bin2hex(random_bytes(12)));
        flash('Réglages enregistrés.');
    }
    redirect('planning-ressources.php');
}
$pers = personnel_liste(true); $mats = materiel_liste(true); $eqs = equipes_liste();
$comptes = q("SELECT id, prenom, nom FROM utilisateurs WHERE actif = 1 ORDER BY prenom")->fetchAll();
if (empty(setting('planning_cle_cron'))) set_setting('planning_cle_cron', bin2hex(random_bytes(12)));
page_start('Personnel, matériel et équipes', 'planning.php', ['planning.php', 'Planning']);
?>
<style>
.tbl input,.tbl select{margin:0}.tbl .comp{display:flex;flex-wrap:wrap;gap:4px;max-width:330px}.tbl .comp label{display:inline-flex;gap:4px;align-items:center;margin:0;font-weight:400;font-size:.78rem;border:1px solid #d5dccd;border-radius:999px;padding:2px 7px;background:#fff}
.tbl .comp input,.tbl input[type=checkbox]{width:auto}.tbl input[type=color]{width:42px;height:32px;padding:2px}
.eq-carte{border-left:6px solid;}.eq-carte .choix{display:flex;flex-wrap:wrap;gap:5px}.eq-carte .choix label{display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:700;font-size:.83rem;border:1px solid #d5dccd;border-radius:999px;padding:4px 10px;background:#fff}.eq-carte .choix input{width:auto;margin:0}
</style>
<p class="lead">Les personnes, les machines et les équipes utilisées par le planning. Une <strong>équipe</strong> regroupe des personnes et leur matériel habituel : on l'affecte en un clic à un chantier. Un outil <strong>monté sur</strong> un porte-outil partage la même machine : il ne peut pas être utilisé en même temps qu'un autre outil du même porteur.</p>
<p><a class="btn secondary" href="planning.php">← Retour au planning</a> <a class="mini" href="#personnel">Personnel</a> <a class="mini" href="#materiel">Matériel</a> <a class="mini" href="#equipes">Équipes</a> <a class="mini" href="#secteurs">Secteurs</a> <a class="mini" href="#reglages">Réglages</a><?= est_admin() ? ' <a class="mini" href="reglages-planning.php">SMS, avis, Pennylane, Urgence Élagage…</a>' : '' ?></p>

<style>
.multi{position:relative;min-width:170px}.multi summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:6px;border:1px solid #d5dccd;border-radius:8px;padding:6px 9px;background:var(--blanc);font-size:.85rem;line-height:1.3}.multi summary::-webkit-details-marker{display:none}.multi summary .res{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}.multi summary i{font-style:normal;font-size:.7rem;opacity:.7}
.multi .comp{position:absolute;top:calc(100% + 4px);left:0;z-index:40;background:var(--blanc);border:1px solid #d5dccd;border-radius:10px;padding:8px;box-shadow:0 10px 24px rgba(28,59,43,.16);display:flex;flex-direction:column;gap:2px;min-width:220px}.multi .comp label{display:flex;gap:8px;align-items:center;margin:0;border:0;background:none;padding:5px 6px;border-radius:6px;font-size:.88rem;cursor:pointer;white-space:nowrap}.multi .comp label:hover{background:var(--clair)}.multi .comp input{width:auto;margin:0}
</style>
<h2 id="personnel">Personnel</h2>
<form method="post" data-paquet><?= csrf_field() ?><input type="hidden" name="action" value="personnel">
<div class="scroll"><table class="tbl"><thead><tr><th>Prénom</th><th>Nom</th><th>Poste</th><th>Téléphone</th><th>E-mail</th><th>Compétences</th><th>Compte de l'espace pro</th><th>Couleur</th><th>Ordre</th><th>Actif</th></tr></thead><tbody>
<?php foreach (array_merge($pers, [['id' => 'nouveau', 'prenom' => '', 'nom' => '', 'poste' => '', 'telephone' => '', 'email' => '', 'couleur' => '#2f6b45', 'competences' => '', 'utilisateur_id' => null, 'ordre' => count($pers) + 1, 'actif' => 1, 'ics_token' => '']]) as $p): $k = $p['id']; ?>
<tr<?= $k === 'nouveau' ? ' style="background:#f6f9f1"' : '' ?>>
  <td><input name="p[<?= $k ?>][prenom]" value="<?= e($p['prenom']) ?>" placeholder="<?= $k === 'nouveau' ? 'Nouvelle personne…' : '' ?>" style="min-width:110px"></td>
  <td><input name="p[<?= $k ?>][nom]" value="<?= e($p['nom']) ?>" style="min-width:110px"></td>
  <td><input name="p[<?= $k ?>][poste]" value="<?= e($p['poste']) ?>" placeholder="Grimpeur, chef d'équipe…" style="min-width:130px"></td>
  <td><input name="p[<?= $k ?>][telephone]" value="<?= e($p['telephone']) ?>" style="min-width:110px"></td>
  <td><input name="p[<?= $k ?>][email]" value="<?= e($p['email']) ?>" style="min-width:160px"></td>
  <td><details class="multi"><summary><span class="res"><?php $sel = array_values(array_intersect_key(COMPETENCES, array_flip(array_filter(explode(',', (string)$p['competences']))))); echo $sel ? e(implode(', ', $sel)) : '<span class="muted">Aucune</span>'; ?></span> <i>▾</i></summary><div class="comp"><?php foreach (COMPETENCES as $c => $l): ?><label><input type="checkbox" name="p[<?= $k ?>][comp][<?= $c ?>]" value="1" <?= str_contains(",{$p['competences']},", ",$c,") ? 'checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?></div></details></td>
  <td><select name="p[<?= $k ?>][utilisateur_id]"><option value="">—</option><?php foreach ($comptes as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$p['utilisateur_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['prenom'] . ' ' . $u['nom']) ?></option><?php endforeach; ?></select>
    <?php if ($k !== 'nouveau'): ?><br><a class="mini" href="livret.php?p=<?= $k ?>" title="Titres, habilitations, formations, documents">📘 Livret</a><?php endif; ?>
    <?php if ($k !== 'nouveau' && $p['ics_token']): ?><br><button type="button" class="mini" onclick="navigator.clipboard.writeText(this.dataset.l).then(()=>this.textContent='Lien copié !')" data-l="<?= e(lien_base() . '/planning-ics.php?t=' . $p['ics_token']) ?>" title="Adresse à ajouter dans l'agenda du téléphone (abonnement) : ses chantiers et rendez-vous s'y affichent tout seuls">Lien agenda du téléphone</button><?php endif; ?></td>
  <td><input type="color" name="p[<?= $k ?>][couleur]" value="<?= e($p['couleur']) ?>"></td>
  <td><input class="tiny" name="p[<?= $k ?>][ordre]" value="<?= (int)$p['ordre'] ?>"></td>
  <td><input type="checkbox" name="p[<?= $k ?>][actif]" value="1" <?= (int)$p['actif'] ? 'checked' : '' ?>></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<p><button class="btn">Enregistrer le personnel</button> <span class="muted small">Le « compte de l'espace pro » relie la personne à son compte : elle voit alors ses chantiers dans « Mon planning ». Le « lien agenda » s'ajoute à l'agenda du téléphone (Google Agenda : « À partir de l'URL » ; iPhone : Réglages › Calendrier › Comptes › Ajouter un calendrier avec abonnement).</span></p>
</form>

<script>
// liste déroulante des compétences : résumé mis à jour, un seul menu ouvert, fermeture au clic ailleurs
document.querySelectorAll('details.multi').forEach(d => { const maj = () => { const l = [...d.querySelectorAll('input:checked')].map(i => i.parentNode.textContent.trim()); d.querySelector('.res').innerHTML = l.length ? l.map(x => x.replace(/[<>&]/g, '')).join(', ') : '<span class="muted">Aucune</span>'; }; d.querySelectorAll('input').forEach(i => i.addEventListener('change', maj)); });
document.addEventListener('click', e => { const d = e.target.closest('details.multi'); document.querySelectorAll('details.multi[open]').forEach(x => { if (x !== d) x.open = false; }); });
</script>
<h2 id="materiel">Matériel</h2>
<form method="post" data-paquet><?= csrf_field() ?><input type="hidden" name="action" value="materiel">
<div class="scroll"><table class="tbl"><thead><tr><th>Nom</th><th>Type</th><th>Monté sur (machine partagée)</th><th>Immatriculation</th><th>Compétence requise</th><th>Prochain contrôle (VGP…)</th><th>Notes</th><th>Couleur</th><th>Ordre</th><th>Actif</th></tr></thead><tbody>
<?php foreach (array_merge($mats, [['id' => 'nouveau', 'nom' => '', 'type' => 'autre', 'immatriculation' => '', 'competence_requise' => '', 'controle_le' => null, 'couleur' => '#7a4fa0', 'notes' => '', 'ordre' => count($mats) + 1, 'actif' => 1, 'porteur_id' => null]]) as $m): $k = $m['id']; ?>
<tr<?= $k === 'nouveau' ? ' style="background:#f6f9f1"' : '' ?>>
  <td><input name="m[<?= $k ?>][nom]" value="<?= e($m['nom']) ?>" placeholder="<?= $k === 'nouveau' ? 'Nouvelle machine ou outil…' : '' ?>" style="min-width:170px"></td>
  <td><select name="m[<?= $k ?>][type]"><?php foreach (TYPES_MAT as $t => $l): ?><option value="<?= $t ?>" <?= $m['type'] === $t ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
  <td><select name="m[<?= $k ?>][porteur_id]" title="Si cet outil se monte sur une machine (porte-outil), choisis-la : l'outil et la machine ne pourront pas servir à deux chantiers en même temps"><option value="">— machine autonome —</option><?php foreach ($mats as $o): if ($o['id'] === $m['id']) continue; ?><option value="<?= $o['id'] ?>" <?= (int)($m['porteur_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['nom']) ?></option><?php endforeach; ?></select></td>
  <td><input name="m[<?= $k ?>][immatriculation]" value="<?= e($m['immatriculation']) ?>" style="width:110px"></td>
  <td><select name="m[<?= $k ?>][competence_requise]"><option value="">—</option><?php foreach (COMPETENCES as $c => $l): ?><option value="<?= $c ?>" <?= $m['competence_requise'] === $c ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
  <td><input type="date" name="m[<?= $k ?>][controle_le]" value="<?= e($m['controle_le'] ?? '') ?>"></td>
  <td><input name="m[<?= $k ?>][notes]" value="<?= e($m['notes']) ?>" style="min-width:150px"></td>
  <td><input type="color" name="m[<?= $k ?>][couleur]" value="<?= e($m['couleur']) ?>"></td>
  <td><input class="tiny" name="m[<?= $k ?>][ordre]" value="<?= (int)$m['ordre'] ?>"></td>
  <td><input type="checkbox" name="m[<?= $k ?>][actif]" value="1" <?= (int)$m['actif'] ? 'checked' : '' ?>></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<p><button class="btn">Enregistrer le matériel</button> <span class="muted small">Exemple : crée « Robo Evo Energreen » (porte-outil), puis « Tête forestière » et « Rogneuse de souches » avec « Monté sur : Robo Evo Energreen ». Réserver l'un des trois bloque les deux autres sur le même créneau.</span></p>
</form>

<h2 id="equipes">Équipes</h2>
<p class="muted">Une équipe se modifie à tout moment ; les chantiers déjà planifiés gardent les personnes qui y étaient affectées. Dans le planning, vue Ressources, glisse un chantier sur la ligne d'une équipe pour lui affecter toute l'équipe.</p>
<div class="cols">
<?php foreach (array_merge($eqs, [['id' => 'nouveau', 'nom' => '', 'couleur' => '#2f6b45', 'chef_id' => null, 'ordre' => count($eqs) + 1, 'membres' => [], 'materiel' => []]]) as $q): ?>
<form method="post" class="card eq-carte" style="border-left-color:<?= e($q['couleur']) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="equipe"><input type="hidden" name="id" value="<?= $q['id'] ?>">
  <h3 style="margin-top:0"><?= $q['id'] === 'nouveau' ? 'Nouvelle équipe' : e($q['nom']) ?></h3>
  <div class="cols"><label>Nom <input name="nom" value="<?= e($q['nom']) ?>" placeholder="Ex. Équipe Benjamin" required></label><label>Chef d'équipe <select name="chef_id"><option value="">—</option><?php foreach ($pers as $p): if (!(int)$p['actif']) continue; ?><option value="<?= $p['id'] ?>" <?= (int)$q['chef_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['prenom'] . ' ' . $p['nom']) ?></option><?php endforeach; ?></select></label></div>
  <p style="margin:6px 0 4px;font-weight:700">Membres</p><div class="choix"><?php foreach ($pers as $p): if (!(int)$p['actif']) continue; ?><label><input type="checkbox" name="membres[]" value="<?= $p['id'] ?>" <?= in_array((int)$p['id'], $q['membres'], true) ? 'checked' : '' ?>><?= e($p['prenom']) ?></label><?php endforeach; ?><?= !$pers ? '<span class="muted small">Ajoute d\'abord du personnel.</span>' : '' ?></div>
  <p style="margin:10px 0 4px;font-weight:700">Matériel habituel</p><div class="choix"><?php foreach ($mats as $m): if (!(int)$m['actif']) continue; ?><label><input type="checkbox" name="mat[]" value="<?= $m['id'] ?>" <?= in_array((int)$m['id'], $q['materiel'], true) ? 'checked' : '' ?>><?= e($m['nom']) ?></label><?php endforeach; ?></div>
  <div class="cols" style="align-items:end;margin-top:8px"><label>Couleur <input type="color" name="couleur" value="<?= e($q['couleur']) ?>" style="width:60px;height:34px;padding:2px"></label><label>Ordre <input class="tiny" name="ordre" value="<?= (int)$q['ordre'] ?>"></label></div>
  <p><button class="btn"><?= $q['id'] === 'nouveau' ? 'Créer l\'équipe' : 'Enregistrer' ?></button>
  <?php if ($q['id'] !== 'nouveau'): ?> <button class="mini danger" type="submit" form="eqs<?= $q['id'] ?>">Retirer</button><?php endif; ?></p>
</form>
<?php if ($q['id'] !== 'nouveau'): ?><form id="eqs<?= $q['id'] ?>" method="post" onsubmit="return confirm('Retirer cette équipe ?')"><?= csrf_field() ?><input type="hidden" name="action" value="equipe_suppr"><input type="hidden" name="id" value="<?= $q['id'] ?>"></form><?php endif; ?>
<?php endforeach; ?>
</div>

<section class="card" id="agendas"><?= agendas_bloc(array_values(array_filter($pers, fn($p) => (int)$p['actif'])), true) ?></section>

<h2 id="secteurs">Journées secteur</h2>
<p class="muted">Regroupe les visites par zone : un secteur = un point central (une commune) et un rayon. Si tu lui donnes des jours (ex. Lot nord le mardi), « Trouver un créneau » propose en priorité ces jours-là pour les visites de la zone, et évite d'y mettre des visites d'ailleurs.</p>
<form method="post" data-paquet><?= csrf_field() ?><input type="hidden" name="action" value="secteurs">
<div class="scroll"><table class="tbl"><thead><tr><th>Nom</th><th>Commune centrale</th><th>Rayon (km)</th><th>Jours réservés</th><th>Couleur</th><th>Actif</th></tr></thead><tbody>
<?php foreach (array_merge(q("SELECT * FROM secteurs ORDER BY ordre, nom")->fetchAll(), [['id' => 'nouveau', 'nom' => '', 'couleur' => '#3b6ea8', 'lat' => null, 'lng' => null, 'rayon_km' => 15, 'jours' => '', 'actif' => 1]]) as $x): $k = $x['id']; ?>
<tr class="sect"<?= $k === 'nouveau' ? ' style="background:#f6f9f1"' : '' ?>><td><input name="s[<?= $k ?>][nom]" value="<?= e($x['nom']) ?>" placeholder="<?= $k === 'nouveau' ? 'Nouveau secteur…' : '' ?>" style="min-width:140px"></td>
  <td class="ac"><input class="comm" placeholder="Tape une commune" value="<?= $x['lat'] !== null ? e(round((float)$x['lat'], 3) . ', ' . round((float)$x['lng'], 3)) : '' ?>" style="min-width:170px"><ul hidden></ul><input type="hidden" name="s[<?= $k ?>][lat]" value="<?= e((string)$x['lat']) ?>"><input type="hidden" name="s[<?= $k ?>][lng]" value="<?= e((string)$x['lng']) ?>"></td>
  <td><input class="tiny" name="s[<?= $k ?>][rayon_km]" value="<?= e((string)(float)$x['rayon_km']) ?>"></td>
  <td style="white-space:nowrap"><?php foreach (['1' => 'L', '2' => 'Ma', '3' => 'Me', '4' => 'J', '5' => 'V'] as $j => $l): ?><label style="display:inline-flex;gap:2px;margin:0 4px 0 0;font-weight:400"><input type="checkbox" name="s[<?= $k ?>][j][<?= $j ?>]" value="1" style="width:auto;margin:0" <?= in_array((string)$j, explode(',', $x['jours']), true) ? 'checked' : '' ?>><?= $l ?></label><?php endforeach; ?></td>
  <td><input type="color" name="s[<?= $k ?>][couleur]" value="<?= e($x['couleur']) ?>"></td><td><input type="checkbox" name="s[<?= $k ?>][actif]" value="1" <?= (int)$x['actif'] ? 'checked' : '' ?>></td></tr>
<?php endforeach; ?></tbody></table></div>
<p><button class="btn">Enregistrer les secteurs</button></p></form>
<script>
document.querySelectorAll('tr.sect').forEach(tr => { const a = tr.querySelector('.comm'), l = a.nextElementSibling, la = tr.querySelector('[name$="[lat]"]'), lo = tr.querySelector('[name$="[lng]"]'); let t, r = [];
  a.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => { if (a.value.length < 3) return; try { r = (await (await fetch('https://api-adresse.data.gouv.fr/search/?type=municipality&limit=5&lat=44.6&lon=1.95&q=' + encodeURIComponent(a.value))).json()).features || []; l.innerHTML = r.map((x, i) => `<li data-i="${i}">${x.properties.label.replace(/</g, '&lt;')}</li>`).join(''); l.hidden = !r.length; } catch (e) {} }, 250); });
  l.addEventListener('mousedown', ev => { const li = ev.target.closest('li'); if (!li) return; ev.preventDefault(); const x = r[+li.dataset.i]; a.value = x.properties.label; la.value = x.geometry.coordinates[1]; lo.value = x.geometry.coordinates[0]; l.hidden = true;
    const n = tr.querySelector('[name$="[nom]"]'); if (!n.value) n.value = x.properties.city || x.properties.label; });
  a.addEventListener('blur', () => setTimeout(() => l.hidden = true, 150)); });
</script>

<h2 id="reglages">Réglages des tournées</h2>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="reglages">
  <label class="ac">Adresse du dépôt (départ et retour des tournées) <input name="planning_depot" id="dep" value="<?= e(setting('planning_depot')) ?>"><ul id="depL" hidden style="position:absolute;z-index:5;background:#fff;border:1px solid #d5dccd;border-radius:8px;list-style:none;margin:2px 0 0;padding:4px;box-shadow:0 6px 16px rgba(0,0,0,.12)"></ul></label>
  <div class="cols"><label>Latitude <input name="planning_depot_lat" id="depLat" value="<?= e(setting('planning_depot_lat')) ?>"></label><label>Longitude <input name="planning_depot_lng" id="depLng" value="<?= e(setting('planning_depot_lng')) ?>"></label></div>
  <div class="cols"><label>Début de journée <input type="time" name="planning_debut" value="<?= e(setting('planning_debut')) ?>"></label><label>Fin de journée <input type="time" name="planning_fin" value="<?= e(setting('planning_fin')) ?>"></label></div>
  <div class="cols"><label>Vitesse moyenne sur route (km/h) <input name="planning_vitesse" value="<?= e(setting('planning_vitesse')) ?>"></label><label>Facteur route / vol d'oiseau <input name="planning_facteur" value="<?= e(setting('planning_facteur')) ?>"></label><label>Alerte vent : rafales à partir de (km/h) <input name="planning_vent" value="<?= e(setting('planning_vent')) ?>"></label></div>
  <label>Types de rendez-vous proposés dans la fiche (un par ligne) <textarea name="planning_motifs" rows="4"><?= e(setting('planning_motifs')) ?></textarea></label>
  <label>Pictogrammes de chantier (un par ligne : <code>code|icône|libellé|action à créer automatiquement|délai en jours avant le chantier</code> ; les deux derniers sont facultatifs) <textarea name="planning_pictos" rows="8" style="font-family:monospace;font-size:.85rem"><?= e(setting('planning_pictos')) ?></textarea></label>
  <p class="small" style="margin:4px 0">Icônes disponibles : <?= pictos_sprite() ?><?php foreach (array_keys(PICTO_ICONES) as $n): ?><span style="display:inline-flex;align-items:center;gap:3px;margin:0 8px 4px 0;border:1px solid #e0e5da;border-radius:8px;padding:2px 7px"><?= picto_html($n) ?> <code><?= $n ?></code></span><?php endforeach; ?></p>
  <p class="small muted">Cocher un pictogramme dans une fiche crée l'action indiquée dans « Actions à faire avant le chantier », avec une échéance calculée à partir de la date du chantier (ex. J-15 pour un arrêté de circulation). Le code ne doit pas changer une fois utilisé.</p>
  <p><button class="btn">Enregistrer les réglages</button></p>
  <p class="muted small">Rappels automatiques aux clients (la veille) : envoyés à l'ouverture du planning. Pour qu'ils partent même sans ouverture, ajoute dans OVH une tâche planifiée (Hébergement › Plus › Tâches planifiées – Cron) qui ouvre chaque heure : <code><?= e(lien_base() . '/planning-cron.php?cle=' . setting('planning_cle_cron')) ?></code></p>
</form>
<script>
(function(){ const a = document.getElementById('dep'), l = document.getElementById('depL'); let t, f = [];
  a.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => { if (a.value.length < 5) return; try { f = (await (await fetch('https://api-adresse.data.gouv.fr/search/?limit=5&q=' + encodeURIComponent(a.value))).json()).features || []; l.innerHTML = f.map((x, i) => '<li data-i="' + i + '" style="padding:6px 8px;cursor:pointer">' + x.properties.label.replace(/</g, '&lt;') + '</li>').join(''); l.hidden = !f.length; } catch (e) {} }, 250); });
  l.addEventListener('mousedown', ev => { const li = ev.target.closest('li'); if (!li) return; ev.preventDefault(); const x = f[+li.dataset.i]; a.value = x.properties.label; document.getElementById('depLat').value = x.geometry.coordinates[1]; document.getElementById('depLng').value = x.geometry.coordinates[0]; l.hidden = true; });
})();
</script>
<?php page_end(); ?>
