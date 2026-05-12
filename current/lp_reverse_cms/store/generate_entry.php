<?php

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../lib/LpGenerator.php';
require_once __DIR__ . '/../lib/LpIoNeutralizer.php';
require_once __DIR__ . '/../lib/LpOutputAudit.php';
require_once __DIR__ . '/../lib/LpSiteMapper.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/JobRegistry.php';
require_once __DIR__ . '/../lib/lp_job_runtime.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    [$body, $jobId] = lp_job_parse_body_and_id();
    $cmsRoot       = dirname(__DIR__);
    $dataDir       = LpWorkspace::dataDir($cmsRoot);
    $jobRegistry   = new JobRegistry($cmsRoot);
    if ($jobId !== '') {
        $jobRegistry->heartbeat($jobId, 'generate_entry start');
        lp_job_check_abort($jobRegistry, $jobId, '生成ジョブが停止されました。');
    }
    $abortFlag     = $dataDir . 'abort.flag';
    $outputDir     = LpWorkspace::outputDir($cmsRoot);
    $structureFile = $dataDir . 'lp_structure.json';
    $siteMapPath   = $dataDir . 'site_map.json';
    $clientFile    = $dataDir . 'client_data.json';

    if (file_exists($abortFlag)) {
        @unlink($abortFlag);
        if ($jobId !== '') {
            $jobRegistry->finish($jobId, 'stopped', null, 'abort.flag detected');
        }
        echo json_encode([
            'ok'      => false,
            'aborted' => true,
            'error'   => '生成が中断されました。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!file_exists($structureFile)) {
        throw new RuntimeException('サイト構造JSONが見つかりません。先にURLを解析してください。');
    }

    if (!is_readable($siteMapPath)) {
        throw new RuntimeException('site_map.json が見つかりません。解析後に再試行してください。');
    }

    $siteMapRaw = json_decode((string) file_get_contents($siteMapPath), true);
    if (!is_array($siteMapRaw) || empty($siteMapRaw['pages']) || !is_array($siteMapRaw['pages']['index'] ?? null)) {
        throw new RuntimeException('site_map.json が不正です。解析をやり直してください。');
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

    // Prefer per-page client data (saved by tree UI) over the top-level fallback
    $pageClientPath = $dataDir . 'page_client/index.json';
    if (is_readable($pageClientPath)) {
        $dec = json_decode((string) file_get_contents($pageClientPath), true);
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
    [$structure, $loadErr] = $generator->loadStructureForSiteMapPageKey('index', $mainStructure, $dataDir);
    if ($structure === null || $loadErr !== null) {
        throw new RuntimeException($loadErr ?? 'index 構造を読み込めませんでした。');
    }

    $indexPage = $siteMapRaw['pages']['index'];

    $html = $generator->generate($structure, $clientData, $dataDir, $assetOverride);
    if ($jobId !== '') {
        $jobRegistry->heartbeat($jobId, 'generate_entry html ready');
        lp_job_check_abort($jobRegistry, $jobId, '生成ジョブが停止されました。');
    }

    $regions = $indexPage['data_io_regions'] ?? [];
    $html = LpIoNeutralizer::applyNeutralization($html, is_array($regions) ? $regions : []);

    $urlMap = LpGenerator::buildInternalUrlToPageKeyMap($siteMapRaw);
    $origin = LpGenerator::entryOriginFromSiteMap($siteMapRaw);
    $html   = $generator->injectClickInterceptorScript($html, $origin, $urlMap, 0, LpWorkspace::id(), $siteMapRaw);

    $localPathRel = trim((string) ($indexPage['local_path'] ?? ''));
    if ($localPathRel === '') {
        throw new RuntimeException('pages.index.local_path が空です。');
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
        throw new RuntimeException('index.html の書き込みに失敗しました。');
    }

    LpSiteMapper::persistSinglePageGenerated($dataDir, $siteMapRaw, 'index');

    LpOutputAudit::persist($targetFile, $dataDir);

    $relIndex = LpWorkspace::outputRelIndex();

    echo json_encode([
        'ok'          => true,
        'success'     => true,
        'page'        => 'index',
        'local_path'  => $localPathRel,
        'preview_url' => '/current/lp_reverse_cms/' . $relIndex,
        'size'        => strlen($html),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'ok'      => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
