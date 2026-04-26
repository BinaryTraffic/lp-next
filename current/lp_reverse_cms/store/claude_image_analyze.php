<?php

declare(strict_types=1);

/**
 * Claude Vision API proxy — 画像内テキストの座標抽出。
 *
 * POST JSON: { "image_url", "width", "height", "api_key"? }
 * 返却: {
 *   "type": "photo|illustration|ui|composite|gradient|bordered|badge",
 *   "texts": [...], "icons": [...],
 *   "illustration_style": "...",
 *   "background_description": "...",
 *   "gradient": { ... },   // type=gradient のみ
 *   "border": { ... },    // type=bordered のみ
 *   "badge": { ... }      // type=badge のみ
 * }
 *
 * bordered 後続フロー（実装は呼出元）:
 * ① 本レスポンスで border.width_pct 等を取得
 * ② inner 寸法を算出し hf_image_proxy で inner 画像生成
 * ③ image_composite.php に border_fill + background_url(inner) を POST
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/api_usage_log.php';
require_once __DIR__ . '/../lib/icon_map.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imageUrl = isset($in['image_url']) ? trim((string) $in['image_url']) : '';
$imgW     = isset($in['width'])     ? (int) $in['width']              : 0;
$imgH     = isset($in['height'])    ? (int) $in['height']             : 0;
$bodyKey  = isset($in['api_key'])   ? trim((string) $in['api_key'])   : '';

$serverKey  = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
$denyClient = getenv('ANTHROPIC_DENY_CLIENT_KEY') === '1';

$apiKey = '';
if ($serverKey !== '') {
    $apiKey = $serverKey;
} elseif (!$denyClient && $bodyKey !== '') {
    $apiKey = $bodyKey;
}

if ($imageUrl === '') {
    http_response_code(400);
    echo json_encode(['error' => 'image_url が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'ANTHROPIC_API_KEY が未設定です'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 画像をbase64に変換
$ch = curl_init($imageUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$imgBin  = curl_exec($ch);
$imgMime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
curl_close($ch);

if ($imgBin === false || strlen($imgBin) < 100) {
    http_response_code(502);
    echo json_encode(['error' => '画像の取得に失敗しました: ' . $imageUrl], JSON_UNESCAPED_UNICODE);
    exit;
}

// MIMEをClaudeが受け付ける形式に正規化
$mime = match(true) {
    str_contains($imgMime, 'png')  => 'image/png',
    str_contains($imgMime, 'webp') => 'image/webp',
    str_contains($imgMime, 'gif')  => 'image/gif',
    default                        => 'image/jpeg',
};

$sizeHint = ($imgW > 0 && $imgH > 0)
    ? "画像の実寸は {$imgW}×{$imgH}px です。"
    : '';

$iconLabelList = implode(', ', array_keys(IconMap::ICON_MAP));

$prompt = <<<PROMPT
この画像を解析してください。{$sizeHint}

以下のJSONのみを返してください（説明文・コードブロック不要）:
{
  "type": "photo | illustration | ui | composite | gradient | bordered | badge",
  "illustration_style": "line_art | flat | watercolor | none",
  "background_description": "背景の視覚的説明（英語、FLUX生成プロンプト用）",
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
      "content": "テキスト内容",
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
]);

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
$curlErr  = curl_error($ch);
$code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    lp_reverse_api_usage_record([
        'env_var' => 'ANTHROPIC_API_KEY',
        'provider' => 'anthropic',
        'operation' => 'messages',
        'ok' => false,
        'http_code' => 502,
        'meta' => [
            'model' => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'curl_error' => $curlErr,
        ],
        'usage' => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'Anthropic 接続エラー: ' . $curlErr], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
$usageBlock = is_array($data) && isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
$inTok = (int) ($usageBlock['input_tokens'] ?? 0);
$outTok = (int) ($usageBlock['output_tokens'] ?? 0);
$estAnth = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);

if ($code !== 200 || !isset($data['content'][0]['text'])) {
    $msg = is_array($data) && isset($data['error']['message'])
        ? (string) $data['error']['message']
        : mb_substr($response, 0, 400);
    lp_reverse_api_usage_record([
        'env_var' => 'ANTHROPIC_API_KEY',
        'provider' => 'anthropic',
        'operation' => 'messages',
        'ok' => false,
        'http_code' => $code,
        'meta' => [
            'model' => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'error_message' => $msg,
        ],
        'usage' => [
            'input_tokens' => $inTok,
            'output_tokens' => $outTok,
        ],
        'estimated_usd' => $estAnth,
    ]);
    http_response_code($code >= 400 ? $code : 502);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ClaudeのJSONレスポンスをそのまま返す
$text   = trim($data['content'][0]['text']);
$parsed = json_decode($text, true);

if (!is_array($parsed)) {
    lp_reverse_api_usage_record([
        'env_var' => 'ANTHROPIC_API_KEY',
        'provider' => 'anthropic',
        'operation' => 'messages',
        'ok' => false,
        'http_code' => $code,
        'meta' => [
            'model' => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'reason' => 'vision_json_parse_failed',
        ],
        'usage' => [
            'input_tokens' => $inTok,
            'output_tokens' => $outTok,
        ],
        'estimated_usd' => $estAnth,
    ]);
    http_response_code(502);
    echo json_encode([
        'error'    => 'Claude応答のJSONパースに失敗しました',
        'raw'      => $text,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_api_usage_record([
    'env_var' => 'ANTHROPIC_API_KEY',
    'provider' => 'anthropic',
    'operation' => 'messages',
    'ok' => true,
    'http_code' => $code,
    'meta' => [
        'model' => 'claude-sonnet-4-6',
        'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
    ],
    'usage' => [
        'input_tokens' => $inTok,
        'output_tokens' => $outTok,
    ],
    'estimated_usd' => $estAnth,
]);

if (!isset($parsed['icons']) || !is_array($parsed['icons'])) {
    $parsed['icons'] = [];
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

echo json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
