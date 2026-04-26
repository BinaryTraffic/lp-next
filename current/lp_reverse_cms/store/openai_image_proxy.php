<?php

declare(strict_types=1);

/**
 * Browser-friendly proxy for OpenAI Images API (same-origin only).
 *
 * POST JSON: { "prompt", "model"?, "size"?, "api_key"? }
 * - OPENAI_API_KEY が環境または lp_reverse_cms/.env にある場合はそれを使用し、本文の api_key は無視。
 * - サーバーにキーが無く、OPENAI_DENY_CLIENT_KEY が 1 でない限り、従来どおり POST の api_key を許可（開発用）。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/api_usage_log.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$prompt = isset($in['prompt']) ? trim((string) $in['prompt']) : '';
$bodyKey = isset($in['api_key']) ? trim((string) $in['api_key']) : '';
$model = isset($in['model']) ? trim((string) $in['model']) : 'dall-e-3';
$size = isset($in['size']) ? trim((string) $in['size']) : '1024x1024';

$serverKey = trim((string) (getenv('OPENAI_API_KEY') ?: ''));
$denyClient = getenv('OPENAI_DENY_CLIENT_KEY') === '1';

$apiKey = '';
if ($serverKey !== '') {
    $apiKey = $serverKey;
} elseif (!$denyClient && $bodyKey !== '') {
    $apiKey = $bodyKey;
}

if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'prompt が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode([
        'error' => 'OpenAI API キーが未設定です。サーバーの lp_reverse_cms/.env に OPENAI_API_KEY を設定するか、環境変数で注入してください。（開発のみ: キーがサーバーに無い場合は POST の api_key。厳格に拒否する場合は OPENAI_DENY_CLIENT_KEY=1）',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($model !== 'dall-e-2' && $model !== 'dall-e-3') {
    $model = 'dall-e-3';
}

$allowedD3 = ['1024x1024', '1024x1792', '1792x1024'];
$allowedD2 = ['256x256', '512x512', '1024x1024'];
if ($model === 'dall-e-3') {
    if (!in_array($size, $allowedD3, true)) {
        $size = '1024x1024';
    }
} else {
    if (!in_array($size, $allowedD2, true)) {
        $size = '1024x1024';
    }
}

$payload = [
    'model' => $model,
    'prompt' => mb_substr($prompt, 0, 4000),
    'n' => 1,
    'size' => $size,
    'response_format' => 'url',
];

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP cURL 拡張が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = curl_init('https://api.openai.com/v1/images/generations');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 180,
]);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    lp_reverse_api_usage_record([
        'env_var' => 'OPENAI_API_KEY',
        'provider' => 'openai',
        'operation' => 'images/generations',
        'ok' => false,
        'http_code' => 502,
        'meta' => [
            'model' => $model,
            'size' => $size,
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'curl_error' => $curlErr,
        ],
        'usage' => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI 接続エラー: ' . $curlErr], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if ($code !== 200) {
    $msg = is_array($data) && isset($data['error']['message'])
        ? (string) $data['error']['message']
        : mb_substr($response, 0, 800);
    lp_reverse_api_usage_record([
        'env_var' => 'OPENAI_API_KEY',
        'provider' => 'openai',
        'operation' => 'images/generations',
        'ok' => false,
        'http_code' => $code,
        'meta' => [
            'model' => $model,
            'size' => $size,
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'error_message' => $msg,
        ],
        'usage' => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code($code >= 400 && $code < 600 ? $code : 502);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($data) || empty($data['data'][0]['url'])) {
    lp_reverse_api_usage_record([
        'env_var' => 'OPENAI_API_KEY',
        'provider' => 'openai',
        'operation' => 'images/generations',
        'ok' => false,
        'http_code' => $code,
        'meta' => [
            'model' => $model,
            'size' => $size,
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'reason' => 'no_image_url_in_response',
        ],
        'usage' => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code(502);
    echo json_encode(['error' => '応答に画像 URL がありません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$est = lp_reverse_api_usage_estimate_openai_image($model, $size);
$metaOk = [
    'model' => $model,
    'size' => $size,
    'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
];
if (is_array($data) && isset($data['usage']) && is_array($data['usage'])) {
    $metaOk['response_usage'] = $data['usage'];
}
lp_reverse_api_usage_record([
    'env_var' => 'OPENAI_API_KEY',
    'provider' => 'openai',
    'operation' => 'images/generations',
    'ok' => true,
    'http_code' => $code,
    'meta' => $metaOk,
    'usage' => ['images' => 1],
    'estimated_usd' => $est,
]);

echo json_encode(['url' => (string) $data['data'][0]['url']], JSON_UNESCAPED_UNICODE);
