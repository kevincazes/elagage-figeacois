<?php
// Images du guide : servies uniquement aux personnes connectées (elles montrent des prix internes)
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_login();
$f = basename((string)($_GET['f'] ?? ''));
$p = __DIR__ . '/guide-img/' . $f;
if (!preg_match('/^[a-z0-9-]+\.(jpg|png)$/', $f) || !is_file($p)) { http_response_code(404); exit; }
header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
header('Cache-Control: private, max-age=86400');
readfile($p);
