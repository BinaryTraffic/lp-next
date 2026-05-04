<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/env_load.php';
lp_reverse_load_env();
require_once $cmsRoot . '/lib/UserRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessRole  = strtolower((string) ($_SESSION['auth']['role'] ?? ''));
$sessEmail = strtolower(trim((string) ($_SESSION['auth']['email'] ?? '')));

if ($sessRole === '' || !in_array($sessRole, ['super_admin', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$superAdminLower = strtolower(trim((string) getenv('CMS_SUPER_ADMIN')));
$payload         = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
/** @phpstan-ignore-next-line */
$action = (string) ($payload['action'] ?? '');
$email  = strtolower(trim((string) ($payload['email'] ?? '')));
$role   = strtolower((string) ($payload['role'] ?? 'preview'));

if ($email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'email required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'approve' && $action !== 'reject') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid action'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'approve') {
    if (!in_array($role, ['admin', 'preview'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid role'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($role === 'admin' && $sessRole !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'admin ロールの付与は super_admin のみ可能です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($superAdminLower !== '' && $email === $superAdminLower) {
        http_response_code(403);
        echo json_encode(['error' => 'super_admin アカウントには適用できません'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($superAdminLower !== '' && $email === $superAdminLower) {
    http_response_code(403);
    echo json_encode(['error' => 'super_admin アカウントには適用できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dataDir  = LpWorkspace::authRegistryDir($cmsRoot);
$registry = new UserRegistry($dataDir);

if ($action === 'approve') {
    /** @var 'admin'|'preview' $grant */
    $grant = ($role === 'admin') ? 'admin' : 'preview';
    $ok    = $registry->approve($email, $grant, $sessEmail);
} else {
    $ok = $registry->reject($email, $sessEmail);
}

if (!$ok) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => '対象ユーザーが見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
