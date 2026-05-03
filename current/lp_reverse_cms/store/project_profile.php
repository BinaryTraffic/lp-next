<?php

declare(strict_types=1);

/**
 * 企業・LPプロフィール（将来の AI 自動生成の入力源）。
 * エンティティ関係図は仕様確定後にドキュメント化予定。
 * data/ws_{session}/lp_project_profile.json
 */
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dataDir = LpWorkspace::dataDir(dirname(__DIR__));
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$path = $dataDir . 'lp_project_profile.json';

/** @return array<string, string> */
function lp_project_profile_defaults(): array
{
    return [
        'company_name'         => '',
        'representative_name'  => '',
        'postal_code'          => '',
        'address_pref'         => '',
        'address_city'         => '',
        'address_line'         => '',
        'address_building'     => '',
        'phone_main'           => '',
        'phone_fax'            => '',
        'phone_tollfree'       => '',
        'appeal_points'        => '',
        'lp_tone'              => '',
        'brand_color'          => '',
        'company_url'          => '',
        'sns_x'                => '',
        'sns_line'             => '',
        'sns_instagram'        => '',
        'sns_facebook'         => '',
        'sns_youtube'          => '',
        'sns_tiktok'           => '',
        'company_industry'     => '',
        'corporate_number'     => '',
        'company_capital'      => '',
        'company_history'      => '',
    ];
}

/** @param array<string, mixed> $in */
function lp_project_profile_normalize(array $in): array
{
    $out = lp_project_profile_defaults();
    $max = [
        'company_name'        => 200,
        'representative_name' => 120,
        'postal_code'         => 16,
        'address_pref'        => 48,
        'address_city'        => 120,
        'address_line'        => 200,
        'address_building'    => 200,
        'phone_main'          => 48,
        'phone_fax'           => 48,
        'phone_tollfree'      => 48,
        'appeal_points'       => 8000,
        'lp_tone'             => 80,
        'brand_color'         => 32,
        'company_url'         => 500,
        'sns_x'               => 500,
        'sns_line'            => 500,
        'sns_instagram'       => 500,
        'sns_facebook'        => 500,
        'sns_youtube'         => 500,
        'sns_tiktok'          => 500,
        'company_industry'    => 120,
        'corporate_number'    => 13,
        'company_capital'     => 120,
        'company_history'     => 8000,
    ];

    foreach ($max as $key => $lim) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $v = is_string($in[$key]) ? trim($in[$key]) : '';
        if (mb_strlen($v) > $lim) {
            $v = mb_substr($v, 0, $lim);
        }
        $out[$key] = $v;
    }

    if ($out['brand_color'] !== '' && !preg_match('/^#[0-9A-Fa-f]{3,8}$/', $out['brand_color'])) {
        $out['brand_color'] = '';
    }

    $cn = preg_replace('/\D/', '', (string) ($out['corporate_number'] ?? ''));
    $out['corporate_number'] = strlen($cn) === 13 ? $cn : '';

    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = lp_project_profile_defaults();
    if (is_readable($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            unset($decoded['updated_at'], $decoded['_meta']);
            $data = lp_project_profile_normalize(array_merge($data, $decoded));
        }
    }
    echo json_encode([
        'ok'        => true,
        'profile'   => $data,
        'saved_at'  => is_readable($path) ? date('Y-m-d H:i:s', (int) filemtime($path)) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $in  = $raw ? json_decode($raw, true) : null;
    if (!is_array($in)) {
        throw new InvalidArgumentException('JSON が必要です');
    }
    $profile = lp_project_profile_normalize($in);
    $payload = $profile;
    $payload['updated_at'] = date('c');

    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo json_encode(['ok' => true, 'profile' => $profile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
