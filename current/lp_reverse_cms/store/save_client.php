<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $raw   = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : null;

    if (!is_array($input)) {
        throw new InvalidArgumentException('有効なJSONデータを送信してください。');
    }

    // Sanitise: only allow known top-level keys
    $allowed = ['meta', 'elements'];
    foreach (array_keys($input) as $key) {
        if (!in_array($key, $allowed, true)) {
            unset($input[$key]);
        }
    }

    $dataDir = LpWorkspace::dataDir(dirname(__DIR__));
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    file_put_contents(
        $dataDir . 'client_data.json',
        json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo json_encode(['success' => true, 'message' => '顧客データを保存しました。']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
