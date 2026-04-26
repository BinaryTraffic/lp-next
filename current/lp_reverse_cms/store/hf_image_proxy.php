<?php

declare(strict_types=1);

/**
 * Hugging Face Inference API — テキストから画像を生成し output/ai_images/hf_* に保存する。
 *
 * Claude Vision（claude_image_analyze.php）の type に応じたプロンプト組み立てを想定:
 * - photo        → 実写向け接頭辞 + prompt / background_description
 * - illustration → illustration_style に応じたイラスト接頭辞
 * - composite    → 文字なし背景・バナー向け（テキストは後段 image_composite.php）
 * - ui           → 通常は画像生成をスキップし image_composite のみだが、同モードで「背景のみ」生成も可
 *
 * POST JSON:
 * {
 *   "mode": "photo | illustration | composite | ui",
 *   "prompt": "（任意）英語メイン断片",
 *   "background_description": "（任意）Claude の background_description",
 *   "illustration_style": "line_art | flat | watercolor | none",
 *   "width": 0,
 *   "height": 0,
 *   "api_key": "（任意）hf_… サーバー未設定時"
 * }
 *
 * 成功: { "url": "/output/ai_images/hf_<hex>.png|jpg", "content_type": "..." }
 *
 * .env: HUGGINGFACE_API_TOKEN または HF_TOKEN、任意で HF_IMAGE_MODEL
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

$mode = strtolower(trim((string) ($in['mode'] ?? 'composite')));
$prompt = trim((string) ($in['prompt'] ?? ''));
$bgDesc = trim((string) ($in['background_description'] ?? ''));
$illStyle = strtolower(trim((string) ($in['illustration_style'] ?? 'none')));
$w = isset($in['width']) ? max(0, (int) $in['width']) : 0;
$h = isset($in['height']) ? max(0, (int) $in['height']) : 0;
$bodyKey = isset($in['api_key']) ? trim((string) $in['api_key']) : '';

$allowedModes = ['photo', 'illustration', 'composite', 'ui'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'composite';
}

$serverToken = trim((string) (getenv('HUGGINGFACE_API_TOKEN') ?: getenv('HF_TOKEN') ?: ''));
$denyClient = getenv('HF_DENY_CLIENT_KEY') === '1';

$token = '';
if ($serverToken !== '') {
    $token = $serverToken;
} elseif (!$denyClient && $bodyKey !== '') {
    $token = $bodyKey;
}

if ($token === '') {
    http_response_code(503);
    echo json_encode([
        'error' => 'Hugging Face トークンが未設定です。.env に HUGGINGFACE_API_TOKEN（または HF_TOKEN）を設定するか、環境変数で注入してください。（開発のみ: POST の api_key。厳格に拒否する場合は HF_DENY_CLIENT_KEY=1）',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fullPrompt = hf_build_image_prompt($mode, $prompt, $bgDesc, $illStyle);
if ($fullPrompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'prompt または background_description のいずれかが必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$model = trim((string) (getenv('HF_IMAGE_MODEL') ?: 'black-forest-labs/FLUX.1-schnell'));
if ($model === '' || str_contains($model, '..') || !preg_match('#^[a-zA-Z0-9._\\-/]+$#', $model)) {
    $model = 'black-forest-labs/FLUX.1-schnell';
}

$apiUrl = 'https://api-inference.huggingface.co/models/' . $model;

$payload = ['inputs' => $fullPrompt];
if ($w >= 64 && $h >= 64 && $w <= 2048 && $h <= 2048) {
    $payload['parameters'] = array_filter([
        'width' => $w,
        'height' => $h,
    ], static fn (int $v): bool => $v > 0);
    if (($payload['parameters'] ?? []) === []) {
        unset($payload['parameters']);
    }
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP cURL 拡張が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = hf_inference_request($apiUrl, $token, $payload);
if (!$result['ok'] && $result['code'] === 503 && hf_is_model_loading($result['body'])) {
    sleep(12);
    $result = hf_inference_request($apiUrl, $token, $payload);
}

if (!$result['ok']) {
    $msg = hf_extract_hf_error($result['body'], $result['curl_err']);
    lp_reverse_api_usage_record([
        'env_var' => lp_reverse_api_usage_hf_env_slot(),
        'provider' => 'huggingface',
        'operation' => 'inference/text-to-image',
        'ok' => false,
        'http_code' => $result['code'],
        'meta' => [
            'model' => $model,
            'mode' => $mode,
            'key_source' => $serverToken !== '' ? 'server_env' : 'client_body',
            'error_message' => $msg,
        ],
        'usage' => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code($result['code'] >= 400 && $result['code'] < 600 ? $result['code'] : 502);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = $result['body'];
$ctype = $result['content_type'] ?: '';

if (strlen($body) > 4 && $body[0] === '{' && str_contains($body, 'error')) {
    $j = json_decode($body, true);
    $msg = is_array($j) && isset($j['error']) ? (string) $j['error'] : mb_substr($body, 0, 400);
    lp_reverse_api_usage_record([
        'env_var' => lp_reverse_api_usage_hf_env_slot(),
        'provider' => 'huggingface',
        'operation' => 'inference/text-to-image',
        'ok' => false,
        'http_code' => 502,
        'meta' => [
            'model' => $model,
            'mode' => $mode,
            'key_source' => $serverToken !== '' ? 'server_env' : 'client_body',
            'error_message' => $msg,
        ],
        'usage' => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'HF API: ' . $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$ext = hf_guess_image_extension($body, $ctype);
if ($ext === null) {
    http_response_code(502);
    echo json_encode(['error' => '生成結果が画像バイナリとして認識できませんでした'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'CMS ルートの解決に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$aiDir = $cmsRoot . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR . 'ai_images';
if (!is_dir($aiDir) && !@mkdir($aiDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'output/ai_images を作成できません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fname = 'hf_' . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $aiDir . DIRECTORY_SEPARATOR . $fname;
if (file_put_contents($dest, $body) === false) {
    http_response_code(500);
    echo json_encode(['error' => '画像の保存に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
$publicUrl = '/output/ai_images/' . $fname;

$hfEst = lp_reverse_api_usage_estimate_hf_call();
lp_reverse_api_usage_record([
    'env_var' => lp_reverse_api_usage_hf_env_slot(),
    'provider' => 'huggingface',
    'operation' => 'inference/text-to-image',
    'ok' => true,
    'http_code' => 200,
    'meta' => [
        'model' => $model,
        'mode' => $mode,
        'key_source' => $serverToken !== '' ? 'server_env' : 'client_body',
        'saved' => $fname,
    ],
    'usage' => ['hf_inferences' => 1],
    'estimated_usd' => $hfEst,
]);

echo json_encode([
    'url' => $publicUrl,
    'content_type' => $mime,
    'mode' => $mode,
    'model' => $model,
], JSON_UNESCAPED_UNICODE);

/**
 * @return array{ok: bool, code: int, body: string, content_type: string, curl_err: string}
 */
