<?php

declare(strict_types=1);

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/api_usage_log.php';

/**
 * lp_structure からページ title/description を取り、Claude Haiku で元業種＋関連5業種を推定。
 *
 * @return array{source_industry: string, suggestions: list<string>}
 */
function lp_reverse_suggest_industries_from_structure(array $structure): array
{
    lp_reverse_load_env();

    $meta  = $structure['meta'] ?? [];
    $title = trim((string) ($meta['title'] ?? $structure['page_title'] ?? $structure['title'] ?? ''));
    $desc  = trim((string) ($meta['description'] ?? $structure['meta_description'] ?? $structure['description'] ?? ''));

    if ($title === '' && $desc === '') {
        return ['source_industry' => '', 'suggestions' => []];
    }

    $serverKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
    if ($serverKey === '') {
        return ['source_industry' => '', 'suggestions' => []];
    }

    $titleMax = 500;
    $descMax  = 1500;
    if (mb_strlen($title) > $titleMax) {
        $title = mb_substr($title, 0, $titleMax) . '…';
    }
    if (mb_strlen($desc) > $descMax) {
        $desc = mb_substr($desc, 0, $descMax) . '…';
    }

    $prompt = <<<PROMPT
以下はLPのページ情報です。
タイトル: {$title}
説明: {$desc}

このLPの業種を10文字以内の日本語で答えてください（source_industry）。
次に、同じターゲット（個人・店舗向けサービス業）で、このLPの構成や訴求が流用しやすい別業種を5つ、日本語の短文で挙げてください（suggestions）。

応答は次のJSONオブジェクトのみ（説明文・マークダウン・コードフェンス禁止）:
{"source_industry":"...","suggestions":["...","...","...","...","..."]}
PROMPT;

    $model = 'claude-haiku-4-5-20251001';
    $payload = json_encode([
        'model'       => $model,
        'max_tokens'  => 256,
        'temperature' => 0.3,
        'messages'    => [[
            'role'    => 'user',
            'content' => $prompt,
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $serverKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $empty = ['source_industry' => '', 'suggestions' => []];

    if ($response === false) {
        lp_reverse_api_usage_record([
            'env_var'   => 'ANTHROPIC_API_KEY',
            'provider'  => 'anthropic',
            'operation' => 'suggest_industries',
            'ok'        => false,
            'http_code' => 502,
            'meta'      => ['model' => $model, 'curl_error' => $curlErr],
            'usage'         => [],
            'estimated_usd' => 0.0,
        ]);

        return $empty;
    }

    $data = json_decode($response, true);
    $usageBlock = is_array($data) && isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
    $inTok      = (int) ($usageBlock['input_tokens'] ?? 0);
    $outTok     = (int) ($usageBlock['output_tokens'] ?? 0);
    $estAnth    = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);

    if ($code !== 200 || !isset($data['content'][0]['text'])) {
        $msg = is_array($data) && isset($data['error']['message'])
            ? (string) $data['error']['message']
            : mb_substr($response, 0, 300);
        lp_reverse_api_usage_record([
            'env_var'   => 'ANTHROPIC_API_KEY',
            'provider'  => 'anthropic',
            'operation' => 'suggest_industries',
            'ok'        => false,
            'http_code' => $code,
            'meta'      => ['model' => $model, 'error_message' => $msg],
            'usage'     => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
            'estimated_usd' => $estAnth,
        ]);

        return $empty;
    }

    $text = trim((string) $data['content'][0]['text']);
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/m', $text, $m)) {
        $text = trim($m[1]);
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        lp_reverse_api_usage_record([
            'env_var'   => 'ANTHROPIC_API_KEY',
            'provider'  => 'anthropic',
            'operation' => 'suggest_industries',
            'ok'        => false,
            'http_code' => $code,
            'meta'      => ['model' => $model, 'reason' => 'json_parse_failed'],
            'usage'     => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
            'estimated_usd' => $estAnth,
        ]);

        return $empty;
    }

    $src = isset($parsed['source_industry']) ? trim((string) $parsed['source_industry']) : '';
    if (mb_strlen($src) > 32) {
        $src = mb_substr($src, 0, 32);
    }

    $sug = [];
    if (isset($parsed['suggestions']) && is_array($parsed['suggestions'])) {
        foreach ($parsed['suggestions'] as $s) {
            $t = trim((string) $s);
            if ($t !== '' && !in_array($t, $sug, true)) {
                $sug[] = $t;
            }
            if (count($sug) >= 5) {
                break;
            }
        }
    }

    lp_reverse_api_usage_record([
        'env_var'   => 'ANTHROPIC_API_KEY',
        'provider'  => 'anthropic',
        'operation' => 'suggest_industries',
        'ok'        => true,
        'http_code' => $code,
        'meta'      => ['model' => $model],
        'usage'     => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
        'estimated_usd' => $estAnth,
    ]);

    return [
        'source_industry' => $src,
        'suggestions'     => $sug,
    ];
}
