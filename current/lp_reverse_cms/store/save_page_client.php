<?php

declare(strict_types=1);

/**
 * POST store/save_page_client.php
 *
 * Body: { "page_key": "index"|"internal_N", "meta": {...}, "elements": {...} }
 * Saves per-page client data to data/ws_xxx/page_client/<key>.json.
 * For the index page, also mirrors to client_data.json for backward compatibility.
 */

require_once __DIR__ . '/../lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Require logged-in session
if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $raw   = (string) file_get_contents('php://input');
    $input = ($raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        throw new InvalidArgumentException('有効なJSONボディが必要です。');
    }

    $pageKey = trim((string) ($input['page_key'] ?? ''));
    if ($pageKey !== 'index' && !preg_match('/^internal_\d+$/', $pageKey)) {
        throw new InvalidArgumentException('page_key が不正です（index または internal_N）。');
    }

    // Allow only known top-level keys
    $data = [];
    foreach (['meta', 'elements'] as $k) {
        if (isset($input[$k]) && is_array($input[$k])) {
            $data[$k] = $input[$k];
        }
    }

    require_once __DIR__ . '/../lib/LpWorkspace.php';
    $cmsRoot = dirname(__DIR__);
    $dataDir = LpWorkspace::dataDir($cmsRoot);

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    // Save to per-page file
    $pageClientDir = $dataDir . 'page_client';
    if (!is_dir($pageClientDir)) {
        mkdir($pageClientDir, 0755, true);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($pageClientDir . '/' . $pageKey . '.json', $json, LOCK_EX);

    // For index page: also mirror to client_data.json for backward compat with older generators
    if ($pageKey === 'index') {
        file_put_contents($dataDir . 'client_data.json', $json, LOCK_EX);
    }

    echo json_encode(['ok' => true, 'page_key' => $pageKey], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
