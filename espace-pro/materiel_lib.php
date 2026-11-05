<?php
// Suivi du matériel : fiches, contrôles périodiques (VGP, contrôle technique, EPI…), entretiens, pannes, carnet, documents
declare(strict_types=1);

const TYPES_JOURNAL = ['controle' => 'Contrôle', 'entretien' => 'Entretien', 'reparation' => 'Réparation', 'panne' => 'Panne / anomalie', 'note' => 'Note'];
const RESULTATS = ['conforme' => 'Conforme', 'reserves' => 'Conforme avec réserves', 'non_conforme' => 'Non conforme'];
/** Contrôles proposés d'office selon le type de matériel (nom, périodicité en mois, obligatoire = bloque au planning si dépassé) */
const CONTROLES_TYPES = [
    'nacelle' => [['VGP (vérification générale périodique)', 6, 1]], 'grue' => [['VGP (vérification générale périodique)', 6, 1]],
    'camion' => [['Contrôle technique', 12, 1], ['Chronotachygraphe / limiteur', 24, 0]], 'vehicule' => [['Contrôle technique', 24, 1]],
    'broyeur' => [['Vérification annuelle (organes de sécurité)', 12, 0]], 'rogneuse' => [['Vérification annuelle (organes de sécurité)', 12, 0]],
    'extincteur' => [['Vérification annuelle', 12, 1]],
];
const ENTRETIENS_TYPES = [
    'nacelle' => [['Révision moteur / hydraulique', 12, 250]], 'grue' => [['Révision', 12, 250]], 'camion' => [['Vidange et révision', 12, 20000]], 'vehicule' => [['Vidange et révision', 12, 15000]],
    'broyeur' => [['Affûtage / retournement des couteaux', 0, 50], ['Vidange moteur', 6, 100]], 'rogneuse' => [['Dents de rognage', 0, 40], ['Vidange', 6, 100]], 'tronconneuse' => [['Affûtage chaîne / nettoyage filtre', 0, 20]],
];

