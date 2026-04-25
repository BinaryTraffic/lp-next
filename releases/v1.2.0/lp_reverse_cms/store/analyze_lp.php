<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpAnalyzer.php';
require_once __DIR__ . '/../lib/LpMapper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $dataDir  = __DIR__ . '/../data/';
    $htmlFile = $dataDir . 'fetched.html';
    $urlFile  = $dataDir . 'source_url.txt';

    if (!file_exists($htmlFile)) {
        throw new RuntimeException('HTMLファイルが見つかりません。先にURLからHTMLを取得してください。');
    }

    $html      = file_get_contents($htmlFile);
    $sourceUrl = file_exists($urlFile) ? trim((string) file_get_contents($urlFile)) : '';

    $analyzer  = new LpAnalyzer();
    $structure = $analyzer->analyze($html, $sourceUrl);

    $mapper    = new LpMapper();
    $structure = $mapper->enrich($structure);

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    file_put_contents(
        $dataDir . 'lp_structure.json',
        json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    echo json_encode([
        'success'        => true,
        'section_count'  => count($structure['sections']),
        'total_elements' => $structure['total_elements'] ?? 0,
        'meta'           => $structure['meta'],
        'message'        => '解析が完了しました。',
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
