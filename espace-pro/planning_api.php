<?php
// Interface de données du planning (JSON) : lecture, enregistrement, déplacement, créneaux, tournées, e-mails
declare(strict_types=1);
require_once __DIR__ . '/planning_lib.php';
header('Content-Type: application/json; charset=UTF-8');
require_login(); planning_migrate();
$a = $_GET['a'] ?? '';
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$sortie = function ($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$erreur = function (string $m, int $code = 400) use ($sortie) { http_response_code($code); $sortie(['erreur' => $m]); };

// Lecture croisée : tout compte connecté consulte ; écriture selon les droits (peut_modifier_evt)
$LECTURE = ['liste', 'fiche', 'verifier', 'creneaux', 'tournee', 'clients'];
if (!in_array($a, $LECTURE, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') $erreur('Cette action demande un envoi (POST).', 405);
if (!in_array($a, $LECTURE, true) && !in_array($a, ['tache', 'prestation'], true) && !peut_planifier()) $erreur("Ton compte peut consulter les agendas mais pas les modifier. Demande l'autorisation à un administrateur.", 403);
$refus = fn(array $pers) => peut_modifier_evt($pers) ? null : "Tu n'as pas le droit de modifier l'agenda de toutes les personnes concernées.";
$avecTaches = function (array $evs): array { // nombre d'actions restantes / prestations par événement (badges)
    if (!$evs) return $evs; $ids = implode(',', array_map(fn($e) => (int)$e['id'], $evs)); $t = []; $pr = [];
    foreach (q("SELECT evenement_id, COUNT(*) n, SUM(fait_le IS NULL) r, SUM(fait_le IS NULL AND echeance IS NOT NULL AND echeance < CURDATE()) retard FROM evt_taches WHERE evenement_id IN ($ids) GROUP BY evenement_id") as $x) $t[$x['evenement_id']] = $x;
    foreach (q("SELECT evenement_id, COUNT(*) n, SUM(fait_le IS NOT NULL) f FROM evt_prestations WHERE evenement_id IN ($ids) GROUP BY evenement_id") as $x) $pr[$x['evenement_id']] = $x;
    foreach ($evs as &$e) { $e['pictos_l'] = pictos_evt($e); $e['taches_nb'] = (int)($t[$e['id']]['n'] ?? 0); $e['taches_restantes'] = (int)($t[$e['id']]['r'] ?? 0); $e['taches_retard'] = (int)($t[$e['id']]['retard'] ?? 0); $e['prestations_nb'] = (int)($pr[$e['id']]['n'] ?? 0); $e['prestations_faites'] = (int)($pr[$e['id']]['f'] ?? 0); } unset($e);
    return $evs;
};
$masquer = function (array $e): array { if (planning_niveau() !== 'tout') { $e['devis_montant'] = null; $e['notes'] = ''; } return $e; };

$dt = function ($v): ?string { $v = trim((string)$v); if ($v === '') return null; $t = strtotime($v); return $t ? date('Y-m-d H:i:s', $t) : null; };
$normaliser = function (array $e) use ($dt): array {
    $types = array_keys(TYPES_EVT); $stats = array_keys(STATUTS_EVT);
    $r = [
        'type' => in_array($e['type'] ?? '', $types, true) ? $e['type'] : 'chantier',
        'titre' => mb_substr(trim((string)($e['titre'] ?? '')), 0, 200),
        'statut' => in_array($e['statut'] ?? '', $stats, true) ? $e['statut'] : 'a_planifier',
        'debut' => $dt($e['debut'] ?? ''), 'fin' => $dt($e['fin'] ?? ''), 'journee' => !empty($e['journee']) ? 1 : 0,
        'client_nom' => mb_substr(trim((string)($e['client_nom'] ?? '')), 0, 150), 'client_tel' => mb_substr(trim((string)($e['client_tel'] ?? '')), 0, 30), 'client_email' => ($x = mb_substr(trim((string)($e['client_email'] ?? '')), 0, 190)) !== '' && filter_var($x, FILTER_VALIDATE_EMAIL) ? $x : '',
        'adresse' => mb_substr(trim((string)($e['adresse'] ?? '')), 0, 255), 'cp' => mb_substr(trim((string)($e['cp'] ?? '')), 0, 10), 'ville' => mb_substr(trim((string)($e['ville'] ?? '')), 0, 100),
        'lat' => is_numeric($e['lat'] ?? null) ? (float)$e['lat'] : null, 'lng' => is_numeric($e['lng'] ?? null) ? (float)$e['lng'] : null,
        'devis_ref' => mb_substr(trim((string)($e['devis_ref'] ?? '')), 0, 60), 'devis_montant' => ($e['devis_montant'] ?? '') === '' || $e['devis_montant'] === null ? null : nb($e['devis_montant']),
        'devis_signe' => !empty($e['devis_signe']) ? 1 : 0, 'devis_lien' => preg_match('#^https?://#i', $x = mb_substr(trim((string)($e['devis_lien'] ?? '')), 0, 500)) ? $x : '',
        'bordereau_id' => !empty($e['bordereau_id']) ? (int)$e['bordereau_id'] : null, 'equipe_id' => !empty($e['equipe_id']) ? (int)$e['equipe_id'] : null,
        'duree_h' => ($e['duree_h'] ?? '') === '' || $e['duree_h'] === null ? null : nb($e['duree_h']), 'nb_jours' => ($e['nb_jours'] ?? '') === '' || $e['nb_jours'] === null ? null : nb($e['nb_jours']),
        'besoin_personnes' => max(0, (int)($e['besoin_personnes'] ?? 0)), 'besoin_materiel' => implode(',', array_intersect((array)($e['besoin_materiel'] ?? []), array_keys(TYPES_MAT))),
        'priorite' => min(3, max(1, (int)($e['priorite'] ?? 2))), 'souhait_client' => mb_substr(trim((string)($e['souhait_client'] ?? '')), 0, 255), 'motif' => mb_substr(trim((string)($e['motif'] ?? '')), 0, 60),
        'notes' => (string)($e['notes'] ?? ''), 'consignes' => (string)($e['consignes'] ?? ''),
        'dict_requise' => !empty($e['dict_requise']) ? 1 : 0, 'dict_ref' => mb_substr(trim((string)($e['dict_ref'] ?? '')), 0, 60),
        'dict_envoi' => ($x = $dt($e['dict_envoi'] ?? '')) ? substr($x, 0, 10) : null, 'dict_retour' => ($x = $dt($e['dict_retour'] ?? '')) ? substr($x, 0, 10) : null,
        'client_id' => !empty($e['client_id']) ? (int)$e['client_id'] : null, 'phyto' => !empty($e['phyto']) ? 1 : 0,
        'pictos' => implode(',', array_values(array_intersect(array_map('strval', (array)($e['pictos'] ?? [])), array_keys(pictos_liste())))),
    ];
    if ($r['debut'] && !$r['fin']) $r['fin'] = date('Y-m-d H:i:s', strtotime($r['debut']) + 3600);
    if ($r['debut'] && $r['fin'] && strtotime($r['fin']) <= strtotime($r['debut'])) $r['fin'] = date('Y-m-d H:i:s', strtotime($r['debut']) + 3600);
    if ($r['statut'] !== 'a_planifier' && !$r['debut'] && $r['statut'] !== 'annule') $r['statut'] = 'a_planifier';
    if ($r['statut'] === 'a_planifier' && $r['debut'] && $r['type'] !== 'absence') $r['statut'] = 'propose'; // une date posée = proposée
    $r['personnel'] = array_values(array_unique(array_map('intval', (array)($e['personnel'] ?? []))));
    $r['materiel'] = array_values(array_unique(array_map('intval', (array)($e['materiel'] ?? []))));
    return $r;
};
$affecter = function (int $id, array $pers, array $mat) {
    q("DELETE FROM evenement_personnel WHERE evenement_id = ?", [$id]); foreach ($pers as $p) q("INSERT IGNORE INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$id, $p]);
    q("DELETE FROM evenement_materiel WHERE evenement_id = ?", [$id]); foreach ($mat as $m) q("INSERT IGNORE INTO evenement_materiel (evenement_id, materiel_id) VALUES (?,?)", [$id, $m]);
};
$resume = function (array $e): string { return TYPES_EVT[$e['type']][0] . ' « ' . $e['titre'] . ' » ' . ($e['debut'] ? evt_quand($e) : '(à planifier)'); };
$avecConflits = function (array $evs): array { foreach ($evs as &$e) { $e['alertes'] = array_values(array_filter(evt_conflits($e), fn($c) => !str_starts_with($c, 'Tombe un week-end') || $e['type'] !== 'absence')); } unset($e); return $evs; };

switch ($a) {
    case 'liste':
        $du = date('Y-m-d', strtotime((string)($_GET['du'] ?? '')) ?: strtotime('monday this week')); $au = date('Y-m-d', strtotime((string)($_GET['au'] ?? '')) ?: strtotime("$du +7 days"));
        try { urgence_sync(); } catch (Throwable $t) {}
        $sortie([
            'secteurs' => secteurs_liste(), 'astreinte' => (function () { try { return urgence_astreinte(); } catch (Throwable $t) { return null; } })(),
            'evenements' => array_merge(array_map($masquer, $avecConflits($avecTaches(evt_periode($du, $au)))), (function () use ($du, $au) { try { return externes_periode($du, $au); } catch (Throwable $t) { return []; } })()),
            'pictos' => array_values(pictos_liste()), 'mes_taches' => mes_taches(),
            'a_planifier' => planning_niveau() === 'tout' ? $avecTaches(q("SELECT * FROM evenements WHERE statut = 'a_planifier' ORDER BY priorite DESC, cree_le")->fetchAll()) : [],
            'personnel' => personnel_liste(), 'materiel' => materiel_liste(), 'equipes' => equipes_liste(), 'alertes' => planning_niveau() === 'tout' ? planning_alertes() : [],
            'droits' => ['niveau' => planning_niveau(), 'modifiables' => agendas_modifiables(), 'planifier' => peut_planifier()],
            'motifs' => motifs_rdv(), 'reglages' => ['debut' => setting('planning_debut', '08:00'), 'fin' => setting('planning_fin', '17:00'), 'vent' => (float)setting('planning_vent', '50'), 'depot' => depot(), 'feries' => array_merge(jours_feries((int)substr($du, 0, 4)), jours_feries((int)substr($au, 0, 4)))],
        ]);
    case 'fiche':
        $e = evt_charger((int)($_GET['id'] ?? 0)); if (!$e) $erreur('Fiche introuvable.', 404);
        $e = $masquer($e); $e['modifiable'] = peut_modifier_evt($e['personnel']);
        if ((int)$e['phyto']) { $e['phyto_fiche'] = phyto_charger((int)$e['id']); $e['phyto_fiche']['code_auto'] = phyto_code($e); $e['phyto_zone'] = phyto_zone_delimitee($e['ville'], $e['cp']); $e['phyto_verrou'] = (bool)$e['phyto_fiche']['verrou_le']; }
        $e['alertes'] = evt_conflits($e); $e['pictos_l'] = pictos_evt($e); $e['taches'] = taches_liste((int)$e['id']); $e['prestations'] = prestations_liste((int)$e['id']); $e['personnel_lie'] = mon_personnel_id();
        $e['journal'] = q("SELECT j.quand, j.quoi, u.prenom FROM evenement_journal j LEFT JOIN utilisateurs u ON u.id = j.qui WHERE j.evenement_id = ? ORDER BY j.id DESC LIMIT 50", [$e['id']])->fetchAll();
        $sortie($e);
    case 'verifier':
        $r = $normaliser($in); $r['id'] = (int)($in['id'] ?? 0); $sortie(['alertes' => evt_conflits($r)]);
    case 'enregistrer':
        $r = $normaliser($in); $id = (int)($in['id'] ?? 0);
        if ($r['type'] === 'visite' && $r['motif'] === '') $r['motif'] = 'Visite de chiffrage';
        if ($r['titre'] === '') $r['titre'] = trim(($r['motif'] !== '' && $r['type'] !== 'chantier' ? $r['motif'] . ' – ' : ($r['type'] === 'visite' ? 'Visite ' : ($r['type'] === 'absence' ? 'Absence ' : ''))) . ($r['client_nom'] ?: $r['ville'])) ?: TYPES_EVT[$r['type']][0];
        $cols = ['type', 'titre', 'statut', 'debut', 'fin', 'journee', 'client_nom', 'client_tel', 'client_email', 'adresse', 'cp', 'ville', 'lat', 'lng', 'devis_ref', 'devis_montant', 'devis_signe', 'devis_lien', 'bordereau_id', 'equipe_id', 'duree_h', 'nb_jours', 'besoin_personnes', 'besoin_materiel', 'priorite', 'souhait_client', 'notes', 'consignes', 'dict_requise', 'dict_ref', 'dict_envoi', 'dict_retour', 'client_id', 'phyto', 'motif', 'pictos'];
        $avant = $id ? evt_charger($id) : null;
        if ($id && !$avant) $erreur('Fiche introuvable.', 404);
        if ($avant && ($m = $refus($avant['personnel']))) $erreur($m, 403);
        if ($m = $refus($r['personnel'])) $erreur($m . ' Coche au moins une personne dont tu gères l\'agenda.', 403);
        if ($avant && planning_niveau() !== 'tout') { $r['devis_montant'] = $avant['devis_montant']; $r['notes'] = $avant['notes']; } // champs non affichés : conservés
        try { $r['client_id'] = client_assurer($r); } catch (Throwable $t) {}
        if ($avant) {
            q("UPDATE evenements SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . " WHERE id = ?", array_merge(array_map(fn($c) => $r[$c], $cols), [$id]));
            $ch = [];
            $dateCh = (int)$avant['journee'] && $r['journee'] ? substr((string)$avant['debut'], 0, 10) !== substr((string)$r['debut'], 0, 10) || substr((string)$avant['fin'], 0, 10) !== substr((string)$r['fin'], 0, 10) : $avant['debut'] !== $r['debut'];
            if ($dateCh) { $ch[] = 'date : ' . ($avant['debut'] ? evt_quand($avant) : '—') . ' → ' . ($r['debut'] ? evt_quand($r + ['journee' => $r['journee']]) : '—');
                q("UPDATE evenements SET rappel_le = NULL, proposition_le = NULL WHERE id = ?", [$id]); // nouvelle date : rappel et proposition à refaire
                if ($avant['statut'] === 'confirme' && $r['statut'] === 'confirme' && $r['client_email'] !== '') { q("UPDATE evenements SET statut = 'propose', client_reponse = '' WHERE id = ?", [$id]); $r['statut'] = 'propose'; $ch[] = 'à reconfirmer par le client'; } }
            if ($avant['statut'] !== $r['statut']) $ch[] = 'statut : ' . STATUTS_EVT[$avant['statut']] . ' → ' . STATUTS_EVT[$r['statut']];
            if ($avant['personnel'] != $r['personnel'] || $avant['materiel'] != $r['materiel']) $ch[] = 'affectations modifiées';
            evt_journal($id, $ch ? implode(' ; ', $ch) : 'Fiche modifiée');
        } else {
            q("INSERT INTO evenements (" . implode(', ', $cols) . ", cree_par) VALUES (" . implode(',', array_fill(0, count($cols) + 1, '?')) . ")", array_merge(array_map(fn($c) => $r[$c], $cols), [user()['id']]));
            $id = (int)db()->lastInsertId(); evt_journal($id, 'Création : ' . $resume($r));
        }
        $affecter($id, $r['personnel'], $r['materiel']);
        if ($r['phyto'] && is_array($in['phyto_fiche'] ?? null)) phyto_enregistrer($id, $in['phyto_fiche']);
        try { kleyk_init($id); } catch (Throwable $t) {}
        $e = evt_charger($id);
        try { taches_depuis_pictos($e); if ($avant && $avant['debut'] !== $e['debut']) taches_recaler($e);
              if ($e['bordereau_id'] && (!$avant || (int)$avant['bordereau_id'] !== (int)$e['bordereau_id']) && !prestations_liste($id)) prestations_depuis_bordereau($id, (int)$e['bordereau_id']); } catch (Throwable $t) {}
        if ($e['statut'] === 'termine' && (!$avant || $avant['statut'] !== 'termine')) q("UPDATE evenements SET termine_le = NOW() WHERE id = ?", [$id]);
        if ($e['statut'] === 'termine' && (int)$e['phyto']) phyto_verrouiller($id);
        if ($e['debut'] && $e['statut'] !== 'annule') {
            $nouveaux = $avant ? array_diff($e['personnel'], $avant['personnel']) : $e['personnel'];
            $dateCh = $avant && ($avant['debut'] !== $e['debut'] || $avant['fin'] !== $e['fin']);
            if ($nouveaux) notifier_evt(['personnel' => array_values($nouveaux)] + $e, 'Nouveau : ' . $e['titre'], ucfirst(evt_quand($e)) . ($e['ville'] ? ' – ' . $e['ville'] : ''));
            if ($dateCh) notifier_evt(['personnel' => array_values(array_intersect($e['personnel'], $avant['personnel']))] + $e, 'Changement : ' . $e['titre'], 'Nouvelle date : ' . evt_quand($e));
        }
        $sortie(['id' => $id, 'statut' => $e['statut'], 'alertes' => evt_conflits($e), 'client_id' => $e['client_id']]);
    case 'deplacer':
        // Glisser-déposer : nouvelle date et, en vue Ressources, changement de personne ou de machine
        $e = evt_charger((int)($in['id'] ?? 0)); if (!$e) $erreur('Fiche introuvable.', 404);
        $deb = $dt($in['debut'] ?? ''); $fin = $dt($in['fin'] ?? ''); if (!$deb || !$fin) $erreur('Date invalide.');
        if ($m = $refus($e['personnel'])) $erreur($m, 403);
        $pers = $e['personnel']; $mat = $e['materiel']; $equipe = $e['equipe_id'];
        if (!empty($in['vers']) && str_starts_with((string)$in['vers'], 'e:')) { // déposé sur une équipe : l'équipe et son matériel habituel
            foreach (equipes_liste() as $eq) if ((int)$eq['id'] === (int)substr((string)$in['vers'], 2)) { $equipe = (int)$eq['id']; $pers = $eq['membres']; if ($eq['materiel']) $mat = $eq['materiel']; }
            $in['vers'] = null;
        }
        if (!empty($in['de']) && !empty($in['vers']) && $in['de'] !== $in['vers']) {
            [$kd, $idd] = explode(':', (string)$in['de']) + [1 => 0]; [$kv, $idv] = explode(':', (string)$in['vers']) + [1 => 0];
            if ($kd === 'p') $pers = array_values(array_diff($pers, [(int)$idd])); if ($kd === 'm') $mat = array_values(array_diff($mat, [(int)$idd]));
            if ($kv === 'p') $pers[] = (int)$idv; if ($kv === 'm') $mat[] = (int)$idv;
            $pers = array_values(array_unique($pers)); $mat = array_values(array_unique($mat));
        }
        if ($m = $refus($pers)) $erreur($m, 403);
        $statut = $e['statut'] === 'a_planifier' ? 'propose' : $e['statut'];
        $reconf = $e['statut'] === 'confirme' && $e['debut'] !== $deb && $e['client_email'] !== '';
        if ($reconf) $statut = 'propose';
        q("UPDATE evenements SET debut = ?, fin = ?, statut = ?, equipe_id = ?" . ($reconf ? ", client_reponse = ''" : '') . ($e['debut'] !== $deb ? ", rappel_le = NULL, proposition_le = NULL" : '') . " WHERE id = ?", [$deb, $fin, $statut, $equipe, $e['id']]);
        $affecter((int)$e['id'], $pers, $mat);
        $n = evt_charger((int)$e['id']);
        $neufs = array_diff($n['personnel'], $e['personnel']);
        if ($neufs) notifier_evt(['personnel' => array_values($neufs)] + $n, 'Nouveau : ' . $n['titre'], ucfirst(evt_quand($n)));
        if ($e['debut'] !== $n['debut'] || $e['fin'] !== $n['fin']) notifier_evt(['personnel' => array_values(array_intersect($n['personnel'], $e['personnel']))] + $n, 'Changement : ' . $n['titre'], 'Nouvelle date : ' . evt_quand($n));
        evt_journal((int)$e['id'], 'Déplacé : ' . ($e['debut'] ? evt_quand($e) : 'à planifier') . ' → ' . evt_quand($n) . ($reconf ? ' (à reconfirmer par le client)' : ''));
        $sortie(['ok' => 1, 'alertes' => evt_conflits($n), 'reconfirmer' => $reconf]);
    case 'supprimer':
        $id = (int)($in['id'] ?? 0); $e = evt_charger($id); if (!$e) $erreur('Fiche introuvable.', 404); if ($m = $refus($e['personnel'])) $erreur($m, 403);
        if ($e['contrat_id'] || $e['urgence_ref'] !== '' || q("SELECT 1 FROM rapports WHERE evenement_id = ? AND signe_le IS NOT NULL", [$id])->fetch()) { // passage de contrat, urgence ou bon signé : on annule, on ne supprime pas
            q("UPDATE evenements SET statut = 'annule' WHERE id = ?", [$id]); evt_journal($id, 'Annulé (au lieu de supprimé : lié à un contrat, à une urgence ou à un bon signé)'); $sortie(['ok' => 1, 'annule' => 1]);
        }
        foreach (q("SELECT * FROM rapport_photos WHERE evenement_id = ?", [$id]) as $ph) foreach ([$ph['fichier'], $ph['original']] as $f) if ($f !== '' && ($c = chemin_fichier($f))) @unlink($c);
        foreach (['evenement_personnel', 'evenement_materiel', 'rapport_photos', 'rapports', 'evenement_journal', 'phyto_chantiers', 'evt_taches', 'evt_prestations'] as $t) try { q("DELETE FROM $t WHERE evenement_id = ?", [$id]); } catch (Throwable $t2) {}
        q("UPDATE conges SET evenement_id = NULL, statut = IF(statut = 'accepte', 'annule', statut) WHERE evenement_id = ?", [$id]);
        q("DELETE FROM evenements WHERE id = ?", [$id]);
        journal('planning', 'Supprimé : ' . $resume($e));
        $sortie(['ok' => 1]);
    case 'dupliquer':
        $e = evt_charger((int)($in['id'] ?? 0)); if (!$e) $erreur('Fiche introuvable.', 404); if ($m = $refus($e['personnel'])) $erreur($m, 403);
        $cols = ['type', 'motif', 'titre', 'client_id', 'client_nom', 'client_tel', 'client_email', 'adresse', 'cp', 'ville', 'lat', 'lng', 'dict_requise', 'phyto', 'devis_ref', 'devis_montant', 'devis_signe', 'devis_lien', 'bordereau_id', 'equipe_id', 'duree_h', 'nb_jours', 'besoin_personnes', 'besoin_materiel', 'priorite', 'notes', 'consignes', 'journee', 'pictos'];
        q("INSERT INTO evenements (" . implode(', ', $cols) . ", statut, cree_par) VALUES (" . implode(',', array_fill(0, count($cols) + 2, '?')) . ")", array_merge(array_map(fn($c) => $e[$c], $cols), ['a_planifier', user()['id']]));
        $nid = (int)db()->lastInsertId(); evt_journal($nid, "Copie de la fiche n° {$e['id']}");
        $mod = agendas_modifiables(); foreach ($e['personnel'] as $p) if ($mod === null || in_array((int)$p, $mod, true)) q("INSERT IGNORE INTO evenement_personnel (evenement_id, personnel_id) VALUES (?,?)", [$nid, $p]);
        foreach ($e['materiel'] as $mm) q("INSERT IGNORE INTO evenement_materiel (evenement_id, materiel_id) VALUES (?,?)", [$nid, $mm]);
        $sortie(['id' => $nid]);
    case 'creneaux':
        $e = !empty($_GET['id']) ? evt_charger((int)$_GET['id']) : null;
        $p = $e ? array_merge($e, array_filter($in, fn($v) => $v !== null && $v !== '')) : $in;
        if (($p['type'] ?? 'chantier') === 'chantier') {
            $p['besoin_materiel'] = is_array($p['besoin_materiel'] ?? null) ? implode(',', $p['besoin_materiel']) : ($p['besoin_materiel'] ?? '');
            $sortie(['type' => 'chantier', 'creneaux' => creneaux_chantier($p)]);
        }
        if (empty($p['lat'])) $erreur("Renseigne d'abord l'adresse (choisis-la dans la liste proposée) pour calculer les tournées.");
        $dur = (int)round(((float)($p['duree_h'] ?? 0) ?: 1) * 60);
        $pid = !empty($p['personnel'][0]) ? (int)$p['personnel'][0] : null;
        $sortie(['type' => 'visite', 'creneaux' => creneaux_visite((float)$p['lat'], (float)$p['lng'], $dur, $pid, 5, 15, (int)($e['id'] ?? 0))]);
    case 'tournee':
        $jour = date('Y-m-d', strtotime((string)($_GET['jour'] ?? '')) ?: time()); $pid = (int)($_GET['personnel'] ?? 0);
        $sql = "SELECT ev.* FROM evenements ev" . ($pid ? " JOIN evenement_personnel ep ON ep.evenement_id = ev.id AND ep.personnel_id = $pid" : '') . " WHERE ev.statut <> 'annule' AND ev.type IN ('visite','rdv','chantier') AND ev.debut < ? AND ev.fin > ? ORDER BY ev.debut";
        $evs = q($sql, [date('Y-m-d', strtotime("$jour +1 day")), $jour])->fetchAll();
        $sansGeo = array_values(array_filter($evs, fn($x) => $x['lat'] === null));
        $pts = array_map(fn($x) => ['id' => (int)$x['id'], 'lat' => (float)$x['lat'], 'lng' => (float)$x['lng'], 'titre' => $x['titre'], 'type' => $x['type'], 'adresse' => trim($x['adresse'] . ' ' . $x['cp'] . ' ' . $x['ville']), 'statut' => $x['statut'],
                                     'duree_min' => (int)round((strtotime($x['fin']) - strtotime($x['debut'])) / 60), 'heure_actuelle' => substr($x['debut'], 11, 5)], array_values(array_filter($evs, fn($x) => $x['lat'] !== null)));
        $actuel = tournee_horaires($pts, $jour, $pts ? $pts[0]['heure_actuelle'] : '');
        $opt = tournee_horaires(tournee_ordre($pts), $jour);
        $sortie(['jour' => $jour, 'depot' => depot(), 'actuel' => $actuel, 'optimise' => $opt, 'sans_adresse' => array_map(fn($x) => $x['titre'], $sansGeo)]);
    case 'appliquer_tournee':
        $n = 0;
        foreach ((array)($in['etapes'] ?? []) as $et) {
            $e = evt_charger((int)$et['id']); $deb = $dt($et['arrivee'] ?? ''); if (!$e || !$deb || $e['type'] === 'chantier' || !peut_modifier_evt($e['personnel'])) continue;
            $dur = strtotime($e['fin']) - strtotime($e['debut']); $fin = date('Y-m-d H:i:s', strtotime($deb) + $dur);
            if ($deb === $e['debut']) continue;
            $reconf = $e['statut'] === 'confirme' && $e['client_email'] !== '';
            q("UPDATE evenements SET debut = ?, fin = ?, rappel_le = NULL, proposition_le = NULL" . ($reconf ? ", statut = 'propose', client_reponse = ''" : '') . " WHERE id = ?", [$deb, $fin, $e['id']]);
            evt_journal((int)$e['id'], 'Tournée optimisée : ' . date('H:i', strtotime($e['debut'])) . ' → ' . date('H:i', strtotime($deb)) . ($reconf ? ' (à reconfirmer par le client)' : '')); $n++;
        }
        $sortie(['modifies' => $n]);
    case 'proposer':
        if (($x = evt_charger((int)($in['id'] ?? 0))) && ($m = $refus($x['personnel']))) $erreur($m, 403);
        $m = envoyer_proposition((int)($in['id'] ?? 0)); if ($m !== '') $erreur($m); $sortie(['ok' => 1]);
    case 'reponse_traitee':
        if (($x = evt_charger((int)($in['id'] ?? 0))) && ($m = $refus($x['personnel']))) $erreur($m, 403);
        q("UPDATE evenements SET client_reponse = 'vu' WHERE id = ? AND client_reponse = 'autre'", [(int)($in['id'] ?? 0)]); $sortie(['ok' => 1]);
    case 'facturer':
        if (planning_niveau() !== 'tout') $erreur('Réservé à l\'administration.', 403);
        [$ok, $m] = pl_facturer((int)($in['id'] ?? 0)); if (!$ok) $erreur($m); $sortie(['ok' => 1]);
    case 'tache': // actions à faire avant le chantier : op = ajouter / fait / defait / supprimer / attribuer
        $op = (string)($in['op'] ?? ''); $eid = (int)($in['evenement_id'] ?? 0); $tid = (int)($in['id'] ?? 0);
        if ($tid) { $t = q("SELECT * FROM evt_taches WHERE id = ?", [$tid])->fetch(); if (!$t) $erreur('Action introuvable.', 404); $eid = (int)$t['evenement_id']; } else $t = ['personnel_id' => null];
        $e = evt_charger($eid); if (!$e) $erreur('Fiche introuvable.', 404);
        if (!peut_tache($t, $e)) $erreur("Tu ne peux pas modifier cette action.", 403);
        if ($op === 'ajouter') { if (!peut_planifier() && !in_array(mon_personnel_id(), $e['personnel'], true)) $erreur('Non autorisé.', 403); $ech = ($x = $dt($in['echeance'] ?? '')) ? substr($x, 0, 10) : null; tache_ajouter($eid, (string)($in['libelle'] ?? ''), !empty($in['personnel_id']) ? (int)$in['personnel_id'] : null, $ech); }
        elseif ($op === 'fait' && $tid) { q("UPDATE evt_taches SET fait_le = NOW(), fait_par = ? WHERE id = ?", [user()['id'], $tid]); evt_journal($eid, "Fait : {$t['libelle']}"); }
        elseif ($op === 'defait' && $tid) q("UPDATE evt_taches SET fait_le = NULL, fait_par = NULL WHERE id = ?", [$tid]);
        elseif ($op === 'supprimer' && $tid) { if (!peut_planifier()) $erreur('Non autorisé.', 403); q("DELETE FROM evt_taches WHERE id = ?", [$tid]); }
        elseif ($op === 'attribuer' && $tid) { if (!peut_planifier()) $erreur('Non autorisé.', 403); $p = !empty($in['personnel_id']) ? (int)$in['personnel_id'] : null; $ech = ($x = $dt($in['echeance'] ?? '')) ? substr($x, 0, 10) : null;
            q("UPDATE evt_taches SET personnel_id = ?, echeance = ?, delai_j = NULL, libelle = ? WHERE id = ?", [$p, $ech, mb_substr(trim((string)($in['libelle'] ?? $t['libelle'])), 0, 150) ?: $t['libelle'], $tid]); if ($p && $p !== (int)$t['personnel_id']) tache_notifier($tid, 'À faire'); }
        else $erreur('Opération inconnue.');
        $sortie(['taches' => taches_liste($eid), 'mes_taches' => mes_taches()]);
    case 'prestation': // relevé de chantier : op = ajouter / coller / devis / fait / defait / supprimer / remarque
        $op = (string)($in['op'] ?? ''); $eid = (int)($in['evenement_id'] ?? 0); $pid = (int)($in['id'] ?? 0);
        if ($pid) { $p = q("SELECT * FROM evt_prestations WHERE id = ?", [$pid])->fetch(); if (!$p) $erreur('Prestation introuvable.', 404); $eid = (int)$p['evenement_id']; }
        $e = evt_charger($eid); if (!$e) $erreur('Fiche introuvable.', 404);
        if (!peut_tache(['personnel_id' => null], $e)) $erreur("Tu ne peux pas modifier ce chantier.", 403);
        if ($op === 'ajouter') prestation_ajouter($eid, (string)($in['libelle'] ?? ''), $in['quantite'] ?? null, (string)($in['unite'] ?? ''));
        elseif ($op === 'coller') prestations_depuis_texte($eid, (string)($in['texte'] ?? ''));
        elseif ($op === 'devis') { if (!$e['bordereau_id']) $erreur('Aucun bordereau lié à ce chantier.'); prestations_depuis_bordereau($eid, (int)$e['bordereau_id']); }
        elseif ($op === 'fait' && $pid) q("UPDATE evt_prestations SET fait_le = NOW(), fait_par = ? WHERE id = ?", [user()['id'], $pid]);
        elseif ($op === 'defait' && $pid) q("UPDATE evt_prestations SET fait_le = NULL, fait_par = NULL WHERE id = ?", [$pid]);
        elseif ($op === 'remarque' && $pid) q("UPDATE evt_prestations SET remarque = ? WHERE id = ?", [mb_substr(trim((string)($in['remarque'] ?? '')), 0, 255), $pid]);
        elseif ($op === 'supprimer' && $pid) { if (!peut_planifier()) $erreur('Non autorisé.', 403); q("DELETE FROM evt_prestations WHERE id = ?", [$pid]); }
        else $erreur('Opération inconnue.');
        $sortie(['prestations' => prestations_liste($eid)]);
    case 'clients': // recherche dans le répertoire (saisie semi-automatique)
        $t = trim((string)($_GET['q'] ?? '')); if (mb_strlen($t) < 2) $sortie(['clients' => []]);
        $l = q("SELECT id, nom, type, telephone, mobile, email, ok_sms, ok_email FROM clients WHERE actif = 1 AND (nom LIKE ? OR email LIKE ? OR REPLACE(mobile,' ','') LIKE ? OR REPLACE(telephone,' ','') LIKE ?) ORDER BY nom LIMIT 12", ["%$t%", "%$t%", '%' . str_replace(' ', '', $t) . '%', '%' . str_replace(' ', '', $t) . '%'])->fetchAll();
        foreach ($l as &$c) $c['adresses'] = q("SELECT * FROM client_adresses WHERE client_id = ? ORDER BY ordre, id", [$c['id']])->fetchAll(); unset($c);
        $sortie(['clients' => $l]);
    default:
        $erreur('Action inconnue.');
}
