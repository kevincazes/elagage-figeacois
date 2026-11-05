<?php
// Fonctions communes : base de données, session, sécurité, mise en page
declare(strict_types=1);
// Configuration : d'abord espace-pro/config.php, sinon le fichier placé HORS du dossier www
// (ex. /homez.xxx/jdjhgfg/config-espace-pro.php, au même niveau que www et urgence).
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
elseif (is_file(__DIR__ . '/../../config-espace-pro.php')) require_once __DIR__ . '/../../config-espace-pro.php';
else { http_response_code(500); exit('Fichier de configuration introuvable : dépose config-espace-pro.php à la racine de l\'hébergement (à côté de www).'); }

date_default_timezone_set('Europe/Paris'); // heures du planning, rappels, journaux : heure de Paris quel que soit le serveur
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string)(12 * 3600)); // session active 12 h
ini_set('session.cookie_lifetime', (string)(12 * 3600));
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS'])) ini_set('session.cookie_secure', '1');
session_start();

// Gros tableaux (catalogue, bordereau, import) : le navigateur envoie tout dans un seul champ « payload »
// pour ne pas dépasser la limite d'hébergement sur le nombre de champs (max_input_vars) ; on le redéploie ici.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payload'])) {
    foreach (explode('&', (string)$_POST['payload']) as $paire) {
        if ($paire === '') continue;
        [$k, $v] = array_pad(explode('=', $paire, 2), 2, '');
        $k = urldecode($k); $v = urldecode($v);
        if (!preg_match('/^([^\[]+)((?:\[[^\]]*\])*)$/', $k, $m)) continue;
        $cles = [$m[1]]; if ($m[2] !== '') { preg_match_all('/\[([^\]]*)\]/', $m[2], $mm); $cles = array_merge($cles, $mm[1]); }
        $ref = &$_POST;
        foreach ($cles as $i => $c) {
            $dernier = $i === count($cles) - 1;
            if ($c === '') { if ($dernier) { $ref[] = $v; break; } $ref[] = []; end($ref); $c = key($ref); }
            if ($dernier) $ref[$c] = $v; else { if (!isset($ref[$c]) || !is_array($ref[$c])) $ref[$c] = []; $ref = &$ref[$c]; }
        }
        unset($ref);
    }
    unset($_POST['payload']);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $t) {} // NOW() de MySQL à la même heure que PHP
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }

