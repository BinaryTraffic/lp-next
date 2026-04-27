<?php

declare(strict_types=1);

/**
 * 業種に合わせた LP テキストの一括置換草案（Claude Messages API）。
 *
 * POST JSON:
 * {
 *   "industry": "歯科クリニック",           // 必須・差し替え先の業種
 *   "tone": "落ち着いた信頼感",            // 任意・トーンのヒント
 *   "source_context": "元LPはペットサロン向け", // 任意
 *   "elements": [
 *     { "id": "elem_sec_0_1", "type": "heading", "label": "見出し", "original_text": "元の文言" }
 *   ],
 *   "api_key": "..."                       // 任意（.env の ANTHROPIC_API_KEY が無いとき）
 * }
 *
 * 成功 200:
 * {
 *   "industry": "...",
 *   "items": [
 *     { "id": "elem_sec_0_1", "original_text": "...", "replaced_text": "..." }
 *   ]
 * }
 *
 * 認証: claude_image_analyze.php と同じ（サーバ ANTHROPIC_API_KEY 優先、ANTHROPIC_DENY_CLIENT_KEY で body 鍵拒否）。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/api_usage_log.php';

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

$industry = isset($in['industry']) ? trim((string) $in['industry']) : '';
$tone     = isset($in['tone']) ? trim((string) $in['tone']) : '';
$sourceCx = isset($in['source_context']) ? trim((string) $in['source_context']) : '';
$bodyKey  = isset($in['api_key']) ? trim((string) $in['api_key']) : '';
/** @var mixed $elementsRaw */
$elementsRaw = $in['elements'] ?? null;

$serverKey  = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
$denyClient = getenv('ANTHROPIC_DENY_CLIENT_KEY') === '1';

$apiKey = '';
if ($serverKey !== '') {
    $apiKey = $serverKey;
} elseif (!$denyClient && $bodyKey !== '') {
    $apiKey = $bodyKey;
}

