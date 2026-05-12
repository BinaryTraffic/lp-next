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

$superRaw        = getenv('CMS_SUPER_ADMIN');
$superRaw        = (is_string($superRaw) && trim($superRaw) !== '') ? trim($superRaw) : '';
if ($superRaw === '') {
    $e = $_ENV['CMS_SUPER_ADMIN'] ?? null;
    $superRaw = (is_string($e) && trim($e) !== '') ? trim($e) : '';
}
$superAdminLower = strtolower($superRaw);
$payload         = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
/** @phpstan-ignore-next-line */
$action  = (string) ($payload['action'] ?? '');
$email   = strtolower(trim((string) ($payload['email'] ?? '')));
$newRole = strtolower(trim((string) ($payload['role'] ?? '')));

if ($action === 'add_user') {
    $name      = trim((string) ($payload['name'] ?? ''));
    $addStatus = strtolower(trim((string) ($payload['status'] ?? 'approved')));
    $addRole   = strtolower(trim((string) ($payload['role'] ?? 'preview')));

    if ($email === '') {
        http_response_code(400);
        echo json_encode(['error' => 'email required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($superAdminLower !== '' && $email === $superAdminLower) {
        http_response_code(403);
        echo json_encode(['error' => 'super_admin メールには追加できません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($addStatus, ['pending', 'approved'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'status は pending または approved です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($addStatus === 'approved') {
        if (!in_array($addRole, ['admin', 'preview'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid role'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($addRole === 'admin' && $sessRole !== 'super_admin') {
            http_response_code(403);
            echo json_encode(['error' => 'admin として追加できるのは super_admin のみです'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $dataDir  = LpWorkspace::authRegistryDir($cmsRoot);
    $registry = new UserRegistry($dataDir);

    $roleApproved = ($addStatus === 'approved')
        ? (($addRole === 'admin') ? 'admin' : 'preview')
        : null;

    $code = $registry->addManualUser($email, $name, $addStatus, $roleApproved, $sessEmail !== '' ? $sessEmail : 'system');

    if ($code !== 'ok') {
        $map = [
            'invalid_email'         => [400, 'メールアドレスの形式が正しくありません'],
            'duplicate'             => [409, '既に登録されています'],
            'super_admin_conflict'  => [403, 'super_admin メールには追加できません'],
            'invalid_role'          => [400, 'invalid role'],
            'invalid_status'        => [400, 'invalid status'],
        ];
        [$stat, $msg] = $map[$code] ?? [400, '追加に失敗しました'];
        http_response_code($stat);
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'email required'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($superAdminLower !== '' && $email === $superAdminLower) {
    http_response_code(403);
    echo json_encode(['error' => 'super_admin はこの API から変更・削除できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'remove' && $sessEmail !== '' && $email === $sessEmail) {
    http_response_code(400);
    echo json_encode(['error' => '自分自身のアカウントは削除できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'change_role' && $action !== 'remove') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid action'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dataDir  = LpWorkspace::authRegistryDir($cmsRoot);
$registry = new UserRegistry($dataDir);

if ($action === 'change_role') {
    if (!in_array($newRole, ['admin', 'preview'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid role'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($newRole === 'admin' && $sessRole !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'admin ロールへの変更は super_admin のみ可能です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $nr = ($newRole === 'admin') ? 'admin' : 'preview';
    $ok = $registry->changeRole($email, $nr);
} else {
    $ok = $registry->remove($email);
}

if (!$ok) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => '対象ユーザーが見つかりません、または変更できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