function materiel_migrate(): void {
    $pdo = db(); $fait = setting('schema_materiel') === '1';
    foreach (["ALTER TABLE materiel ADD COLUMN marque VARCHAR(80) NOT NULL DEFAULT ''", "ALTER TABLE materiel ADD COLUMN modele VARCHAR(80) NOT NULL DEFAULT ''", "ALTER TABLE materiel ADD COLUMN serie VARCHAR(80) NOT NULL DEFAULT ''",
              "ALTER TABLE materiel ADD COLUMN achat_le DATE NULL", "ALTER TABLE materiel ADD COLUMN prix_achat DECIMAL(10,2) NULL", "ALTER TABLE materiel ADD COLUMN compteur DECIMAL(10,1) NULL", "ALTER TABLE materiel ADD COLUMN compteur_unite VARCHAR(5) NOT NULL DEFAULT 'h'",
              "ALTER TABLE materiel ADD COLUMN compteur_le DATE NULL", "ALTER TABLE materiel ADD COLUMN etat VARCHAR(20) NOT NULL DEFAULT 'ok'", "ALTER TABLE materiel ADD COLUMN personnel_id INT NULL", "ALTER TABLE materiel ADD COLUMN usage_annuel DECIMAL(10,1) NULL"] as $sql) { try { $pdo->exec($sql); } catch (Throwable $t) {} }
    if ($fait) return;
    foreach ([
        "CREATE TABLE IF NOT EXISTS materiel_controles (id INT AUTO_INCREMENT PRIMARY KEY, materiel_id INT NOT NULL, nom VARCHAR(120) NOT NULL, periodicite_mois INT NOT NULL DEFAULT 12, obligatoire TINYINT NOT NULL DEFAULT 1, prestataire VARCHAR(120) NOT NULL DEFAULT '',
            rappel_jours INT NOT NULL DEFAULT 30, dernier_le DATE NULL, prochain_le DATE NULL, notes VARCHAR(255) NOT NULL DEFAULT '', actif TINYINT NOT NULL DEFAULT 1, INDEX (materiel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS materiel_entretiens (id INT AUTO_INCREMENT PRIMARY KEY, materiel_id INT NOT NULL, nom VARCHAR(120) NOT NULL, tous_les_mois INT NOT NULL DEFAULT 0, tous_les_compteur DECIMAL(10,1) NOT NULL DEFAULT 0,
            dernier_le DATE NULL, dernier_compteur DECIMAL(10,1) NULL, notes VARCHAR(255) NOT NULL DEFAULT '', actif TINYINT NOT NULL DEFAULT 1, INDEX (materiel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS materiel_journal (id INT AUTO_INCREMENT PRIMARY KEY, materiel_id INT NOT NULL, type VARCHAR(20) NOT NULL, plan_id INT NULL, quand DATE NOT NULL, compteur DECIMAL(10,1) NULL, cout DECIMAL(10,2) NULL,
            qui VARCHAR(120) NOT NULL DEFAULT '', detail TEXT NULL, resultat VARCHAR(20) NOT NULL DEFAULT '', fichier VARCHAR(80) NOT NULL DEFAULT '', photo VARCHAR(80) NOT NULL DEFAULT '', statut VARCHAR(20) NOT NULL DEFAULT '', immobilise TINYINT NOT NULL DEFAULT 0,
            evenement_id INT NULL, cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (materiel_id, quand)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS materiel_docs (id INT AUTO_INCREMENT PRIMARY KEY, materiel_id INT NOT NULL, dossier VARCHAR(40) NOT NULL DEFAULT 'Documents', nom VARCHAR(200) NOT NULL, fichier VARCHAR(80) NOT NULL, taille INT NOT NULL DEFAULT 0, cree_par INT NULL, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (materiel_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $sql) $pdo->exec($sql);
    // reprise de l'ancienne date de contrôle unique en un contrôle périodique
    foreach (q("SELECT id, type, controle_le FROM materiel WHERE controle_le IS NOT NULL") as $m) {
        if (q("SELECT id FROM materiel_controles WHERE materiel_id = ?", [$m['id']])->fetch()) continue;
        [$nom, $per, $obl] = CONTROLES_TYPES[$m['type']][0] ?? ['Contrôle réglementaire', 12, 1];
        q("INSERT INTO materiel_controles (materiel_id, nom, periodicite_mois, obligatoire, prochain_le) VALUES (?,?,?,?,?)", [$m['id'], $nom, $per, $obl, $m['controle_le']]);
    }
    set_setting('schema_materiel', '1');
}
const TYPES_MAT_PLUS = ['tronconneuse' => 'Tronçonneuse / outil thermique', 'remorque' => 'Remorque', 'extincteur' => 'Extincteur']; // les EPI sont suivis dans Vérif EPI (elagage-hevea.com), pas ici
function types_materiel(): array { return TYPES_MAT + TYPES_MAT_PLUS; }
const DOSSIERS_MATERIEL = ['Carte grise / assurance', 'Notice', 'Rapports de contrôle', 'Factures', 'Photos', 'Documents'];

/** Date du prochain contrôle obligatoire le plus proche → colonne materiel.controle_le (utilisée par les conflits du planning) */
function materiel_maj_controle(int $mid): void {
    $p = q("SELECT MIN(prochain_le) p FROM materiel_controles WHERE materiel_id = ? AND actif = 1 AND obligatoire = 1 AND prochain_le IS NOT NULL", [$mid])->fetch()['p'] ?? null;
    q("UPDATE materiel SET controle_le = ? WHERE id = ?", [$p, $mid]);
}
/** Échéances : liste [materiel, libellé, date (ou null si au compteur), retard (bool), bientôt (bool), genre] */
function materiel_echeances(?int $mid = null): array {
    $out = []; $w = $mid ? "AND m.id = $mid" : ''; $auj = date('Y-m-d');
    foreach (q("SELECT c.*, m.nom mnom, m.etat FROM materiel_controles c JOIN materiel m ON m.id = c.materiel_id WHERE c.actif = 1 AND m.actif = 1 $w ORDER BY c.prochain_le") as $c) {
        if (!$c['prochain_le']) { $out[] = ['mid' => (int)$c['materiel_id'], 'materiel' => $c['mnom'], 'lib' => $c['nom'], 'date' => null, 'retard' => false, 'bientot' => true, 'genre' => 'controle', 'id' => (int)$c['id'], 'obligatoire' => (int)$c['obligatoire'], 'txt' => 'jamais fait']; continue; }
        $j = (int)floor((strtotime($c['prochain_le']) - strtotime($auj)) / 86400);
        if ($j <= (int)$c['rappel_jours']) $out[] = ['mid' => (int)$c['materiel_id'], 'materiel' => $c['mnom'], 'lib' => $c['nom'], 'date' => $c['prochain_le'], 'retard' => $j < 0, 'bientot' => $j >= 0, 'genre' => 'controle', 'id' => (int)$c['id'], 'obligatoire' => (int)$c['obligatoire'], 'txt' => $j < 0 ? 'dépassé de ' . -$j . ' j' : "dans $j j"];
    }
    foreach (q("SELECT e.*, m.nom mnom, m.compteur, m.compteur_unite FROM materiel_entretiens e JOIN materiel m ON m.id = e.materiel_id WHERE e.actif = 1 AND m.actif = 1 $w") as $e) {
        $retard = false; $bientot = false; $txt = []; $j = 999;
        if ((int)$e['tous_les_mois'] > 0) { $d = $e['dernier_le'] ? date('Y-m-d', strtotime($e['dernier_le'] . ' +' . (int)$e['tous_les_mois'] . ' months')) : $auj; $j = (int)floor((strtotime($d) - strtotime($auj)) / 86400); if ($j < 0) { $retard = true; $txt[] = 'dépassé de ' . -$j . ' j'; } elseif ($j <= 30) { $bientot = true; $txt[] = "dans $j j"; } }
        if ((float)$e['tous_les_compteur'] > 0 && $e['compteur'] !== null) { $reste = ((float)($e['dernier_compteur'] ?? 0) + (float)$e['tous_les_compteur']) - (float)$e['compteur']; if ($reste < 0) { $retard = true; $txt[] = 'dépassé de ' . round(-$reste) . ' ' . $e['compteur_unite']; } elseif ($reste <= (float)$e['tous_les_compteur'] * 0.15) { $bientot = true; $txt[] = 'dans ' . round($reste) . ' ' . $e['compteur_unite']; } }
        if ($retard || $bientot) $out[] = ['mid' => (int)$e['materiel_id'], 'materiel' => $e['mnom'], 'lib' => $e['nom'], 'date' => isset($d) && ($j < 0 || $j <= 30) ? $d : null, 'retard' => $retard, 'bientot' => !$retard, 'genre' => 'entretien', 'id' => (int)$e['id'], 'obligatoire' => 0, 'txt' => implode(', ', $txt)];
        unset($d);
    }
    foreach (q("SELECT j.id, j.materiel_id, j.detail, j.immobilise, m.nom mnom FROM materiel_journal j JOIN materiel m ON m.id = j.materiel_id WHERE j.type = 'panne' AND j.statut = 'ouverte' $w") as $p)
        $out[] = ['mid' => (int)$p['materiel_id'], 'materiel' => $p['mnom'], 'lib' => 'Panne : ' . mb_substr((string)$p['detail'], 0, 80), 'date' => null, 'retard' => (bool)$p['immobilise'], 'bientot' => !$p['immobilise'], 'genre' => 'panne', 'id' => (int)$p['id'], 'obligatoire' => (int)$p['immobilise'], 'txt' => $p['immobilise'] ? 'immobilisé' : 'à réparer'];
    usort($out, fn($a, $b) => [$b['retard'], $a['date'] ?? '9'] <=> [$a['retard'], $b['date'] ?? '9']);
    return $out;
}
/** Enregistre un contrôle ou un entretien réalisé : carnet + report de l'échéance */
function materiel_realiser(int $mid, string $genre, int $planId, string $quand, ?float $compteur, ?float $cout, string $qui, string $detail, string $resultat = '', string $fichier = ''): void {
    q("INSERT INTO materiel_journal (materiel_id, type, plan_id, quand, compteur, cout, qui, detail, resultat, fichier, cree_par) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$mid, $genre, $planId ?: null, $quand, $compteur, $cout, $qui, $detail, $resultat, $fichier, user()['id'] ?? null]);
    if ($compteur !== null) q("UPDATE materiel SET compteur = GREATEST(COALESCE(compteur, 0), ?), compteur_le = ? WHERE id = ?", [$compteur, $quand, $mid]);
    if ($genre === 'controle' && $planId) {
        $c = q("SELECT * FROM materiel_controles WHERE id = ?", [$planId])->fetch();
        if ($c) { $proch = $resultat === 'non_conforme' ? $quand : date('Y-m-d', strtotime("$quand +" . max(1, (int)$c['periodicite_mois']) . ' months')); q("UPDATE materiel_controles SET dernier_le = ?, prochain_le = ? WHERE id = ?", [$quand, $proch, $planId]); materiel_maj_controle($mid); }
        if ($resultat === 'non_conforme') q("UPDATE materiel SET etat = 'immobilise' WHERE id = ?", [$mid]);
    }
    if ($genre === 'entretien' && $planId) q("UPDATE materiel_entretiens SET dernier_le = ?, dernier_compteur = COALESCE(?, dernier_compteur) WHERE id = ?", [$quand, $compteur, $planId]);
}
/** Alertes matériel pour le planning et le tableau de bord */
function materiel_alertes(): array {
    $a = [];
    foreach (materiel_echeances() as $x) {
        if ($x['genre'] === 'panne') { $a[] = ['niv' => $x['retard'] ? 'haut' : 'moyen', 'id' => 0, 'url' => 'materiel.php?id=' . $x['mid'], 'txt' => "{$x['materiel']} : " . $x['lib'] . ($x['retard'] ? ' (immobilisé)' : '')]; continue; }
        $a[] = ['niv' => $x['retard'] && $x['obligatoire'] ? 'haut' : ($x['retard'] ? 'moyen' : 'info'), 'id' => 0, 'url' => 'materiel.php?id=' . $x['mid'], 'txt' => "{$x['materiel']} : {$x['lib']} " . ($x['date'] ? 'le ' . date('d/m/Y', strtotime($x['date'])) . ' ' : '') . "({$x['txt']})"];
    }
    return $a;
}
/** Coût d'une machine sur 12 mois (entretiens, réparations, contrôles) */
function materiel_cout_annuel(int $mid): float { return (float)(q("SELECT COALESCE(SUM(cout),0) c FROM materiel_journal WHERE materiel_id = ? AND quand >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", [$mid])->fetch()['c'] ?? 0); }
