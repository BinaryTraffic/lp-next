<?php

declare(strict_types=1);

/**
 * Hugging Face Inference — テキストから画像生成してワークスペース ai_images に保存（共有）。
 * hf_image_proxy.php と lp_ai_image_pipeline.php から利用。
 */
require_once __DIR__ . '/LpWorkspace.php';
require_once __DIR__ . '/api_usage_log.php';

/**
 * @return array{ok: bool, url?: string, error?: string, content_type?: string, model?: string, mode?: string, key_source?: string}
 */
function lp_reverse_hf_save_generated_image(
    string $cmsRoot,
    string $mode,
    string $prompt,
    string $bgDesc,
    string $illStyle,
    int $w,
    int $h,
    string $token,
    string $keySourceForLog,
): array {
    $allowedModes = ['photo', 'illustration', 'composite', 'ui'];
    if (!in_array($mode, $allowedModes, true)) {
        $mode = 'composite';
    }

    $fullPrompt = hf_client_build_image_prompt($mode, $prompt, $bgDesc, $illStyle);
    if ($fullPrompt === '') {
        return ['ok' => false, 'error' => 'prompt または background_description のいずらかが必要です'];
    }

    $model = trim((string) (getenv('HF_IMAGE_MODEL') ?: 'black-forest-labs/FLUX.1-schnell'));
    if ($model === '' || str_contains($model, '..') || !preg_match('#^[a-zA-Z0-9._\\-/]+$#', $model)) {
        $model = 'black-forest-labs/FLUX.1-schnell';
    }

    $apiUrl = 'https://api-inference.huggingface.co/models/' . $model;
    $payload = ['inputs' => $fullPrompt];
    if ($w >= 64 && $h >= 64 && $w <= 2048 && $h <= 2048) {
        $payload['parameters'] = array_filter([
            'width' => $w,
            'height' => $h,
        ], static fn (int $v): bool => $v > 0);
        if (($payload['parameters'] ?? []) === []) {
            unset($payload['parameters']);
        }
    }

    $result = hf_client_inference_request($apiUrl, $token, $payload);
    if (!$result['ok'] && $result['code'] === 503 && hf_client_is_model_loading($result['body'])) {
        sleep(12);
        $result = hf_client_inference_request($apiUrl, $token, $payload);
    }

    if (!$result['ok']) {
        $msg = hf_client_extract_hf_error($result['body'], $result['curl_err']);
        lp_reverse_api_usage_record([
            'env_var' => lp_reverse_api_usage_hf_env_slot(),
            'provider' => 'huggingface',
            'operation' => 'inference/text-to-image',
            'ok' => false,
            'http_code' => $result['code'],
            'meta' => [
                'model' => $model,
                'mode' => $mode,
                'key_source' => $keySourceForLog,
                'error_message' => $msg,
            ],
            'usage' => [],
            'estimated_usd' => 0.0,
        ]);

        return ['ok' => false, 'error' => $msg];
    }

    $body = $result['body'];
    $ctype = $result['content_type'] ?: '';

    if (strlen($body) > 4 && $body[0] === '{' && str_contains($body, 'error')) {
        $j = json_decode($body, true);
        $msg = is_array($j) && isset($j['error']) ? (string) $j['error'] : mb_substr($body, 0, 400);
        lp_reverse_api_usage_record([
            'env_var' => lp_reverse_api_usage_hf_env_slot(),
            'provider' => 'huggingface',
            'operation' => 'inference/text-to-image',
            'ok' => false,
            'http_code' => 502,
            'meta' => [
                'model' => $model,
                'mode' => $mode,
                'key_source' => $keySourceForLog,
                'error_message' => $msg,
            ],
            'usage' => [],
            'estimated_usd' => 0.0,
        ]);

        return ['ok' => false, 'error' => 'HF API: ' . $msg];
    }

    $ext = hf_client_guess_image_extension($body, $ctype);
    if ($ext === null) {
        return ['ok' => false, 'error' => '生成結果が画像バイナリとして認識できませんでした'];
    }

    $aiDir = LpWorkspace::outputDir($cmsRoot) . 'ai_images';
    if (!is_dir($aiDir) && !@mkdir($aiDir, 0755, true)) {
        return ['ok' => false, 'error' => 'output/ai_images を作成できません'];
    }

    $fname = 'hf_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $aiDir . DIRECTORY_SEPARATOR . $fname;
    if (file_put_contents($dest, $body) === false) {
        return ['ok' => false, 'error' => '画像の保存に失敗しました'];
    }

    $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
    $publicUrl = LpWorkspace::outputWebAbsPrefix() . 'ai_images/' . $fname;

    $hfEst = lp_reverse_api_usage_estimate_hf_call();
    lp_reverse_api_usage_record([
        'env_var' => lp_reverse_api_usage_hf_env_slot(),
        'provider' => 'huggingface',
        'operation' => 'inference/text-to-image',
        'ok' => true,
        'http_code' => 200,
        'meta' => [
            'model' => $model,
            'mode' => $mode,
            'key_source' => $keySourceForLog,
            'saved' => $fname,
        ],
        'usage' => ['hf_inferences' => 1],
        'estimated_usd' => $hfEst,
    ]);

    return [
        'ok' => true,
        'url' => $publicUrl,
        'content_type' => $mime,
        'model' => $model,
        'mode' => $mode,
        'key_source' => $keySourceForLog,
    ];
}

