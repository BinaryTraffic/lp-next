<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$cmsRoot = rtrim((string) ($argv[1] ?? ''), "/\\");
$taskId = strtolower(trim((string) ($argv[2] ?? '')));
if ($cmsRoot === '' || $taskId === '') {
    fwrite(STDERR, "usage: php analyze_worker.php <cmsRoot> <taskId>\n");
    exit(1);
}

require_once $cmsRoot . '/lib/AnalyzeTask.php';

/**
 * @throws RuntimeException
 */
function ana_storage_put(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('file_put_contents failed: ' . $path);
    }
}

/**
 * @param array<string,mixed> $task
 */
function ana_task_save(string $cmsRoot, string $taskId, array &$task): void
{
    AnalyzeTask::save($cmsRoot, $taskId, $task);
}

/**
 * 進捗は常に 000–099 /100（完了時のみ 100/100）。内部ページ数 n 確定後は steps_total=n+3 で均等割当。
 *
 * @param array<string,mixed> $task
 */
function ana_apply_progress_pct(array &$task, string $cmsRoot, string $taskId, int &$cursorPct, int $pct): void
{
    $pct = max($cursorPct, min(99, $pct));
    $cursorPct = $pct;
    $task['progress_text'] = sprintf('%03d/100', $pct);
    ana_task_save($cmsRoot, $taskId, $task);
}

