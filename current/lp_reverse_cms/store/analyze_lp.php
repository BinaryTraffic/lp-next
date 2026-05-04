<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpAnalyzer.php';
require_once __DIR__ . '/../lib/LpMapper.php';
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
}

$emitNd = static function (array $row) use ($streamProgress): void {
    if (!$streamProgress) {
        return;
    }
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
};

$dataDir = LpWorkspace::dataDir(dirname(__DIR__));

/**
 * @return array<string, mixed>
 *
 * @throws Throwable
 */
$runAnalyze = function () use ($dataDir, $emitNd, $streamProgress): array {
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
        /** 書き込み直前まで 92% までをツリー割当 */
        $pct = min(92, round(92 * ($done / $den), 2));
        $emitNd([
            'type'           => 'progress',
            'phase'          => $phase,
            'walk_completed' => $done,
            'walk_total'     => $total,
            'pct'            => $pct,
            'detail_ja'      => sprintf('構造ツリー走査… %s / %s ステップ (%s%%)', number_format($done), number_format($total), (string) $pct),
        ]);
    };

    $structure = $analyzer->analyze($html, $sourceUrl, $walkProgressCb);

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
        'pct'       => 93,
        'detail_ja' => 'セクション詳細情報を整形しています',
    ]);

    $mapper    = new LpMapper();
    $structure = $mapper->enrich($structure);

    $emitNd([
        'type'      => 'progress',
        'phase'     => 'memos',
        'pct'       => 96,
        'detail_ja' => '画像メモ付与を処理しています',
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

    try {
        $structure = lp_reverse_enrich_structure_image_text_memos($structure, dirname(__DIR__), $dataDir, $assetMap);
    } catch (Throwable $e) {
        lp_reverse_analyze_append_log($dataDir, 'warning', 'lp_reverse_enrich_structure_image_text_memos failed', [
            'exception' => $e::class,
            'message'   => $e->getMessage(),
        ]);
        /** @phpstan-ignore-next-line */
        if (isset($structure['parse_diagnostics']) && is_array($structure['parse_diagnostics'])) {
            $structure['parse_diagnostics']['warnings'][] = [
                'phase'   => 'image_memos',
                'message' => $e::class . ': ' . $e->getMessage(),
            ];
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

    $emitNd([
        'type'             => 'progress',
        'phase'            => 'complete',
        'pct'              => 100,
        'walk_completed'   => is_array($diag) ? ($diag['walk_completed_steps'] ?? null) : null,
        'walk_total'       => is_array($diag) ? ($diag['walk_total_steps'] ?? null) : null,
        'detail_ja'        => '解析処理が完了しました',
    ]);

    return [
        'success'             => true,
        'section_count'       => count($structure['sections'] ?? []),
        'total_elements'      => $structure['total_elements'] ?? 0,
        'meta'                => $structure['meta'],
        'message'             => '解析が完了しました。',
        'parse_diagnostics'   => $diag,
    ];
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
} else {
    echo json_encode($outArr, JSON_UNESCAPED_UNICODE);
}
