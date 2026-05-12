<?php

declare(strict_types=1);

/**
 * Claude Vision API proxy — 画像内テキストの座標抽出。
 *
 * POST JSON:
 * - image_url: 取得可能な URL（従来）
 * - または image_data: base64（パイプライン・同一サーバからの直接解析用）
 * - image_media_type: image_data 利用時（例: image/png）
 * - industry: 任意（業種ヒント。texts.lines の置換案に反映させる）
 * - width, height: 任意ヒント
 * - api_key: 任意（.env が無いとき）
 *
 * 返却: type / texts（lines, semantic_role 等）/ icons / replacement / …
 *
 * bordered 後続フロー（実装は呼出元）:
 * ① 本レスポンスで border.width_pct 等を取得
 * ② inner 寸法を算出し hf_image_proxy で inner 画像生成
 * ③ image_composite.php に border_fill + background_url(inner) を POST
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/api_usage_log.php';
require_once __DIR__ . '/../lib/claude_vision_analyze.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageUrl = isset($in['image_url']) ? trim((string) $in['image_url']) : '';
$imageB64 = isset($in['image_data']) ? trim((string) $in['image_data']) : '';
$imageMedia = isset($in['image_media_type']) ? trim((string) $in['image_media_type']) : '';
$industry = isset($in['industry']) ? trim((string) $in['industry']) : '';
$imgW     = isset($in['width']) ? (int) $in['width'] : 0;
$imgH     = isset($in['height']) ? (int) $in['height'] : 0;
$bodyKey  = isset($in['api_key']) ? trim((string) $in['api_key']) : '';

$serverKey  = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
$denyClient = getenv('ANTHROPIC_DENY_CLIENT_KEY') === '1';

$apiKey = '';
if ($serverKey !== '') {
    $apiKey = $serverKey;
} elseif (!$denyClient && $bodyKey !== '') {
    $apiKey = $bodyKey;
}

if ($imageUrl === '' && $imageB64 === '') {
    http_response_code(400);
    echo json_encode(['error' => 'image_url または image_data のどちらかが必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'ANTHROPIC_API_KEY が未設定です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imgBin = false;
$imgMime = '';

if ($imageB64 !== '') {
    $imgBin = base64_decode($imageB64, true);
    if ($imgBin === false || strlen($imgBin) < 100) {
        http_response_code(400);
        echo json_encode(['error' => 'image_data の base64 が無効です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mime = $imageMedia !== '' ? $imageMedia : 'image/jpeg';
    $mime = match (true) {
        str_contains(strtolower($mime), 'png')  => 'image/png',
        str_contains(strtolower($mime), 'webp') => 'image/webp',
        str_contains(strtolower($mime), 'gif')  => 'image/gif',
        default                                  => 'image/jpeg',
    };
} else {
    $ch = curl_init($imageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $imgBin = curl_exec($ch);
    $imgMime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
    curl_close($ch);

    if ($imgBin === false || strlen($imgBin) < 100) {
        http_response_code(502);
        echo json_encode(['error' => '画像の取得に失敗しました: ' . $imageUrl], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mime = match (true) {
        str_contains($imgMime, 'png')  => 'image/png',
        str_contains($imgMime, 'webp') => 'image/webp',
        str_contains($imgMime, 'gif')  => 'image/gif',
        default                        => 'image/jpeg',
    };
}

if ($imgW <= 0 || $imgH <= 0) {
    $info = @getimagesizefromstring($imgBin);
    if (is_array($info)) {
        $imgW = (int) ($info[0] ?? 0);
        $imgH = (int) ($info[1] ?? 0);
    }
}

$vr = lp_reverse_claude_vision_request($imgBin, $mime, $imgW, $imgH, $apiKey, $industry);

if (!$vr['ok']) {
    lp_reverse_api_usage_record([
        'env_var' => 'ANTHROPIC_API_KEY',
        'provider' => 'anthropic',
        'operation' => 'messages',
        'ok' => false,
        'http_code' => $vr['http_code'] ?? 502,
        'meta' => [
            'model' => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'error_message' => $vr['error'] ?? '',
        ],
        'usage' => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code($vr['http_code'] ?? 502);
    echo json_encode(['error' => $vr['error'] ?? 'Vision エラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = $vr['text'];
$inTok = (int) ($vr['usage']['input_tokens'] ?? 0);
$outTok = (int) ($vr['usage']['output_tokens'] ?? 0);
$estAnth = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);

$parsed = json_decode($text, true);

if (!is_array($parsed)) {
    lp_reverse_api_usage_record([
        'env_var' => 'ANTHROPIC_API_KEY',
        'provider' => 'anthropic',
        'operation' => 'messages',
        'ok' => false,
        'http_code' => 502,
        'meta' => [
            'model' => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'reason' => 'vision_json_parse_failed',
        ],
        'usage' => [
            'input_tokens' => $inTok,
            'output_tokens' => $outTok,
        ],
        'estimated_usd' => $estAnth,
    ]);
    http_response_code(502);
    echo json_encode([
        'error'    => 'Claude応答のJSONパースに失敗しました',
        'raw'      => $text,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_api_usage_record([
    'env_var' => 'ANTHROPIC_API_KEY',
    'provider' => 'anthropic',
    'operation' => 'messages',
    'ok' => true,
    'http_code' => 200,
    'meta' => [
        'model' => 'claude-sonnet-4-6',
        'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
    ],
    'usage' => [
        'input_tokens' => $inTok,
        'output_tokens' => $outTok,
    ],
    'estimated_usd' => $estAnth,
]);

$parsed = lp_reverse_normalize_claude_vision_array($parsed);

echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
