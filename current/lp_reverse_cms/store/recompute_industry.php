<?php

declare(strict_types=1);

/**
 * POST store/recompute_industry.php
 *
 * AI モーダルを開いた時に source_industry が空の場合に呼ばれる。
 * lp_reverse_suggest_industries_from_structure() を実行し、
 * 成功すれば industry_suggest.json を上書き保存して結果を返す。
 *
 * Response: { ok: true, source_industry: "探偵事務所", suggestions: ["...", ...] }
 *        or { ok: false, error: "..." }
 */

require_once __DIR__ . '/../lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../lib/LpWorkspace.php';

$cmsRoot = dirname(__DIR__);
$dataDir = LpWorkspace::dataDir($cmsRoot);

$structureFile = $dataDir . 'lp_structure.json';
if (!is_readable($structureFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'lp_structure.json が見つかりません'], JSON_UNESCAPED_UNICODE);
    exit;
}

$structure = json_decode((string) file_get_contents($structureFile), true);
if (!is_array($structure)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'lp_structure.json が不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $cmsRoot . '/lib/suggest_industries.php';

$result = lp_reverse_suggest_industries_from_structure($structure);
$sourceIndustry = trim((string) ($result['source_industry'] ?? ''));
$suggestions    = [];
$sugRaw = $result['suggestions'] ?? [];
if (is_array($sugRaw)) {
    foreach ($sugRaw as $s) {
        $t = trim((string) $s);
        if ($t !== '') {
            $suggestions[] = $t;
        }
    }
}

// 成功した場合のみ保存
if ($sourceIndustry !== '') {
    $industrySuggestPath = $dataDir . 'industry_suggest.json';
    file_put_contents(
        $industrySuggestPath,
        json_encode(
            ['source_industry' => $sourceIndustry, 'suggestions' => $suggestions],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ),
        LOCK_EX,
    );
}

echo json_encode([
    'ok'              => true,
    'source_industry' => $sourceIndustry,
    'suggestions'     => $suggestions,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