// Mises à jour de structure (exécutées une seule fois)
function migrate(): void {
    $etapes = [
        "CREATE TABLE IF NOT EXISTS packs (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(150) NOT NULL, description VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS pack_lignes (pack_id INT NOT NULL, ligne_id INT NOT NULL, ordre INT NOT NULL DEFAULT 0, PRIMARY KEY (pack_id, ligne_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bordereaux (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(190) NOT NULL, client VARCHAR(190) NOT NULL DEFAULT '', notes TEXT, statut VARCHAR(10) NOT NULL DEFAULT 'brouillon', coef DECIMAL(6,3) NULL, tva DECIMAL(5,2) NULL, taux_json TEXT, cree_par INT, cree_le DATETIME DEFAULT CURRENT_TIMESTAMP, fige_le DATETIME NULL, verrou_par INT NULL, verrou_le DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS bordereau_lignes (id INT AUTO_INCREMENT PRIMARY KEY, bordereau_id INT NOT NULL, ordre INT NOT NULL DEFAULT 0, numero VARCHAR(12) NOT NULL DEFAULT '', type VARCHAR(10) NOT NULL DEFAULT 'ligne', code VARCHAR(30) NOT NULL DEFAULT '', libelle VARCHAR(255) NOT NULL DEFAULT '', unite VARCHAR(30) NOT NULL DEFAULT '', ligne_id INT NULL, quantite DECIMAL(10,2) NULL, pu_manuel DECIMAL(10,2) NULL, pu_fige DECIMAL(10,2) NULL, INDEX (bordereau_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS actions (id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(150) NOT NULL, duree DECIMAL(6,2) NOT NULL DEFAULT 0, description VARCHAR(255) NOT NULL DEFAULT '', ordre INT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ligne_actions (ligne_id INT NOT NULL, action_id INT NOT NULL, nb DECIMAL(6,2) NOT NULL DEFAULT 1, PRIMARY KEY (ligne_id, action_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS historique (id INT AUTO_INCREMENT PRIMARY KEY, bordereau_id INT NULL, utilisateur_id INT NULL, quand DATETIME DEFAULT CURRENT_TIMESTAMP, action VARCHAR(40) NOT NULL, detail TEXT, INDEX (bordereau_id), INDEX (quand)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($etapes as $sql) { try { db()->exec($sql); } catch (Throwable $t) {} }
    // Colonnes ajoutées au fil des versions : [table, colonne, instruction ALTER]
    $colonnes = [
        ['utilisateurs', 'nom', "ALTER TABLE utilisateurs ADD COLUMN nom VARCHAR(80) NOT NULL DEFAULT '' AFTER prenom"],
        ['utilisateurs', 'token', "ALTER TABLE utilisateurs ADD COLUMN token VARCHAR(64) NULL, ADD COLUMN token_expire DATETIME NULL"],
        ['utilisateurs', 'mfa_type', "ALTER TABLE utilisateurs ADD COLUMN mfa_type VARCHAR(10) NOT NULL DEFAULT 'none', ADD COLUMN mfa_secret VARCHAR(64) NULL, ADD COLUMN mfa_code VARCHAR(255) NULL, ADD COLUMN mfa_expire DATETIME NULL"],
        ['bpu_lignes', 'famille', "ALTER TABLE bpu_lignes ADD COLUMN famille VARCHAR(80) NOT NULL DEFAULT '', ADD COLUMN hauteur VARCHAR(10) NOT NULL DEFAULT '', ADD COLUMN houppier VARCHAR(10) NOT NULL DEFAULT '', ADD COLUMN circonference VARCHAR(12) NOT NULL DEFAULT '', ADD COLUMN prix_ref DECIMAL(10,2) NULL, ADD COLUMN source VARCHAR(120) NOT NULL DEFAULT ''"],
        ['bordereaux', 'verrou_par', "ALTER TABLE bordereaux ADD COLUMN verrou_par INT NULL, ADD COLUMN verrou_le DATETIME NULL"],
        ['utilisateurs', 'souvenir', "ALTER TABLE utilisateurs ADD COLUMN souvenir VARCHAR(255) NULL, ADD COLUMN souvenir_expire DATETIME NULL"],
        ['utilisateurs', 'version_vue', "ALTER TABLE utilisateurs ADD COLUMN version_vue VARCHAR(10) NOT NULL DEFAULT ''"],
        ['ressources', 'role', "ALTER TABLE ressources ADD COLUMN role VARCHAR(10) NOT NULL DEFAULT ''"],
        ['bordereaux', 'equipe_id', "ALTER TABLE bordereaux ADD COLUMN equipe_id INT NULL"],
        ['bpu_lignes', 'base_id', "ALTER TABLE bpu_lignes ADD COLUMN base_id INT NULL"],
        ['bordereau_lignes', 'numero', "ALTER TABLE bordereau_lignes ADD COLUMN numero VARCHAR(12) NOT NULL DEFAULT '' AFTER ordre"],
    ];
    foreach ($colonnes as [$table, $col, $sql]) {
        try { $cols = array_column(q("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field'); if ($cols && !in_array($col, $cols)) db()->exec($sql); } catch (Throwable $t) {}
    }
    try { foreach (q("SHOW COLUMNS FROM utilisateurs LIKE 'mfa_code'") as $c) if (stripos($c['Type'], '255') === false) db()->exec("ALTER TABLE utilisateurs MODIFY mfa_code VARCHAR(255) NULL"); } catch (Throwable $t) {}
    // 2.4 : colonnes élargies (codes de variantes, libellés de BPU très longs, numéros de prix)
    if (setting('schema_roles') !== '1') { try { db()->exec("ALTER TABLE utilisateurs MODIFY role VARCHAR(20) NOT NULL DEFAULT 'user'"); set_setting('schema_roles', '1'); } catch (Throwable $t) {} }
    if (setting('schema') !== '2.4') {
        foreach (["ALTER TABLE bpu_lignes MODIFY code VARCHAR(60) NOT NULL DEFAULT ''", "ALTER TABLE bpu_lignes MODIFY libelle VARCHAR(1000) NOT NULL",
                  "ALTER TABLE bpu_lignes MODIFY unite VARCHAR(60) NOT NULL DEFAULT 'U'", "ALTER TABLE bordereau_lignes MODIFY numero VARCHAR(40) NOT NULL DEFAULT ''",
                  "ALTER TABLE bordereau_lignes MODIFY code VARCHAR(60) NOT NULL DEFAULT ''", "ALTER TABLE bordereau_lignes MODIFY libelle VARCHAR(1000) NOT NULL DEFAULT ''",
                  "ALTER TABLE bordereau_lignes MODIFY unite VARCHAR(60) NOT NULL DEFAULT ''"] as $sql) { try { db()->exec($sql); } catch (Throwable $t) {} }
        set_setting('schema', '2.4');
    }
}

// ---- Historique et verrous ----
const VERROU_MINUTES = 10;
function journal(string $action, string $detail = '', ?int $bordereau = null): void {
    try { q("INSERT INTO historique (bordereau_id, utilisateur_id, action, detail) VALUES (?,?,?,?)", [$bordereau, user()['id'] ?? null, $action, $detail]); } catch (Throwable $t) {}
}
/** Tente de prendre le verrou du bordereau. Retourne null si obtenu, sinon le détenteur (prenom, nom, verrou_le). */
function verrou_prendre(int $bid, bool $forcer = false): ?array {
    $b = q("SELECT b.verrou_par, b.verrou_le, u.prenom, u.nom FROM bordereaux b LEFT JOIN utilisateurs u ON u.id = b.verrou_par WHERE b.id = ?", [$bid])->fetch();
    $moi = (int)user()['id'];
    $actif = $b['verrou_par'] && strtotime($b['verrou_le']) > time() - VERROU_MINUTES * 60;
    if ($actif && (int)$b['verrou_par'] !== $moi && !$forcer) return $b;
    if ($actif && (int)$b['verrou_par'] !== $moi && $forcer) journal('verrou_force', "Verrou de {$b['prenom']} {$b['nom']} libéré de force", $bid);
    q("UPDATE bordereaux SET verrou_par = ?, verrou_le = NOW() WHERE id = ?", [$moi, $bid]);
    return null;
}
function verrou_liberer(int $bid): void { q("UPDATE bordereaux SET verrou_par = NULL, verrou_le = NULL WHERE id = ? AND verrou_par = ?", [$bid, user()['id']]); }

// ---- Actions (temps par action dans l'arbre) ----
function actions_catalogue(): array { static $a = null; return $a ??= q("SELECT * FROM actions WHERE actif = 1 ORDER BY ordre, nom")->fetchAll(); }
function actions_par_ligne(): array { static $t = null; if ($t === null) { $t = []; foreach (q("SELECT la.*, a.nom, a.duree FROM ligne_actions la JOIN actions a ON a.id = la.action_id WHERE a.actif = 1") as $x) $t[$x['ligne_id']][] = $x; } return $t; }
function ressources_equipes(): array { return array_values(array_filter(ressources(), fn($r) => $r['role'] === 'equipe')); }
/** Équipe principale : celle dont la colonne porte les heures dans le catalogue */
function ressource_equipe(): ?array { return ressources_equipes()[0] ?? null; }
/** Équipe à appliquer : celle demandée (id) ou, par défaut, l'équipe principale */
function equipe_choisie(?int $id): ?array { foreach (ressources_equipes() as $r) if ((int)$r['id'] === (int)$id) return $r; return ressource_equipe(); }
/** Équipe choisie pour l'aperçu des prix (« Prix avec » / « Coût avec ») : mémorisée pour la session, jusqu'à un autre choix */
function equipe_apercu(): ?int {
    if (isset($_GET['equipe']) && $_GET['equipe'] !== '') $_SESSION['equipe_apercu'] = (int)$_GET['equipe'];
    $id = $_SESSION['equipe_apercu'] ?? null;
    foreach (ressources_equipes() as $r) if ((int)$r['id'] === (int)$id) return (int)$id;
    return null;
}
/** Recalcule les heures d'équipe d'une ligne composée d'actions (Σ nb × durée) */
function appliquer_actions(int $ligneId): void {
    $eq = ressource_equipe(); if (!$eq) return;
    $tot = (float)(q("SELECT COALESCE(SUM(la.nb * a.duree),0) h FROM ligne_actions la JOIN actions a ON a.id = la.action_id WHERE la.ligne_id = ? AND a.actif = 1", [$ligneId])->fetch()['h']);
    $nb = (int)q("SELECT COUNT(*) c FROM ligne_actions WHERE ligne_id = ?", [$ligneId])->fetch()['c'];
    if ($nb > 0) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?) ON DUPLICATE KEY UPDATE heures = VALUES(heures)", [$ligneId, $eq['id'], round($tot, 2)]);
}
/** Passe de N ressources à deux (Équipe tout compris + Rognage) en conservant le coût de chaque ligne. */
function simplifier_en_equipe(float $tauxEquipe, float $tauxRognage, float $tauxEquipe2 = 0): array {
    $pdo = db(); $res = ressources();
    $pdo->beginTransaction();
    // Ressources cibles : équipe principale (porte les heures), seconde équipe optionnelle, rognage
    $eq = ressource_equipe();
    // Une ressource déjà nommée « équipe » (créée à la main) devient l'équipe standard
    if (!$eq) foreach ($res as $r) if ($r['role'] === '' && stripos($r['nom'], 'quipe') !== false) { q("UPDATE ressources SET role = 'equipe', ordre = 0 WHERE id = ?", [$r['id']]); $eq = $r; $res = array_values(array_filter($res, fn($x) => $x['id'] !== $r['id'])); break; }
    if (!$eq) { q("INSERT INTO ressources (nom, taux, ordre, role) VALUES ('Équipe standard (personnel + nacelle + broyeur + matériel)', ?, 0, 'equipe')", [$tauxEquipe]); $eqId = (int)$pdo->lastInsertId(); }
    else { $eqId = (int)$eq['id']; if ((float)$eq['taux'] > 0 && abs((float)$eq['taux'] - $tauxEquipe) > 0.001) q("UPDATE bpu_temps SET heures = ROUND(heures * ? / ?, 2) WHERE ressource_id = ?", [(float)$eq['taux'], $tauxEquipe, $eqId]); q("UPDATE ressources SET taux = ? WHERE id = ?", [$tauxEquipe, $eqId]); } // heures converties : prix inchangés
    if ($tauxEquipe2 > 0 && count(ressources_equipes()) < 2) q("INSERT INTO ressources (nom, taux, ordre, role) VALUES ('Équipe marché public', ?, 1, 'equipe')", [$tauxEquipe2]);
    $ro = null; foreach ($res as $r) if ($r['role'] === 'rognage') $ro = $r;
    if (!$ro) { q("INSERT INTO ressources (nom, taux, ordre, role) VALUES ('Rognage (opérateur + rogneuse)', ?, 2, 'rognage')", [$tauxRognage]); $roId = (int)$pdo->lastInsertId(); } else { $roId = (int)$ro['id']; q("UPDATE ressources SET taux = ? WHERE id = ?", [$tauxRognage, $roId]); }
    $anciennes = array_filter($res, fn($r) => !in_array($r['role'], ['equipe', 'rognage']) && (int)$r['id'] !== $eqId);
    $taux = []; foreach ($anciennes as $r) $taux[$r['id']] = (float)$r['taux'];
    $rogneuseIds = []; foreach ($anciennes as $r) if (stripos($r['nom'], 'rogn') !== false) $rogneuseIds[] = (int)$r['id'];
    $n = 0;
    foreach (q("SELECT id FROM bpu_lignes") as $l) {
        $temps = q("SELECT * FROM bpu_temps WHERE ligne_id = ?", [$l['id']])->fetchAll();
        $coutEq = 0; $coutRo = 0; $touche = false;
        foreach ($temps as $t) { if (!isset($taux[$t['ressource_id']])) continue; $touche = true; $c = (float)$t['heures'] * $taux[$t['ressource_id']]; if (in_array((int)$t['ressource_id'], $rogneuseIds)) $coutRo += $c; else $coutEq += $c; }
        if (!$touche) continue;
        // Les lignes de rognage : tout le coût passe en heures de rognage
        $estRognage = $coutRo > 0;
        q("DELETE FROM bpu_temps WHERE ligne_id = ? AND ressource_id IN (" . implode(',', array_map('intval', array_keys($taux))) . ")", [$l['id']]);
        if ($estRognage) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?) ON DUPLICATE KEY UPDATE heures = heures + VALUES(heures)", [$l['id'], $roId, round(($coutEq + $coutRo) / $tauxRognage, 2)]);
        elseif ($coutEq > 0) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?) ON DUPLICATE KEY UPDATE heures = heures + VALUES(heures)", [$l['id'], $eqId, round($coutEq / $tauxEquipe, 2)]);
        $n++;
    }
    foreach ($anciennes as $r) q("UPDATE ressources SET actif = 0 WHERE id = ?", [$r['id']]);
    $pdo->commit();
    return [$n, count($anciennes)];
}

// ---- Variantes par taille d'arbre (progression en % du temps) ----
const CLASSES_H = ['<10', '10-20', '>20']; const CLASSES_D = ['<=5', '5-10', '>10'];
function multiplicateur(string $h, string $d): float {
    $mh = ['<10' => 0, '' => 0, '10-20' => (float)setting('prog_h2', '80'), '>20' => (float)setting('prog_h3', '200')];
    $md = ['<=5' => 0, '' => 0, '5-10' => (float)setting('prog_d2', '50'), '>10' => (float)setting('prog_d3', '120')];
    return (1 + ($mh[$h] ?? 0) / 100) * (1 + ($md[$d] ?? 0) / 100);
}
/** Recalcule les temps des variantes liées à une ligne de base */
function appliquer_variantes(int $baseId): int {
    $base = q("SELECT * FROM bpu_lignes WHERE id = ?", [$baseId])->fetch(); if (!$base) return 0;
    $tb = q("SELECT * FROM bpu_temps WHERE ligne_id = ?", [$baseId])->fetchAll();
    $m0 = multiplicateur($base['hauteur'], $base['houppier']); $n = 0;
    foreach (q("SELECT * FROM bpu_lignes WHERE base_id = ? AND actif = 1", [$baseId]) as $v) {
        $m = multiplicateur($v['hauteur'], $v['houppier']) / ($m0 ?: 1);
        q("DELETE FROM bpu_temps WHERE ligne_id = ?", [$v['id']]);
        foreach ($tb as $t) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?)", [$v['id'], $t['ressource_id'], round((float)$t['heures'] * $m, 2)]);
        q("UPDATE bpu_lignes SET forfait = ? WHERE id = ?", [round((float)$base['forfait'] * $m, 2), $v['id']]); $n++;
    }
    return $n;
}
/** Crée les variantes manquantes (hauteur × houppier, ou hauteur seule) d'une ligne de base */
function generer_variantes(int $baseId, bool $avecHouppier): int {
    $base = q("SELECT * FROM bpu_lignes WHERE id = ?", [$baseId])->fetch(); if (!$base) return 0;
    // La base prend la classe minimale si elle n'en a pas, pour ne pas la dupliquer
    if ($base['hauteur'] === '') { q("UPDATE bpu_lignes SET hauteur = '<10' WHERE id = ?", [$baseId]); $base['hauteur'] = '<10'; }
    if ($avecHouppier && $base['houppier'] === '') { q("UPDATE bpu_lignes SET houppier = '<=5' WHERE id = ?", [$baseId]); $base['houppier'] = '<=5'; }
    $lib = ['<10' => 'arbre de moins de 10 m', '10-20' => 'arbre de 10 à 20 m', '>20' => 'arbre de plus de 20 m'];
    $libd = ['<=5' => 'houppier ≤ 5 m', '5-10' => 'houppier de 5 à 10 m', '>10' => 'houppier > 10 m'];
    $racine = preg_replace('/\s*[–-]\s*(arbre|houppier).*$/iu', '', $base['libelle']);
    $existe = []; foreach (q("SELECT hauteur, houppier FROM bpu_lignes WHERE (base_id = ? OR id = ?) AND actif = 1", [$baseId, $baseId]) as $x) $existe[$x['hauteur'] . '|' . $x['houppier']] = true;
    $ordre = (int)$base['ordre']; $n = 0; $pdo = db();
    foreach (CLASSES_H as $h) foreach (($avecHouppier ? CLASSES_D : ['']) as $d) {
        if (isset($existe["$h|$d"])) continue;
        $l = $racine . ' – ' . $lib[$h] . ($d ? ', ' . $libd[$d] : '');
        $code = preg_replace('/-H[123](-D[123])?$/', '', $base['code']) . '-H' . (array_search($h, CLASSES_H) + 1) . ($d ? '-D' . (array_search($d, CLASSES_D) + 1) : '');
        q("INSERT INTO bpu_lignes (code, libelle, unite, forfait, ordre, famille, hauteur, houppier, circonference, source, base_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [$code, $l, $base['unite'], 0, ++$ordre, $base['famille'], $h, $d, '', 'Variante générée (+' . round((multiplicateur($h, $d) - 1) * 100) . ' %)', $baseId]);
        $n++;
    }
    appliquer_variantes($baseId);
    return $n;
}

// ---- Catalogue : prix d'une ligne à partir des temps courants ----
function ressources(): array { static $r = null; return $r ??= q("SELECT * FROM ressources WHERE actif = 1 ORDER BY ordre, id")->fetchAll(); }
/** Taux par ressource ; si une équipe est choisie, son taux remplace celui de l'équipe principale (les heures d'équipe sont stockées sur l'équipe principale) */
function taux_courants(?int $equipeId = null): array {
    $t = []; foreach (ressources() as $r) $t[$r['id']] = (float)$r['taux'];
    $p = ressource_equipe(); $c = equipe_choisie($equipeId);
    foreach (ressources_equipes() as $r) if ($p && (int)$r['id'] !== (int)$p['id']) $t[$r['id']] = 0.0; // heures jamais portées par les autres équipes
    if ($p && $c && (int)$c['id'] !== (int)$p['id']) $t[$p['id']] = (float)$c['taux'];
    return $t;
}
function temps_par_ligne(): array { static $t = null; if ($t === null) { $t = []; foreach (q("SELECT * FROM bpu_temps") as $x) $t[$x['ligne_id']][$x['ressource_id']] = (float)$x['heures']; } return $t; }
function prix_catalogue(array $ligne, ?int $equipeId = null): float {
    $p = prix_ligne(temps_par_ligne()[$ligne['id']] ?? [], taux_courants($equipeId), (float)$ligne['forfait'], (float)setting('coef', '1'), (float)setting('arrondi', '1'));
    return $p['pu'];
}
/** Importe le catalogue de démarrage (lignes + packs), sans doublon sur le code. Retourne [lignes ajoutées, packs ajoutés]. */
function importer_catalogue(): array {
    $data = require __DIR__ . '/catalogue_data.php';
    $res = ressources(); $coef = (float)setting('coef', '1'); $pdo = db();
    $existants = array_column(q("SELECT code, id FROM bpu_lignes")->fetchAll(), 'id', 'code');
    $ordre = (int)q("SELECT COALESCE(MAX(ordre),0) o FROM bpu_lignes")->fetch()['o'];
    $nb = 0; $ids = $existants;
    foreach ($data['lignes'] as $l) {
        [$code, $fam, $lib, $unite, $h, $d, $c, $prix, $src, $profil] = $l + [9 => 'grimpe'];
        if (isset($existants[$code])) continue;
        q("INSERT INTO bpu_lignes (code, libelle, unite, forfait, ordre, famille, hauteur, houppier, circonference, prix_ref, source) VALUES (?,?,?,0,?,?,?,?,?,?,?)",
          [$code, $lib, $unite, ++$ordre, $fam, $h, $d, $c, $prix, $src]);
        $id = (int)$pdo->lastInsertId(); $ids[$code] = $id; $nb++;
        // Temps calibrés pour retomber sur le prix de référence avec les taux et le coefficient actuels
        $cout = $prix / $coef;
        if ($eqP = ressource_equipe()) { // modèle Équipe (+ rognage) : tout le coût en heures d'équipe, ou de rognage pour les souches
            $ro = null; foreach ($res as $r) if ($r['role'] === 'rognage') $ro = $r;
            $cible = ($profil === 'souche' && $ro) ? $ro : $eqP;
            if ((float)$cible['taux'] > 0) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?) ON DUPLICATE KEY UPDATE heures = VALUES(heures)", [$id, $cible['id'], round($cout / (float)$cible['taux'], 2)]);
            continue;
        }
        foreach (PROFILS[$profil] ?? PROFILS['grimpe'] as $idx => $part) {
            $r = $res[$idx] ?? $res[0]; $taux = (float)$r['taux'];
            if ($taux <= 0) continue;
            $heures = round($cout * $part / $taux * 20) / 20; // au vingtième d'heure (3 min)
            if ($heures > 0) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?) ON DUPLICATE KEY UPDATE heures = heures + VALUES(heures)", [$id, $r['id'], $heures]);
        }
    }
    $nbp = 0;
    $packsExistants = array_column(q("SELECT nom FROM packs")->fetchAll(), 'nom');
    foreach ($data['packs'] as $nom => [$desc, $prefixes]) {
        if (in_array($nom, $packsExistants)) continue;
        q("INSERT INTO packs (nom, description) VALUES (?,?)", [$nom, $desc]);
        $pid = (int)$pdo->lastInsertId(); $o = 0;
        foreach ($prefixes as $pref) foreach ($ids as $code => $lid) if ($code === $pref || str_starts_with($code, $pref)) q("INSERT IGNORE INTO pack_lignes (pack_id, ligne_id, ordre) VALUES (?,?,?)", [$pid, $lid, ++$o]);
        $nbp++;
    }
    return [$nb, $nbp];
}

