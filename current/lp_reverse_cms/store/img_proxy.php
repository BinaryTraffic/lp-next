<?php

declare(strict_types=1);

/**
 * Same-origin image relay for Canvas blending (CORS workaround).
 * Fetches any http/https image server-side and returns it with
 * Access-Control-Allow-Origin: * so the browser can draw it to a canvas.
 * Auth required; only image/* responses are relayed.
 */

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';

try {
    lp_reverse_store_auth_actor($cmsRoot); // throws on unauthenticated
} catch (Throwable) {
    http_response_code(401);
    exit;
}

$url = trim((string) ($_GET['url'] ?? ''));
if (!preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo 'url param required (http/https)';
    exit;
}

$ctx = stream_context_create([
    'http' => [
        'method'          => 'GET',
        'timeout'         => 12,
        'follow_location' => 1,
        'max_redirects'   => 5,
        'user_agent'      => 'Mozilla/5.0 (compatible; LpReverseCMS/1.0)',
        'ignore_errors'   => true,
    ],
    'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
]);

$data = @file_get_contents($url, false, $ctx);
if ($data === false || $data === '') {
    http_response_code(502);
    echo 'fetch failed';
    exit;
}

$contentType = 'image/jpeg';
if (!empty($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (stripos((string) $h, 'content-type:') === 0) {
            $contentType = trim(substr((string) $h, 13));
            break;
        }
    }
}

// Only relay images
$ctBase = strtolower(explode(';', $contentType)[0]);
if (!str_starts_with($ctBase, 'image/')) {
    http_response_code(403);
    echo 'non-image content-type: ' . htmlspecialchars($ctBase);
    exit;
}

header('Content-Type: ' . $ctBase);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . strlen($data));
echo $data;
