<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$dataDir = LpWorkspace::dataDir(dirname(__DIR__));
$flagPath = $dataDir . 'abort.flag';

file_put_contents($flagPath, date('c'), LOCK_EX);

echo json_encode([
    'ok'      => true,
    'message' => 'abort signal sent',
], JSON_UNESCAPED_UNICODE);