// Lien d'invitation / réinitialisation : génère un jeton valable 48 h et envoie l'e-mail
function envoyer_lien(array $u, string $type = 'invitation'): string {
    $token = bin2hex(random_bytes(24));
    q("UPDATE utilisateurs SET token = ?, token_expire = DATE_ADD(NOW(), INTERVAL 48 HOUR) WHERE id = ?", [$token, $u['id']]);
    $base = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $lien = $base . '/definir-mdp.php?token=' . $token;
    $prenom = $u['prenom'];
    if ($type === 'invitation') {
        $sujet = "Votre accès à l'espace pro Élagage Figeacois";
        $corps = "Bonjour $prenom,\n\nUn compte vient d'être créé pour vous sur l'espace pro d'Élagage Figeacois.\nPour choisir votre mot de passe et vous connecter, cliquez sur ce lien (valable 48 heures) :\n\n$lien\n\nVotre identifiant est votre adresse e-mail : {$u['email']}\n\nÀ bientôt,\nÉlagage Figeacois";
    } else {
        $sujet = "Réinitialisation de votre mot de passe – Espace pro Élagage Figeacois";
        $corps = "Bonjour $prenom,\n\nPour choisir un nouveau mot de passe, cliquez sur ce lien (valable 48 heures) :\n\n$lien\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\nÉlagage Figeacois";
    }
    $entetes = "From: Espace pro Élagage Figeacois <" . APP_EMAIL . ">\r\nReply-To: " . APP_EMAIL . "\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($u['email'], "=?UTF-8?B?" . base64_encode($sujet) . "?=", $corps, $entetes);
    return $lien;
}
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header("Location: $url"); exit; }

