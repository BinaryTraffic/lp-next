<?php

declare(strict_types=1);

/**
 * GET: .env の読み可否と主要キーの有無（値は返さない）。
 * ブラウザツールが「サーバー鍵あり」と判断する用。
 */
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/api_usage_log.php';
require_once __DIR__ . '/../lib/icon_map.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

lp_reverse_load_env();

$path = lp_reverse_env_file_path();
$resolved = realpath($path);

$has = static function (string $k): bool {
    $v = getenv($k);

    return is_string($v) && trim($v) !== '';
};

$gd = extension_loaded('gd');
$imagick = extension_loaded('imagick');
$freetype = $gd && function_exists('imagettfbbox');

echo json_encode([
    'env_relative'      => 'lp_reverse_cms/.env',
    'env_path_resolved' => $resolved !== false ? $resolved : $path,
    'env_readable'      => is_readable($path),
    'OPENAI_API_KEY'    => $has('OPENAI_API_KEY'),
    'ANTHROPIC_API_KEY' => $has('ANTHROPIC_API_KEY'),
    'HUGGINGFACE_API_TOKEN' => $has('HUGGINGFACE_API_TOKEN') || $has('HF_TOKEN'),
    'php_gd'            => $gd,
    'php_gd_freetype'   => $freetype,
    'php_imagick'       => $imagick,
    /** icons[] 合成に Imagick が必要（GD のみでもテキスト合成は可） */
    'image_composite_icons_ok' => $imagick,
    'icon_labels'       => array_keys(IconMap::ICON_MAP),
    'api_usage_events'  => lp_reverse_api_usage_events_path(),
    'api_usage_totals'  => lp_reverse_api_usage_totals_path(),
    'api_usage_summary' => 'store/api_usage_summary.php',
    'lp_theme_json'     => 'store/lp_theme.php',
    'lp_theme_css'      => 'store/lp_theme.css.php',
], JSON_UNESCAPED_UNICODE);
