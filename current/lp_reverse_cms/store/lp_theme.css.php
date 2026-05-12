<?php

declare(strict_types=1);

/**
 * GET: :root 用 CSS カスタムプロパティ（LpTheme）。静的 HTML は本 URL を link すればテーマと同期。
 */
require_once __DIR__ . '/../lib/LpTheme.php';

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo '/* Method Not Allowed */';
    exit;
}

echo LpTheme::cssCustomProperties();