// ---- Paramètres (clé / valeur) ----
function setting(string $key, string $default = ''): string {
    if (!isset($GLOBALS['__reglages'])) { $GLOBALS['__reglages'] = []; foreach (q("SELECT cle, valeur FROM parametres") as $r) $GLOBALS['__reglages'][$r['cle']] = $r['valeur']; }
    return $GLOBALS['__reglages'][$key] ?? $default;
}
function set_setting(string $key, string $val): void {
    q("INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)", [$key, $val]);
    if (isset($GLOBALS['__reglages'])) $GLOBALS['__reglages'][$key] = $val; // les calculs suivants voient la nouvelle valeur
}

// ---- Sécurité ----
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function check_csrf(): void { if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf(), $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) { http_response_code(403); exit('Formulaire expiré, recharge la page.'); } }
function user(): ?array {
    if (!empty($_SESSION['user'])) {
        if (empty($GLOBALS['__user_verifie'])) {
            $GLOBALS['__user_verifie'] = true;
            try {
                $u = q("SELECT role, actif, prenom FROM utilisateurs WHERE id = ?", [(int)$_SESSION['user']['id']])->fetch();
                if (!$u || !(int)$u['actif']) { $_SESSION = []; return null; }
                $_SESSION['user']['role'] = $u['role']; $_SESSION['user']['prenom'] = $u['prenom'];
            } catch (Throwable $t) {}
        }
        return $_SESSION['user'];
    }
    // Reconnexion automatique : cookie « rester connecté » (id:jeton), jeton haché en base, 30 jours
    if (!empty($_COOKIE['ef_souvenir']) && str_contains($_COOKIE['ef_souvenir'], ':')) {
        [$id, $jeton] = explode(':', $_COOKIE['ef_souvenir'], 2);
        try {
            $u = q("SELECT * FROM utilisateurs WHERE id = ? AND actif = 1 AND souvenir IS NOT NULL AND souvenir_expire > NOW()", [(int)$id])->fetch();
            if ($u && password_verify($jeton, $u['souvenir'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => $u['id'], 'prenom' => $u['prenom'], 'email' => $u['email'], 'role' => $u['role']];
                return $_SESSION['user'];
            }
        } catch (Throwable $t) {}
        setcookie('ef_souvenir', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    return null;
}
function souvenir_creer(int $uid): void {
    $jeton = bin2hex(random_bytes(32));
    q("UPDATE utilisateurs SET souvenir = ?, souvenir_expire = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?", [password_hash($jeton, PASSWORD_DEFAULT), $uid]);
    setcookie('ef_souvenir', "$uid:$jeton", ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}
function souvenir_effacer(?int $uid): void {
    if ($uid) try { q("UPDATE utilisateurs SET souvenir = NULL, souvenir_expire = NULL WHERE id = ?", [$uid]); } catch (Throwable $t) {}
    setcookie('ef_souvenir', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}
function require_login(): void { if (!user()) redirect('login.php'); migrate(); check_csrf(); }
/** Rôles : admin (tout), secretaire (chiffrage complet, sans les réglages de prix ni les comptes), user (tableau de bord et son compte) */
const ROLES = ['admin' => 'Administrateur', 'secretaire' => 'Secrétaire', 'user' => 'Utilisateur'];
function role_libelle(string $r): string { return ROLES[$r] ?? 'Utilisateur'; }
function est_admin(): bool { return (user()['role'] ?? '') === 'admin'; }
function acces_chiffrage(): bool { return in_array(user()['role'] ?? '', ['admin', 'secretaire'], true); }
/** Pages du calculateur (catalogue, bordereaux, import, assistant…) : administrateurs et secrétariat */
function require_chiffrage(): void {
    require_login();
    if (!acces_chiffrage()) { http_response_code(403); page_start('Accès réservé', 'index.php'); echo '<p class="lead">Le calculateur de prix est réservé à l\'administration et au secrétariat.</p><p><a class="btn" href="index.php">Retour au tableau de bord</a></p>'; page_end(); exit; }
}
function require_admin(): void { require_login(); if (!est_admin()) { http_response_code(403); page_start('Accès réservé', 'index.php'); echo '<p class="lead">Cette page est réservée aux administrateurs.</p><p><a class="btn" href="index.php">Retour au tableau de bord</a></p>'; page_end(); exit; } }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }

// ---- Double authentification ----
function base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function base32_decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32))) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
    return $out;
}
function totp_code(string $secret, ?int $t = null): string {
    $t = intdiv($t ?? time(), 30);
    $hash = hash_hmac('sha1', pack('N*', 0, $t), base32_decode($secret), true);
    $o = ord($hash[19]) & 0x0F;
    $code = ((ord($hash[$o]) & 0x7F) << 24 | ord($hash[$o+1]) << 16 | ord($hash[$o+2]) << 8 | ord($hash[$o+3])) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}
