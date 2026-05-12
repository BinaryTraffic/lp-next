<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/LpWorkspace.php';
require_once __DIR__ . '/lib/LpCloneContext.php';
require_once __DIR__ . '/lib/LpExportBundle.php';

$cmsRoot    = __DIR__;
$outputDir  = LpWorkspace::outputDir($cmsRoot);
$outputFile = $outputDir . 'index.html';

if (!file_exists($outputFile)) {
    header('Location: index.php');
    exit;
}

$type = strtolower(trim((string) ($_GET['type'] ?? 'bundle')));

if ($type === 'html') {
    $filename = 'lp_' . date('Ymd_His') . '.html';

    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($outputFile));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($outputFile);
    exit;
}

$slug = 'lp';
$urlFile = LpWorkspace::dataDir($cmsRoot) . 'source_url.txt';
if (is_readable($urlFile)) {
    $u = parse_url(trim((string) file_get_contents($urlFile)));
    if (is_array($u) && !empty($u['host'])) {
        $slug = preg_replace('/[^\w.-]+/', '_', $u['host']) ?: 'lp';
    }
}

LpExportBundle::streamZip($cmsRoot, $slug);
