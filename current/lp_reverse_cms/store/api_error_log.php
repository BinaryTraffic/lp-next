<?php

declare(strict_types=1);

/**
 * GET: api_usage_events.jsonl からエラー・リトライイベントを返す。
 * ユーザーが動作不具合をレポートする際の確認用。
 */
$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/api_usage_log.php';

lp_reverse_store_auth_actor($cmsRoot); // 認証チェック（未ログインは401で exit）

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = min(500, max(10, (int) ($_GET['limit'] ?? 200)));

$eventsPath = lp_reverse_api_usage_events_path();
$events     = [];

if (is_readable($eventsPath)) {
    $fp = fopen($eventsPath, 'rb');
    if ($fp !== false) {
        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false || trim($line) === '') {
                continue;
            }
            $ev = json_decode($line, true);
            if (!is_array($ev)) {
                continue;
            }
            $isError  = !($ev['ok'] ?? true);
            $isRetry  = ($ev['operation'] ?? '') === 'image_load_retry';
            if ($isError || $isRetry) {
                $events[] = $ev;
            }
        }
        fclose($fp);
    }
}

// 新しい順、件数上限
$events = array_reverse(array_slice($events, -$limit));

echo json_encode([
    'ok'     => true,
    'events' => $events,
    'count'  => count($events),
    'source' => basename($eventsPath),
], JSON_UNESCAPED_UNICODE);