function totp_verify(string $secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    foreach ([-1, 0, 1] as $d) if (hash_equals(totp_code($secret, time() + 30 * $d), $code)) return true;
    return false;
}
/** Envoie un code à 6 chiffres par e-mail (valable 10 min) */
function envoyer_code_email(array $u): void {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q("UPDATE utilisateurs SET mfa_code = ?, mfa_expire = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?", [password_hash($code, PASSWORD_DEFAULT), $u['id']]);
    $sujet = "Votre code de connexion : $code";
    $corps = "Bonjour {$u['prenom']},\n\nVotre code de connexion à l'espace pro Élagage Figeacois est :\n\n    $code\n\nIl est valable 10 minutes. Si vous n'êtes pas à l'origine de cette connexion, changez votre mot de passe.\n\nÉlagage Figeacois";
    $entetes = "From: Espace pro Élagage Figeacois <" . APP_EMAIL . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    @mail($u['email'], "=?UTF-8?B?" . base64_encode($sujet) . "?=", $corps, $entetes);
}
function verifier_code_email(array $u, string $code): bool {
    if (!$u['mfa_code'] || strtotime($u['mfa_expire']) < time()) return false;
    $ok = password_verify(preg_replace('/\D/', '', $code), $u['mfa_code']);
    if ($ok) q("UPDATE utilisateurs SET mfa_code = NULL, mfa_expire = NULL WHERE id = ?", [$u['id']]);
    return $ok;
}
/** Frein anti force brute sur les codes : 5 essais puis 2 s d'attente par essai */
function frein(): void { $_SESSION['essais'] = ($_SESSION['essais'] ?? 0) + 1; if ($_SESSION['essais'] > 5) sleep(2); }
/** Ouvre la session définitivement (après mot de passe + 2e facteur) */
function connecter(array $u): never {
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => $u['id'], 'prenom' => $u['prenom'], 'email' => $u['email'], 'role' => $u['role']];
    if (!empty($_SESSION['souvenir'])) souvenir_creer((int)$u['id']);
    unset($_SESSION['pending'], $_SESSION['essais'], $_SESSION['souvenir']);
    redirect('index.php');
}
/** Utilisateur en attente de 2e facteur (mot de passe déjà validé) */
function pending_user(): ?array {
    if (empty($_SESSION['pending'])) return null;
    return q("SELECT * FROM utilisateurs WHERE id = ? AND actif = 1", [$_SESSION['pending']])->fetch() ?: null;
}
function page_simple_start(string $title): void {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . ' – ' . e(APP_NAME) . '</title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css?v=' . e(app_version()) . '.' . @filemtime(__DIR__ . '/pro.css') . '"></head><body class="centered"><main class="box"><img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois" class="box-logo">';
}
function page_simple_end(): void { echo '</main></body></html>'; }

