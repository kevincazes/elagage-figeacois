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
function require_login(): void { if (!user()) redirect('login.php'); check_csrf(); }
function require_admin(): void { require_login(); if (user()['role'] !== 'admin') { http_response_code(403); exit('Réservé aux administrateurs.'); } }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }

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
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>' . e($title) . ' – ' . e(APP_NAME) . '</title><link rel="icon" href="../images/favicon.png"><link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="pro.css"></head><body>';
    echo '<header class="top"><a class="logo" href="index.php"><img src="../images/logo-elagage-figeacois.png" alt="Élagage Figeacois"></a><span class="apptitle">Espace pro</span><nav>';
    if ($u) {
        foreach ($nav as $f => $l) echo '<a href="' . $f . '"' . ($active === $f ? ' class="active"' : '') . '>' . $l . '</a>';
        echo '<a class="out" href="logout.php">Déconnexion (' . e($u['prenom']) . ')</a>';
    }
    echo '</nav></header><main>';
    if (!empty($_SESSION['flash'])) { [$m, $t] = $_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="flash ' . e($t) . '">' . e($m) . '</div>'; }
    echo '<h1>' . e($title) . '</h1>';
}
function page_end(): void { echo '</main><footer class="bottom">' . e(APP_NAME) . ' · <a href="../index.html">Retour au site</a></footer></body></html>'; }
