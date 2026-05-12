<?php

declare(strict_types=1);

/**
 * POST JSON: { "company_name": "...", "address_pref": "", "address_city": "", "skip_ai": false }
 * 企業名から公表法人情報（任意）と AI 参考ヒントを返す。LP への利用は必ずユーザー確認後。
 *
 * 国税庁法人番号 API は .env のキーがある場合のみ。クライアントの反社チェック等の契約・方針に応じ、
 * 本エンドポイント経由で使うか・別系統に任せるかは製品要件側で決める。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/company_profile_lookup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

lp_reverse_load_env();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in  = $raw ? json_decode($raw, true) : null;
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name    = isset($in['company_name']) ? trim((string) $in['company_name']) : '';
$pref    = isset($in['address_pref']) ? trim((string) $in['address_pref']) : '';
$city    = isset($in['address_city']) ? trim((string) $in['address_city']) : '';
$skipAi  = !empty($in['skip_ai']);

$out = lp_reverse_company_profile_lookup($name, $pref, $city, $skipAi);
if (!($out['ok'] ?? false)) {
    http_response_code(400);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