// ---- Calcul ----
/** Nombre saisi par l'utilisateur : « 1 234,5 », « 12.5 », espaces insécables… */
function nb($v): float { return (float)str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], trim((string)$v)); }
function money(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }
function arrondir(float $v, float $pas): float { $v = round($v, 6); return $pas > 0 ? round(round($v / $pas, 6)) * $pas : round($v, 2); }
/** Prix unitaire HT d'une ligne à partir de ses temps et des taux */
function prix_ligne(array $temps, array $taux, float $forfait, float $coef, float $pas): array {
    $cout = 0.0;
    foreach ($temps as $rid => $h) $cout += $h * ($taux[$rid] ?? 0);
    $brut = ($cout + $forfait) * $coef;
    return ['cout' => $cout, 'brut' => $brut, 'pu' => arrondir($brut, $pas)];
}

function versions(): array { static $v = null; return $v ??= require __DIR__ . '/versions.php'; }
function app_version(): string { return versions()[0][0]; }
function nouveautes_non_lues(): int {
    $vue = ''; try { $vue = q("SELECT version_vue FROM utilisateurs WHERE id = ?", [user()['id']])->fetch()['version_vue'] ?? ''; } catch (Throwable $t) {}
    $n = 0; foreach (versions() as [$num]) if ($vue === '' || version_compare($num, $vue, '>')) $n++;
    return $n;
}

