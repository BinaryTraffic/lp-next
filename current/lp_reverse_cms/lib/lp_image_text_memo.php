<?php

declare(strict_types=1);

/**
 * 解析済み LP 構造の画像要素に、Vision で抽出した「画像内テキスト」を image_embedded_text_memo に書き込む。
 */
require_once __DIR__ . '/LpWorkspace.php';
require_once __DIR__ . '/api_usage_log.php';
require_once __DIR__ . '/claude_vision_analyze.php';

/**
 * @param array<string, string> $assetMap LpAssetDownloader の map（絶対URL => output 相対）
 * @param (callable(int, int): void)|null $memoProgressCb 画像メモ処理の進捗（処理済み件数 / 対象総数）
 *
 * @return array<string, mixed> 変更後の $structure
 */
function lp_reverse_enrich_structure_image_text_memos(
    array $structure,
    string $cmsRoot,
    string $dataDir,
    array $assetMap,
    ?callable $memoProgressCb = null,
): array {
    $apiKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
    if ($apiKey === '' || getenv('LP_IMAGE_TEXT_MEMO_DISABLE') === '1') {
        return lp_reverse_ensure_image_memo_keys($structure);
    }

    $denyClient = getenv('ANTHROPIC_DENY_CLIENT_KEY') === '1';

    $outputDir = rtrim(LpWorkspace::outputDir($cmsRoot), '/\\');
    $rawMemoMax = getenv('LP_IMAGE_TEXT_MEMO_MAX');
    $memoMaxSet = $rawMemoMax !== false && trim((string) $rawMemoMax) !== '';
    $maxImages   = $memoMaxSet ? max(1, (int) $rawMemoMax) : PHP_INT_MAX;

    $rawMemoBytes = getenv('LP_IMAGE_TEXT_MEMO_MAX_BYTES');
    $memoBytesSet = $rawMemoBytes !== false && trim((string) $rawMemoBytes) !== '';
    $maxBytes     = $memoBytesSet
        ? max(50_000, min(500_000_000, (int) $rawMemoBytes))
        : 500_000_000;

    $memoTotal = 0;
    foreach ($structure['sections'] ?? [] as $secCount) {
        foreach ($secCount['elements'] ?? [] as $elCount) {
            if (($elCount['type'] ?? '') !== 'image') {
                continue;
            }
            $srcCount = trim((string) ($elCount['original_src'] ?? ''));
            if ($srcCount === '' || preg_match('#favicon\\.ico#i', $srcCount)) {
                continue;
            }
            $memoTotal++;
        }
    }

    $denMemo       = max(1, $memoTotal);
    $memoProcessed = 0;
    if ($memoProgressCb !== null && $memoTotal > 0) {
        $memoProgressCb(0, $denMemo);
    }

    $done = 0;
    foreach ($structure['sections'] ?? [] as &$section) {
        foreach ($section['elements'] ?? [] as &$el) {
            if (($el['type'] ?? '') !== 'image') {
                continue;
            }
            if (!isset($el['image_embedded_text_memo'])) {
                $el['image_embedded_text_memo'] = '';
            }

            $src = trim((string) ($el['original_src'] ?? ''));
            if ($src === '' || preg_match('#favicon\\.ico#i', $src)) {
                continue;
            }

            $bumpMemo = static function () use (&$memoProcessed, $denMemo, $memoProgressCb): void {
                if ($memoProgressCb === null) {
                    return;
                }
                $memoProcessed++;
                $memoProgressCb($memoProcessed, $denMemo);
            };

            if ($done >= $maxImages) {
                $bumpMemo();
                continue;
            }

            // Vision/API が数十秒〜数分かかるため、その手前でも進捗コールバックで heartbeat させる
            if ($memoProgressCb !== null) {
                $memoProgressCb($memoProcessed, $denMemo);
            }

            $retryCount = 0;
            $resolved = lp_reverse_load_image_bin_for_memo($src, $outputDir, $assetMap, $maxBytes, $cmsRoot, $retryCount);
            if ($retryCount > 0) {
                lp_reverse_api_usage_record([
                    'env_var'       => '',
                    'provider'      => 'internal',
                    'operation'     => 'image_load_retry',
                    'ok'            => $resolved !== null,
                    'http_code'     => $resolved !== null ? 200 : 0,
                    'meta'          => [
                        'src'        => $src,
                        'retry'      => $retryCount,
                        'recovered'  => $resolved !== null,
                        'element_id' => (string) ($el['id'] ?? ''),
                    ],
                    'usage'         => [],
                    'estimated_usd' => 0.0,
                ]);
            }
            if ($resolved === null) {
                $bumpMemo();
                continue;
            }

            $mime = $resolved['mime'];
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                $bumpMemo();
                continue;
            }

            if ($memoProgressCb !== null) {
                $memoProgressCb($memoProcessed, $denMemo);
            }

            $memoRes = lp_reverse_claude_image_embedded_text_memo(
                $resolved['bin'],
                $mime,
                $resolved['w'],
                $resolved['h'],
                $apiKey,
            );

            if ($memoRes['ok']) {
                $inTok = (int) ($memoRes['usage']['input_tokens'] ?? 0);
                $outTok = (int) ($memoRes['usage']['output_tokens'] ?? 0);
                $estA = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);
                lp_reverse_api_usage_record([
                    'env_var'   => 'ANTHROPIC_API_KEY',
                    'provider'  => 'anthropic',
                    'operation' => 'vision_image_text_memo',
                    'ok'        => true,
                    'http_code' => 200,
                    'meta'      => [
                        'model'      => 'claude-sonnet-4-6',
                        'key_source' => $denyClient ? 'server_env' : 'server_env',
                        'element_id' => (string) ($el['id'] ?? ''),
                    ],
                    'usage'         => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
                    'estimated_usd' => $estA,
                ]);
                $el['image_embedded_text_memo'] = (string) ($memoRes['memo'] ?? '');
                $done++;
            } else {
                lp_reverse_api_usage_record([
                    'env_var'   => 'ANTHROPIC_API_KEY',
                    'provider'  => 'anthropic',
                    'operation' => 'vision_image_text_memo',
                    'ok'        => false,
                    'http_code' => $memoRes['http_code'] ?? 502,
                    'meta'      => [
                        'model'         => 'claude-sonnet-4-6',
                        'error_message' => $memoRes['error'] ?? '',
                        'element_id'    => (string) ($el['id'] ?? ''),
                    ],
                    'usage'         => [],
                    'estimated_usd' => 0.0,
                ]);
            }

            $bumpMemo();
        }
        unset($el);
    }
    unset($section);

    return lp_reverse_backfill_image_memo_from_alt($structure);
}

