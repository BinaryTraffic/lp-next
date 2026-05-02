<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$pRaw = isset($_GET['p']) ? trim((string) $_GET['p']) : '';
if ($pRaw !== '' && strpos($pRaw, "\0") !== false) {
    http_response_code(400);
    exit;
}

$p = '/' . ltrim(trim(rawurldecode($pRaw)), '/');
$p = strtok($p, '?'); // disallow query concatenation attacks on p itself

if (!is_string($p) || $p === '/') {
    http_response_code(400);
    exit;
}

$sessionId = LpWorkspace::id();
if (strlen($sessionId) !== 32 || !ctype_xdigit($sessionId)) {
    http_response_code(403);
    exit;
}

$expectedPrefix = '/output/ws_' . $sessionId . '/';
if ($expectedPrefix === '//') {
    http_response_code(500);
    exit;
}

if (!str_starts_with($p, $expectedPrefix)) {
    http_response_code(403);
    exit;
}

$inside = substr($p, strlen($expectedPrefix));
if ($inside === '' || strpos(str_replace('\\', '/', $inside), '..') !== false) {
    http_response_code(400);
    exit;
}

$cmsRoot = realpath(dirname(__DIR__));
if ($cmsRoot === false) {
    http_response_code(500);
    exit;
}

$outputRoot = realpath(LpWorkspace::outputDir($cmsRoot));
if ($outputRoot === false || !is_dir($outputRoot)) {
    http_response_code(404);
    exit;
}

$candidateFs = str_replace('/', DIRECTORY_SEPARATOR, $inside);
$target      = realpath($outputRoot . DIRECTORY_SEPARATOR . $candidateFs);

/**
 * upload のバグ等で legacy となる sites//custom_images/up_*.jpg が渡されたとき、同一 ws に
 * そのファイルが1件だけあるなら復旧（複数クローンにある場合は 404）。
 */
$tryLegacyDoubleSlashSites = static function (
    string $insidePath,
    string $outputRootReal,
) /* : string|false */ {
    $unix = str_replace('\\', '/', $insidePath);
    if (!preg_match('#^sites/+custom_images/([^/]+)$#', $unix, $m)) {
        return false;
    }
    $basename = basename($m[1]);
    if ($basename === '' || $basename !== $m[1]) {
        return false;
    }
    $glob = glob(
        $outputRootReal . DIRECTORY_SEPARATOR . 'sites'
        . DIRECTORY_SEPARATOR . '*'
        . DIRECTORY_SEPARATOR . 'custom_images'
        . DIRECTORY_SEPARATOR . $basename,
        GLOB_NOSORT,
    );

    return is_array($glob) && count($glob) === 1 ? $glob[0] : false;
};

if (($target === false || !is_file($target))) {
    $alt = $tryLegacyDoubleSlashSites($inside, $outputRoot);
    if (is_string($alt) && is_file($alt)) {
        $target = realpath($alt);
    }
}

if ($target === false || !is_file($target)) {
    http_response_code(404);
    exit;
}

$outputNorm = str_replace('\\', '/', $outputRoot) . '/';
$targetNorm = str_replace('\\', '/', $target);

if (!str_starts_with($targetNorm, $outputNorm)) {
    http_response_code(403);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($target);
if ($mime === false || $mime === '') {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');

readfile($target);
