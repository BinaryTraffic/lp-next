<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpGenerator.php';
require_once __DIR__ . '/../lib/LpOutputAudit.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $cmsRoot       = dirname(__DIR__);
    $dataDir       = LpWorkspace::dataDir($cmsRoot);
    $outputDir     = LpWorkspace::outputDir($cmsRoot);
    $structureFile = $dataDir . 'lp_structure.json';
    $clientFile    = $dataDir . 'client_data.json';

    if (!file_exists($structureFile)) {
        throw new RuntimeException('サイト構造JSONが見つかりません。先にURLを解析してください。');
    }

    $structure = json_decode((string) file_get_contents($structureFile), true);
    if (!is_array($structure)) {
        throw new RuntimeException('サイト構造JSONの読み込みに失敗しました。');
    }

    $clientData = [];
    if (file_exists($clientFile)) {
        $decoded = json_decode((string) file_get_contents($clientFile), true);
        if (is_array($decoded)) {
            $clientData = $decoded;
        }
    }

    $generator = new LpGenerator();
    $html      = $generator->generate($structure, $clientData, $dataDir);

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $outputFile = $outputDir . 'index.html';
    file_put_contents($outputFile, $html);

    foreach ($structure['internal_pages'] ?? [] as $page) {
        if (empty($page['fetch_ok']) || empty($page['structure_file']) || empty($page['output_file'])) {
            continue;
        }
        $subPath = $dataDir . $page['structure_file'];
        if (!is_readable($subPath)) {
            continue;
        }
        $decoded = json_decode((string) file_get_contents($subPath), true);
        if (!is_array($decoded)) {
            continue;
        }
        $innerHtml = $generator->generate($decoded, [], $dataDir);
        $target    = $outputDir . $page['output_file'];
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        file_put_contents($target, $innerHtml);
    }

    LpOutputAudit::persist($outputFile, $dataDir);

    echo json_encode([
        'success'  => true,
        'size'     => strlen($html),
        'message'  => 'サイトを生成しました。',
        'preview'  => LpWorkspace::outputRelIndex(),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
