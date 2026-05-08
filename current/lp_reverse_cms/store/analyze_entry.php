<?php

declare(strict_types=1);

set_time_limit(0);

require_once __DIR__ . '/../lib/LpAnalyzer.php';
require_once __DIR__ . '/../lib/LpFetcher.php';
require_once __DIR__ . '/../lib/LpInternalPagesPipeline.php';
require_once __DIR__ . '/../lib/LpLinkRedirectVerifier.php';
require_once __DIR__ . '/../lib/LpMapper.php';
require_once __DIR__ . '/../lib/LpSiteMapper.php';
require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/env_load.php';
require_once __DIR__ . '/../lib/JobRegistry.php';
require_once __DIR__ . '/../lib/lp_job_runtime.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    [$body, $jobId] = lp_job_parse_body_and_id();
    $cmsRoot = dirname(__DIR__);
    $jobRegistry = new JobRegistry($cmsRoot);
    if ($jobId !== '') {
        $jobRegistry->heartbeat($jobId, 'analyze_entry start');
        lp_job_check_abort($jobRegistry, $jobId, '解析ジョブが停止されました。');
    }
    $dataDir = LpWorkspace::dataDir($cmsRoot);
    $outputDir = LpWorkspace::outputDir($cmsRoot);
    $htmlFile = $dataDir . 'fetched.html';
    $urlFile = $dataDir . 'source_url.txt';

    if (!is_readable($htmlFile)) {
        throw new RuntimeException('HTMLファイルが見つかりません。先にURLからHTMLを取得してください。');
    }
    $html = (string) file_get_contents($htmlFile);
    $sourceUrl = is_readable($urlFile) ? trim((string) file_get_contents($urlFile)) : '';
    if ($sourceUrl === '') {
        throw new RuntimeException('source_url.txt が見つかりません。先にURL取得を実行してください。');
    }

    $analyzer = new LpAnalyzer();
    $structure = $analyzer->analyze($html, $sourceUrl);
    if ($jobId !== '') {
        $jobRegistry->heartbeat($jobId, 'analyze_entry analyzed');
        lp_job_check_abort($jobRegistry, $jobId, '解析ジョブが停止されました。');
    }
    $diag = $structure['parse_diagnostics'] ?? null;

    $mapper = new LpMapper();
    $structure = $mapper->enrich($structure);

    $fetchRedirect = new LpFetcher();
    LpLinkRedirectVerifier::verifyAndAnnotate($structure, $fetchRedirect, null);

    $candidateUrls = LpInternalPagesPipeline::extractCandidateUrls($structure, $sourceUrl);

    $candidates = [];
    foreach ($candidateUrls as $idx => $canon) {
        $candidates[] = [
            'index' => $idx,
            'canonical_url' => $canon,
            'status' => 'pending',
        ];
    }

    $candidatePayload = [
        'entry_url' => $sourceUrl,
        'urls' => $candidates,
        'total' => count($candidates),
        'processed' => 0,
        'pending' => count($candidates),
        'error' => 0,
    ];
    file_put_contents(
        $dataDir . 'internal_candidate_urls.json',
        json_encode($candidatePayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $structure['internal_pages'] = [];
    unset($structure['parse_diagnostics']);
    file_put_contents(
        $dataDir . 'lp_structure.json',
        json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $siteMap = LpSiteMapper::build($structure, $dataDir, $outputDir, is_array($diag) ? $diag : null);
    $wsFolder = basename(rtrim(str_replace('\\', '/', $outputDir), '/'));
    foreach ($candidates as $item) {
        $i = (int) $item['index'];
        $key = 'internal_' . (string) $i;
        $siteMap['pages'][$key] = [
            'source_url' => (string) $item['canonical_url'],
            'coordinate' => sprintf('internal[%d]', $i),
            'local_path' => 'output/' . $wsFolder . '/' . $key . '/index.html',
            'status' => 'pending',
            'sections' => [],
            'data_io_regions' => [],
            'dynamic_regions' => [],
        ];
    }
    file_put_contents(
        $dataDir . 'site_map.json',
        json_encode($siteMap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    try {
        lp_reverse_load_env();
        require_once dirname(__DIR__) . '/lib/suggest_industries.php';
        $industrySuggest = lp_reverse_suggest_industries_from_structure($structure);
        file_put_contents(
            $dataDir . 'industry_suggest.json',
            json_encode($industrySuggest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    } catch (Throwable) {
    }

    echo json_encode([
        'ok' => true,
        'success' => true,
        'section_count' => count($structure['sections'] ?? []),
        'total_elements' => (int) ($structure['total_elements'] ?? 0),
        'internal_count' => count($candidates),
        'internal_candidate_urls' => $candidates,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