function hf_inference_request(string $url, string $token, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HEADER         => false,
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'code' => 502, 'body' => '', 'content_type' => '', 'curl_err' => $curlErr];
    }

    return [
        'ok' => $code === 200,
        'code' => $code,
        'body' => $body,
        'content_type' => $ctype,
        'curl_err' => $curlErr,
    ];
}

function hf_is_model_loading(string $body): bool
{
    $l = strtolower($body);

    return str_contains($l, 'loading') && str_contains($l, 'model');
}

function hf_extract_hf_error(string $body, string $curlErr): string
{
    if ($curlErr !== '') {
        return 'HF 接続エラー: ' . $curlErr;
    }
    $j = json_decode($body, true);
    if (is_array($j) && isset($j['error'])) {
        return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE);
    }

    return mb_substr(trim($body), 0, 500) ?: 'Hugging Face API エラー';
}

function hf_build_image_prompt(string $mode, string $prompt, string $bgDesc, string $illStyle): string
{
    $parts = [];
    $main = $prompt !== '' ? $prompt : $bgDesc;
    $extra = ($prompt !== '' && $bgDesc !== '' && $prompt !== $bgDesc) ? $bgDesc : '';

    if ($mode === 'photo') {
        $parts[] = 'Photorealistic photograph, natural lighting, sharp focus, professional quality, no text, no watermark, no typography, no letters.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    } elseif ($mode === 'illustration') {
        $style = match ($illStyle) {
            'line_art', 'lineart' => 'Clean line art, distinct outlines, minimal fill, ',
            'flat' => 'Flat design illustration, solid colors, simple shapes, ',
            'watercolor' => 'Soft watercolor illustration, gentle gradients, ',
            default => 'Modern digital illustration, ',
        };
        $parts[] = $style . 'no text, no captions, no logos, no watermarks.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    } elseif ($mode === 'ui') {
        $parts[] = 'Clean UI or marketing banner background, subtle texture, ample negative space for labels, absolutely no text, letters, numbers, or icons with glyphs.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    } else {
        // composite（既定）— 後からテキストを焼く前提
        $parts[] = 'Wide marketing background, cohesive scene, areas suitable for text overlay, completely text-free image: no letters, no numbers, no logos, no watermarks.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    }

    $s = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts))) ?? '');
    if (mb_strlen($s) > 1800) {
        $s = mb_substr($s, 0, 1800);
    }

    return $s;
}

function hf_guess_image_extension(string $bin, string $ctype): ?string
{
    if (str_contains($ctype, 'jpeg') || str_contains($ctype, 'jpg')) {
        return 'jpg';
    }
    if (str_contains($ctype, 'png')) {
        return 'png';
    }
    if (str_contains($ctype, 'webp')) {
        return 'webp';
    }
    if (strlen($bin) >= 3 && $bin[0] === "\xff" && $bin[1] === "\xd8") {
        return 'jpg';
    }
    if (strlen($bin) >= 8 && str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
        return 'png';
    }
    if (strlen($bin) >= 12 && str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP') {
        return 'webp';
    }

    return null;
}
