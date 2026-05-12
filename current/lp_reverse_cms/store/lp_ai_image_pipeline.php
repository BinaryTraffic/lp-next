<?php

declare(strict_types=1);

/**
 * LP 画像の AI 置換テスト用パイプライン（Vision → HF 生成 or プレースホルダ）。
 *
 * POST JSON:
 * - image_local_rel: 必須。例 "output/ws_<32hex>/assets/foo.jpg"（CMS ルート相対）
 * - industry: 任意（Vision の業種ヒント）
 * - force_placeholder: true のとき常にサイズ付き PNG のみ出力
 * - memo_text: 任意。UI / composite かつ Vision が texts を返したとき、焼き込み文言をメモで上書き
 * - anthropic_api_key / api_key: 任意
 *
 * 成功例:
 * - outcome: replaced_photo | replaced_illustration | needs_composite | placeholder | vision_only
 * - vision: 正規化済み解析 JSON
 * - url: 生成画像（プレースホルダ or HF）
 * - image_composite_post_body: needs_composite のとき 2 段目用
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/api_usage_log.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/claude_vision_analyze.php';
require_once __DIR__ . '/../lib/hf_image_client.php';
require_once __DIR__ . '/../lib/placeholder_png.php';
require_once __DIR__ . '/../lib/lp_image_memo_merge.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

lp_reverse_load_env();

$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    echo json_encode(['error' => 'CMS ルートの解決に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rel = isset($in['image_local_rel']) ? trim(str_replace('\\', '/', (string) $in['image_local_rel']), '/') : '';
$industry = isset($in['industry']) ? trim((string) $in['industry']) : '';
$memoText = isset($in['memo_text']) ? (string) $in['memo_text'] : '';
$forcePh = !empty($in['force_placeholder']);
$bodyKey = isset($in['anthropic_api_key']) ? trim((string) $in['anthropic_api_key']) : (isset($in['api_key']) ? trim((string) $in['api_key']) : '');

if ($rel === '' || !preg_match('#^output/ws_[a-f0-9]{32}/#', $rel)) {
    http_response_code(400);
    echo json_encode(['error' => 'image_local_rel は output/ws_<32桁hex>/... の形式が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$abs = $cmsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
$outDir = realpath(LpWorkspace::outputDir($cmsRoot));
$real = realpath(dirname($abs));
if ($outDir === false || $real === false || !str_starts_with($real, $outDir) || !is_file($abs)) {
    http_response_code(400);
    echo json_encode(['error' => '画像ファイルが見つからないか、ワークスペース外です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$serverKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
$denyClient = getenv('ANTHROPIC_DENY_CLIENT_KEY') === '1';
$anthKey = '';
if ($serverKey !== '') {
    $anthKey = $serverKey;
} elseif (!$denyClient && $bodyKey !== '') {
    $anthKey = $bodyKey;
}

if ($anthKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'ANTHROPIC_API_KEY が未設定です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imgBin = file_get_contents($abs);
if ($imgBin === false || strlen($imgBin) < 32) {
    http_response_code(400);
    echo json_encode(['error' => '画像の読み込みに失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

$info = @getimagesizefromstring($imgBin);
$imgW = is_array($info) ? (int) ($info[0] ?? 0) : 0;
$imgH = is_array($info) ? (int) ($info[1] ?? 0) : 0;
$mime = 'image/jpeg';
if (is_array($info) && isset($info['mime'])) {
    $m = (string) $info['mime'];
    $mime = match (true) {
        str_contains($m, 'png')  => 'image/png',
        str_contains($m, 'webp') => 'image/webp',
        str_contains($m, 'gif')  => 'image/gif',
        default                   => 'image/jpeg',
    };
}

$vr = lp_reverse_claude_vision_request($imgBin, $mime, $imgW, $imgH, $anthKey, $industry);

$inTok = 0;
$outTok = 0;
if ($vr['ok']) {
    $inTok = (int) ($vr['usage']['input_tokens'] ?? 0);
    $outTok = (int) ($vr['usage']['output_tokens'] ?? 0);
    $estA = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);
    lp_reverse_api_usage_record([
        'env_var'   => 'ANTHROPIC_API_KEY',
        'provider'  => 'anthropic',
        'operation' => 'lp_ai_image_pipeline',
        'ok'        => true,
        'http_code' => 200,
        'meta'      => [
            'model'      => 'claude-sonnet-4-6',
            'key_source' => $serverKey !== '' ? 'server_env' : 'client_body',
        ],
        'usage'         => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
        'estimated_usd' => $estA,
    ]);
}

if (!$vr['ok']) {
    $estA = lp_reverse_api_usage_estimate_anthropic_usd($inTok, $outTok);
    lp_reverse_api_usage_record([
        'env_var'   => 'ANTHROPIC_API_KEY',
        'provider'  => 'anthropic',
        'operation' => 'lp_ai_image_pipeline',
        'ok'        => false,
        'http_code' => $vr['http_code'] ?? 502,
        'meta'      => [
            'model'         => 'claude-sonnet-4-6',
            'key_source'    => $serverKey !== '' ? 'server_env' : 'client_body',
            'error_message' => $vr['error'] ?? '',
        ],
        'usage'         => ['input_tokens' => $inTok, 'output_tokens' => $outTok],
        'estimated_usd' => $estA,
    ]);
    http_response_code($vr['http_code'] ?? 502);
    echo json_encode(['error' => $vr['error'] ?? 'Vision エラー'], JSON_UNESCAPED_UNICODE);
    exit;
}

$parsed = json_decode($vr['text'], true);
if (!is_array($parsed)) {
    http_response_code(502);
    echo json_encode(['error' => 'Vision JSON のパースに失敗', 'raw' => $vr['text']], JSON_UNESCAPED_UNICODE);
    exit;
}

$vision = lp_reverse_normalize_claude_vision_array($parsed);
$memoText = trim($memoText);

if ($memoText !== '') {
    $tyMemo = (string) ($vision['type'] ?? '');
    if ($tyMemo !== 'ui' && $tyMemo !== 'composite') {
        http_response_code(400);
        echo json_encode([
            'error' => 'memo_text によるテキスト焼き込みは Vision の type が ui または composite の画像のみ対応です。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $textsCheck = $vision['texts'] ?? [];
    if (!is_array($textsCheck) || $textsCheck === []) {
        http_response_code(400);
        echo json_encode([
            'error' => 'この画像では Vision が texts を返さなかったため、メモからテキストを焼き込めません。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $vision = lp_reverse_apply_memo_to_vision_texts($vision, $memoText);
}

$replacementMode = $vision['replacement']['mode'] ?? 'full';
$reasonJa = (string) ($vision['replacement']['reason_ja'] ?? '');
$type = (string) ($vision['type'] ?? 'composite');

$webSrc = '/' . $rel;

function lp_pipe_fail_placeholder(
    string $cmsRoot,
    int $w,
    int $h,
    string $why,
    array $vision,
): void {
    $url = lp_reverse_save_placeholder_png(
        $cmsRoot,
        max(64, $w) ?: 512,
        max(64, $h) ?: 384,
        '[ PLACEHOLDER ]',
        ['HF or step failed', preg_replace('/[^\x20-\x7E]/u', '?', $why)],
    );
    echo json_encode([
        'outcome' => 'placeholder',
        'url'     => $url,
        'vision'  => $vision,
        'note'    => $why,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$emitPlaceholder = static function (
    string $cmsRoot,
    int $w,
    int $h,
    string $sub,
    array $extra = [],
): string {
    $asciiReason = $sub !== '' ? preg_replace('/[^\x20-\x7E]/u', '?', $sub) : 'manual / pipeline';

    return lp_reverse_save_placeholder_png(
        $cmsRoot,
        $w,
        $h,
        '[ PLACEHOLDER ]',
        array_merge([$asciiReason], $extra),
    );
};

if ($forcePh || $replacementMode === 'placeholder') {
    $url = $emitPlaceholder($cmsRoot, $imgW ?: 512, $imgH ?: 384, $reasonJa !== '' ? $reasonJa : 'replacement=placeholder', [$type]);
    echo json_encode([
        'outcome'   => 'placeholder',
        'url'       => $url,
        'vision'    => $vision,
        'note'      => 'プレースホルダ PNG（サイズ表記）。HTML の src を手動で差し替えてください。',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$hfToken = trim((string) (getenv('HUGGINGFACE_API_TOKEN') ?: getenv('HF_TOKEN') ?: ''));
$hfDeny = getenv('HF_DENY_CLIENT_KEY') === '1';
$hfKeyBody = isset($in['huggingface_api_key']) ? trim((string) $in['huggingface_api_key']) : '';
if ($hfToken === '' && !$hfDeny && $hfKeyBody !== '') {
    $hfToken = $hfKeyBody;
}

$hfServerToken = trim((string) (getenv('HUGGINGFACE_API_TOKEN') ?: getenv('HF_TOKEN') ?: ''));
$hfKeySource = ($hfServerToken !== '' && ($hfKeyBody === '' || $hfToken === $hfServerToken))
    ? 'server_env'
    : 'client_body';

if ($type === 'photo' || $type === 'illustration') {
    if ($hfToken === '') {
        lp_pipe_fail_placeholder($cmsRoot, $imgW, $imgH, 'HF トークン未設定のためプレースホルダにフォールバック', $vision);
    }
    $ill = (string) ($vision['illustration_style'] ?? 'none');
    $bg = (string) ($vision['background_description'] ?? '');
    $mode = $type === 'photo' ? 'photo' : 'illustration';
    $hf = lp_reverse_hf_save_generated_image($cmsRoot, $mode, '', $bg, $ill, $imgW, $imgH, $hfToken, $hfKeySource);
    if (!$hf['ok']) {
        lp_pipe_fail_placeholder($cmsRoot, $imgW, $imgH, $hf['error'] ?? 'HF', $vision);
    }
    echo json_encode([
        'outcome' => $type === 'photo' ? 'replaced_photo' : 'replaced_illustration',
        'url'     => $hf['url'],
        'vision'  => $vision,
        'note'    => 'HTML の該当 img src をこの url に置換してください。',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($type === 'ui' || $type === 'composite') {
    if ($hfToken === '') {
        lp_pipe_fail_placeholder($cmsRoot, $imgW, $imgH, 'UI/合成は HF 背景が必要（トークン未設定）', $vision);
    }
    $ill = (string) ($vision['illustration_style'] ?? 'none');
    $bg = (string) ($vision['background_description'] ?? '');
    $hf = lp_reverse_hf_save_generated_image($cmsRoot, 'composite', '', $bg, $ill, $imgW, $imgH, $hfToken, $hfKeySource);
    if (!$hf['ok']) {
        lp_pipe_fail_placeholder($cmsRoot, $imgW, $imgH, $hf['error'] ?? 'HF composite bg', $vision);
    }

    $body = [
        'source_url'      => $webSrc,
        'background_url'  => $hf['url'],
        'width'           => $imgW,
        'height'          => $imgH,
        'texts'           => $vision['texts'] ?? [],
        'icons'           => $vision['icons'] ?? [],
        'crop_to_button'  => false,
    ];

    echo json_encode([
        'outcome'                      => 'needs_composite',
        'vision'                       => $vision,
        'hf_background_url'            => $hf['url'],
        'image_composite_post_body'    => $body,
        'note'                         => 'store/image_composite.php に image_composite_post_body を POST し、返却 url を HTML に反映してください。',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$url = $emitPlaceholder($cmsRoot, $imgW ?: 512, $imgH ?: 384, 'type=' . $type . ' (pipeline v1 は placeholder)', [$reasonJa]);
echo json_encode([
    'outcome' => 'placeholder',
    'url'     => $url,
    'vision'  => $vision,
    'note'    => 'gradient / bordered / badge 等はこの版ではプレースホルダ。別 API 接続は今後。',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
