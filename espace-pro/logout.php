<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
souvenir_effacer((int)($_SESSION['user']['id'] ?? 0));
$_SESSION = [];
session_destroy();
header('Location: login.php');
