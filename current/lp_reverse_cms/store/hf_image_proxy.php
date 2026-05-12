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
require_once __DIR__ . '/../lib/hf_image_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

require_once __DIR__ . '/../lib/LpWorkspace.php';
$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'CMS ルートの解決に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP cURL 拡張が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$keySrc = $serverToken !== '' ? 'server_env' : 'client_body';
$res = lp_reverse_hf_save_generated_image($cmsRoot, $mode, $prompt, $bgDesc, $illStyle, $w, $h, $token, $keySrc);

if (!$res['ok']) {
    $msg = $res['error'] ?? 'HF エラー';
    $code = str_contains($msg, '必要です') || str_contains($msg, 'prompt') ? 400 : 502;
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'url'            => $res['url'],
    'content_type'   => $res['content_type'] ?? 'image/jpeg',
    'mode'           => $res['mode'] ?? $mode,
    'model'          => $res['model'] ?? '',
], JSON_UNESCAPED_UNICODE);
