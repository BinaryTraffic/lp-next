<?php

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../lib/LpInternalPagesPipeline.php';
require_once __DIR__ . '/../lib/LpSiteMapper.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/lp_image_text_memo.php';
require_once __DIR__ . '/../lib/JobRegistry.php';
require_once __DIR__ . '/../lib/lp_job_runtime.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    [$body, $jobId] = lp_job_parse_body_and_id();
    $cmsRoot = dirname(__DIR__);
    $jobRegistry = new JobRegistry($cmsRoot);
    if ($jobId !== '') {
        $jobRegistry->heartbeat($jobId, 'finalize_analyze start');
        lp_job_check_abort($jobRegistry, $jobId, '解析ジョブが停止されました。');
    }
    $dataDir = LpWorkspace::dataDir($cmsRoot);
    $outputDir = LpWorkspace::outputDir($cmsRoot);
    $structurePath = $dataDir . 'lp_structure.json';
    if (!is_readable($structurePath)) {
        throw new RuntimeException('lp_structure.json が見つかりません。');
    }

    $structure = json_decode((string) file_get_contents($structurePath), true);
    if (!is_array($structure)) {
        throw new RuntimeException('lp_structure.json が不正です。');
    }

    $urlToOutput = [];
    foreach ($structure['internal_pages'] ?? [] as $row) {
        if (!is_array($row) || empty($row['fetch_ok'])) {
            continue;
        }
        $canon = (string) ($row['canonical_url'] ?? '');
        $out = (string) ($row['output_file'] ?? '');
        if ($canon !== '' && $out !== '') {
            $urlToOutput[$canon] = $out;
        }
    }
    LpInternalPagesPipeline::patchInternalRelativeHrefs($structure, $urlToOutput);
    if ($jobId !== '') {
        $jobRegistry->heartbeat($jobId, 'finalize_analyze patched');
        lp_job_check_abort($jobRegistry, $jobId, '解析ジョブが停止されました。');
    }

    // 内部ページ構造 JSON にも同じパッチを適用（内部ページ同士のリンクを pages/slug.html に書き換える）
    foreach ($structure['internal_pages'] ?? [] as $row) {
        if (!is_array($row) || empty($row['fetch_ok']) || empty($row['structure_file'])) {
            continue;
        }
        $subPath = $dataDir . (string) $row['structure_file'];
        if (!is_readable($subPath)) {
            continue;
        }
        $sub = json_decode((string) file_get_contents($subPath), true);
        if (!is_array($sub)) {
            continue;
        }
        LpInternalPagesPipeline::patchInternalRelativeHrefs($sub, $urlToOutput);
        file_put_contents(
            $subPath,
            json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        if ($jobId !== '') {
            $jobRegistry->heartbeat($jobId, 'finalize_analyze internal patch loop');
            lp_job_check_abort($jobRegistry, $jobId, '解析ジョブが停止されました。');
        }
    }

    lp_reverse_load_env();
    $assetMapPath = $dataDir . 'asset_map.json';
    $assetMap = [];
    if (is_readable($assetMapPath)) {
        $rawMap = json_decode((string) file_get_contents($assetMapPath), true);
        if (is_array($rawMap)) {
            /** @var array<string, string> $assetMap */
            $assetMap = $rawMap;
        }
    }
    $memoProgressCb = null;
    if ($jobId !== '') {
        $memoProgressCb = static function (int $done, int $total) use ($jobRegistry, $jobId): void {
            // 進行中に止められるように、画像メモループの中でも heartbeat + stop check を行う。
            $jobRegistry->heartbeat($jobId, sprintf('finalize_analyze memos %d/%d', $done, $total));
            lp_job_check_abort($jobRegistry, $jobId, '解析ジョブが停止されました。');
        };
    }

    $structure = lp_reverse_enrich_structure_image_text_memos(
        $structure,
        $cmsRoot,
        $dataDir,
        $assetMap,
        $memoProgressCb
    );
    if ($jobId !== '') {
        $jobRegistry->heartbeat($jobId, 'finalize_analyze memos done');
        lp_job_check_abort($jobRegistry, $jobId, '解析ジョブが停止されました。');
    }

    require_once dirname(__DIR__) . '/lib/suggest_industries.php';
    $industrySuggest = lp_reverse_suggest_industries_from_structure($structure);
    file_put_contents(
        $dataDir . 'industry_suggest.json',
        json_encode($industrySuggest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    file_put_contents(
        $structurePath,
        json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $siteMap = LpSiteMapper::build($structure, $dataDir, $outputDir, null);
    file_put_contents(
        $dataDir . 'site_map.json',
        json_encode($siteMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $okCount = 0;
    $errCount = 0;
    foreach ($structure['internal_pages'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!empty($row['fetch_ok'])) {
            $okCount++;
        } else {
            $errCount++;
        }
    }

    echo json_encode([
        'ok' => true,
        'internal_pages_ok' => $okCount,
        'internal_pages_error' => $errCount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

