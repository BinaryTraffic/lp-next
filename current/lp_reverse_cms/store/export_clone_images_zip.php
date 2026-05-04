<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/cms_editor_auth.php';
require_once dirname(__DIR__) . '/lib/LpCloneImagePack.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed';
    exit;
}

$cmsRoot = dirname(__DIR__);

if (lp_reverse_resolve_workspace_editor($cmsRoot) === false) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$slug = 'clone_images';
$urlFile = LpWorkspace::dataDir($cmsRoot) . 'source_url.txt';

if (is_readable($urlFile)) {
    $u = parse_url(trim((string) file_get_contents($urlFile)));

    if (is_array($u) && !empty($u['host'])) {
        $slug = preg_replace('/[^\w.-]+/', '_', (string) $u['host']) ?: 'clone_images';
    }
}

try {
    LpCloneImagePack::streamZip($cmsRoot, $slug);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