// ---- Mise en page ----
function page_start(string $title, string $active = '', ?array $parent = null): void {
    $u = user();
    $admin = $u && $u['role'] === 'admin'; $gestion = $u && in_array($u['role'], ['admin', 'secretaire'], true);
    // Menu principal en groupes déroulants : chantiers & agenda / chiffrage / compte
    $chantiers = ['planning.php' => 'Planning', 'mon-planning.php' => 'Mon planning', 'clients.php' => 'Clients', 'conges.php' => 'Congés', 'materiel.php' => 'Matériel'];
    if ($gestion) $chantiers += ['contrats.php' => 'Contrats', 'charge.php' => 'Tableau de charge', 'phyto.php' => 'Platanes', 'planning-ressources.php' => 'Personnel, matériel, équipes', 'livret.php' => 'Livrets du personnel'];
    else $chantiers['livret.php'] = 'Mon livret';
    $chiffrage = $gestion ? ['bordereaux.php' => 'Bordereaux', 'bpu.php' => 'Catalogue', 'packs.php' => 'Packs', 'actions.php' => 'Actions', 'historique.php' => 'Historique'] + ($admin ? ['parametres.php' => 'Paramètres de prix'] : []) : [];
    $compte = ($admin ? ['utilisateurs.php' => 'Comptes', 'reglages-planning.php' => 'Réglages (SMS, Pennylane…)'] : []) + ['compte.php' => 'Mon compte', 'nouveautes.php' => 'Nouveautés', 'guide.php' => 'Guide'];
    $nav = [['index.php' => 'Tableau de bord'], ['Chantiers & agenda', $chantiers], ['Chiffrage', $chiffrage], ['Compte', $compte]];
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>' . e($title) . ' – ' . e(APP_NAME) . '</title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css?v=' . e(app_version()) . '.' . @filemtime(__DIR__ . '/pro.css') . '">'
       . '<link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#683c11"><link rel="apple-touch-icon" href="icone-192.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Élagage pro">'
       . ($u ? '<meta name="csrf" content="' . e(csrf()) . '">' : '') . '</head><body>';
    echo '<header class="top"><a class="logo" href="index.php"><img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois"></a><span class="apptitle">Espace pro <small class="version">v' . e(app_version()) . '</small></span>';
    if ($u) { $nn0 = nouveautes_non_lues(); echo '<a class="bell mob' . ($nn0 ? ' has' : '') . '" href="nouveautes.php">&#128276;' . ($nn0 ? '<b>' . $nn0 . '</b>' : '') . '</a>'; }
    echo '<button class="burger" type="button" aria-label="Menu" onclick="document.body.classList.toggle(\'nav-open\')"><span></span><span></span><span></span></button><nav>';
    if ($u) {
        if ($active === 'securite.php') $active = 'compte.php';
        if ($active === 'bordereau.php') $active = 'bordereaux.php';
        if ($active === 'rapport.php' || $active === 'phyto.php' && !$gestion) $active = 'mon-planning.php';
        foreach ($nav as $g) {
            if (!isset($g[1])) { foreach ($g as $f => $l) echo '<a href="' . $f . '"' . ($active === $f ? ' class="active"' : '') . '>' . $l . '</a>'; continue; }
            [$titre, $liens] = $g; if (!$liens) continue;
            $dedans = isset($liens[$active]);
            echo '<details class="menu' . ($dedans ? ' active' : '') . '"><summary>' . $titre . ' <i>▾</i></summary><div>';
            foreach ($liens as $f => $l) echo '<a href="' . $f . '"' . ($active === $f ? ' class="active"' : '') . '>' . $l . '</a>';
            echo '</div></details>';
        }
        $nNotif = 0; try { $nNotif = (int)q("SELECT COUNT(*) c FROM notifications WHERE utilisateur_id = ? AND lu_le IS NULL", [$u['id']])->fetch()['c']; } catch (Throwable $t) {}
        echo '<a class="notifs' . ($nNotif ? ' has' : '') . '" href="notifications.php" title="Mes notifications">📣' . ($nNotif ? '<b>' . $nNotif . '</b>' : '') . '</a>';
        $nn = nouveautes_non_lues();
        echo '<a class="bell' . ($nn ? ' has' : '') . '" href="nouveautes.php" title="Nouveautés de l\'espace pro">&#128276;' . ($nn ? '<b>' . $nn . '</b>' : '') . '</a>';
        echo '<a class="out site" href="../index.html" title="Ouvrir le site elagage-figeacois.com">Site ↗</a>';
        echo '<a class="out" href="logout.php">Déconnexion (' . e($u['prenom']) . ')</a>';
    }
    echo '</nav></header>';
    // un seul menu déroulant ouvert à la fois ; fermeture au clic ailleurs (grand écran)
    echo '<script>document.addEventListener("click",e=>{const d=e.target.closest("details.menu");document.querySelectorAll("details.menu[open]").forEach(x=>{if(x!==d&&!matchMedia("(max-width:900px)").matches)x.open=false;});});if(matchMedia("(max-width:900px)").matches)document.querySelectorAll("details.menu.active").forEach(x=>x.open=true);</script>';
    // Fil d'Ariane
    if ($u) {
        $fil = [['index.php', 'Tableau de bord']];
        if ($parent) $fil[] = $parent;
        elseif ($active && $active !== 'index.php' && isset($nav[$active]) && $active !== 'compte.php' . '_') $fil[] = [$active, $nav[$active]];
        $html = '<nav class="fil" aria-label="Fil d\'Ariane">';
        foreach ($fil as [$href, $lib]) { if ($lib === $title && !$parent) break; $html .= '<a href="' . e($href) . '">' . e($lib) . '</a><span>›</span>'; }
        $html .= '<strong>' . e($title) . '</strong></nav>';
        echo $html;
        // Panneau des nouveautés (ouvert par la cloche)
        echo '<div id="nouv" class="modal" hidden><div class="modal-box"><button class="modal-x" type="button" onclick="fermerNouv()" aria-label="Fermer">×</button><h2>Nouveautés – version ' . e(app_version()) . '</h2><div id="nouv-corps" class="muted">Chargement…</div></div></div>';
        echo '<script>function ouvrirNouv(e){e.preventDefault();const m=document.getElementById("nouv");m.hidden=false;fetch("nouveautes.php?panneau=1").then(r=>r.text()).then(h=>{document.getElementById("nouv-corps").innerHTML=h;document.querySelectorAll(".bell b").forEach(b=>b.remove());});}function fermerNouv(){document.getElementById("nouv").hidden=true;}document.addEventListener("keydown",e=>{if(e.key==="Escape")fermerNouv();});document.addEventListener("click",e=>{if(e.target.id==="nouv")fermerNouv();});document.querySelectorAll("a.bell").forEach(a=>a.addEventListener("click",ouvrirNouv));</script>';
    }
    echo '<main' . (in_array($active, ['bpu.php', 'bordereaux.php', 'bordereau.php']) ? ' class="large"' : '') . '>';
    if (!empty($_SESSION['flash'])) { [$m, $t] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="flash ' . e($t) . '">' . e($m) . '</div>'; }
    echo '<h1>' . e($title) . '</h1>';
}
function page_end(): void {
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($self !== 'assistant.php' && user() && acces_chiffrage()) {
        $ctx = 'page=' . $self . ($self === 'bordereau.php' && isset($_GET['id']) ? '&bordereau=' . (int)$_GET['id'] : '');
        echo '<button type="button" class="bulle-ia" id="bulleIA" title="Assistant : pose une question sur cet écran ou sur un chiffrage">✦<span>Assistant</span></button>'
           . '<aside class="pan-ia" id="panIA" hidden><button type="button" class="pan-fermer" title="Fermer">×</button><iframe data-src="assistant.php?panneau=1&' . e($ctx) . '" title="Assistant"></iframe></aside>';
    }
    echo '</main>' . (user() ? '<script src="notif.js?v=' . e(app_version()) . '" defer></script>' : '') . <<<'JS'
<script>
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(function(){});
(function(){
  // Familles de contenu : chaque titre de section (h2 au premier niveau de la page) et tout ce qui le suit jusqu'au titre suivant sont réunis dans un bloc encadré
  var m=document.querySelector('main'); if(m && !m.classList.contains('sans-blocs')){ var kids=[].slice.call(m.children), cur=null;
    kids.forEach(function(el){ if(el.tagName==='H2'){ cur=document.createElement('section'); cur.className='bloc'; m.insertBefore(cur,el); cur.appendChild(el); if(el.id){ cur.id='bloc-'+el.id; } }
      else if(cur && !/^(SCRIPT|STYLE|TEMPLATE)$/.test(el.tagName) && !el.classList.contains('bulle-ia') && !el.classList.contains('pan-ia')){ cur.appendChild(el); } }); }
  // Hauteur réelle du bandeau (une ou deux lignes selon l'écran) : sert aux ancres et éléments collants
  function bandeau(){ var t=document.querySelector('header.top'); if(t) document.documentElement.style.setProperty('--bandeau', (getComputedStyle(t).position==='sticky'?t.offsetHeight:0)+'px'); }
  bandeau(); window.addEventListener('resize',bandeau);
  // Formulaires « paquet » : tous les champs partent dans un seul champ caché (voir lib.php)
  window.addEventListener('pageshow',function(e){ if(e.persisted) document.querySelectorAll('form[data-paquet]').forEach(function(f){ delete f.dataset.envoye; f.querySelectorAll('[disabled]').forEach(function(x){x.disabled=false;}); var p=f.querySelector('input[name=payload]'); if(p) p.remove(); }); });
  document.querySelectorAll('form[data-paquet]').forEach(function(f){
    f.addEventListener('submit',function(){
      if(f.dataset.envoye) return; f.dataset.envoye='1';
      var fd=new FormData(f), garder={csrf:1,action:1}, parts=[];
      fd.forEach(function(v,k){ if(typeof v==='string') parts.push(encodeURIComponent(k)+'='+encodeURIComponent(v)); });
      f.querySelectorAll('[name]').forEach(function(el){ if(!garder[el.name]) el.disabled=true; });
      var h=document.createElement('input'); h.type='hidden'; h.name='payload'; h.value=parts.join('&'); f.appendChild(h);
    });
  });
  // Bulle Assistant : ouvre un panneau latéral, chargé à la première ouverture, état mémorisé
  var bIA=document.getElementById('bulleIA'), pIA=document.getElementById('panIA');
  if(bIA&&pIA){
    var ifr=pIA.querySelector('iframe');
    function ouvrirIA(on){ pIA.hidden=!on; bIA.classList.toggle('on',on); document.body.classList.toggle('ia-ouvert',on); if(on&&!ifr.src) ifr.src=ifr.dataset.src; try{sessionStorage.setItem('ia_ouvert',on?'1':'0');}catch(e){} }
    bIA.addEventListener('click',function(){ ouvrirIA(pIA.hidden); });
    pIA.querySelector('.pan-fermer').addEventListener('click',function(){ ouvrirIA(false); });
    try{ if(sessionStorage.getItem('ia_ouvert')==='1' && window.innerWidth>900) ouvrirIA(true); }catch(e){}
  }
  // Mémorise la position (page et tableau) avant un rechargement, la restaure ensuite
  var cle='pos:'+location.pathname;
  try{
    var sv=JSON.parse(sessionStorage.getItem(cle)||'null');
    if(sv && Date.now()-sv.t<600000){
      requestAnimationFrame(function(){ window.scrollTo(0,sv.y); document.querySelectorAll('.scroll').forEach(function(sc,i){ if(sv.x[i]!=null) sc.scrollLeft=sv.x[i]; if(sv.v&&sv.v[i]!=null) sc.scrollTop=sv.v[i]; }); });
    }
    sessionStorage.removeItem(cle);
  }catch(e){}
  function memoPos(){ try{ sessionStorage.setItem(cle, JSON.stringify({t:Date.now(), y:window.scrollY, x:[].map.call(document.querySelectorAll('.scroll'),function(sc){return sc.scrollLeft;}), v:[].map.call(document.querySelectorAll('.scroll'),function(sc){return sc.scrollTop;})})); }catch(e){} }
  document.addEventListener('submit',memoPos,true);
  window.addEventListener('beforeunload',memoPos);
  document.querySelectorAll('.scroll').forEach(function(sc){ if(sc.querySelector('.tbl.bpu,.tbl.bord,.tbl.imp')) sc.classList.add('fenetre'); });
  document.querySelectorAll('.scroll.fenetre').forEach(function(sc){
    // Quand la molette arrive dans le tableau, la page se cale d'abord pour que la fenêtre occupe l'écran
    sc.addEventListener('wheel',function(e){
      var r=sc.getBoundingClientRect();
      if(Math.abs(e.deltaY)>Math.abs(e.deltaX) && r.top>8 && e.deltaY>0 && sc.scrollTop===0 && r.top<window.innerHeight*0.6){ e.preventDefault(); window.scrollBy({top:r.top-4,behavior:'smooth'}); }
    },{passive:false});
    // Glisser le tableau à la souris en dehors des champs
    var dep=null;
    sc.addEventListener('mousedown',function(e){
      if(e.button!==0 || e.target.closest('input,select,textarea,button,a,label')) return;
      dep={x:e.pageX,y:e.pageY,sl:sc.scrollLeft,st:sc.scrollTop}; sc.classList.add('grab'); e.preventDefault();
    });
    document.addEventListener('mousemove',function(e){ if(!dep) return; sc.scrollLeft=dep.sl-(e.pageX-dep.x); sc.scrollTop=dep.st-(e.pageY-dep.y); });
    document.addEventListener('mouseup',function(){ if(dep){dep=null; sc.classList.remove('grab');} });
    if (sc.scrollWidth <= sc.clientWidth + 4) return;
    var bar=document.createElement('div'); bar.className='defil';
    bar.innerHTML='<button type="button" class="mini" title="Tout à gauche">⇤</button><div class="piste"><div></div></div><button type="button" class="mini" title="Tout à droite">⇥</button>';
    sc.parentNode.insertBefore(bar, sc.nextSibling);
    var piste=bar.querySelector('.piste'), inner=piste.firstChild, b=bar.querySelectorAll('button');
    function dim(){ inner.style.width=sc.scrollWidth+'px'; bar.hidden = sc.scrollWidth <= sc.clientWidth + 4; }
    dim(); new ResizeObserver(dim).observe(sc); new MutationObserver(dim).observe(sc,{childList:true,subtree:true,attributes:true});
    var lock=false;
    piste.addEventListener('scroll',function(){ if(lock) return; lock=true; sc.scrollLeft=piste.scrollLeft; lock=false; });
    sc.addEventListener('scroll',function(){ if(lock) return; lock=true; piste.scrollLeft=sc.scrollLeft; lock=false; });
    b[0].addEventListener('click',function(){ sc.scrollLeft=0; });
    b[1].addEventListener('click',function(){ sc.scrollLeft=sc.scrollWidth; });
  });
})();
</script>
JS
 . '<footer class="bottom">' . e(APP_NAME) . ' · version ' . e(app_version()) . ' · <a href="nouveautes.php">Nouveautés</a> · <a href="../index.html">Retour au site</a></footer></body></html>'; }