if ($industry === '') {
    http_response_code(400);
    echo json_encode(['error' => 'industry が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_array($elementsRaw) || $elementsRaw === []) {
    http_response_code(400);
    echo json_encode(['error' => 'elements は空でない配列である必要があります'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'ANTHROPIC_API_KEY が未設定です'], JSON_UNESCAPED_UNICODE);
    exit;
}

const TEXT_REPLACE_MAX_ELEMENTS = 60;
const TEXT_REPLACE_MAX_ORIG_LEN = 2000;

/** @var list<array{id: string, type: string, label: string, original_text: string}> $elementsNorm */
$elementsNorm = [];
foreach ($elementsRaw as $idx => $row) {
    if (!is_array($row)) {
        http_response_code(400);
        echo json_encode(['error' => 'elements[' . $idx . '] がオブジェクトではありません'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = isset($row['id']) ? trim((string) $row['id']) : '';
    $ot = isset($row['original_text']) ? (string) $row['original_text'] : '';
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'elements[' . $idx . '].id が空です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($ot) < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'elements[' . $idx . '].original_text が空です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($ot) > TEXT_REPLACE_MAX_ORIG_LEN) {
        http_response_code(400);
        echo json_encode(['error' => 'elements[' . $idx . '].original_text が長すぎます（最大 ' . TEXT_REPLACE_MAX_ORIG_LEN . ' 文字）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ty = isset($row['type']) ? trim((string) $row['type']) : '';
    $lb = isset($row['label']) ? trim((string) $row['label']) : '';
    $elementsNorm[] = [
        'id'             => $id,
        'type'           => $ty,
        'label'          => $lb,
        'original_text'  => $ot,
    ];
}

if (count($elementsNorm) > TEXT_REPLACE_MAX_ELEMENTS) {
    http_response_code(400);
    echo json_encode(['error' => 'elements は最大 ' . TEXT_REPLACE_MAX_ELEMENTS . ' 件です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$elementsJson = json_encode($elementsNorm, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$toneLine     = $tone !== '' ? "トーンの希望: {$tone}\n" : '';
$srcLine      = $sourceCx !== '' ? "元コンテンツの文脈: {$sourceCx}\n" : '';

$prompt = <<<PROMPT
あなたは日本語のLPコピーライターです。次の JSON 配列 `elements` は、参照ランディングページから抽出した編集可能テキストです。
各要素を、**業種「{$industry}」**の店舗・サービス向けランディングページとして自然な日本語に書き換えてください。

{$toneLine}{$srcLine}
ルール:
- 役割（見出し・本文・ボタンラベル等）は `type` と `label` を手掛かりに維持する。
- **固有名詞・実在ブランド・電話番号・URL・メールアドレスは新規に捏造しない。** 元に含まれる場合は業種に合わせて一般化するか、プレースホルダに置き換えてよい（例: 「お問い合わせはフォームから」）。
- 誇大・断定的医療効果など法令上問題になりそうな表現は避ける。
- 文字数は極端に増やさず、レイアウト上おおよそ同程度の長さを目安にする。

入力 elements:
{$elementsJson}

**応答は次の JSON オブジェクトのみ**（説明文・マークダウン・コードフェンス禁止）:
{
  "items": [
    { "id": "（入力と同一）", "replaced_text": "書き換え後の本文（改行・<br>は元に合わせ、HTMLタグは付けない）" }
  ]
}

`items` は入力の全要素について **id ごとに1件ずつ**、**件数も入力と同じ**にすること。順序は入力と同じ推奨。
PROMPT;

$payload = json_encode([
    'model'       => 'claude-sonnet-4-6',
    'max_tokens'  => 8192,
    'temperature' => 0.4,
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
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    lp_reverse_api_usage_record([
        'env_var'   => 'ANTHROPIC_API_KEY',
        'provider'  => 'anthropic',
        'operation' => 'text_replace',
        'ok'        => false,
        'http_code' => 502,
        'meta'      => [
            'model'        => 'claude-sonnet-4-6',
            'key_source'   => $serverKey !== '' ? 'server_env' : 'client_body',
            'curl_error'   => $curlErr,
        ],
        'usage'         => [],
        'estimated_usd' => 0.0,
    ]);
    http_response_code(502);
    echo json_encode(['error' => 'Anthropic 接続エラー: ' . $curlErr], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
$usageBlock = is_array($data) && isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
$inTok      = (int) ($usageBlock['input_tokens'] ?? 0);
$outTok     = (int) ($usageBlock['output_tokens'] ?? 0);
$estAnth    = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);

if ($code !== 200 || !isset($data['content'][0]['text'])) {
    $msg = is_array($data) && isset($data['error']['message'])
        ? (string) $data['error']['message']
        : mb_substr($response, 0, 400);
    lp_reverse_api_usage_record([
        'env_var'   => 'ANTHROPIC_API_KEY',
        'provider'  => 'anthropic',
        'operation' => 'text_replace',
        'ok'        => false,
        'http_code' => $code,
        'meta'      => [
            'model'          => 'claude-sonnet-4-6',
            'key_source'     => $serverKey !== '' ? 'server_env' : 'client_body',
            'error_message'  => $msg,
        ],
        'usage' => [
            'input_tokens'  => $inTok,
            'output_tokens' => $outTok,
        ],
        'estimated_usd' => $estAnth,
    ]);
    http_response_code($code >= 400 ? $code : 502);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$text   = trim((string) $data['content'][0]['text']);
$parsed = json_decode($text, true);

if (!is_array($parsed) || !isset($parsed['items']) || !is_array($parsed['items'])) {
    lp_reverse_api_usage_record([
        'env_var'   => 'ANTHROPIC_API_KEY',
        'provider'  => 'anthropic',
        'operation' => 'text_replace',
        'ok'        => false,
        'http_code' => $code,
        'meta'      => [
            'model'      => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'reason'     => 'text_replace_json_parse_failed',
        ],
        'usage' => [
            'input_tokens'  => $inTok,
            'output_tokens' => $outTok,
        ],
        'estimated_usd' => $estAnth,
    ]);
    http_response_code(502);
    echo json_encode([
        'error' => 'Claude 応答の JSON パースに失敗しました',
        'raw'   => $text,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @var array<string, string> $byId */
$byId = [];
foreach ($parsed['items'] as $i => $it) {
    if (!is_array($it)) {
        continue;
    }
    $rid = isset($it['id']) ? trim((string) $it['id']) : '';
    $rt  = isset($it['replaced_text']) ? trim((string) $it['replaced_text']) : '';
    if ($rid !== '' && $rt !== '') {
        $byId[$rid] = $rt;
    }
}

$outItems = [];
foreach ($elementsNorm as $el) {
    $eid = $el['id'];
    if (!isset($byId[$eid])) {
        lp_reverse_api_usage_record([
            'env_var'   => 'ANTHROPIC_API_KEY',
            'provider'  => 'anthropic',
            'operation' => 'text_replace',
            'ok'        => false,
            'http_code' => $code,
            'meta'      => [
                'model'      => 'claude-sonnet-4-6',
                'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
                'reason'     => 'missing_id_in_model_response',
                'missing_id' => $eid,
            ],
            'usage' => [
                'input_tokens'  => $inTok,
                'output_tokens' => $outTok,
            ],
            'estimated_usd' => $estAnth,
        ]);
        http_response_code(502);
        echo json_encode([
            'error' => 'モデル応答に id が欠落しています: ' . $eid,
            'raw'   => $text,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $outItems[] = [
        'id'             => $eid,
        'original_text'  => $el['original_text'],
        'replaced_text'  => $byId[$eid],
    ];
}

if (count($byId) !== count($elementsNorm)) {
    lp_reverse_api_usage_record([
        'env_var'   => 'ANTHROPIC_API_KEY',
        'provider'  => 'anthropic',
        'operation' => 'text_replace',
        'ok'        => false,
        'http_code' => $code,
        'meta'      => [
            'model'      => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
            'reason'     => 'extra_items_in_model_response',
        ],
        'usage' => [
            'input_tokens'  => $inTok,
            'output_tokens' => $outTok,
        ],
        'estimated_usd' => $estAnth,
    ]);
    http_response_code(502);
    echo json_encode([
        'error' => 'モデル応答の items 件数が入力と一致しません',
        'raw'   => $text,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_api_usage_record([
    'env_var'   => 'ANTHROPIC_API_KEY',
    'provider'  => 'anthropic',
    'operation' => 'text_replace',
    'ok'        => true,
    'http_code' => $code,
    'meta'      => [
        'model'           => 'claude-sonnet-4-6',
        'key_source'      => $serverKey !== '' ? 'server_env' : 'client_body',
        'element_count'   => count($elementsNorm),
    ],
    'usage' => [
        'input_tokens'  => $inTok,
        'output_tokens' => $outTok,
    ],
    'estimated_usd' => $estAnth,
]);

echo json_encode([
    'industry' => $industry,
    'items'    => $outItems,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
