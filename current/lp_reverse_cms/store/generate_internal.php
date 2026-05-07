<?php

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../lib/LpGenerator.php';
require_once __DIR__ . '/../lib/LpIoNeutralizer.php';
require_once __DIR__ . '/../lib/LpSiteMapper.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    $raw  = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? []) : [];

    $pageKey = trim((string) ($body['key'] ?? ''));
    if ($pageKey === '' || !preg_match('/^internal_\d+$/', $pageKey)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'ok'      => false,
            'error'   => 'key が不正です（internal_N 形式）。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cmsRoot       = dirname(__DIR__);
    $dataDir       = LpWorkspace::dataDir($cmsRoot);
    $outputDir     = LpWorkspace::outputDir($cmsRoot);
    $structureFile = $dataDir . 'lp_structure.json';
    $siteMapPath   = $dataDir . 'site_map.json';
    $clientFile    = $dataDir . 'client_data.json';

    if (!file_exists($structureFile) || !is_readable($siteMapPath)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'ok'      => false,
            'error'   => 'site_map またはサイト構造が見つかりません。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $siteMapRaw = json_decode((string) file_get_contents($siteMapPath), true);
    if (!is_array($siteMapRaw)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'ok' => false, 'error' => 'site_map.json が読めません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pages = $siteMapRaw['pages'] ?? [];
    if (!isset($pages[$pageKey]) || !is_array($pages[$pageKey])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'ok' => false, 'error' => '指定キーのページが site_map にありません。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pageRow = $pages[$pageKey];
    if (($pageRow['status'] ?? '') === 'error') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'ok'      => false,
            'error'   => 'この内部ページは解析エラーのため生成できません。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mainStructure = json_decode((string) file_get_contents($structureFile), true);
    if (!is_array($mainStructure)) {
        throw new RuntimeException('サイト構造JSONの読み込みに失敗しました。');
    }

    $clientData = [];
    if (file_exists($clientFile)) {
        $dec = json_decode((string) file_get_contents($clientFile), true);
        if (is_array($dec)) {
            $clientData = $dec;
        }
    }

    $assetMap = [];
    $assetMapPath = $dataDir . 'asset_map.json';
    if (is_readable($assetMapPath)) {
        $am = json_decode((string) file_get_contents($assetMapPath), true);
        if (is_array($am)) {
            foreach ($am as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $assetMap[$k] = $v;
                }
            }
        }
    }

    $assetOverride = $assetMap !== [] ? $assetMap : null;

    $generator = new LpGenerator();
    [$structure, $loadErr] = $generator->loadStructureForSiteMapPageKey($pageKey, $mainStructure, $dataDir);
    if ($structure === null || $loadErr !== null) {
        throw new RuntimeException($loadErr ?? '内部ページ構造を読み込めませんでした。');
    }

    $html = $generator->generate($structure, $clientData, $dataDir, $assetOverride);

    $regions = $pageRow['data_io_regions'] ?? [];
    $html = LpIoNeutralizer::applyNeutralization($html, is_array($regions) ? $regions : []);

    $urlMap = LpGenerator::buildInternalUrlToPageKeyMap($siteMapRaw);
    $origin = LpGenerator::entryOriginFromSiteMap($siteMapRaw);
    $html   = $generator->injectClickInterceptorScript($html, $origin, $urlMap);

    $localPathRel = trim((string) ($pageRow['local_path'] ?? ''));
    if ($localPathRel === '') {
        throw new RuntimeException('local_path が空です。');
    }

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $targetFile = $generator->filesystemPathForSiteMapLocal($outputDir, $localPathRel);
    $targetDir  = dirname($targetFile);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('出力ディレクトリを作成できません: ' . $targetDir);
    }

    if (file_put_contents($targetFile, $html) === false) {
        throw new RuntimeException('HTML の書き込みに失敗しました。');
    }

    LpSiteMapper::persistSinglePageGenerated($dataDir, $siteMapRaw, $pageKey);

    $rOut    = rtrim(str_replace('\\', '/', $outputDir), '/') . '/';
    $rTarget = str_replace('\\', '/', $targetFile);
    $previewRelative = str_starts_with($rTarget, $rOut)
        ? substr($rTarget, strlen($rOut))
        : ($pageKey . '/index.html');

    echo json_encode([
        'ok'               => true,
        'success'          => true,
        'page'             => $pageKey,
        'coordinate'       => (string) ($pageRow['coordinate'] ?? ''),
        'source_url'       => (string) ($pageRow['source_url'] ?? ''),
        'local_path'       => $localPathRel,
        'preview_relative' => $previewRelative,
        'size'             => strlen($html),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'ok'      => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
