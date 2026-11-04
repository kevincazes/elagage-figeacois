<?php
// Installation : crée les tables, les données d'exemple et le premier compte administrateur.
// À lancer UNE SEULE FOIS, puis supprimer ce fichier du serveur.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$err = ''; $done = false;
try { $pdo = db(); } catch (Throwable $t) { $err = "Connexion à la base impossible : vérifie DB_PASS dans config.php. (" . $t->getMessage() . ")"; }

if (!$err) {
    $deja = false;
    try { $deja = (int)q("SELECT COUNT(*) c FROM utilisateurs")->fetch()['c'] > 0; } catch (Throwable $t) {}
    if ($deja) $err = "L'installation a déjà été faite. Supprime install.php du serveur.";
}

if (!$err && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $prenom = trim($_POST['prenom'] ?? ''); $email = trim($_POST['email'] ?? ''); $mdp = $_POST['mdp'] ?? '';
    if ($prenom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($mdp) < 10) {
        $err = 'Prénom, e-mail valide et mot de passe d\'au moins 10 caractères sont obligatoires.';
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
            id INT AUTO_INCREMENT PRIMARY KEY, prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL UNIQUE,
            mdp VARCHAR(255) NOT NULL, token VARCHAR(64) NULL, token_expire DATETIME NULL, role ENUM('admin','user') NOT NULL DEFAULT 'user', actif TINYINT NOT NULL DEFAULT 1,
            cree_le DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS parametres (cle VARCHAR(60) PRIMARY KEY, valeur TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ressources (
            id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, taux DECIMAL(10,2) NOT NULL DEFAULT 0,
            ordre INT NOT NULL DEFAULT 0, actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bpu_lignes (
            id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL DEFAULT '', libelle VARCHAR(255) NOT NULL,
            unite VARCHAR(30) NOT NULL DEFAULT 'U', forfait DECIMAL(10,2) NOT NULL DEFAULT 0, ordre INT NOT NULL DEFAULT 0,
            actif TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bpu_temps (
            ligne_id INT NOT NULL, ressource_id INT NOT NULL, heures DECIMAL(8,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (ligne_id, ressource_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS grilles (
            id INT AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(190) NOT NULL, client VARCHAR(190) NOT NULL DEFAULT '',
            notes TEXT, coef DECIMAL(6,3) NOT NULL, tva DECIMAL(5,2) NOT NULL, taux_json TEXT, cree_par INT,
            cree_le DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS grille_lignes (
            id INT AUTO_INCREMENT PRIMARY KEY, grille_id INT NOT NULL, ordre INT NOT NULL DEFAULT 0, code VARCHAR(20) NOT NULL DEFAULT '',
            libelle VARCHAR(255) NOT NULL, unite VARCHAR(30) NOT NULL, detail_json TEXT, cout DECIMAL(10,2) NOT NULL,
            pu_ht DECIMAL(10,2) NOT NULL, INDEX (grille_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        q("INSERT INTO utilisateurs (prenom, email, mdp, role) VALUES (?,?,?,'admin')", [$prenom, $email, password_hash($mdp, PASSWORD_DEFAULT)]);

        foreach (['coef' => '1.25', 'tva' => '20', 'arrondi' => '1', 'lien_drive' => '', 'lien_extrabat' => '', 'lien_urgence' => 'https://urgence-elagage.fr', 'lien_arbogestion' => 'https://arbogestion.fr'] as $k => $v)
            set_setting($k, $v);

        // Ressources d'exemple (taux horaires à ajuster dans Paramètres)
        $res = [['Grimpeur (arboriste)', 55], ['Homme de pied', 38], ['Chef d\'équipe / encadrement', 60], ['Nacelle (avec conducteur)', 85], ['Broyeur de branches', 30], ['Camion-benne / évacuation', 45], ['Rogneuse de souche', 40]];
        $rid = [];
        foreach ($res as $i => [$n, $t]) { q("INSERT INTO ressources (nom, taux, ordre) VALUES (?,?,?)", [$n, $t, $i]); $rid[$i] = (int)$pdo->lastInsertId(); }

        // Lignes de BPU d'exemple : [code, libellé, unité, forfait €, temps par ressource (index ressource => heures)]
        $lignes = [
            ['T1', 'Taille d\'entretien – arbre de hauteur < 10 m', 'U', 0, [0 => 1.0, 1 => 1.0, 4 => 0.5]],
            ['T2', 'Taille d\'entretien – arbre de 10 à 15 m', 'U', 0, [0 => 2.0, 1 => 2.0, 4 => 1.0]],
            ['T3', 'Taille d\'entretien – arbre de 15 à 20 m', 'U', 0, [0 => 3.5, 1 => 3.5, 4 => 1.5]],
            ['T4', 'Taille d\'entretien – arbre > 20 m', 'U', 0, [0 => 5.0, 1 => 5.0, 2 => 1.0, 4 => 2.0]],
            ['A1', 'Abattage simple – arbre < 10 m', 'U', 0, [0 => 1.0, 1 => 1.5, 4 => 1.0]],
            ['A2', 'Abattage simple – arbre de 10 à 20 m', 'U', 0, [0 => 2.0, 1 => 3.0, 4 => 2.0]],
            ['D1', 'Démontage avec rétention – arbre de 10 à 20 m', 'U', 0, [0 => 5.0, 1 => 5.0, 2 => 1.0, 4 => 2.5]],
            ['D2', 'Démontage avec rétention – arbre > 20 m', 'U', 0, [0 => 8.0, 1 => 8.0, 2 => 2.0, 4 => 4.0]],
            ['N1', 'Mise à disposition nacelle avec conducteur', 'h', 0, [3 => 1.0]],
            ['S1', 'Rognage de souche – diamètre < 50 cm', 'U', 0, [1 => 0.75, 6 => 0.75]],
            ['S2', 'Rognage de souche – diamètre > 50 cm', 'U', 0, [1 => 1.5, 6 => 1.5]],
            ['E1', 'Évacuation des rémanents (par m³ broyé)', 'm³', 8, [1 => 0.25, 5 => 0.25]],
            ['H1', 'Taille de haie – hauteur < 2 m (par ml)', 'ml', 0, [1 => 0.08]],
            ['H2', 'Taille de haie – hauteur 2 à 4 m (par ml)', 'ml', 0, [1 => 0.12, 3 => 0.05]],
            ['F1', 'Déplacement et installation de chantier', 'forfait', 40, [0 => 0.5, 1 => 0.5, 5 => 0.5]],
        ];
        foreach ($lignes as $i => [$c, $l, $u, $f, $t]) {
            q("INSERT INTO bpu_lignes (code, libelle, unite, forfait, ordre) VALUES (?,?,?,?,?)", [$c, $l, $u, $f, $i + 1]);
            $lid = (int)$pdo->lastInsertId();
            foreach ($t as $ri => $h) q("INSERT INTO bpu_temps (ligne_id, ressource_id, heures) VALUES (?,?,?)", [$lid, $rid[$ri], $h]);
        }
        $done = true;
    }
}
?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Installation – Espace pro</title><link rel="stylesheet" href="pro.css"></head>
<body class="centered"><main class="box">
<h1>Installation de l'espace pro</h1>
<?php if ($done): ?>
  <div class="flash ok">Installation terminée. Compte administrateur créé.</div>
  <p><strong>Supprime maintenant le fichier <code>install.php</code> du serveur</strong> (via FileZilla), puis connecte-toi.</p>
  <a class="btn" href="login.php">Se connecter</a>
<?php elseif ($err): ?>
  <div class="flash err"><?= e($err) ?></div>
<?php else: ?>
  <p>Connexion à la base réussie. Crée le premier compte administrateur :</p>
  <form method="post">
    <label>Prénom <input name="prenom" required></label>
    <label>E-mail (identifiant) <input type="email" name="email" required></label>
    <label>Mot de passe (10 caractères minimum) <input type="password" name="mdp" minlength="10" required></label>
    <button class="btn" type="submit">Installer</button>
  </form>
<?php endif; ?>
</main></body></html>
