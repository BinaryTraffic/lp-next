<?php

declare(strict_types=1);

/**
 * GET: whether OpenAI proxy uses server-side OPENAI_API_KEY (no secret leaked).
 */
require_once __DIR__ . '/../lib/env_load.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$serverKey = trim((string) (getenv('OPENAI_API_KEY') ?: ''));
$denyClient = getenv('OPENAI_DENY_CLIENT_KEY') === '1';

echo json_encode([
    'server_key_configured' => $serverKey !== '',
    /** サーバーにキーが無いとき、POST 本文の api_key を受け付けるか（本番では deny + サーバーキー推奨） */
    'client_key_allowed' => $serverKey === '' && !$denyClient,
], JSON_UNESCAPED_UNICODE);