/**
 * @return array<string, mixed>
 */
function lp_reverse_ensure_image_memo_keys(array $structure): array
{
    foreach ($structure['sections'] ?? [] as &$section) {
        foreach ($section['elements'] ?? [] as &$el) {
            if (($el['type'] ?? '') === 'image' && !isset($el['image_embedded_text_memo'])) {
                $el['image_embedded_text_memo'] = '';
            }
        }
        unset($el);
    }
    unset($section);

    return lp_reverse_backfill_image_memo_from_alt($structure);
}

/**
 * Vision が空・未処理でも、編集画面と data-lp-image-text-memo 用に HTML の alt をメモへコピーする。
 *
 * @return array<string, mixed>
 */
function lp_reverse_backfill_image_memo_from_alt(array $structure): array
{
    foreach ($structure['sections'] ?? [] as &$section) {
        foreach ($section['elements'] ?? [] as &$el) {
            if (($el['type'] ?? '') !== 'image') {
                continue;
            }
            $memo = trim((string) ($el['image_embedded_text_memo'] ?? ''));
            if ($memo !== '') {
                continue;
            }
            $alt = trim((string) ($el['original_text'] ?? ''));
            if ($alt !== '') {
                $el['image_embedded_text_memo'] = $alt;
            }
        }
        unset($el);
    }
    unset($section);

    return $structure;
}

