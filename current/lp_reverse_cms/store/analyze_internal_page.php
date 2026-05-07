<?php

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../lib/LpInternalPagesPipeline.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $cmsRoot = dirname(__DIR__);
    $dataDir = LpWorkspace::dataDir($cmsRoot);
    $outputDir = LpWorkspace::outputDir($cmsRoot);

    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? []) : [];
    $index = (int) ($body['index'] ?? -1);
    if ($index < 0) {
        throw new RuntimeException('index が不正です。');
    }

    $candidatePath = $dataDir . 'internal_candidate_urls.json';
    if (!is_readable($candidatePath)) {
        throw new RuntimeException('internal_candidate_urls.json が見つかりません。');
    }
    $candidateDoc = json_decode((string) file_get_contents($candidatePath), true);
    if (!is_array($candidateDoc) || !is_array($candidateDoc['urls'] ?? null)) {
        throw new RuntimeException('internal_candidate_urls.json が不正です。');
    }

    $urls = $candidateDoc['urls'];
    if (!isset($urls[$index]) || !is_array($urls[$index])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => '指定 index の内部ページ候補がありません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = $urls[$index];
    $canon = (string) ($row['canonical_url'] ?? '');
    if ($canon === '') {
        throw new RuntimeException('canonical_url が不正です。');
    }
    $status = (string) ($row['status'] ?? 'pending');
    if ($status === 'error') {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'index' => $index,
            'key' => 'internal_' . (string) $index,
            'error' => 'この候補は error のためスキップします。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = LpInternalPagesPipeline::processSingleUrl($canon, $dataDir, $outputDir);
    $key = 'internal_' . (string) $index;
    $wsFolder = basename(rtrim(str_replace('\\', '/', $outputDir), '/'));
    $outputRel = $key . '/index.html';

    $manifestRow = [
        'fetch_ok' => (bool) ($res['fetch_ok'] ?? false),
        'canonical_url' => (string) ($res['canonical_url'] ?? $canon),
        'source_canonical' => (string) ($res['source_canonical'] ?? $canon),
        'structure_file' => $res['structure_file'] ?? null,
        'output_file' => $outputRel,
        'final_fetch_url' => (string) ($res['final_fetch_url'] ?? $canon),
        'section_count' => (int) ($res['section_count'] ?? 0),
        'asset_new_downloads' => (int) ($res['asset_new_downloads'] ?? 0),
        'asset_sync_limited' => (bool) ($res['asset_sync_limited'] ?? false),
    ];
    if (isset($res['error'])) {
        $manifestRow['error'] = (string) $res['error'];
    }

    $structurePath = $dataDir . 'lp_structure.json';
    $structure = is_readable($structurePath) ? json_decode((string) file_get_contents($structurePath), true) : null;
    if (!is_array($structure)) {
        throw new RuntimeException('lp_structure.json が見つかりません。');
    }
    $internalPages = $structure['internal_pages'] ?? [];
    if (!is_array($internalPages)) {
        $internalPages = [];
    }
    $internalPages[$index] = $manifestRow;
    ksort($internalPages);
    $structure['internal_pages'] = $internalPages;
    file_put_contents(
        $structurePath,
        json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $urls[$index]['status'] = !empty($res['fetch_ok']) ? 'processed' : 'error';
    $candidateDoc['urls'] = $urls;
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
    $candidateDoc['processed'] = $processed;
    $candidateDoc['pending'] = $pending;
    $candidateDoc['error'] = $error;
    file_put_contents(
        $candidatePath,
        json_encode($candidateDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $siteMapPath = $dataDir . 'site_map.json';
    $siteMap = is_readable($siteMapPath) ? json_decode((string) file_get_contents($siteMapPath), true) : null;
    if (is_array($siteMap) && is_array($siteMap['pages'] ?? null)) {
        $siteMap['pages'][$key]['source_url'] = (string) ($manifestRow['final_fetch_url'] ?? $canon);
        $siteMap['pages'][$key]['coordinate'] = sprintf('internal[%d]', $index);
        $siteMap['pages'][$key]['local_path'] = 'output/' . $wsFolder . '/' . $outputRel;
        $siteMap['pages'][$key]['status'] = !empty($res['fetch_ok']) ? 'ok' : 'error';
        if (!empty($res['error'])) {
            $siteMap['pages'][$key]['error'] = [
                'phase' => 'internal_pages',
                'severity' => 'fatal',
                'message' => (string) $res['error'],
            ];
        }
        file_put_contents(
            $siteMapPath,
            json_encode($siteMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    echo json_encode([
        'ok' => !empty($res['fetch_ok']),
        'index' => $index,
        'key' => $key,
        'canonical_url' => (string) ($manifestRow['canonical_url'] ?? $canon),
        'section_count' => (int) ($manifestRow['section_count'] ?? 0),
        'asset_new_downloads' => (int) ($manifestRow['asset_new_downloads'] ?? 0),
        'error' => $manifestRow['error'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

