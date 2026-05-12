<?php

declare(strict_types=1);

/**
 * GET: LpTheme::forApi() — サイトテーマ JSON（palette / button_plate / ui / classes）。
 */
require_once __DIR__ . '/../lib/LpTheme.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(LpTheme::forApi(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
