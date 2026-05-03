<?php

declare(strict_types=1);

/**
 * GET: lp_structure.json を読み、元業種＋関連5業種を JSON で返す（Claude Haiku）。
 *
 * レスポンス例:
 * {"source_industry":"ペットサロン","suggestions":["美容室","…"]}
 *
 * 認証: サーバの ANTHROPIC_API_KEY のみ（クライアント鍵は使わない）。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/suggest_industries.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$path = LpWorkspace::dataDir(dirname(__DIR__)) . 'lp_structure.json';
if (!is_readable($path)) {
    echo json_encode(['source_industry' => '', 'suggestions' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$raw = file_get_contents($path);
$structure = json_decode($raw ?: '[]', true);
if (!is_array($structure)) {
    $structure = [];
}

$out = lp_reverse_suggest_industries_from_structure($structure);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
