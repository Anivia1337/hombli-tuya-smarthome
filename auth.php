<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
session_start(['cookie_httponly' => true, 'cookie_secure' => true, 'cookie_samesite' => 'Strict']);
$loggedIn = !empty($_SESSION['smarthome_auth']);
