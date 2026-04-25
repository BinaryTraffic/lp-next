<?php
declare(strict_types=1);

$outputFile = __DIR__ . '/output/index.html';

if (!file_exists($outputFile)) {
    header('Location: index.php');
    exit;
}

$filename = 'lp_' . date('Ymd_His') . '.html';

header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($outputFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($outputFile);
exit;
