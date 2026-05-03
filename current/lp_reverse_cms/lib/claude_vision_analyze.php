<?php

declare(strict_types=1);

/**
 * Claude Vision — LP 画像解析用プロンプト生成と API 呼び出し（claude_image_analyze / パイプライン共通）。
 */
require_once __DIR__ . '/icon_map.php';

/**
 * @return array{ok: true, text: string, usage: array{input_tokens: int, output_tokens: int}}|array{ok: false, error: string, http_code: int, raw?: string}
 */
function lp_reverse_claude_vision_request(
    string $imgBin,
    string $mime,
    int $imgW,
    int $imgH,
    string $apiKey,
    string $industryLine = '',
): array {
    $sizeHint = ($imgW > 0 && $imgH > 0)
        ? "画像の実寸は {$imgW}×{$imgH}px です。"
        : '';
    $iconLabelList = implode(', ', array_keys(IconMap::ICON_MAP));
    $industryBlock = $industryLine !== ''
        ? "\nページ文脈: 想定業種・トーンは「{$industryLine}」です。texts の lines はその文脈に合う短い日本語の置換案としてよい（確定コピーでなくてよい）。HTML タグは含めない。\n"
        : '';

    $prompt = <<<PROMPT
この画像を解析してください。{$sizeHint}
{$industryBlock}
以下のJSONのみを返してください（説明文・コードブロック不要）:
{
  "type": "photo | illustration | ui | composite | gradient | bordered | badge",
  "illustration_style": "line_art | flat | watercolor | none",
  "background_description": "背景の視覚的説明（英語、FLUX生成プロンプト用）",
  "replacement": {
    "mode": "full | placeholder",
    "reason_ja": "mode が placeholder のとき、プレースホルダ出力が妥当な理由を1文で"
  },
  "gradient": {
    "type": "linear | radial",
    "angle": 180,
    "colors": [
      { "color": "#3a7bd5", "stop": 0.0 },
      { "color": "#00d2ff", "stop": 1.0 }
    ]
  },
  "border": {
    "color": "#c8a96e",
    "width_pct": 0.06,
    "inner_type": "photo | illustration",
    "inner_description": "English prompt for inner image generation"
  },
  "badge": {
    "shape": "circle | pill | ribbon | rect",
    "bg_color": "#e63c3c",
    "text_color": "#ffffff"
  },
  "texts": [
    {
      "content": "テキスト内容（画像に焼く全文。改行は含めず lines の連結と同じ）",
      "lines": ["1行目", "2行目"],
      "semantic_role": "cta_primary | cta_secondary | nav_label | caption | price | badge_label | headline_in_banner | body_in_banner | other",
      "line_break_policy": "hard_wrap | slash_or_pipe_allowed | single_line_only",
      "char_budget_hint": { "max_total": 24, "max_per_line": [12, 12] },
      "x_pct": 左端のX座標（画像幅に対する0〜1の比率）,
      "y_pct": 上端のY座標（画像高さに対する0〜1の比率）,
      "w_pct": テキスト領域の幅比率（0〜1）,
      "h_pct": テキスト領域の高さ比率（0〜1）,
      "font_size_pct": 上記テキスト領域の高さ（h_pct の範囲）に対する比率。ボタンラベルは 0.22〜0.35 程度が目安（大きすぎないこと）,
      "bold": true | false,
      "color": "#rrggbb"
    }
  ],
  "icons": [
    {
      "label": "次のいずれかの文字列のみ: {$iconLabelList}",
      "x_pct": 左端（0〜1）,
      "y_pct": 上端（0〜1）,
      "w_pct": 幅（0〜1）,
      "h_pct": 高さ（0〜1）
    }
  ]
}

replacement.mode 判定:
- full: 実写・イラストの単体差し替え、UI/合成バナーの座標付きテキスト再合成が現実的。
- placeholder: 極端に複雑なコラージュ、文字が判読不能、顔・固有名所の誤生成リスクが高い、法的に再生成を避けるべき内容、パイプラインが未保持のタイプで自動処理困難と判断した場合。reason_ja を必ず書く。

texts の各要素について:
- lines は行配列。content は lines を空なしで連結したものと同一意味（HTML タグ禁止）。
- line_break_policy: CTA ボタンは single_line_only を優先。縦長枠は hard_wrap。

icons には「電話・矢印・チェック・カレンダー・メール・地図ピン・LINE / Instagram / X(twitter) / Facebook / YouTube / TikTok のロゴ」など、テキストではない装飾シンボルだけを入れてください。label は指定リストのキーと完全一致させてください（サーバ側の公式ロゴ SVG と対応）。日本語や英単語の文字そのものは texts にのみ含めます。該当がなければ "icons": [] としてください。

typeの判定基準:
- photo: 実写・写真
- illustration: イラスト・アイコン・ベクター
- ui: ボタン・電話番号・バナー文字など機能的UI
- composite: 上記が複数混在（背景+テキスト+イラストなど）
- gradient: グラデーション単色背景（写真なし。テキストを乗せる前提の帯・セクション背景）
- bordered: 写真やイラストを縁取るフレーム・ボーダーが存在する画像
- badge: ナンバリング丸・NEWリボン・価格タグなど小型アクセント要素

gradient: type が gradient のときのみ gradient を埋める。gradient.colors に開始・終了（必要なら中間）の色を stop(0.0〜1.0) 付きで返す。angle は linear のとき度数(0=上→下, 90=左→右)。radial のとき 0。
bordered: type が bordered のときのみ border を埋める。border.width_pct は画像短辺に対するフレーム幅の比率（片側）。inner_description は FLUX へ渡す英語プロンプト。
badge: type が badge のときのみ badge を埋める。badge.shape は形状の最も近いもの。色は badge に含める。テキスト内容は通常どおり texts[] に含める。

type が gradient / bordered / badge でないときは gradient, border, badge は null または省略可能（空オブジェクトでも可）。

textsには画像内に見えるすべてのテキストを含めてください。
PROMPT;

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 2048,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mime,
                        'data'       => base64_encode($imgBin),
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Anthropic 接続エラー: ' . $curlErr, 'http_code' => 502];
    }

    $data = json_decode($response, true);
    if ($code !== 200 || !isset($data['content'][0]['text'])) {
        $msg = is_array($data) && isset($data['error']['message'])
            ? (string) $data['error']['message']
            : mb_substr($response, 0, 400);

        return ['ok' => false, 'error' => $msg, 'http_code' => $code >= 400 ? $code : 502, 'raw' => $response];
    }

    $usage = ['input_tokens' => 0, 'output_tokens' => 0];
    if (is_array($data) && isset($data['usage']) && is_array($data['usage'])) {
        $usage = [
            'input_tokens'  => (int) ($data['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($data['usage']['output_tokens'] ?? 0),
        ];
    }

    $text = trim((string) $data['content'][0]['text']);
    $text = lp_reverse_claude_strip_json_fence($text);

    return ['ok' => true, 'text' => $text, 'usage' => $usage];
}

function lp_reverse_claude_strip_json_fence(string $text): string
{
    $t = trim($text);
    if (preg_match('/^```(?:json)?\s*(.+?)```\s*$/s', $t, $m)) {
        return trim($m[1]);
    }

    return $t;
}

/**
 * 画像内の焼き込み文字のみを軽量抽出（編集画面のメモ欄向け・解析時に一括投入）。
 *
 * @return array{ok: true, memo: string, usage: array{input_tokens: int, output_tokens: int}}|array{ok: false, error: string, http_code: int, raw?: string}
 */
function lp_reverse_claude_image_embedded_text_memo(
    string $imgBin,
    string $mime,
    int $imgW,
    int $imgH,
    string $apiKey,
): array {
    $hint = ($imgW > 0 && $imgH > 0) ? "画像サイズは {$imgW}×{$imgH}px。" : '';
    $prompt = <<<PROMPT
あなたは画像内テキスト抽出の専門家です。{$hint}
画像に含まれる**人が読むための文字**だけを、自然な読み順（日本語: 上から・左から右。複数列は列ごと）で列挙してください。ロゴの装飾のみ判別不能な場合は無理に埋めないでください。

次の JSON のみを返す（説明文・コードフェンス禁止）:
{"embedded_lines":["1行目のテキスト","2行目", "..."]}

文字が一切ない場合は {"embedded_lines":[]} 。HTML タグや Markdown は含めない。
PROMPT;

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 1200,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $mime,
                        'data'       => base64_encode($imgBin),
                    ],
                ],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 55,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Anthropic 接続エラー: ' . $curlErr, 'http_code' => 502];
    }

    $data = json_decode($response, true);
    if ($code !== 200 || !isset($data['content'][0]['text'])) {
        $msg = is_array($data) && isset($data['error']['message'])
            ? (string) $data['error']['message']
            : mb_substr($response, 0, 400);

        return ['ok' => false, 'error' => $msg, 'http_code' => $code >= 400 ? $code : 502, 'raw' => $response];
    }

    $usage = ['input_tokens' => 0, 'output_tokens' => 0];
    if (is_array($data) && isset($data['usage']) && is_array($data['usage'])) {
        $usage = [
            'input_tokens'  => (int) ($data['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($data['usage']['output_tokens'] ?? 0),
        ];
    }

    $rawText = lp_reverse_claude_strip_json_fence(trim((string) $data['content'][0]['text']));
    $parsed = json_decode($rawText, true);
    $lines = [];
    if (is_array($parsed) && isset($parsed['embedded_lines']) && is_array($parsed['embedded_lines'])) {
        foreach ($parsed['embedded_lines'] as $ln) {
            if (is_string($ln)) {
                $t = trim($ln);
                if ($t !== '') {
                    $lines[] = $t;
                }
            }
        }
    }
    $memo = implode("\n", $lines);
    if (mb_strlen($memo) > 4000) {
        $memo = mb_substr($memo, 0, 4000) . "\n…";
    }

    return ['ok' => true, 'memo' => $memo, 'usage' => $usage];
}

/**
 * Vision 応答 JSON を claude_image_analyze と同形に正規化する。
 *
 * @param array<string, mixed> $parsed
 * @return array<string, mixed>
 */
function lp_reverse_normalize_claude_vision_array(array $parsed): array
{
    if (!isset($parsed['icons']) || !is_array($parsed['icons'])) {
        $parsed['icons'] = [];
    }

    if (!isset($parsed['replacement']) || !is_array($parsed['replacement'])) {
        $parsed['replacement'] = ['mode' => 'full', 'reason_ja' => ''];
    } else {
        $rm = isset($parsed['replacement']['mode']) ? strtolower(trim((string) $parsed['replacement']['mode'])) : 'full';
        $parsed['replacement']['mode'] = in_array($rm, ['full', 'placeholder'], true) ? $rm : 'full';
        $parsed['replacement']['reason_ja'] = isset($parsed['replacement']['reason_ja'])
            ? (string) $parsed['replacement']['reason_ja'] : '';
    }

    $allowedTypes = ['photo', 'illustration', 'ui', 'composite', 'gradient', 'bordered', 'badge'];
    $ty = isset($parsed['type']) ? strtolower(trim((string) $parsed['type'])) : '';
    if (!in_array($ty, $allowedTypes, true)) {
        $parsed['type'] = 'composite';
    } else {
        $parsed['type'] = $ty;
    }

    if ($parsed['type'] === 'gradient') {
        if (!isset($parsed['gradient']) || !is_array($parsed['gradient'])) {
            $parsed['gradient'] = [];
        }
        $g = $parsed['gradient'];
        $parsed['gradient'] = [
            'type'   => in_array($g['type'] ?? '', ['linear', 'radial'], true) ? $g['type'] : 'linear',
            'angle'  => isset($g['angle']) ? (int) $g['angle'] : 180,
            'colors' => (isset($g['colors']) && is_array($g['colors'])) ? $g['colors'] : [],
        ];
    }

    if ($parsed['type'] === 'bordered') {
        if (!isset($parsed['border']) || !is_array($parsed['border'])) {
            $parsed['border'] = [];
        }
        $b = $parsed['border'];
        $parsed['border'] = [
            'color'             => isset($b['color']) ? (string) $b['color'] : '#000000',
            'width_pct'         => isset($b['width_pct']) ? (float) $b['width_pct'] : 0.05,
            'inner_type'        => in_array($b['inner_type'] ?? '', ['photo', 'illustration'], true) ? $b['inner_type'] : 'photo',
            'inner_description' => isset($b['inner_description']) ? (string) $b['inner_description'] : '',
        ];
    }

    if ($parsed['type'] === 'badge') {
        if (!isset($parsed['badge']) || !is_array($parsed['badge'])) {
            $parsed['badge'] = [];
        }
        $bd = $parsed['badge'];
        $parsed['badge'] = [
            'shape'      => in_array($bd['shape'] ?? '', ['circle', 'pill', 'ribbon', 'rect'], true) ? $bd['shape'] : 'circle',
            'bg_color'   => isset($bd['bg_color']) ? (string) $bd['bg_color'] : '#e63c3c',
            'text_color' => isset($bd['text_color']) ? (string) $bd['text_color'] : '#ffffff',
        ];
    }

    return $parsed;
}
