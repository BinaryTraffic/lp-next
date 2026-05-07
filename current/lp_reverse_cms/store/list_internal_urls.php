<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$cmsRoot     = dirname(__DIR__);
$dataDir     = LpWorkspace::dataDir($cmsRoot);
$siteMapPath = $dataDir . 'site_map.json';

if (!is_readable($siteMapPath)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => 'site_map.json が見つかりません。解析を実行してください。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($siteMapPath);
$siteMap = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($siteMap) || empty($siteMap['pages']) || !is_array($siteMap['pages'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'site_map.json が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$entryUrl = trim((string) (($siteMap['meta'] ?? [])['entry_url'] ?? ''));

/**
 * UI 表示用ステータス: ok → pending、その他 generated / error はそのまま
 */
$toUiStatus = static function (string $rawStatus): string {
    return match ($rawStatus) {
        'generated' => 'generated',
        'error' => 'error',
        default => 'pending',
    };
};

$pageKeys = array_keys($siteMap['pages']);
$internalKeys = array_values(array_filter(
    $pageKeys,
    static fn($k): bool => is_string($k) && preg_match('/^internal_\d+$/', $k),
));

usort(
    $internalKeys,
    static function (string $a, string $b): int {
        preg_match('/^internal_(\d+)$/', $a, $ma);
        preg_match('/^internal_(\d+)$/', $b, $mb);

        return ((int) ($ma[1] ?? 0)) <=> ((int) ($mb[1] ?? 0));
    },
);

$internals = [];
$stats = ['total' => 0, 'generated' => 0, 'pending' => 0, 'error' => 0];

foreach ($internalKeys as $key) {
    $page = $siteMap['pages'][$key];
    if (!is_array($page)) {
        continue;
    }

    $rawStatus = (string) ($page['status'] ?? 'ok');
    $ui = $toUiStatus($rawStatus);

    ++$stats['total'];
    if ($ui === 'generated') {
        ++$stats['generated'];
    } elseif ($ui === 'error') {
        ++$stats['error'];
    } else {
        ++$stats['pending'];
    }

    $internals[] = [
        'key'         => $key,
        'source_url'  => (string) ($page['source_url'] ?? ''),
        'status'      => $ui,
        'raw_status'  => $rawStatus,
        'coordinate'  => (string) ($page['coordinate'] ?? ''),
        'local_path'  => (string) ($page['local_path'] ?? ''),
    ];
}

echo json_encode([
    'success'    => true,
    'ok'         => true,
    'entry_url'  => $entryUrl,
    'internals'  => $internals,
    'total'      => $stats['total'],
    'generated'  => $stats['generated'],
    'pending'    => $stats['pending'],
    'error'      => $stats['error'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
