<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $dataDir = LpWorkspace::dataDir(dirname(__DIR__));
    $path = $dataDir . 'internal_candidate_urls.json';
    if (!is_readable($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'internal_candidate_urls.json が見つかりません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $doc = json_decode((string) file_get_contents($path), true);
    if (!is_array($doc) || !is_array($doc['urls'] ?? null)) {
        throw new RuntimeException('internal_candidate_urls.json が不正です。');
    }

    $urls = $doc['urls'];
    $processed = 0;
    $pending = 0;
    $error = 0;
    foreach ($urls as $u) {
        $st = (string) ($u['status'] ?? 'pending');
        if ($st === 'processed') {
            $processed++;
        } elseif ($st === 'error') {
            $error++;
        } else {
            $pending++;
        }
    }

    echo json_encode([
        'ok' => true,
        'entry_url' => (string) ($doc['entry_url'] ?? ''),
        'urls' => $urls,
        'total' => (int) ($doc['total'] ?? count($urls)),
        'processed' => $processed,
        'pending' => $pending,
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

