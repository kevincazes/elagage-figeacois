<?php
// Livret du salarié : titres et habilitations (SST, CACES, autorisations de conduite…) avec validité et scans, formations, documents.
// Un titre valide donne la compétence correspondante au planning ; expiré, il la retire (et le planning signale le conflit).
declare(strict_types=1);

const LIVRET_TITRES_DEFAUT = "sst|SST – Sauveteur secouriste du travail|24|
caces_r486|CACES R486 – nacelle (PEMP)|60|nacelle
caces_r482|CACES R482 – engins de chantier|120|
caces_r489|CACES R489 – chariot élévateur|60|
permis_b|Permis B|0|
permis_be|Permis BE (remorque)|0|
permis_c|Permis C (poids lourd)|60|pl
autorisation_conduite|Autorisation de conduite employeur (par engin)|12|
aipr|AIPR – travaux à proximité des réseaux|60|
habilitation_elec|Habilitation électrique (H0B0 / BF-HF élagage)|36|
hauteur|Travail en hauteur / secours en hauteur (GSA)|36|grimpeur
cs_arboriste|CS Arboriste élagueur|0|grimpeur
chancre|Formation chancre coloré du platane|0|
tronconneuse|Formation tronçonneuse / abattage|0|abattage
visite_medicale|Visite médicale (suivi individuel renforcé)|24|";
const DOSSIERS_LIVRET = ['Diplômes et formations', 'Contrat et fiche de poste', 'Autorisations de conduite', 'Attestations', 'Autres'];

