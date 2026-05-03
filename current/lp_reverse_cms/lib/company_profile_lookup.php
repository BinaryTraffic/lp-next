<?php

declare(strict_types=1);

require_once __DIR__ . '/env_load.php';
require_once __DIR__ . '/api_usage_log.php';
require_once __DIR__ . '/houjin_bangou_client.php';

/**
 * 企業名から公表データ（任意）＋ AI 参考ヒント（要検証）を取得する。
 *
 * 法人番号 API は HOUJIN_BANGOU_APP_ID 設定時のみ利用。**未設定なら呼ばれない**（クライアントの契約・運用に合わせ、
 * 反社チェック用のデータ基盤などと役割分担したうえで、本 CMS への組み込み範囲を決められるようにしている）。
 *
 * @return array{
 *   ok: bool,
 *   company_name: string,
 *   official: array{
 *     source_label: string,
 *     configured: bool,
 *     api_error: ?string,
 *     matches: list<array<string, string>>
 *   },
 *   ai_hints: ?array{
 *     industry_hint: string,
 *     business_characteristics: string,
 *     official_url_hint: string,
 *     history_summary: string,
 *     capital_hint: string
 *   },
 *   requires_user_verification: true,
 *   notice: string,
 *   attribution: string
 * }
 */
function lp_reverse_company_profile_lookup(
    string $companyName,
    string $addressPref = '',
    string $addressCity = '',
    bool $skipAi = false
): array {
    lp_reverse_load_env();

    $companyName  = trim($companyName);
    $addressPref  = trim($addressPref);
    $addressCity  = trim($addressCity);
    $attribution = '国税庁法人番号システムの Web-API を利用して取得した情報を含む場合があります。'
        . ' サービスの内容は国税庁によって保証されたものではありません。';

    $notice = '表示される業種・URL・沿革・資本金などは推測や公開データの要約であり誤りがあり得ます。'
        . ' LP の根拠とする前に、必ず公式サイト・登記情報・最新の有価証券報告書等でご確認ください。';

    if ($companyName === '' || mb_strlen($companyName) > 200) {
        return [
            'ok'                         => false,
            'error'                      => '企業名を入力してください（200文字以内）。',
            'company_name'               => $companyName,
            'official'                   => [
                'source_label' => '国税庁法人番号システム Web-API',
                'configured'   => false,
                'api_error'    => null,
                'matches'      => [],
            ],
            'ai_hints'                   => null,
            'requires_user_verification' => true,
            'notice'                     => $notice,
            'attribution'              => $attribution,
        ];
    }

    $houjin     = lp_reverse_houjin_search_by_name($companyName);
    $rawMatches = $houjin['matches'] ?? [];
    $apiErr     = $houjin['error'] ?? null;
    $narrowed   = lp_reverse_houjin_narrow_matches_by_address($rawMatches, $addressPref, $addressCity);
    $matches    = count($narrowed) > 0 ? $narrowed : $rawMatches;

    $official = [
        'source_label' => '国税庁法人番号システム Web-API',
        'configured'   => !empty($houjin['configured']),
        'api_error'    => $apiErr,
        'http_code'    => $houjin['http_code'] ?? null,
        'matches'      => $matches,
    ];

    if ($skipAi) {
        if (count($matches) === 0) {
            $detail = '';
            if (($official['configured'] ?? false) && $apiErr !== null) {
                $hc     = $houjin['http_code'] ?? 0;
                $detail = ' 法人番号API: ' . $apiErr . ($hc ? ' (HTTP ' . $hc . ')' : '');
            }
            if (!($official['configured'] ?? false)) {
                return [
                    'ok'                         => false,
                    'error'                      => '法人番号API（HOUJIN_BANGOU_APP_ID）が未設定か、該当法人がありません。',
                    'company_name'               => $companyName,
                    'official'                   => $official,
                    'ai_hints'                   => null,
                    'requires_user_verification' => true,
                    'notice'                     => $notice,
                    'attribution'                => $attribution,
                ];
            }

            return [
                'ok'                         => false,
                'error'                      => '公表法人の該当がありませんでした。' . $detail,
                'company_name'               => $companyName,
                'official'                   => $official,
                'ai_hints'                   => null,
                'requires_user_verification' => true,
                'notice'                     => $notice,
                'attribution'                => $attribution,
            ];
        }

        return [
            'ok'                         => true,
            'company_name'               => $companyName,
            'official'                   => $official,
            'ai_hints'                   => null,
            'requires_user_verification' => true,
            'notice'                     => $notice,
            'attribution'                => $attribution,
        ];
    }

    $aiHints   = null;
    $serverKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));

    if ($serverKey !== '') {
        $ctx = '';
        if ($matches !== []) {
            $pick = array_slice($matches, 0, 5);
            $ctx  = "国税庁法人番号APIの検索結果（商号・所在地・法人番号。業種や資本金は含まれない）:\n"
                . json_encode($pick, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $prompt = <<<PROMPT
入力された法人名に関して、日本の法人LP制作の参考用に次の JSON だけを返してください。
説明文・マークダウン・コードフェンスは禁止。

厳守:
- 公的データにない具体数値（資本金の円単位の額、正確な設立年など）は、**確実に知っている場合のみ**記載。少しでも不明なら空文字 "" にする。
- 捏造・推測で数値や日付を埋めない。
- 公式URLは、一般に公知のコーポレートドメインを確信できる場合のみ。不明なら ""。
- 沿革は、広く知られた事実の1〜3文の要約に限る。不確かなら ""。
- 業種は日本語で簡潔に（例: 「Web広告」「飲食」「ソフトウェア」）。曖昧なら ""。
- 特色（business_characteristics）は事業内容の短い一般的説明。不明なら ""。

法人名: {$companyName}
{$ctx}

応答フォーマットのみ:
{"industry_hint":"","business_characteristics":"","official_url_hint":"","history_summary":"","capital_hint":""}
PROMPT;

        $model   = 'claude-haiku-4-5-20251001';
        $payload = json_encode([
            'model'       => $model,
            'max_tokens'  => 512,
            'temperature' => 0.2,
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

        $data       = is_string($response) ? json_decode($response, true) : null;
        $usageBlock = is_array($data) && isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $inTok      = (int) ($usageBlock['input_tokens'] ?? 0);
        $outTok     = (int) ($usageBlock['output_tokens'] ?? 0);
        $estAnth    = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);

        if ($response === false) {
            lp_reverse_api_usage_record([
                'env_var'       => 'ANTHROPIC_API_KEY',
                'provider'      => 'anthropic',
                'operation'     => 'company_profile_lookup',
                'ok'            => false,
                'http_code'     => 502,
                'meta'          => ['model' => $model, 'curl_error' => $curlErr],
                'usage'         => [],
                'estimated_usd' => 0.0,
            ]);
        } elseif ($code !== 200 || !is_array($data) || !isset($data['content'][0]['text'])) {
            lp_reverse_api_usage_record([
                'env_var'       => 'ANTHROPIC_API_KEY',
                'provider'      => 'anthropic',
                'operation'     => 'company_profile_lookup',
                'ok'            => false,
                'http_code'     => $code,
                'meta'          => [
                    'model' => $model,
                    'err'   => is_array($data) && isset($data['error']['message'])
                        ? (string) $data['error']['message'] : mb_substr((string) $response, 0, 200),
                ],
                'usage'         => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
                'estimated_usd' => $estAnth,
            ]);
        } else {
            $text = trim((string) $data['content'][0]['text']);
            if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/m', $text, $m)) {
                $text = trim($m[1]);
            }
            $parsed = json_decode($text, true);
            if (is_array($parsed)) {
                $aiHints = [
                    'industry_hint'            => lp_reverse_company_lookup_clip((string) ($parsed['industry_hint'] ?? ''), 120),
                    'business_characteristics' => lp_reverse_company_lookup_clip((string) ($parsed['business_characteristics'] ?? ''), 600),
                    'official_url_hint'        => lp_reverse_company_lookup_clip((string) ($parsed['official_url_hint'] ?? ''), 500),
                    'history_summary'          => lp_reverse_company_lookup_clip((string) ($parsed['history_summary'] ?? ''), 2000),
                    'capital_hint'             => lp_reverse_company_lookup_clip((string) ($parsed['capital_hint'] ?? ''), 120),
                ];
            }

            lp_reverse_api_usage_record([
                'env_var'       => 'ANTHROPIC_API_KEY',
                'provider'      => 'anthropic',
                'operation'     => 'company_profile_lookup',
                'ok'            => $aiHints !== null,
                'http_code'     => $code,
                'meta'          => ['model' => $model],
                'usage'         => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
                'estimated_usd' => $estAnth,
            ]);
        }
    }

    $hasOfficial = count($matches) > 0;
    $hasAiContent = is_array($aiHints) && array_filter(
        $aiHints,
        static fn ($v) => trim((string) $v) !== ''
    );

    if (!$hasOfficial && !$hasAiContent) {
        if ($serverKey === '' && !($official['configured'] ?? false)) {
            return [
                'ok'                         => false,
                'error'                      => '検索を行うには ANTHROPIC_API_KEY、または法人番号API（.env の HOUJIN_BANGOU_APP_ID）のいずれかが必要です。',
                'company_name'               => $companyName,
                'official'                   => $official,
                'ai_hints'                   => $aiHints,
                'requires_user_verification' => true,
                'notice'                     => $notice,
                'attribution'                => $attribution,
            ];
        }

        $detail = '';
        if (($official['configured'] ?? false) && $apiErr !== null) {
            $hc = $houjin['http_code'] ?? 0;
            $detail = ' 法人番号API: ' . $apiErr . ($hc ? ' (HTTP ' . $hc . ')' : '');
        }

        if ($serverKey === '') {
            return [
                'ok'                         => false,
                'error'                      => '公表情報の該当がありませんでした。' . $detail
                    . ' AI による補足には ANTHROPIC_API_KEY を設定するか、手入力してください。',
                'company_name'               => $companyName,
                'official'                   => $official,
                'ai_hints'                   => null,
                'requires_user_verification' => true,
                'notice'                     => $notice,
                'attribution'                => $attribution,
            ];
        }

        return [
            'ok'                         => false,
            'error'                      => '公表情報・AIヒントのいずれも得られませんでした。'
                . $detail
                . ' 企業名を変えて再試行するか、手入力してください。',
            'company_name'               => $companyName,
            'official'                   => $official,
            'ai_hints'                   => $aiHints,
            'requires_user_verification' => true,
            'notice'                     => $notice,
            'attribution'                => $attribution,
        ];
    }

    return [
        'ok'                         => true,
        'company_name'               => $companyName,
        'official'                   => $official,
        'ai_hints'                   => $aiHints,
        'requires_user_verification' => true,
        'notice'                     => $notice,
        'attribution'                => $attribution,
    ];
}

function lp_reverse_company_lookup_clip(string $s, int $max): string
{
    $s = trim($s);
    if (mb_strlen($s) <= $max) {
        return $s;
    }

    return mb_substr($s, 0, $max) . '…';
}
