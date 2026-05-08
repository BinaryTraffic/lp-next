<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$cmsRoot = rtrim((string) ($argv[1] ?? ''), "/\\");
$taskId = strtolower(trim((string) ($argv[2] ?? '')));
if ($cmsRoot === '' || $taskId === '') {
    fwrite(STDERR, "usage: php generate_worker.php <cmsRoot> <taskId>\n");
    exit(1);
}

require_once $cmsRoot . '/lib/GenerateTask.php';

try {
    $task = GenerateTask::load($cmsRoot, $taskId);
    if (!is_array($task)) {
        exit(0);
    }
    $status = (string) ($task['status'] ?? '');
    if ($status === 'done' || $status === 'error') {
        exit(0);
    }
    $workspaceId = strtolower(trim((string) ($task['workspace_id'] ?? '')));
    if (!preg_match('/^ws_([a-f0-9]{32})$/', $workspaceId, $m)) {
        throw new RuntimeException('invalid workspace_id');
    }
    putenv('LP_WORKSPACE_ID=' . $m[1]);

    require_once $cmsRoot . '/lib/LpWorkspace.php';
    require_once $cmsRoot . '/lib/LpGenerator.php';
    require_once $cmsRoot . '/lib/LpIoNeutralizer.php';
    require_once $cmsRoot . '/lib/LpOutputAudit.php';
    require_once $cmsRoot . '/lib/LpSiteMapper.php';

    $task['status'] = 'running';
    $task['pid'] = (int) getmypid();
    $task['started_at'] = time();
    $task['phase'] = 'save';
    $task['progress_text'] = '000/000';
    GenerateTask::save($cmsRoot, $taskId, $task);

    $dataDir = LpWorkspace::dataDir($cmsRoot);
    $outputDir = LpWorkspace::outputDir($cmsRoot);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $clientData = is_array($task['client_data'] ?? null) ? $task['client_data'] : [];
    file_put_contents(
        $dataDir . 'client_data.json',
        (string) json_encode($clientData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $task['phase'] = 'generate_entry';
    GenerateTask::save($cmsRoot, $taskId, $task);

    $structureFile = $dataDir . 'lp_structure.json';
    $siteMapPath = $dataDir . 'site_map.json';
    if (!file_exists($structureFile) || !is_readable($siteMapPath)) {
        throw new RuntimeException('site_map or structure missing');
    }
    $siteMapRaw = json_decode((string) file_get_contents($siteMapPath), true);
    if (!is_array($siteMapRaw) || !is_array($siteMapRaw['pages']['index'] ?? null)) {
        throw new RuntimeException('site_map invalid');
    }
    $mainStructure = json_decode((string) file_get_contents($structureFile), true);
    if (!is_array($mainStructure)) {
        throw new RuntimeException('structure invalid');
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
        throw new RuntimeException($loadErr ?? 'index structure load failed');
    }
    $indexPage = $siteMapRaw['pages']['index'];
    $html = $generator->generate($structure, $clientData, $dataDir, $assetOverride);
    $regions = $indexPage['data_io_regions'] ?? [];
    $html = LpIoNeutralizer::applyNeutralization($html, is_array($regions) ? $regions : []);
    $urlMap = LpGenerator::buildInternalUrlToPageKeyMap($siteMapRaw);
    $origin = LpGenerator::entryOriginFromSiteMap($siteMapRaw);
    $html = $generator->injectClickInterceptorScript($html, $origin, $urlMap);
    $localPathRel = trim((string) ($indexPage['local_path'] ?? ''));
    if ($localPathRel === '') {
        throw new RuntimeException('index local_path empty');
    }
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $targetFile = $generator->filesystemPathForSiteMapLocal($outputDir, $localPathRel);
    $targetDir = dirname($targetFile);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('mkdir failed: ' . $targetDir);
    }
    if (file_put_contents($targetFile, $html) === false) {
        throw new RuntimeException('write failed: index');
    }
    LpSiteMapper::persistSinglePageGenerated($dataDir, $siteMapRaw, 'index');
    LpOutputAudit::persist($targetFile, $dataDir);

    $pageKeys = array_keys(is_array($siteMapRaw['pages'] ?? null) ? $siteMapRaw['pages'] : []);
    $internalKeys = array_values(array_filter($pageKeys, static fn ($k): bool => is_string($k) && preg_match('/^internal_\d+$/', $k)));
    usort($internalKeys, static fn (string $a, string $b): int => ((int) preg_replace('/\D/', '', $a)) <=> ((int) preg_replace('/\D/', '', $b)));
    $total = max(1, count($internalKeys));
    $task['phase'] = 'generate_internal';
    $task['progress_text'] = sprintf('%03d/%03d', 0, $total);
    GenerateTask::save($cmsRoot, $taskId, $task);

    foreach ($internalKeys as $i => $pageKey) {
        $pageRow = $siteMapRaw['pages'][$pageKey] ?? null;
        if (!is_array($pageRow) || ($pageRow['status'] ?? '') === 'error') {
            $task['progress_text'] = sprintf('%03d/%03d', $i + 1, $total);
            GenerateTask::save($cmsRoot, $taskId, $task);
            continue;
        }
        [$subStruct, $subErr] = $generator->loadStructureForSiteMapPageKey($pageKey, $mainStructure, $dataDir);
        if ($subStruct === null || $subErr !== null) {
            $task['progress_text'] = sprintf('%03d/%03d', $i + 1, $total);
            GenerateTask::save($cmsRoot, $taskId, $task);
            continue;
        }
        $subHtml = $generator->generate($subStruct, $clientData, $dataDir, $assetOverride);
        $subRegions = $pageRow['data_io_regions'] ?? [];
        $subHtml = LpIoNeutralizer::applyNeutralization($subHtml, is_array($subRegions) ? $subRegions : []);
        $subHtml = $generator->injectClickInterceptorScript($subHtml, $origin, $urlMap);
        $subLocal = trim((string) ($pageRow['local_path'] ?? ''));
        if ($subLocal !== '') {
            $subTarget = $generator->filesystemPathForSiteMapLocal($outputDir, $subLocal);
            $subDir = dirname($subTarget);
            if (!is_dir($subDir)) {
                mkdir($subDir, 0755, true);
            }
            @file_put_contents($subTarget, $subHtml);
            LpSiteMapper::persistSinglePageGenerated($dataDir, $siteMapRaw, $pageKey);
        }
        $task['progress_text'] = sprintf('%03d/%03d', $i + 1, $total);
        GenerateTask::save($cmsRoot, $taskId, $task);
    }

    $task['status'] = 'done';
    $task['phase'] = 'generate_internal';
    $task['ended_at'] = time();
    GenerateTask::save($cmsRoot, $taskId, $task);
} catch (Throwable $e) {
    $task = GenerateTask::load($cmsRoot, $taskId);
    if (is_array($task)) {
        $task['status'] = 'error';
        $task['error'] = $e->getMessage();
        $task['ended_at'] = time();
        GenerateTask::save($cmsRoot, $taskId, $task);
    }
}

