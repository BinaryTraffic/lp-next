<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/app_release.php';
require_once __DIR__ . '/../lib/LpAssetAudit.php';
require_once __DIR__ . '/../lib/LpOutputAudit.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/LpUrlContext.php';

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

/**
 * @return list<string>
 */
function extractHeroBackgroundUrlsFromText(string $text): array {
    if ($text === '') {
        return [];
    }
    preg_match_all(
        '#https?://[^\s"\'()<>]+(?:FV_250724_pc\.webp)(?:\?[^\s"\'()<>]*)?#u',
        $text,
        $m
    );

    return array_values(array_unique(array_map('trim', $m[0] ?? [])));
}

/**
 * @param array<string,string> $assetMap
 * @param list<string> $fetchFailures
 * @return array{
 *   probe_urls:list<string>,
 *   probes:list<array{
 *     input:string,
 *     variants:list<string>,
 *     map_hits:list<array{key:string,local:string,exists:bool}>,
 *     in_fetch_failures:bool
 *   }>,
 *   output_background_urls:list<string>
 * }
 */
function buildHeroBackgroundProbe(
    array $assetMap,
    array $fetchFailures,
    string $sourceHtmlText,
    string $outputHtmlText,
    string $outputDir
): array {
    $seed = [
        'https://www.otakaraya.jp/app/wp-content/uploads/2025/07/%E6%96%B0FV_250724_pc.webp',
        'https://www.otakaraya.jp/app/wp-content/uploads/2025/07/新FV_250724_pc.webp',
    ];
    $sourceFound = extractHeroBackgroundUrlsFromText($sourceHtmlText);
    $outputFound = extractHeroBackgroundUrlsFromText($outputHtmlText);
    $probeUrls = array_values(array_unique(array_merge($seed, $sourceFound, $outputFound)));

    $failSet = [];
    foreach ($fetchFailures as $f) {
        if (is_string($f) && $f !== '') {
            $failSet[$f] = true;
        }
    }

    $probes = [];
    foreach ($probeUrls as $u) {
        $variants = array_values(array_unique(array_merge(
            [$u],
            LpUrlContext::httpHttpsAssetUrlVariants($u),
            [LpUrlContext::canonicalHttpUrlForFetch($u)]
        )));
        $variantSet = [];
        foreach ($variants as $v) {
            $variantSet[$v] = true;
            if (str_starts_with($v, 'https://')) {
                $variantSet['//' . substr($v, 8)] = true;
            } elseif (str_starts_with($v, 'http://')) {
                $variantSet['//' . substr($v, 7)] = true;
            }
        }

        $hits = [];
        foreach (array_keys($variantSet) as $k) {
            if (!isset($assetMap[$k])) {
                continue;
            }
            $local = (string) $assetMap[$k];
            $full  = rtrim($outputDir, '/\\') . '/' . ltrim($local, '/\\');
            $hits[] = [
                'key'    => $k,
                'local'  => $local,
                'exists' => is_file($full),
            ];
        }

        $inFailures = false;
        foreach (array_keys($variantSet) as $k) {
            if (isset($failSet[$k])) {
                $inFailures = true;
                break;
            }
        }

        $probes[] = [
            'input'            => $u,
            'variants'         => array_keys($variantSet),
            'map_hits'         => $hits,
            'in_fetch_failures'=> $inFailures,
        ];
    }

    preg_match_all(
        '/background-image\s*:\s*url\(\s*["\']?([^)"\']+)["\']?\s*\)/iu',
        $outputHtmlText,
        $bm
    );
    $bgUrls = [];
    foreach (($bm[1] ?? []) as $raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || !str_contains($raw, 'FV_250724_pc.webp')) {
            continue;
        }
        $bgUrls[] = $raw;
    }

    return [
        'probe_urls' => $probeUrls,
        'probes' => $probes,
        'output_background_urls' => array_values(array_unique($bgUrls)),
    ];
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

$sourceHtmlText = file_exists($htmlPath)
    ? (string) file_get_contents($htmlPath)
    : '';
$htmlOut = $outputDir . 'index.html';
$outputHtmlText = file_exists($htmlOut)
    ? (string) file_get_contents($htmlOut)
    : '';

$outputUnreplaced = readJsonFile($dataDir . 'output_unreplaced.json');
if ($outputUnreplaced === null) {
    $outputUnreplaced = ['total' => 0, 'items' => [], 'note' => 'まだサイト生成後のスキャンがありません'];
}
if (file_exists($htmlOut)) {
    $jsonPath = $dataDir . 'output_unreplaced.json';
    $mtHtml   = (int) (@filemtime($htmlOut) ?: 0);
    $mtJson   = is_file($jsonPath) ? (int) (@filemtime($jsonPath) ?: 0) : 0;
    // generate_lp が直前に persist 済みなら JSON は HTML 以上に新しい → 再スキャン省略（Step3 診断連打と二重化しない）
    $useCache = $mtJson >= $mtHtml && $mtJson > 0 && is_readable($jsonPath);
    if ($useCache) {
        $cached = readJsonFile($jsonPath);
        if (is_array($cached) && array_key_exists('total', $cached)) {
            $outputUnreplaced = $cached;
        } else {
            $outputUnreplaced = LpOutputAudit::persist($htmlOut, $dataDir);
        }
    } else {
        $outputUnreplaced = LpOutputAudit::persist($htmlOut, $dataDir);
    }
    $outputUnreplaced['live_total'] = $outputUnreplaced['total'];
    $outputUnreplaced['live_items'] = $outputUnreplaced['items'];
}

$appVersion = '1.4.0';
$idx = @file_get_contents(__DIR__ . '/../index.php');
if ($idx !== false && preg_match("/define\\s*\\(\\s*'APP_VERSION'\\s*,\\s*'([^']+)'/", $idx, $vm)) {
    $appVersion = $vm[1];
}
$appBuild = lp_reverse_app_build_label($cmsRoot);

$cssDiagnostics = LpOutputAudit::scanOutputCssForDiagnostics($outputDir);
$heroBgProbe = buildHeroBackgroundProbe(
    $assetMap,
    is_array($fetchFailures) ? $fetchFailures : [],
    $sourceHtmlText,
    $outputHtmlText,
    $outputDir
);

echo json_encode([
    'version' => $appVersion,
    'build'   => $appBuild,
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

    /** Otakaraya FV 背景画像の source→asset_map→output 到達確認 */
    'hero_bg_probe' => $heroBgProbe,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