try {
    $task = AnalyzeTask::load($cmsRoot, $taskId);
    if (!is_array($task)) {
        exit(0);
    }
    $status = (string) ($task['status'] ?? '');
    if ($status === 'done' || $status === 'error') {
        exit(0);
    }

    $workspaceId = strtolower(trim((string) ($task['workspace_id'] ?? '')));
    if (!preg_match('/^ws_([a-f0-9]{32})$/', $workspaceId, $m)) {
        throw new RuntimeException('invalid workspace_id');
    }
    $workspaceHex = $m[1];
    putenv('LP_WORKSPACE_ID=' . $workspaceHex);

    register_shutdown_function(static function () use ($cmsRoot, $taskId): void {
        $task = AnalyzeTask::load($cmsRoot, $taskId);
        if (!is_array($task)) {
            return;
        }
        $st = (string) ($task['status'] ?? '');
        if (in_array($st, ['done', 'error', 'stale'], true)) {
            return;
        }
        $last = error_get_last();
        if ($last === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array((int) ($last['type'] ?? 0), $fatalTypes, true)) {
            return;
        }
        $task['status'] = 'error';
        $task['error'] = sprintf(
            'fatal (%s): %s',
            (string) ($last['type'] ?? ''),
            (string) ($last['message'] ?? ''),
        );
        $task['ended_at'] = time();
        AnalyzeTask::save($cmsRoot, $taskId, $task);
    });

    require_once $cmsRoot . '/lib/LpWorkspace.php';
    require_once $cmsRoot . '/lib/LpFetcher.php';
    require_once $cmsRoot . '/lib/LpAssetDownloader.php';
    require_once $cmsRoot . '/lib/LpAnalyzer.php';
    require_once $cmsRoot . '/lib/LpMapper.php';
    require_once $cmsRoot . '/lib/LpLinkRedirectVerifier.php';
    require_once $cmsRoot . '/lib/LpInternalPagesPipeline.php';
    require_once $cmsRoot . '/lib/LpSiteMapper.php';
    require_once $cmsRoot . '/lib/env_load.php';
    require_once $cmsRoot . '/lib/lp_image_text_memo.php';

    $logFile = sys_get_temp_dir() . '/analyze_worker_' . $taskId . '.log';
    $logFh = @fopen($logFile, 'w');
    $anaLog = static function (string $msg) use ($logFh, $taskId): void {
        $line = date('Y-m-d H:i:s') . ' [' . $taskId . '] ' . $msg . "\n";
        if ($logFh) {
            fwrite($logFh, $line);
            fflush($logFh);
        }
    };

    $progressCursor = 0;

    $task['status'] = 'running';
    $task['pid'] = (int) getmypid();
    $task['started_at'] = time();
    $task['phase'] = 'fetch';
    $task['progress_text'] = '000/100';
    ana_task_save($cmsRoot, $taskId, $task);
    $anaLog('worker started pid=' . getmypid());

    $sourceUrl = trim((string) ($task['source_url'] ?? ''));
    if ($sourceUrl === '') {
        throw new RuntimeException('source_url missing');
    }

    $dataDir = LpWorkspace::dataDir($cmsRoot);
    $outputDir = LpWorkspace::outputDir($cmsRoot);
    foreach ([$dataDir, $outputDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('mkdir failed: ' . $dir);
        }
    }

    foreach (['client_data.json', 'lp_structure.json', 'output_unreplaced.json', 'lp_project_profile.json', 'industry_suggest.json'] as $leaf) {
        $p = $dataDir . $leaf;
        if (is_file($p)) {
            @unlink($p);
        }
    }
    foreach (glob($dataDir . 'internal_pages/*.json') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($outputDir . 'pages/*.html') ?: [] as $f) {
        @unlink($f);
    }

    $anaLog('fetch start url=' . $sourceUrl);
    $fetcher = new LpFetcher();
    $result = $fetcher->fetch($sourceUrl);
    $html = (string) ($result['html'] ?? '');
    $finalUrl = (string) ($result['final_url'] ?? $sourceUrl);
    $anaLog('fetch done final_url=' . $finalUrl . ' html_bytes=' . strlen($html));
    ana_storage_put($dataDir . 'source.html', $html);
    ana_storage_put($dataDir . 'fetched.html', $html);
    ana_storage_put($dataDir . 'source_url.txt', $finalUrl);
    ana_storage_put($dataDir . 'clone_id.txt', bin2hex(random_bytes(16)));
    ana_storage_put($dataDir . 'asset_map.json', '{}');

    $anaLog('asset download start');
    $downloader = new LpAssetDownloader($outputDir);
    $assetMap = $downloader->downloadAll($html, $finalUrl);
    $anaLog('asset download done count=' . count($assetMap));
    ana_storage_put(
        $dataDir . 'asset_map.json',
        (string) json_encode($assetMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    ana_storage_put(
        $dataDir . 'fetch_failures.json',
        (string) json_encode($downloader->getFailedFetches(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $task['phase'] = 'analyze_entry';
    // n はまだ不明なため最小ティックのみ（stale 回避）。比率割当は内部 URL 件数確定後から。
    ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, 1);
    $anaLog('analyze_entry start');

    $analyzer = new LpAnalyzer();
    $structure = $analyzer->analyze($html, $finalUrl);
    $diag = $structure['parse_diagnostics'] ?? null;
    $mapper = new LpMapper();
    $structure = $mapper->enrich($structure);
    $fetchRedirect = new LpFetcher();
    LpLinkRedirectVerifier::verifyAndAnnotate($structure, $fetchRedirect, null);
    $candidateUrls = LpInternalPagesPipeline::extractCandidateUrls($structure, $finalUrl);

    $candidates = [];
    foreach ($candidateUrls as $idx => $canon) {
        $candidates[] = ['index' => $idx, 'canonical_url' => $canon, 'status' => 'pending'];
    }
    ana_storage_put(
        $dataDir . 'internal_candidate_urls.json',
        (string) json_encode([
            'entry_url' => $finalUrl,
            'urls' => $candidates,
            'total' => count($candidates),
            'processed' => 0,
            'pending' => count($candidates),
            'error' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    $structure['internal_pages'] = [];
    unset($structure['parse_diagnostics']);
    ana_storage_put(
        $dataDir . 'lp_structure.json',
        (string) json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $siteMap = LpSiteMapper::build($structure, $dataDir, $outputDir, is_array($diag) ? $diag : null);
    $wsFolder = basename(rtrim(str_replace('\\', '/', $outputDir), '/'));
    foreach ($candidates as $item) {
        $i = (int) $item['index'];
        $key = 'internal_' . (string) $i;
        $siteMap['pages'][$key] = [
            'source_url' => (string) $item['canonical_url'],
            'coordinate' => sprintf('internal[%d]', $i),
            'local_path' => 'output/' . $wsFolder . '/' . $key . '/index.html',
            'status' => 'pending',
            'sections' => [],
            'data_io_regions' => [],
            'dynamic_regions' => [],
        ];
    }
    ana_storage_put(
        $dataDir . 'site_map.json',
        (string) json_encode($siteMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $n = count($candidates);
    $stepsTotal = $n + 3;
    $task['analyze_steps_total'] = $stepsTotal;
    $task['internal_page_count'] = $n;
    $total = max(1, $n);
    $task['phase'] = 'analyze_internal';
    // steps: 1=fetch 済, 2=エントリ+候補確定 済, 3..n+2=各内部ページ, n+3=finalize 完了
    ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, (int) floor(100 * 2 / $stepsTotal));
    $anaLog('analyze_internal start total=' . $total . ' steps_total=' . $stepsTotal);
    foreach ($candidates as $i => $row) {
        $canon = (string) ($row['canonical_url'] ?? '');
        $anaLog(sprintf('processing %03d/%03d url=%s', $i + 1, $total, $canon));
        $t0 = microtime(true);
        $res = LpInternalPagesPipeline::processSingleUrl($canon, $dataDir, $outputDir, $anaLog);
        $elapsed = round(microtime(true) - $t0, 1);
        $anaLog(sprintf('done     %03d/%03d elapsed=%.1fs fetch_ok=%s', $i + 1, $total, $elapsed, ($res['fetch_ok'] ?? false) ? 'true' : 'false'));
        $index = (int) ($row['index'] ?? $i);
        $key = 'internal_' . (string) $index;
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
        $structureNow = json_decode((string) file_get_contents($structurePath), true);
        if (!is_array($structureNow)) {
            throw new RuntimeException('lp_structure.json unreadable');
        }
        $internalPages = is_array($structureNow['internal_pages'] ?? null) ? $structureNow['internal_pages'] : [];
        $internalPages[$index] = $manifestRow;
        ksort($internalPages);
        $structureNow['internal_pages'] = $internalPages;
        ana_storage_put(
            $structurePath,
            (string) json_encode($structureNow, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, (int) floor(100 * (3 + $i) / $stepsTotal));
    }

    $baseFinalize = (int) floor(100 * ($n + 2) / $stepsTotal);
    $task['phase'] = 'finalize';
    ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, $baseFinalize);
    $anaLog('finalize start');

    $structurePath = $dataDir . 'lp_structure.json';
    $structure = json_decode((string) file_get_contents($structurePath), true);
    if (!is_array($structure)) {
        throw new RuntimeException('lp_structure.json invalid');
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
        ana_storage_put($subPath, (string) json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    lp_reverse_load_env();
    $assetMapPath = $dataDir . 'asset_map.json';
    $assetMapRaw = is_readable($assetMapPath) ? json_decode((string) file_get_contents($assetMapPath), true) : [];
    $assetMap = is_array($assetMapRaw) ? $assetMapRaw : [];
    $structure = lp_reverse_enrich_structure_image_text_memos(
        $structure,
        $cmsRoot,
        $dataDir,
        $assetMap,
        static function (int $done, int $total) use ($cmsRoot, $taskId, &$task, &$progressCursor, $baseFinalize): void {
            $safeTotal = max(1, $total);
            $ratio = min(1.0, max(0.0, $done / $safeTotal));
            $span = max(0, 99 - $baseFinalize);
            $pct = $baseFinalize + (int) floor($span * $ratio);
            ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, min(99, $pct));
        }
    );
    ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, 99);
    require_once $cmsRoot . '/lib/suggest_industries.php';
    $industrySuggest = lp_reverse_suggest_industries_from_structure($structure);
    ana_storage_put(
        $dataDir . 'industry_suggest.json',
        (string) json_encode($industrySuggest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    ana_storage_put(
        $structurePath,
        (string) json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    $siteMap = LpSiteMapper::build($structure, $dataDir, $outputDir, null);
    ana_storage_put(
        $dataDir . 'site_map.json',
        (string) json_encode($siteMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $task['status'] = 'done';
    $task['phase'] = 'finalize';
    $task['ended_at'] = time();
    $progressCursor = 100;
    $task['progress_text'] = '100/100';
    ana_task_save($cmsRoot, $taskId, $task);
    $anaLog('worker done');
} catch (Throwable $e) {
    if (isset($anaLog)) {
        $anaLog('ERROR ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    $task = AnalyzeTask::load($cmsRoot, $taskId);
    if (is_array($task)) {
        $task['status'] = 'error';
        $task['error'] = $e->getMessage();
        $task['ended_at'] = time();
        AnalyzeTask::save($cmsRoot, $taskId, $task);
    }
}

