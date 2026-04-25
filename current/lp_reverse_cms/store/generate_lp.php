<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/LpGenerator.php';
require_once __DIR__ . '/../lib/LpOutputAudit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $dataDir       = __DIR__ . '/../data/';
    $outputDir     = __DIR__ . '/../output/';
    $structureFile = $dataDir . 'lp_structure.json';
    $clientFile    = $dataDir . 'client_data.json';

    if (!file_exists($structureFile)) {
        throw new RuntimeException('LP構造JSONが見つかりません。先にURLを解析してください。');
    }

    $structure = json_decode((string) file_get_contents($structureFile), true);
    if (!is_array($structure)) {
        throw new RuntimeException('LP構造JSONの読み込みに失敗しました。');
    }

    $clientData = [];
    if (file_exists($clientFile)) {
        $decoded = json_decode((string) file_get_contents($clientFile), true);
        if (is_array($decoded)) {
            $clientData = $decoded;
        }
    }

    $generator = new LpGenerator();
    $html      = $generator->generate($structure, $clientData);

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $outputFile = $outputDir . 'index.html';
    file_put_contents($outputFile, $html);

    LpOutputAudit::persist($outputFile, $dataDir);

    echo json_encode([
        'success'  => true,
        'size'     => strlen($html),
        'message'  => 'LPを生成しました。',
        'preview'  => 'output/index.html',
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
