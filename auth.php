<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
session_start(['cookie_httponly' => true, 'cookie_secure' => true, 'cookie_samesite' => 'Strict']);

// Stay signed in for 30 days: a cookie with its expiry, signed with the password hash.
// Changing the password signs every device out.
const REMEMBER_COOKIE = 'smarthome_remember';
const REMEMBER_DAYS = 30;

function remember_sig(string $until): string
{
    return hash_hmac('sha256', "smarthome|$until", PAGE_PASSWORD_HASH);
}

function remember_set(bool $on): void
{
    $until = $on ? (string) (time() + REMEMBER_DAYS * 86400) : '';
    setcookie(REMEMBER_COOKIE, $on ? $until . '.' . remember_sig($until) : '', [
        'expires' => $on ? (int) $until : 1, 'path' => dirname($_SERVER['SCRIPT_NAME']), // no slash, so /smarthome matches too
        'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

if (empty($_SESSION['smarthome_auth'])) {
    [$until, $sig] = explode('.', $_COOKIE[REMEMBER_COOKIE] ?? '', 2) + ['', ''];
    if (ctype_digit($until) && (int) $until > time() && hash_equals(remember_sig($until), $sig)) {
        session_regenerate_id(true);
        $_SESSION['smarthome_auth'] = true;
    }
}
$loggedIn = !empty($_SESSION['smarthome_auth']);
