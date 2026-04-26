<?php

declare(strict_types=1);

/**
 * GET: data/api_usage_totals.json の内容とイベントログの行数。
 * ブラウザや運用スクリプトから積算を確認する用（認証なし — 必要なら Web サーバー側で制限してください）。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/api_usage_log.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$totalsPath = lp_reverse_api_usage_totals_path();
$eventsPath = lp_reverse_api_usage_events_path();

$totals = [];
if (is_readable($totalsPath)) {
    $raw = file_get_contents($totalsPath);
    $totals = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($totals)) {
        $totals = [];
    }
}

$eventLines = 0;
if (is_readable($eventsPath)) {
    $fp = fopen($eventsPath, 'rb');
    if ($fp !== false) {
        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line !== false && trim($line) !== '') {
                $eventLines++;
            }
        }
        fclose($fp);
    }
}

echo json_encode([
    'totals_by_env_var' => $totals,
    'events_file' => $eventsPath,
    'events_line_count' => $eventLines,
    'totals_file' => $totalsPath,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