/**
 * @param array<string, string> $assetMap
 * @param int $retryCount リトライが発生した回数（呼び出し元でログ用途に使用）
 *
 * @return null|array{bin: string, mime: string, w: int, h: int}
 */
function lp_reverse_load_image_bin_for_memo(
    string $originalSrc,
    string $outputDir,
    array $assetMap,
    int $maxBytes,
    string $cmsRoot,
    int &$retryCount = 0,
): ?array {
    $retryCount = 0;
    $localFs = lp_reverse_resolve_local_asset_path($originalSrc, $outputDir, $assetMap, $cmsRoot);
    if ($localFs !== null && is_file($localFs)) {
        $sz = filesize($localFs);
        if ($sz !== false && $sz > 100 && $sz <= $maxBytes) {
            $bin = file_get_contents($localFs);
            if ($bin !== false) {
                return lp_reverse_memo_image_meta($bin);
            }
        }
    }

    if (!preg_match('#^https?://#i', $originalSrc)) {
        return null;
    }

    $bin = false;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($originalSrc);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 18,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'LpReverseCMS/1.2 (image text memo)',
        ]);
        $bin = curl_exec($ch);
        curl_close($ch);

        if ($bin !== false && strlen($bin) >= 100) {
            break;
        }
        if ($attempt < 3) {
            $retryCount++;
            sleep(2);
        }
    }

    if ($bin === false || strlen($bin) < 100 || strlen($bin) > $maxBytes) {
        return null;
    }

    return lp_reverse_memo_image_meta($bin);
}

/**
 * @param array<string, string> $assetMap
 */
function lp_reverse_resolve_local_asset_path(
    string $originalSrc,
    string $outputDir,
    array $assetMap,
    string $cmsRoot,
): ?string {
    if (isset($assetMap[$originalSrc]) && is_string($assetMap[$originalSrc])) {
        $p = $outputDir . '/' . str_replace('\\', '/', $assetMap[$originalSrc]);

        return is_file($p) ? $p : null;
    }

    $pathPart = (string) (parse_url($originalSrc, PHP_URL_PATH) ?: '');
    foreach ($assetMap as $absUrl => $rel) {
        if (!is_string($rel) || $rel === '') {
            continue;
        }
        $au = (string) $absUrl;
        $ap = (string) (parse_url($au, PHP_URL_PATH) ?: '');
        if ($pathPart !== '' && ($ap === $pathPart || str_ends_with($au, $pathPart))) {
            $p = $outputDir . '/' . str_replace('\\', '/', $rel);
            if (is_file($p)) {
                return $p;
            }
        }
    }

    if (preg_match('#/(?:current/)?output/(ws_[a-f0-9]{32}/(?:assets/.+|sites/.+))$#i', $originalSrc, $m)) {
        $p = rtrim($cmsRoot, '/\\') . DIRECTORY_SEPARATOR . 'output' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $m[1]);

        return is_file($p) ? $p : null;
    }

    return null;
}

/**
 * @return null|array{bin: string, mime: string, w: int, h: int}
 */
function lp_reverse_memo_image_meta(string $bin): ?array
{
    $info = @getimagesizefromstring($bin);
    if (!is_array($info) || !isset($info[0], $info[1])) {
        return null;
    }
    $w = (int) $info[0];
    $h = (int) $info[1];
    if ($w < 8 || $h < 8) {
        return null;
    }
    $mime = 'image/jpeg';
    if (isset($info['mime'])) {
        $m = (string) $info['mime'];
        $mime = match (true) {
            str_contains($m, 'png')  => 'image/png',
            str_contains($m, 'webp') => 'image/webp',
            str_contains($m, 'gif')  => 'image/gif',
            default                   => 'image/jpeg',
        };
    }

    return ['bin' => $bin, 'mime' => $mime, 'w' => $w, 'h' => $h];
}
