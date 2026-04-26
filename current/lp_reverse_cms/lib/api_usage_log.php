<?php

declare(strict_types=1);

/**
 * 外部 API 呼び出しの利用記録（JSONL + .env 変数名別の積算）。
 *
 * 保存先（Git 管理外）:
 *   lp_reverse_cms/data/api_usage_events.jsonl
 *   lp_reverse_cms/data/api_usage_totals.json
 *
 * 概算 USD は各社の公表単価の目安（環境変数で上書き可）。請求額の保証ではない。
 *
 * 任意の .env 上書き例:
 *   LP_USAGE_OPENAI_DALLE3_1024_USD=0.04
 *   LP_USAGE_OPENAI_DALLE3_WIDE_USD=0.08
 *   LP_USAGE_OPENAI_DALLE2_1024_USD=0.02
 *   LP_USAGE_ANTHROPIC_INPUT_PER_MTOK_USD=3
 *   LP_USAGE_ANTHROPIC_OUTPUT_PER_MTOK_USD=15
 *   LP_USAGE_HF_USD_PER_CALL=0
 */

require_once __DIR__ . '/env_load.php';

function lp_reverse_api_usage_data_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
}

function lp_reverse_api_usage_events_path(): string
{
    return lp_reverse_api_usage_data_dir() . DIRECTORY_SEPARATOR . 'api_usage_events.jsonl';
}

function lp_reverse_api_usage_totals_path(): string
{
    return lp_reverse_api_usage_data_dir() . DIRECTORY_SEPARATOR . 'api_usage_totals.json';
}

/**
 * Hugging Face 用: サーバー鍵がどちらの .env 名由来か（クライアント鍵のみのときは HUGGINGFACE_API_TOKEN を仮ラベルにする）。
 */
function lp_reverse_api_usage_hf_env_slot(): string
{
    lp_reverse_load_env();
    if (trim((string) (getenv('HUGGINGFACE_API_TOKEN') ?: '')) !== '') {
        return 'HUGGINGFACE_API_TOKEN';
    }
    if (trim((string) (getenv('HF_TOKEN') ?: '')) !== '') {
        return 'HF_TOKEN';
    }

    return 'HUGGINGFACE_API_TOKEN';
}

/**
 * @param array{
 *   env_var: string,
 *   provider: string,
 *   operation: string,
 *   ok: bool,
 *   http_code: int,
 *   meta?: array<string, mixed>,
 *   usage?: array{input_tokens?: int, output_tokens?: int, images?: int, hf_inferences?: int}|null,
 *   estimated_usd?: float,
 * } $rec
 */
function lp_reverse_api_usage_record(array $rec): void
{
    lp_reverse_load_env();

    $envVar = $rec['env_var'] ?? '';
    if ($envVar === '') {
        return;
    }

    $provider = (string) ($rec['provider'] ?? '');
    $operation = (string) ($rec['operation'] ?? '');
    $ok = (bool) ($rec['ok'] ?? false);
    $httpCode = (int) ($rec['http_code'] ?? 0);
    $meta = isset($rec['meta']) && is_array($rec['meta']) ? $rec['meta'] : [];
    $usage = isset($rec['usage']) && is_array($rec['usage']) ? $rec['usage'] : [];
    $estimated = isset($rec['estimated_usd']) ? (float) $rec['estimated_usd'] : 0.0;

    $dir = lp_reverse_api_usage_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }

    $event = [
        'ts' => gmdate('Y-m-d\TH:i:s\Z'),
        'env_var' => $envVar,
        'provider' => $provider,
        'operation' => $operation,
        'ok' => $ok,
        'http_code' => $httpCode,
        'meta' => $meta,
        'usage' => $usage,
        'estimated_usd' => round($estimated, 6),
    ];

    $jsonLine = json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(lp_reverse_api_usage_events_path(), $jsonLine, FILE_APPEND | LOCK_EX);

    lp_reverse_api_usage_merge_totals($envVar, $provider, $ok, $estimated, $usage);
}

function lp_reverse_api_usage_estimate_openai_image(string $model, string $size): float
{
    lp_reverse_load_env();
    $model = strtolower($model);
    $wide = $size === '1024x1792' || $size === '1792x1024';

    if ($model === 'dall-e-3') {
        if ($wide) {
            $v = getenv('LP_USAGE_OPENAI_DALLE3_WIDE_USD');

            return $v !== false && is_numeric($v) ? (float) $v : 0.08;
        }
        $v = getenv('LP_USAGE_OPENAI_DALLE3_1024_USD');

        return $v !== false && is_numeric($v) ? (float) $v : 0.04;
    }

    // dall-e-2
    $v = getenv('LP_USAGE_OPENAI_DALLE2_1024_USD');

    return $v !== false && is_numeric($v) ? (float) $v : 0.02;
}

function lp_reverse_api_usage_estimate_anthropic_usd(int $inputTok, int $outputTok): float
{
    lp_reverse_load_env();
    $inRate = getenv('LP_USAGE_ANTHROPIC_INPUT_PER_MTOK_USD');
    $outRate = getenv('LP_USAGE_ANTHROPIC_OUTPUT_PER_MTOK_USD');
    $inPerM = $inRate !== false && is_numeric($inRate) ? (float) $inRate : 3.0;
    $outPerM = $outRate !== false && is_numeric($outRate) ? (float) $outRate : 15.0;

    return ($inputTok / 1_000_000) * $inPerM + ($outputTok / 1_000_000) * $outPerM;
}

function lp_reverse_api_usage_estimate_hf_call(): float
{
    lp_reverse_load_env();
    $v = getenv('LP_USAGE_HF_USD_PER_CALL');

    return $v !== false && is_numeric($v) ? (float) $v : 0.0;
}

/**
 * @param array<string, int> $usage
 */
function lp_reverse_api_usage_merge_totals(
    string $envVar,
    string $provider,
    bool $ok,
    float $estimatedUsd,
    array $usage,
): void {
    $path = lp_reverse_api_usage_totals_path();
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);

        return;
    }

    try {
        $raw = stream_get_contents($fp);
        $totals = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : [];
        if (!is_array($totals)) {
            $totals = [];
        }

        if (!isset($totals[$envVar]) || !is_array($totals[$envVar])) {
            $totals[$envVar] = [
                'provider' => $provider,
                'requests_ok' => 0,
                'requests_err' => 0,
                'estimated_usd_ok' => 0.0,
                'estimated_usd_err' => 0.0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'images_generated' => 0,
                'hf_inferences' => 0,
                'updated_at' => null,
            ];
        }

        $b = &$totals[$envVar];
        $b['provider'] = $provider;
        if ($ok) {
            $b['requests_ok'] = (int) ($b['requests_ok'] ?? 0) + 1;
            $b['estimated_usd_ok'] = round((float) ($b['estimated_usd_ok'] ?? 0) + $estimatedUsd, 6);
        } else {
            $b['requests_err'] = (int) ($b['requests_err'] ?? 0) + 1;
            $b['estimated_usd_err'] = round((float) ($b['estimated_usd_err'] ?? 0) + $estimatedUsd, 6);
        }

        $b['input_tokens'] = (int) ($b['input_tokens'] ?? 0) + (int) ($usage['input_tokens'] ?? 0);
        $b['output_tokens'] = (int) ($b['output_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
        $b['images_generated'] = (int) ($b['images_generated'] ?? 0) + (int) ($usage['images'] ?? 0);
        $b['hf_inferences'] = (int) ($b['hf_inferences'] ?? 0) + (int) ($usage['hf_inferences'] ?? 0);
        $b['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($totals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