/**
 * @return array{ok: bool, code: int, body: string, content_type: string, curl_err: string}
 */
function hf_client_inference_request(string $url, string $token, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_HEADER         => false,
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'code' => 502, 'body' => '', 'content_type' => '', 'curl_err' => $curlErr];
    }

    return [
        'ok' => $code === 200,
        'code' => $code,
        'body' => $body,
        'content_type' => $ctype,
        'curl_err' => $curlErr,
    ];
}

function hf_client_is_model_loading(string $body): bool
{
    $l = strtolower($body);

    return str_contains($l, 'loading') && str_contains($l, 'model');
}

function hf_client_extract_hf_error(string $body, string $curlErr): string
{
    if ($curlErr !== '') {
        return 'HF 接続エラー: ' . $curlErr;
    }
    $j = json_decode($body, true);
    if (is_array($j) && isset($j['error'])) {
        return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE);
    }

    return mb_substr(trim($body), 0, 500) ?: 'Hugging Face API エラー';
}

function hf_client_build_image_prompt(string $mode, string $prompt, string $bgDesc, string $illStyle): string
{
    $parts = [];
    $main = $prompt !== '' ? $prompt : $bgDesc;
    $extra = ($prompt !== '' && $bgDesc !== '' && $prompt !== $bgDesc) ? $bgDesc : '';

    if ($mode === 'photo') {
        $parts[] = 'Photorealistic photograph, natural lighting, sharp focus, professional quality, no text, no watermark, no typography, no letters.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    } elseif ($mode === 'illustration') {
        $style = match ($illStyle) {
            'line_art', 'lineart' => 'Clean line art, distinct outlines, minimal fill, ',
            'flat' => 'Flat design illustration, solid colors, simple shapes, ',
            'watercolor' => 'Soft watercolor illustration, gentle gradients, ',
            default => 'Modern digital illustration, ',
        };
        $parts[] = $style . 'no text, no captions, no logos, no watermarks.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    } elseif ($mode === 'ui') {
        $parts[] = 'Clean UI or marketing banner background, subtle texture, ample negative space for labels, absolutely no text, letters, numbers, or icons with glyphs.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    } else {
        $parts[] = 'Wide marketing background, cohesive scene, areas suitable for text overlay, completely text-free image: no letters, no numbers, no logos, no watermarks.';
        if ($main !== '') {
            $parts[] = $main;
        }
        if ($extra !== '') {
            $parts[] = $extra;
        }
    }

    $s = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts))) ?? '');
    if (mb_strlen($s) > 1800) {
        $s = mb_substr($s, 0, 1800);
    }

    return $s;
}

function hf_client_guess_image_extension(string $bin, string $ctype): ?string
{
    if (str_contains($ctype, 'jpeg') || str_contains($ctype, 'jpg')) {
        return 'jpg';
    }
    if (str_contains($ctype, 'png')) {
        return 'png';
    }
    if (str_contains($ctype, 'webp')) {
        return 'webp';
    }
    if (strlen($bin) >= 3 && $bin[0] === "\xff" && $bin[1] === "\xd8") {
        return 'jpg';
    }
    if (strlen($bin) >= 8 && str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
        return 'png';
    }
    if (strlen($bin) >= 12 && str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP') {
        return 'webp';
    }

    return null;
}
