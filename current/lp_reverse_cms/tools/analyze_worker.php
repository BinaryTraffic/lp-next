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
 * asset_map (URL→ローカルパス) を使い、全 elements / css_background_hints に
 * rollback_src (assets/rollback/filename) を付与する。
 * 既に rollback_src が設定済みの要素はスキップ（再解析でも安全）。
 *
 * @param array<string,mixed> $structure
 * @param array<string,string> $assetMap
 * @return array<string,mixed>
 */
function lp_reverse_resolve_rollback_src(array $structure, array $assetMap): array
{
    /**
     * URL から rollback_src を求める（見つからなければ null）。
     *
     * @param string $src
     * @return string|null
     */
    $resolve = static function (string $src) use ($assetMap): ?string {
        if ($src === '') {
            return null;
        }
        // 1. 完全一致
        $local = $assetMap[$src] ?? null;
        // 2. http/https の相互変換
        if ($local === null) {
            $alt = str_starts_with($src, 'https://') ? 'http://' . substr($src, 8) : (str_starts_with($src, 'http://') ? 'https://' . substr($src, 7) : '');
            if ($alt !== '') {
                $local = $assetMap[$alt] ?? null;
            }
        }
        // 3. ファイル名部分一致（basename）
        if ($local === null) {
            $bn = basename((string) parse_url($src, PHP_URL_PATH));
            if ($bn !== '') {
                foreach ($assetMap as $url => $lp) {
                    if (basename((string) parse_url($url, PHP_URL_PATH)) === $bn) {
                        $local = $lp;
                        break;
                    }
                }
            }
        }
        if ($local === null) {
            return null;
        }
        // assets/img/filename.jpg → assets/rollback/filename.jpg
        $filename = basename($local);
        return 'assets/rollback/' . $filename;
    };

    $processElements = static function (array &$elements) use ($resolve): void {
        foreach ($elements as &$el) {
            if (isset($el['rollback_src'])) {
                continue; // 既存ワークスペース対応：上書きしない
            }
            $src = (string) ($el['original_src'] ?? '');
            if ($src === '') {
                continue;
            }
            $rb = $resolve($src);
            if ($rb !== null) {
                $el['rollback_src'] = $rb;
            }
        }
        unset($el);
    };

    $processHints = static function (array &$hints) use ($resolve): void {
        foreach ($hints as &$hint) {
            if (isset($hint['rollback_src'])) {
                continue;
            }
            $url = (string) ($hint['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $rb = $resolve($url);
            if ($rb !== null) {
                $hint['rollback_src'] = $rb;
            }
        }
        unset($hint);
    };

    // エントリページのセクション
    if (isset($structure['sections']) && is_array($structure['sections'])) {
        foreach ($structure['sections'] as &$sec) {
            if (isset($sec['elements']) && is_array($sec['elements'])) {
                $processElements($sec['elements']);
            }
            if (isset($sec['css_background_hints']) && is_array($sec['css_background_hints'])) {
                $processHints($sec['css_background_hints']);
            }
        }
        unset($sec);
    }

    // 内部ページ
    if (isset($structure['internal_pages']) && is_array($structure['internal_pages'])) {
        foreach ($structure['internal_pages'] as &$page) {
            if (!is_array($page)) {
                continue;
            }
            if (isset($page['sections']) && is_array($page['sections'])) {
                foreach ($page['sections'] as &$sec) {
                    if (isset($sec['elements']) && is_array($sec['elements'])) {
                        $processElements($sec['elements']);
                    }
                    if (isset($sec['css_background_hints']) && is_array($sec['css_background_hints'])) {
                        $processHints($sec['css_background_hints']);
                    }
                }
                unset($sec);
            }
        }
        unset($page);
    }

    return $structure;
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
    $task['detail_ja'] = 'HTMLを取得中…';
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

    $task['detail_ja'] = 'CSSおよび画像アセットを取得中…';
    ana_task_save($cmsRoot, $taskId, $task);
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
    $task['detail_ja'] = 'サイト構造を解析中…';
    // n はまだ不明なため最小ティックのみ（stale 回避）。比率割当は内部 URL 件数確定後から。
    ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, 1);
    $anaLog('analyze_entry start');

    // ダウンロード済み CSS ファイルを結合して渡す（外部CSS内の background-image を検出するため）
    $extraCss = '';
    $cssDir = $outputDir . '/assets/css';
    foreach (glob($cssDir . '/*.css') ?: [] as $cssFile) {
        $fsize = @filesize($cssFile);
        if ($fsize > 0 && $fsize < 600_000) { // 600KB 超は巨大フレームワーク CSS のため除外
            $content = @file_get_contents($cssFile);
            if ($content !== false) {
                $extraCss .= $content . "\n";
            }
        }
    }
    $anaLog('extra_css bytes=' . strlen($extraCss));

    $analyzer = new LpAnalyzer();
    $structure = $analyzer->analyze($html, $finalUrl, null, 0.0, $extraCss);
    $diag = $structure['parse_diagnostics'] ?? null;
    $mapper = new LpMapper();
    $structure = $mapper->enrich($structure);
    $fetchRedirect = new LpFetcher();
    LpLinkRedirectVerifier::verifyAndAnnotate($structure, $fetchRedirect, null);
    $crawlDepth    = max(1, (int) ($task['crawl_depth'] ?? 1));
    $candidateUrls = LpInternalPagesPipeline::extractCandidateUrls($structure, $finalUrl);

    $candidates = [];
    foreach ($candidateUrls as $idx => $canon) {
        $candidates[] = ['index' => $idx, 'canonical_url' => $canon, 'depth' => 1, 'status' => 'pending'];
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
    $task['crawl_depth']         = $crawlDepth;
    $task['phase'] = 'analyze_internal';
    $task['detail_ja'] = '内部ページの解析を準備中…';
    // steps: 1=fetch 済, 2=エントリ+候補確定 済, 3..n+2=各内部ページ, n+3=finalize 完了
    ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, (int) floor(100 * 2 / $stepsTotal));
    $anaLog('analyze_internal start depth=' . $crawlDepth . ' depth1_total=' . $n);

    // BFSキュー: depth-1 を初期投入、処理後に depth<crawlDepth なら次の深さを追加
    $bfsQueue     = $candidates;        // ['index', 'canonical_url', 'depth']
    $visited      = [$finalUrl => true];
    foreach ($candidates as $c) {
        $visited[(string) $c['canonical_url']] = true;
    }
    $processedIdx = $n;                 // depth>1 の新規ページに振る連番
    $processedSeq = 0;

    while (!empty($bfsQueue)) {
        $row       = array_shift($bfsQueue);
        $canon     = (string) ($row['canonical_url'] ?? '');
        $itemDepth = (int) ($row['depth'] ?? 1);
        $index     = (int) ($row['index'] ?? $processedSeq);
        $processedSeq++;

        $task['detail_ja'] = '内部ページ処理中… ' . $canon;
        ana_task_save($cmsRoot, $taskId, $task);
        $anaLog(sprintf('processing #%03d depth=%d url=%s', $processedSeq, $itemDepth, $canon));
        $t0  = microtime(true);
        $res = LpInternalPagesPipeline::processSingleUrl($canon, $dataDir, $outputDir, $anaLog);
        $elapsed = round(microtime(true) - $t0, 1);
        $anaLog(sprintf('done #%03d elapsed=%.1fs fetch_ok=%s', $processedSeq, $elapsed, ($res['fetch_ok'] ?? false) ? 'true' : 'false'));

        $key       = 'internal_' . (string) $index;
        $outputRel = $key . '/index.html';
        $manifestRow = [
            'fetch_ok'            => (bool) ($res['fetch_ok'] ?? false),
            'canonical_url'       => (string) ($res['canonical_url'] ?? $canon),
            'source_canonical'    => (string) ($res['source_canonical'] ?? $canon),
            'structure_file'      => $res['structure_file'] ?? null,
            'output_file'         => $outputRel,
            'final_fetch_url'     => (string) ($res['final_fetch_url'] ?? $canon),
            'section_count'       => (int) ($res['section_count'] ?? 0),
            'asset_new_downloads' => (int) ($res['asset_new_downloads'] ?? 0),
            'asset_sync_limited'  => (bool) ($res['asset_sync_limited'] ?? false),
            'depth'               => $itemDepth,
        ];
        if (isset($res['error'])) {
            $manifestRow['error'] = (string) $res['error'];
        }

        $structurePath = $dataDir . 'lp_structure.json';
        $structureNow  = json_decode((string) file_get_contents($structurePath), true);
        if (!is_array($structureNow)) {
            throw new RuntimeException('lp_structure.json unreadable');
        }
        $internalPages          = is_array($structureNow['internal_pages'] ?? null) ? $structureNow['internal_pages'] : [];
        $internalPages[$index]  = $manifestRow;
        ksort($internalPages);
        $structureNow['internal_pages'] = $internalPages;
        ana_storage_put(
            $structurePath,
            (string) json_encode($structureNow, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // 指定深さ未満 かつ 処理成功 → 次の深さのリンクをキューに追加
        if ($itemDepth < $crawlDepth && !empty($res['fetch_ok']) && !empty($res['structure_file'])) {
            $subPath = $dataDir . (string) $res['structure_file'];
            $sub     = is_readable($subPath) ? json_decode((string) file_get_contents($subPath), true) : null;
            if (is_array($sub)) {
                $nextUrls = LpInternalPagesPipeline::collectInternalDocumentUrlsFromEntryStructure($sub);
                foreach ($nextUrls as $nextUrl) {
                    if (!isset($visited[$nextUrl]) && ($processedIdx < LpInternalPagesPipeline::MAX_PAGES)) {
                        $visited[$nextUrl] = true;
                        $bfsQueue[]        = ['index' => $processedIdx, 'canonical_url' => $nextUrl, 'depth' => $itemDepth + 1];
                        $processedIdx++;
                    }
                }
            }
        }

        $knownTotal  = max($stepsTotal, $processedSeq + count($bfsQueue) + 3);
        ana_apply_progress_pct($task, $cmsRoot, $taskId, $progressCursor, (int) floor(100 * (2 + $processedSeq) / $knownTotal));
    }

    $baseFinalize = (int) floor(100 * ($n + 2) / $stepsTotal);
    $task['phase'] = 'finalize';
    $task['detail_ja'] = '内部リンクを最終調整中…';
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
            $task['detail_ja'] = sprintf('画像テキストを解析中… (%d/%d)', $done, $total);
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
    // rollback_src を解決: asset_map の URL→ローカルパス を使い、assets/rollback/xxx に変換
    $structure = lp_reverse_resolve_rollback_src($structure, $assetMap);

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
    $task['detail_ja'] = '解析完了';
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