function livret_migrate(): void {
    $pdo = db();
    foreach (["ALTER TABLE personnel ADD COLUMN entree_le DATE NULL", "ALTER TABLE personnel ADD COLUMN contrat VARCHAR(40) NOT NULL DEFAULT ''", "ALTER TABLE personnel ADD COLUMN notes_livret TEXT",
              "CREATE TABLE IF NOT EXISTS salarie_titres (id INT AUTO_INCREMENT PRIMARY KEY, personnel_id INT NOT NULL, code VARCHAR(40) NOT NULL DEFAULT '', libelle VARCHAR(150) NOT NULL, obtenu_le DATE NULL, valide_jusqua DATE NULL, organisme VARCHAR(150) NOT NULL DEFAULT '', reference VARCHAR(80) NOT NULL DEFAULT '', precision_t VARCHAR(150) NOT NULL DEFAULT '', notes VARCHAR(500) NOT NULL DEFAULT '', fichier VARCHAR(80) NOT NULL DEFAULT '', fichier_nom VARCHAR(200) NOT NULL DEFAULT '', rappel_le DATETIME NULL, cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (personnel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
              "CREATE TABLE IF NOT EXISTS salarie_formations (id INT AUTO_INCREMENT PRIMARY KEY, personnel_id INT NOT NULL, intitule VARCHAR(150) NOT NULL, code VARCHAR(40) NOT NULL DEFAULT '', prevue_le DATE NULL, fin_le DATE NULL, organisme VARCHAR(150) NOT NULL DEFAULT '', cout DECIMAL(10,2) NULL, statut VARCHAR(20) NOT NULL DEFAULT 'prevue', notes VARCHAR(500) NOT NULL DEFAULT '', titre_id INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (personnel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
              "CREATE TABLE IF NOT EXISTS salarie_docs (id INT AUTO_INCREMENT PRIMARY KEY, personnel_id INT NOT NULL, dossier VARCHAR(60) NOT NULL DEFAULT 'Autres', nom VARCHAR(200) NOT NULL, fichier VARCHAR(80) NOT NULL, taille INT NOT NULL DEFAULT 0, cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (personnel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"] as $sql) { try { $pdo->exec($sql); } catch (Throwable $t) {} }
    if (setting('livret_titres') === '') set_setting('livret_titres', LIVRET_TITRES_DEFAUT);
}
/** Types de titres : code → [libellé, validité en mois (0 = sans limite), compétence planning liée] */
function livret_types(): array {
    $l = [];
    foreach (preg_split('/\R/', setting('livret_titres') ?: LIVRET_TITRES_DEFAUT) as $ln) { $c = array_map('trim', explode('|', $ln)); if (count($c) < 2 || !preg_match('/^[a-z0-9_-]{1,40}$/', $c[0])) continue;
        $l[$c[0]] = ['code' => $c[0], 'libelle' => $c[1], 'mois' => (int)($c[2] ?? 0), 'competence' => array_key_exists($c[3] ?? '', COMPETENCES) ? $c[3] : '']; }
    return $l;
}
function livret_titres(int $pid): array {
    $l = q("SELECT * FROM salarie_titres WHERE personnel_id = ? ORDER BY COALESCE(valide_jusqua, '9999-12-31'), libelle", [$pid])->fetchAll();
    foreach ($l as &$t) $t['etat'] = titre_etat($t); unset($t);
    return $l;
}
/** valide / bientot (moins de 90 jours) / expire / sans (pas de date de fin) */
function titre_etat(array $t): string { if (!$t['valide_jusqua']) return 'sans'; $j = (strtotime($t['valide_jusqua']) - strtotime(date('Y-m-d'))) / 86400; return $j < 0 ? 'expire' : ($j <= 90 ? 'bientot' : 'valide'); }
function livret_formations(int $pid): array { return q("SELECT * FROM salarie_formations WHERE personnel_id = ? ORDER BY statut = 'realisee', COALESCE(prevue_le, '9999-12-31') DESC", [$pid])->fetchAll(); }
function livret_docs(int $pid): array { return q("SELECT * FROM salarie_docs WHERE personnel_id = ? ORDER BY dossier, cree_le DESC", [$pid])->fetchAll(); }
/** Compétences du planning tenues à jour depuis les titres : titre valide → compétence ; expiré (et aucun autre titre valide pour la même compétence) → retirée */
function livret_competences_sync(int $pid): array {
    $p = q("SELECT * FROM personnel WHERE id = ?", [$pid])->fetch(); if (!$p) return [];
    $types = livret_types(); $comp = array_values(array_filter(explode(',', (string)$p['competences']))); $ajout = []; $retrait = [];
    $valides = []; $expires = [];
    foreach (livret_titres($pid) as $t) { $c = $types[$t['code']]['competence'] ?? ''; if ($c === '') continue; if (in_array($t['etat'], ['valide', 'bientot', 'sans'], true)) $valides[$c] = true; else $expires[$c] = true; }
    foreach (array_keys($valides) as $c) if (!in_array($c, $comp, true)) { $comp[] = $c; $ajout[] = COMPETENCES[$c]; }
    foreach (array_keys($expires) as $c) if (!isset($valides[$c]) && in_array($c, $comp, true)) { $comp = array_values(array_diff($comp, [$c])); $retrait[] = COMPETENCES[$c]; }
    if ($ajout || $retrait) q("UPDATE personnel SET competences = ? WHERE id = ?", [implode(',', $comp), $pid]);
    return ['ajout' => $ajout, 'retrait' => $retrait];
}
/** Échéances de tout le personnel actif : titres expirés ou à moins de 90 jours, formations prévues */
function livret_echeances(int $jours = 90): array {
    $l = [];
    foreach (q("SELECT t.*, p.prenom, p.nom FROM salarie_titres t JOIN personnel p ON p.id = t.personnel_id WHERE p.actif = 1 AND t.valide_jusqua IS NOT NULL AND t.valide_jusqua <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY t.valide_jusqua", [$jours]) as $t)
        $l[] = ['personnel_id' => (int)$t['personnel_id'], 'qui' => trim($t['prenom'] . ' ' . $t['nom']), 'lib' => $t['libelle'] . ($t['precision_t'] ? ' (' . $t['precision_t'] . ')' : ''), 'date' => $t['valide_jusqua'], 'retard' => $t['valide_jusqua'] < date('Y-m-d'), 'id' => (int)$t['id']];
    return $l;
}
function livret_alertes(): array {
    $a = [];
    foreach (livret_echeances(60) as $x) $a[] = ['niv' => $x['retard'] ? 'haut' : 'moyen', 'id' => 0, 'url' => 'livret.php?p=' . $x['personnel_id'], 'txt' => $x['qui'] . ' : ' . $x['lib'] . ($x['retard'] ? ' expiré depuis le ' : ' expire le ') . date('d/m/Y', strtotime($x['date']))];
    return $a;
}
/** Quotidien : compétences recalées, notifications J-90 / J-30 / expiration (au plus une par mois et par titre) */
function livret_taches(): void {
    foreach (q("SELECT id FROM personnel WHERE actif = 1") as $p) livret_competences_sync((int)$p['id']);
    foreach (q("SELECT t.*, p.prenom, p.utilisateur_id FROM salarie_titres t JOIN personnel p ON p.id = t.personnel_id WHERE p.actif = 1 AND t.valide_jusqua IS NOT NULL AND t.valide_jusqua <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND (t.rappel_le IS NULL OR t.rappel_le < DATE_SUB(NOW(), INTERVAL 30 DAY))") as $t) {
        $j = (int)floor((strtotime($t['valide_jusqua']) - strtotime(date('Y-m-d'))) / 86400);
        $txt = $t['libelle'] . ($t['precision_t'] ? ' (' . $t['precision_t'] . ')' : '') . ($j < 0 ? ' est expiré depuis le ' : ' expire le ') . date('d/m/Y', strtotime($t['valide_jusqua']));
        $uids = gestionnaires(); if ($t['utilisateur_id']) $uids[] = (int)$t['utilisateur_id'];
        notifier($uids, ($j < 0 ? '⛔ ' : '⏰ ') . $t['prenom'] . ' : ' . $t['libelle'], $txt . ' – prévoir le recyclage.', 'livret.php?p=' . $t['personnel_id']);
        q("UPDATE salarie_titres SET rappel_le = NOW() WHERE id = ?", [$t['id']]);
    }
}
/** Droits : gestion (administration, secrétariat, droit « tout ») ou consultation de son propre livret */
function livret_acces(int $pid): string { if (planning_niveau() === 'tout') return 'gere'; $m = q("SELECT id FROM personnel WHERE id = ? AND utilisateur_id = ?", [$pid, user()['id']])->fetch(); return $m ? 'lecture' : ''; }
