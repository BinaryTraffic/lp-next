<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/LpAssetAudit.php';
require_once __DIR__ . '/../lib/LpOutputAudit.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

$cmsRoot   = dirname(__DIR__);
$dataDir   = LpWorkspace::dataDir($cmsRoot);
$outputDir = LpWorkspace::outputDir($cmsRoot);

function countFiles(string $dir): array {
    if (!is_dir($dir)) {
        return ['count' => 0, 'files' => []];
    }
    $files = array_values(array_filter(scandir($dir), fn($f) => !in_array($f, ['.', '..'], true)));
    return ['count' => count($files), 'files' => $files];
}

function readJsonFile(string $path): ?array {
    if (!file_exists($path)) {
        return null;
    }
    $d = json_decode((string) file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

$assetMap = readJsonFile($dataDir . 'asset_map.json') ?? [];

$mapKeyCount = count($assetMap);

$countByLocalType = static function (array $locals, string $type): int {
    $n = 0;
    foreach ($locals as $lp) {
        $s = (string) $lp;
        // ローカルパスは "assets/css/..." 形式（先頭スラッシュ無し）
        if (str_contains($s, 'assets/' . $type . '/')) {
            $n++;
        }
    }
    return $n;
};

$uniqueLocals = array_values(array_unique(array_values($assetMap)));
$summaryMap   = [
    'map_key_count'   => $mapKeyCount,
    'map_unique_local'=> count($uniqueLocals),
    'map_css'         => $countByLocalType($uniqueLocals, 'css'),
    'map_img'         => $countByLocalType($uniqueLocals, 'img'),
    'map_js'          => $countByLocalType($uniqueLocals, 'js'),
    'map_fonts'       => $countByLocalType($uniqueLocals, 'fonts'),
];

$diskCss   = countFiles($outputDir . 'assets/css');
$diskImg   = countFiles($outputDir . 'assets/img');
$diskJs    = countFiles($outputDir . 'assets/js');
$diskFonts = countFiles($outputDir . 'assets/fonts');

$sourceUrl = file_exists($dataDir . 'source_url.txt')
    ? trim((string) file_get_contents($dataDir . 'source_url.txt'))
    : '';

$fetchFailures = readJsonFile($dataDir . 'fetch_failures.json') ?? [];
if ($fetchFailures !== [] && array_is_list($fetchFailures)) {
    // ok — list of strings
} elseif (is_array($fetchFailures)) {
    $fetchFailures = array_values($fetchFailures);
}

$htmlPath = file_exists($dataDir . 'fetched.html')
    ? $dataDir . 'fetched.html'
    : $dataDir . 'source.html';

$audit = ['referenced' => [], 'unfetched' => []];
if ($sourceUrl && file_exists($htmlPath)) {
    $audit = LpAssetAudit::auditUnfetched(
        $htmlPath,
        $sourceUrl,
        $assetMap,
        $outputDir,
        $fetchFailures
    );
}

$outputUnreplaced = readJsonFile($dataDir . 'output_unreplaced.json');
if ($outputUnreplaced === null) {
    $outputUnreplaced = ['total' => 0, 'items' => [], 'note' => 'まだLP生成後のスキャンがありません'];
}
if (file_exists($outputDir . 'index.html')) {
    // 常に実ファイルをスキャンして JSON を更新（古い generated_at / items のまま残る混乱を防ぐ）
    $outputUnreplaced = LpOutputAudit::persist($outputDir . 'index.html', $dataDir);
    $outputUnreplaced['live_total'] = $outputUnreplaced['total'];
    $outputUnreplaced['live_items'] = $outputUnreplaced['items'];
}

$appVersion = '1.3.0';
$idx = @file_get_contents(__DIR__ . '/../index.php');
if ($idx !== false && preg_match("/define\\s*\\(\\s*'APP_VERSION'\\s*,\\s*'([^']+)'/", $idx, $vm)) {
    $appVersion = $vm[1];
}

$cssDiagnostics = LpOutputAudit::scanOutputCssForDiagnostics($outputDir);

echo json_encode([
    'version' => $appVersion,
    'workspace_id' => LpWorkspace::id(),
    'files'   => [
        'source_html'       => file_exists($dataDir . 'source.html'),
        'fetched_html'      => file_exists($dataDir . 'fetched.html'),
        'lp_structure'      => file_exists($dataDir . 'lp_structure.json'),
        'client_data'       => file_exists($dataDir . 'client_data.json'),
        'asset_map'         => file_exists($dataDir . 'asset_map.json'),
        'fetch_failures'    => file_exists($dataDir . 'fetch_failures.json'),
        'output_unreplaced' => file_exists($dataDir . 'output_unreplaced.json'),
        'output_index'      => file_exists($outputDir . 'index.html'),
    ],
    'source_url' => $sourceUrl,

    'summary' => $summaryMap + [
        'disk_css'    => $diskCss['count'],
        'disk_img'    => $diskImg['count'],
        'disk_js'     => $diskJs['count'],
        'disk_fonts'  => $diskFonts['count'],
        'referenced_total' => count($audit['referenced']),
        'unfetched_total'  => count($audit['unfetched']),
        'fetch_failure_count' => is_array($fetchFailures) ? count($fetchFailures) : 0,
    ],

    /** 参照元HTML＋ローカルCSS内 url() から収集した絶対URLのうち、asset_map に無いもの */
    'unfetched' => $audit['unfetched'],

    /** HTTP 取得に失敗したURL（fetch 時） */
    'fetch_failures' => $fetchFailures,

    /** 生成済みワークスペース内 index.html に残る外部URL・不正スラッシュ等 */
    'output_unreplaced' => $outputUnreplaced,

    /** v1.2+ output/assets/css 内の url() ・ @import 外部残存 */
    'output_css_diagnostics' => $cssDiagnostics,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
