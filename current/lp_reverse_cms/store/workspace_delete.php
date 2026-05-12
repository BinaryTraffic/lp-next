<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/WorkspaceRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';
require_once $cmsRoot . '/lib/lp_reverse_csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $actor = lp_reverse_store_auth_actor($cmsRoot);
    $raw   = (string) file_get_contents('php://input');
    $body  = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        throw new InvalidArgumentException('JSON body required');
    }
    $csrf = isset($body['csrf']) ? trim((string) $body['csrf']) : '';
    if (!lp_reverse_csrf_validate($csrf !== '' ? $csrf : null)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF verification failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = strtolower(trim((string) ($body['workspace_id'] ?? $body['id'] ?? '')));
    if ($id === '' || !preg_match('/^ws_[a-f0-9]{32}$/', $id)) {
        throw new InvalidArgumentException('workspace_id must be ws_ plus 32 hex chars');
    }

    $reg   = new WorkspaceRegistry($cmsRoot);
    $ok    = $reg->deleteIfAllowed($id, $actor);
    if (!$ok) {
        http_response_code(403);
        echo json_encode([
            'ok'    => false,
            'error' => 'このワークスペースは削除できません（所有者のみ、または未登録は super_admin のみ）。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cleared = false;
    if (strtolower('ws_' . LpWorkspace::id()) === $id) {
        unset($_SESSION['lp_reverse_ws']);
        $cleared = true;
    }

    echo json_encode([
        'ok'               => true,
        'cleared_session'  => $cleared,
        'message'          => 'deleted',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    $msg = $e->getMessage();
    if (str_contains($msg, 'unlink failed')
        || str_contains($msg, 'rmdir failed')
        || str_contains($msg, 'Permission denied')) {
        $msg = 'ファイル権限のため削除できません。Apache ユーザー（www-data）がグループ lp-tool に含まれ、'
            . 'ワークスペースが shimizu:lp-tool で作成されている場合に必要です。サーバー管理者が '
            . '`sudo usermod -aG lp-tool www-data` と Apache 再起動を実施してください。技術詳細: '
            . $e->getMessage();
    }
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}
