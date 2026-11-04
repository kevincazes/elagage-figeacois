<?php
// Fonctions communes : base de données, session, sécurité, mise en page
declare(strict_types=1);
// Configuration : d'abord espace-pro/config.php, sinon le fichier placé HORS du dossier www
// (ex. /homez.xxx/jdjhgfg/config-espace-pro.php, au même niveau que www et urgence).
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
elseif (is_file(__DIR__ . '/../../config-espace-pro.php')) require_once __DIR__ . '/../../config-espace-pro.php';
else { http_response_code(500); exit('Fichier de configuration introuvable : dépose config-espace-pro.php à la racine de l\'hébergement (à côté de www).'); }

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS'])) ini_set('session.cookie_secure', '1');
session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }

// Mises à jour de structure (exécutées une seule fois)
function migrate(): void {
    try {
        $cols = array_column(q("SHOW COLUMNS FROM utilisateurs")->fetchAll(), 'Field');
        if (!in_array('nom', $cols)) db()->exec("ALTER TABLE utilisateurs ADD COLUMN nom VARCHAR(80) NOT NULL DEFAULT '' AFTER prenom");
        if (!in_array('token', $cols)) db()->exec("ALTER TABLE utilisateurs ADD COLUMN token VARCHAR(64) NULL, ADD COLUMN token_expire DATETIME NULL");
        if (!in_array('mfa_type', $cols)) db()->exec("ALTER TABLE utilisateurs ADD COLUMN mfa_type VARCHAR(10) NOT NULL DEFAULT 'none', ADD COLUMN mfa_secret VARCHAR(64) NULL, ADD COLUMN mfa_code VARCHAR(255) NULL, ADD COLUMN mfa_expire DATETIME NULL");
        else { foreach (q("SHOW COLUMNS FROM utilisateurs LIKE 'mfa_code'") as $c) if (stripos($c['Type'], '255') === false) db()->exec("ALTER TABLE utilisateurs MODIFY mfa_code VARCHAR(255) NULL"); }
    } catch (Throwable $t) { /* tables pas encore créées : install.php s'en charge */ }
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
    static $cache = null;
    if ($cache === null) { $cache = []; foreach (q("SELECT cle, valeur FROM parametres") as $r) $cache[$r['cle']] = $r['valeur']; }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, string $val): void {
    q("INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)", [$key, $val]);
}

// ---- Sécurité ----
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function check_csrf(): void { if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf(), $_POST['csrf'] ?? '')) { http_response_code(403); exit('Formulaire expiré, recharge la page.'); } }
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!user()) redirect('login.php'); migrate(); check_csrf(); }
function require_admin(): void { require_login(); if (user()['role'] !== 'admin') { http_response_code(403); exit('Réservé aux administrateurs.'); } }
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
    unset($_SESSION['pending'], $_SESSION['essais']);
    redirect('index.php');
}
/** Utilisateur en attente de 2e facteur (mot de passe déjà validé) */
function pending_user(): ?array {
    if (empty($_SESSION['pending'])) return null;
    return q("SELECT * FROM utilisateurs WHERE id = ? AND actif = 1", [$_SESSION['pending']])->fetch() ?: null;
}
function page_simple_start(string $title): void {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . ' – ' . e(APP_NAME) . '</title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css"></head><body class="centered"><main class="box"><img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois" class="box-logo">';
}
function page_simple_end(): void { echo '</main></body></html>'; }

// ---- Calcul ----
function money(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }
function arrondir(float $v, float $pas): float { return $pas > 0 ? round($v / $pas) * $pas : round($v, 2); }
/** Prix unitaire HT d'une ligne à partir de ses temps et des taux */
function prix_ligne(array $temps, array $taux, float $forfait, float $coef, float $pas): array {
    $cout = 0.0;
    foreach ($temps as $rid => $h) $cout += $h * ($taux[$rid] ?? 0);
    $brut = ($cout + $forfait) * $coef;
    return ['cout' => $cout, 'brut' => $brut, 'pu' => arrondir($brut, $pas)];
}

// ---- Mise en page ----
function page_start(string $title, string $active = ''): void {
    $u = user();
    $nav = [
        'index.php' => 'Tableau de bord',
        'parametres.php' => 'Paramètres',
        'bpu.php' => 'Lignes BPU',
        'grilles.php' => 'Grilles par marché',
    ];
    if ($u && $u['role'] === 'admin') $nav['utilisateurs.php'] = 'Comptes';
    $nav['compte.php'] = 'Mon compte';
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>' . e($title) . ' – ' . e(APP_NAME) . '</title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css"></head><body>';
    echo '<header class="top"><a class="logo" href="index.php"><img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois"></a><span class="apptitle">Espace pro</span><nav>';
    if ($u) {
        if ($active === 'securite.php') $active = 'compte.php';
        foreach ($nav as $f => $l) echo '<a href="' . $f . '"' . ($active === $f ? ' class="active"' : '') . '>' . $l . '</a>';
        echo '<a class="out" href="logout.php">Déconnexion (' . e($u['prenom']) . ')</a>';
    }
    echo '</nav></header><main>';
    if (!empty($_SESSION['flash'])) { [$m, $t] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="flash ' . e($t) . '">' . e($m) . '</div>'; }
    echo '<h1>' . e($title) . '</h1>';
}
function page_end(): void { echo '</main><footer class="bottom">' . e(APP_NAME) . ' · <a href="../index.html">Retour au site</a></footer></body></html>'; }
