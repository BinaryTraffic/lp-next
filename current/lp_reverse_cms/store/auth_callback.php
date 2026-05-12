<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/env_load.php';
lp_reverse_load_env();

require_once $cmsRoot . '/lib/GoogleAuth.php';
require_once $cmsRoot . '/lib/UserRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

$auth     = new GoogleAuth();
$dataDir  = LpWorkspace::authRegistryDir($cmsRoot);
$registry = new UserRegistry($dataDir);

$code   = isset($_GET['code']) ? (string) $_GET['code'] : '';
$state  = isset($_GET['state']) ? (string) $_GET['state'] : '';
$error  = isset($_GET['error']) ? (string) $_GET['error'] : '';

if ($error !== '' || $code === '') {
    header('Location: ../index.php?step=1&auth_error=' . rawurlencode('認証がキャンセルされました'));

    exit;
}

try {
    $user       = $auth->handleCallback($code, $state);
    $email      = strtolower(trim($user['email']));
    $name       = (string) $user['name'];
    $effective  = $registry->getRole($email);

    if ($effective === null) {
        $registry->registerPending($email, $name);
        $effective = $registry->getRole($email) ?? 'pending';
    }

    session_regenerate_id(true);

    $_SESSION['auth'] = [
        'email' => $email,
        'name'  => $name,
        'role'  => $effective,
    ];

    if ($effective === 'preview') {
        header('Location: ../preview.php');

        exit;
    }

    header('Location: ../index.php?step=1');

    exit;

} catch (RuntimeException $e) {
    header('Location: ../index.php?step=1&auth_error=' . rawurlencode($e->getMessage()));
    exit;
}
