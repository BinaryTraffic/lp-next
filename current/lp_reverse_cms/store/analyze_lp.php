<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpAnalyzer.php';
require_once __DIR__ . '/../lib/LpInternalPagesPipeline.php';
require_once __DIR__ . '/../lib/LpFetcher.php';
require_once __DIR__ . '/../lib/LpLinkRedirectVerifier.php';
require_once __DIR__ . '/../lib/LpMapper.php';
require_once __DIR__ . '/../lib/LpSiteMapper.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/lp_analyze_log.php';
require_once __DIR__ . '/../lib/lp_image_text_memo.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$bodyRaw = file_get_contents('php://input');
$bodyIn  = ($bodyRaw !== false && trim($bodyRaw) !== '')
    ? json_decode($bodyRaw, true) : [];
$streamProgress = is_array($bodyIn) && !empty($bodyIn['stream_progress']);

if ($streamProgress) {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }
    ini_set('zlib.output_compression', '0');
    ini_set('output_buffering', '0');
    ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_implicit_flush(true);
    /* HEAD × 内部クローンで時間がかかるためプロキシ／PHP の既定制限を緩める */
    @ignore_user_abort(true);
    @set_time_limit(900);
    @ini_set('max_execution_time', '900');
    @ini_set('memory_limit', '256M');
}

$emitNd = static function (array $row) use ($streamProgress): void {
    if (!$streamProgress) {
        return;
    }
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
};

$dataDir = LpWorkspace::dataDir(dirname(__DIR__));

