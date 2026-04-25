<?php

declare(strict_types=1);

/**
 * $data/ や $output/ へ書き込めないと file_put_contents は false を返し例外が出ない。
 * そのまま成功 JSON を返すと、続く analyze で「HTML が見つかりません」になる。
 *
 * @throws RuntimeException
 */
function lp_storage_put(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        $dir = dirname($path);
        throw new RuntimeException(
            "ファイルに書き込めません: {$path} 。 "
            . "ディレクトリ「{$dir}」とその親の書き込み権限（Web サーバー／PHP の実行ユーザー）を付与してください。 "
            . '（リポジトリルートの `ENVIRONMENT_AND_OPERATIONS.md`「運用」節）'
        );
    }
}

require_once __DIR__ . '/../lib/LpFetcher.php';
require_once __DIR__ . '/../lib/LpAssetDownloader.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $raw   = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?? []) : [];
    $url   = trim($input['url'] ?? $_POST['url'] ?? '');

    if (!$url) {
        throw new InvalidArgumentException('URLが指定されていません。');
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('有効なURLを入力してください。');
    }

    $dataDir   = __DIR__ . '/../data/';
    $outputDir = __DIR__ . '/../output/';

    foreach ([$dataDir, $outputDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("ディレクトリを作成できません: {$dir}（書き込み権限を確認）");
        }
    }

    // ── Step 1: Fetch HTML ────────────────────────────────────────────────
    $fetcher = new LpFetcher();
    $result  = $fetcher->fetch($url);
    $html    = $result['html'];
    $finalUrl = $result['final_url'];

    // Save original fetched HTML (used by analyze_lp.php)
    lp_storage_put($dataDir . 'source.html', $html);
    lp_storage_put($dataDir . 'fetched.html', $html);
    lp_storage_put($dataDir . 'source_url.txt', $finalUrl);

    // Reset previous asset map
    lp_storage_put($dataDir . 'asset_map.json', '{}');

    // ── Step 2: Download assets (CSS / images / JS) ───────────────────────
    $downloader = new LpAssetDownloader($outputDir);
    $assetMap   = $downloader->downloadAll($html, $finalUrl);
    $failedList = $downloader->getFailedFetches();

    // Persist map for LpGenerator
    lp_storage_put(
        $dataDir . 'asset_map.json',
        json_encode($assetMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    lp_storage_put(
        $dataDir . 'fetch_failures.json',
        json_encode($failedList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    // Count downloaded assets by type (unique local paths)
    $counts = ['css' => 0, 'img' => 0, 'js' => 0, 'fonts' => 0];
    $seen   = [];
    foreach ($assetMap as $localPath) {
        $lp = (string) $localPath;
        if (isset($seen[$lp])) {
            continue;
        }
        $seen[$lp] = true;
        foreach (array_keys($counts) as $t) {
            if (str_contains($lp, '/assets/' . $t . '/')) {
                $counts[$t]++;
                break;
            }
        }
    }

    echo json_encode([
        'success'       => true,
        'http_code'     => $result['http_code'],
        'final_url'     => $finalUrl,
        'html_size'     => strlen($html),
        'asset_total'   => count($seen),
        'asset_css'     => $counts['css'],
        'asset_img'     => $counts['img'],
        'asset_js'      => $counts['js'],
        'asset_fonts'   => $counts['fonts'],
        'fetch_failed'  => count($failedList),
        'message'       => 'HTML・CSS・画像・フォントの取得が完了しました。',
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
