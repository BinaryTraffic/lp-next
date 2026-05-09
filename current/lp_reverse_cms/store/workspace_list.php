<?php

declare(strict_types=1);

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/lp_reverse_store_auth.php';
require_once $cmsRoot . '/lib/WorkspaceRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $actor = lp_reverse_store_auth_actor($cmsRoot);
    $reg   = new WorkspaceRegistry($cmsRoot);
    $list  = $reg->listForActor($actor);

    // Enrich each workspace with content metadata from lp_structure.json
    $dataRoot = $cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
    $list = array_map(static function (array $ws) use ($dataRoot): array {
        $ws['site_url']       = '';
        $ws['page_title']     = '';
        $ws['page_count']     = 0;
        $ws['analyzed_at']    = '';
        $ws['industry_hint']  = '';
        $structPath = $dataRoot . $ws['id'] . DIRECTORY_SEPARATOR . 'lp_structure.json';
        if (is_readable($structPath)) {
            $struct = json_decode((string) file_get_contents($structPath), true);
            if (is_array($struct)) {
                $ws['site_url']    = (string) ($struct['source_url'] ?? '');
                $ws['page_title']  = (string) ($struct['meta']['title'] ?? '');
                $ws['page_count']  = 1 + count((array) ($struct['internal_pages'] ?? []));
                $ws['analyzed_at'] = (string) ($struct['analyzed_at'] ?? '');
            }
        }
        $suggestPath = $dataRoot . $ws['id'] . DIRECTORY_SEPARATOR . 'industry_suggest.json';
        if ($ws['industry_hint'] === '' && is_readable($suggestPath)) {
            $sug = json_decode((string) file_get_contents($suggestPath), true);
            if (is_array($sug) && trim((string) ($sug['source_industry'] ?? '')) !== '') {
                $ws['industry_hint'] = trim((string) $sug['source_industry']);
            }
        }
        return $ws;
    }, $list);

    echo json_encode(
        [
            'ok'          => true,
            'workspaces'  => $list,
            'current_ws'  => 'ws_' . LpWorkspace::id(),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