if ($streamProgress) {
    $GLOBALS['lp_reverse_analyze_stream_progress']      = true;
    $GLOBALS['lp_reverse_analyze_ndjson_terminal_sent'] = false;
    $shutdownLogDir                                     = $dataDir;
    register_shutdown_function(static function () use ($shutdownLogDir): void {
        if (empty($GLOBALS['lp_reverse_analyze_stream_progress'])) {
            return;
        }
        if (!empty($GLOBALS['lp_reverse_analyze_ndjson_terminal_sent'])) {
            return;
        }
        $GLOBALS['lp_reverse_analyze_ndjson_terminal_sent'] = true;

        $last = error_get_last();
        $msg  = '解析が異常終了しました（プロキシ／PHP のタイムアウト、メモリ不足、または致命的エラーの可能性があります）。';
        if (is_array($last) && isset($last['type'], $last['message']) && is_string($last['message'])) {
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (in_array((int) $last['type'], $fatalTypes, true)) {
                $msg .= ' ' . $last['message'];
            }
        }
        if (function_exists('connection_aborted') && connection_aborted() !== 0) {
            $msg .= '（クライアント切断の可能性）';
        }

        lp_reverse_analyze_append_log($shutdownLogDir, 'error', 'analyze_lp NDJSON ended without terminal row', [
            'error_get_last' => $last,
        ]);

        echo json_encode([
            'type'    => 'error',
            'success' => false,
            'error'   => $msg,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        flush();
    });
}

/**
 * @return array<string, mixed>
 *
 * @throws Throwable
 */
$runAnalyze = function () use ($dataDir, $emitNd, $streamProgress): array {
    $requestId = substr(bin2hex(random_bytes(8)), 0, 12);
    $startedAt = microtime(true);
    $mark = static function (string $phase, array $context = []) use ($dataDir, $requestId, $startedAt): void {
        $context['request_id'] = $requestId;
        $context['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        $context['memory_mb']  = round(memory_get_usage(true) / 1024 / 1024, 1);
        $context['peak_mb']    = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        lp_reverse_analyze_append_log($dataDir, 'info', $phase, $context);
    };

    $htmlFile = $dataDir . 'fetched.html';
    $urlFile  = $dataDir . 'source_url.txt';

    if (!file_exists($htmlFile)) {
        throw new RuntimeException('HTMLファイルが見つかりません。先にURLからHTMLを取得してください。');
    }

    $html = file_get_contents($htmlFile);
    if ($html === false) {
        throw new RuntimeException('HTMLファイルの読み込みに失敗しました。');
    }
    $sourceUrl = file_exists($urlFile) ? trim((string) file_get_contents($urlFile)) : '';
    $mark('analyze_lp:start', [
        'stream_progress' => $streamProgress,
        'source_url'      => $sourceUrl,
        'html_bytes'      => strlen((string) $html),
    ]);

    $emitNd([
        'type'      => 'progress',
        'phase'     => 'start',
        'pct'       => 0,
        'detail_ja' => 'DOM を読み込み、ツリー総ステップを計算します',
    ]);

    $analyzer = new LpAnalyzer();
    $walkProgressCb = function (
        int $done,
        int $total,
        string $phase,
        array $_ctx = []
    ) use ($emitNd, $streamProgress): void {
        if (!$streamProgress) {
            return;
        }
        $den = max(1, $total);
        /** 書き込み直前まで 50% までをツリー割当（残りは Mapper・画像メモ・保存で細分化） */
        $pct = min(50, round(50 * ($done / $den), 2));
        $emitNd([
            'type'           => 'progress',
            'phase'          => $phase,
            'walk_completed' => $done,
            'walk_total'     => $total,
            'pct'            => $pct,
            'detail_ja'      => sprintf('構造ツリー走査… %s / %s ステップ (%s%%)', number_format($done), number_format($total), (string) $pct),
        ]);
    };

    $mark('phase:analyzer:start');
    $structure = $analyzer->analyze($html, $sourceUrl, $walkProgressCb);
    $mark('phase:analyzer:done', [
        'sections'       => count($structure['sections'] ?? []),
        'total_elements' => (int) ($structure['total_elements'] ?? 0),
    ]);

    $secErrs = $structure['parse_diagnostics']['section_errors'] ?? [];
    if (is_array($secErrs) && $secErrs !== []) {
        lp_reverse_analyze_append_log($dataDir, 'warning', 'LpAnalyzer: セクション単位の例外（該当ブロックをスキップ）', [
            'count'  => count($secErrs),
            'errors' => $secErrs,
        ]);
    }

    $emitNd([
        'type'      => 'progress',
        'phase'     => 'mapper',
        'pct'       => 52,
        'detail_ja' => 'セクション詳細情報を整形しています',
    ]);

    $mapper    = new LpMapper();
    $mark('phase:mapper:start');
    $structure = $mapper->enrich($structure);
    $mark('phase:mapper:done', [
        'sections' => count($structure['sections'] ?? []),
    ]);

    $emitProgressOnly = $streamProgress ? static function (array $row) use ($emitNd): void {
        $emitNd($row);
    } : null;

    $fetchRedirect = new LpFetcher();
    $mark('phase:redirect_verify:start');
    LpLinkRedirectVerifier::verifyAndAnnotate($structure, $fetchRedirect, $emitProgressOnly);
    $mark('phase:redirect_verify:done');

    $cmsRootAnalyze   = dirname(__DIR__);
    $outputDirAnalyze = LpWorkspace::outputDir($cmsRootAnalyze);
    $mark('phase:internal_pages:start');
    LpInternalPagesPipeline::run($structure, $dataDir, $outputDirAnalyze, $emitProgressOnly);
    $mark('phase:internal_pages:done', [
        'internal_pages_seen' => count($structure['internal_pages'] ?? []),
    ]);

    lp_reverse_load_env();
    $assetMapPath = $dataDir . 'asset_map.json';
    $assetMap     = [];
    if (is_readable($assetMapPath)) {
        $rawMap = json_decode((string) file_get_contents($assetMapPath), true);
        if (is_array($rawMap)) {
            /** @var array<string, string> $assetMap */
            $assetMap = $rawMap;
        }
    }

    /** 画像メモは 52〜96% を対象枚数で進捗表示（固定96%に張り付かない） */
    $memoPctLow  = 52;
    $memoPctHigh = 96;
    $memoSpan    = $memoPctHigh - $memoPctLow;

    $memoProgressEmitted = false;
    $memoProgressCb = function (int $memoDone, int $memoTotalIn) use (
        $emitNd,
        $streamProgress,
        &$memoProgressEmitted,
        $memoPctLow,
        $memoSpan,
        $memoPctHigh,
    ): void {
        if (!$streamProgress) {
            return;
        }
        $memoProgressEmitted = true;
        $denIn               = max(1, $memoTotalIn);
        $pct                 = $memoPctLow + (int) round($memoSpan * ($memoDone / $denIn));
        $pct                 = min($memoPctHigh, max($memoPctLow, $pct));
        $emitNd([
            'type'        => 'progress',
            'phase'       => 'memos',
            'pct'         => $pct,
            'detail_ja'   => sprintf(
                '画像メモ付与… %s / %s 件',
                number_format($memoDone),
                number_format($memoTotalIn)
            ),
            'memo_done'   => $memoDone,
            'memo_total'  => $memoTotalIn,
        ]);
    };

    try {
        $mark('phase:image_memos:start');
        $structure = lp_reverse_enrich_structure_image_text_memos(
            $structure,
            dirname(__DIR__),
            $dataDir,
            $assetMap,
            $memoProgressCb
        );
        $mark('phase:image_memos:done');
    } catch (Throwable $e) {
        lp_reverse_analyze_append_log($dataDir, 'warning', 'lp_reverse_enrich_structure_image_text_memos failed', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'request_id' => $requestId,
        ]);
        /** @phpstan-ignore-next-line */
        if (isset($structure['parse_diagnostics']) && is_array($structure['parse_diagnostics'])) {
            $structure['parse_diagnostics']['warnings'][] = [
                'phase'   => 'image_memos',
                'message' => $e::class . ': ' . $e->getMessage(),
            ];
        }
    }

    if ($streamProgress && !$memoProgressEmitted) {
        $emitNd([
            'type'      => 'progress',
            'phase'     => 'memos',
            'pct'       => 96,
            'detail_ja' => '画像メモ（対象なしまたはスキップ）',
        ]);
    }

    $internalPagesOk = 0;
    foreach ($structure['internal_pages'] ?? [] as $_ip) {
        if (!empty($_ip['fetch_ok'])) {
            $internalPagesOk++;
        }
    }

    $diag = $structure['parse_diagnostics'] ?? null;
    unset($structure['parse_diagnostics']);

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $emitNd([
        'type'      => 'progress',
        'phase'     => 'write',
        'pct'       => 99,
        'detail_ja' => 'lp_structure.json に保存しています',
    ]);

    $writeRes = file_put_contents(
        $dataDir . 'lp_structure.json',
        json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    if ($writeRes === false) {
        throw new RuntimeException('lp_structure.json の保存に失敗しました。ディレクトリ権限を確認してください。');
    }

    $mark('phase:write_lp_structure:done', [
        'bytes' => (int) $writeRes,
    ]);

    try {
        $mark('phase:site_map:start');
        $siteMap = LpSiteMapper::build($structure, $dataDir, $outputDirAnalyze, is_array($diag) ? $diag : null);
        file_put_contents(
            $dataDir . 'site_map.json',
            json_encode($siteMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        $mark('phase:site_map:done', [
            'pages' => count($siteMap['pages'] ?? []),
        ]);
    } catch (Throwable $e) {
        lp_reverse_analyze_append_log($dataDir, 'warning', 'site_map build failed', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'request_id' => $requestId,
        ]);
    }

    if ($streamProgress) {
        $emitNd([
            'type'      => 'progress',
            'phase'     => 'industry',
            'pct'       => 99,
            'detail_ja' => 'ページのメタ情報から業種候補を推定しています…',
        ]);
    }

    try {
        $mark('phase:industry_suggest:start');
        require_once dirname(__DIR__) . '/lib/suggest_industries.php';
        $industrySuggest = lp_reverse_suggest_industries_from_structure($structure);
        file_put_contents(
            $dataDir . 'industry_suggest.json',
            json_encode($industrySuggest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
        $mark('phase:industry_suggest:done', [
            'industry_count' => count($industrySuggest['industries'] ?? []),
        ]);
    } catch (Throwable $e) {
        lp_reverse_analyze_append_log($dataDir, 'warning', 'industry_suggest failed', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
            'request_id' => $requestId,
        ]);
    }

    $emitNd([
        'type'             => 'progress',
        'phase'            => 'complete',
        'pct'              => 100,
        'walk_completed'   => is_array($diag) ? ($diag['walk_completed_steps'] ?? null) : null,
        'walk_total'       => is_array($diag) ? ($diag['walk_total_steps'] ?? null) : null,
        'detail_ja'        => '解析処理が完了しました',
    ]);

    $result = [
        'success'             => true,
        'section_count'       => count($structure['sections'] ?? []),
        'total_elements'      => $structure['total_elements'] ?? 0,
        'meta'                => $structure['meta'],
        'message'             => '解析が完了しました。',
        'parse_diagnostics'   => $diag,
        'internal_pages_ok'   => $internalPagesOk,
        'internal_pages_seen' => count($structure['internal_pages'] ?? []),
    ];
    $mark('analyze_lp:complete', [
        'success'             => true,
        'section_count'       => (int) $result['section_count'],
        'total_elements'      => (int) $result['total_elements'],
        'internal_pages_ok'   => (int) $result['internal_pages_ok'],
        'internal_pages_seen' => (int) $result['internal_pages_seen'],
    ]);

    return $result;
};

try {
    $outArr = $runAnalyze();
} catch (Throwable $e) {
    lp_reverse_analyze_append_log($dataDir, 'error', 'analyze_lp failed', [
        'exception' => $e::class,
        'message'   => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ]);
    error_log('[lp_reverse analyze_lp] ' . $e::class . ': ' . $e->getMessage());

    http_response_code(400);
    if ($streamProgress) {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        echo json_encode([
            'type'    => 'error',
            'success' => false,
            'error'   => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        flush();
        $GLOBALS['lp_reverse_analyze_ndjson_terminal_sent'] = true;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (!$streamProgress) {
    header('Content-Type: application/json; charset=utf-8');
}

if ($streamProgress) {
    $merged = array_merge(['type' => 'complete'], $outArr);
    echo json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
    $GLOBALS['lp_reverse_analyze_ndjson_terminal_sent'] = true;
} else {
    echo json_encode($outArr, JSON_UNESCAPED_UNICODE);
}
