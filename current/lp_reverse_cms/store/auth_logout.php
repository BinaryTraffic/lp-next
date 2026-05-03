<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        (string) ($p['path'] ?? '/'),
        (string) ($p['domain'] ?? ''),
        (bool) ($p['secure'] ?? false),
        (bool) ($p['httponly'] ?? true),
    );
}

session_destroy();
header('Location: ../index.php');
exit;
