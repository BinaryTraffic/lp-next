<?php

declare(strict_types=1);

/**
 * GET store/get_page_edit_form.php?key=index|internal_N
 *
 * Returns JSON { ok: true, html: "<rendered edit form>" }
 * Used by the Step2 Explorer tree UI to load per-page edit forms via Ajax.
 */

require_once __DIR__ . '/../lib/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    lp_reverse_session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Require logged-in session
if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pageKey = trim((string) ($_GET['key'] ?? 'index'));
if ($pageKey !== 'index' && !preg_match('/^internal_\d+$/', $pageKey)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid page key'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../lib/LpWorkspace.php';
require_once __DIR__ . '/../lib/LpGenerator.php';

$cmsRoot = dirname(__DIR__);
$dataDir = LpWorkspace::dataDir($cmsRoot);

// Load main structure
$structureFile = $dataDir . 'lp_structure.json';
if (!is_readable($structureFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'lp_structure.json が見つかりません。'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mainStructure = json_decode((string) file_get_contents($structureFile), true);
if (!is_array($mainStructure)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'lp_structure.json が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Load page-specific structure
$generator = new LpGenerator();
[$structure, $loadErr] = $generator->loadStructureForSiteMapPageKey($pageKey, $mainStructure, $dataDir);
if ($structure === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $loadErr ?? 'ページ構造が見つかりません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Inject source_url from site_map if missing
$siteMapPath = $dataDir . 'site_map.json';
$pageRow = [];
if (is_readable($siteMapPath)) {
    $sm = json_decode((string) file_get_contents($siteMapPath), true);
    if (is_array($sm)) {
        $pageRow = ($sm['pages'] ?? [])[$pageKey] ?? [];
    }
}
$sourceUrl = (string) ($pageRow['source_url'] ?? $structure['source_url'] ?? '');
if ($sourceUrl !== '' && empty($structure['source_url'])) {
    $structure['source_url'] = $sourceUrl;
}

// Load per-page client data
$clientData = [];
$pageClientPath = $dataDir . 'page_client/' . $pageKey . '.json';
if (is_readable($pageClientPath)) {
    $dec = json_decode((string) file_get_contents($pageClientPath), true);
    if (is_array($dec)) {
        $clientData = $dec;
    }
} elseif (is_readable($dataDir . 'client_data.json')) {
    // Fallback: top-level client_data (backward compat, used for all pages before tree UI)
    $dec = json_decode((string) file_get_contents($dataDir . 'client_data.json'), true);
    if (is_array($dec)) {
        $clientData = $dec;
    }
}

// No AI industry suggestion for Ajax (too slow; user can still type manually)
$sourceIndustry = '';
$suggestions    = [];

// rollback proxy URL 構築のために必要（editPage.php の $rollbackPreviewUrl クロージャが参照）
$outputWsPrefix = LpWorkspace::outputWebAbsPrefix();

// Capture the edit form HTML from the PHP template
ob_start();
include $cmsRoot . '/template/editPage.php';
$formHtml = (string) ob_get_clean();

// Resolve page title: client_data meta.title → structure meta.title → page_key
$pageTitle = (string) ($clientData['meta']['title'] ?? $structure['meta']['title'] ?? '');

echo json_encode([
    'ok'         => true,
    'html'       => $formHtml,
    'page_key'   => $pageKey,
    'page_title' => $pageTitle,
    'source_url' => $sourceUrl,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
