<?php

declare(strict_types=1);

require_once __DIR__ . '/LpDomScriptCleanup.php';
require_once __DIR__ . '/LpIoNeutralizer.php';
require_once __DIR__ . '/LpUrlContext.php';

/**
 * LpGenerator — rebuilds a complete HTML page from lp_structure + client_data.
 *
 * Strategy:
 *  1. For each section, load the stored HTML fragment into a DOMDocument.
 *  2. Locate every element tagged with data-lp-id.
 *  3. Replace text / src / href with client data (or keep originals).
 *  4. Wrap everything in a full HTML document shell.
 *  5. Apply the asset URL map (absolute → local) produced by LpAssetDownloader.
 *
 * Stacking: sections are emitted as consecutive siblings under <body>. If the source page
 * nested blocks under one positioned ancestor, z-index could otherwise compete globally.
 * Each section is wrapped in .lp-reverse-section-root (isolation:isolate) to scope layers.
 *
 * Layout: body_head_snippets restores body-level style/link that are not inside any
 * section fragment (common for hero/banner CSS). The wrapper uses position:relative so
 * position:absolute descendants keep a bounded containing block when the original outer
 * wrapper was not part of the extracted section.
 */
class LpGenerator
{
    /** Path to data/ directory — set by generate() based on __DIR__ */
    private string $dataDir = '';

    /**
     * @param array $structure Contents of lp_structure.json
     * @param array $clientData Contents of client_data.json (may be empty)
     * @param string $dataDir Workspace data directory (trailing slash optional)
     * @param array<string, string>|null $assetMapOverride When non-null, use instead of reading asset_map.json
     * @return string Complete HTML of the generated LP
     */
    public function generate(array $structure, array $clientData, string $dataDir, ?array $assetMapOverride = null): string
    {
        $this->dataDir = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR;

        $meta        = $structure['meta']       ?? [];
        $headExtra   = $structure['head_extra'] ?? '';
        $bodySnip    = $structure['body_head_snippets'] ?? '';
        $sections    = $structure['sections']   ?? [];
        $elemData    = $clientData['elements']  ?? [];
        $clientMeta  = $clientData['meta']      ?? [];

        $title       = $clientMeta['title']       ?? $meta['title']       ?? '';
        $description = $clientMeta['description'] ?? $meta['description'] ?? '';
        $charset     = $meta['charset']           ?? 'UTF-8';
        $viewport    = $meta['viewport']          ?? 'width=device-width, initial-scale=1';

        $title       = htmlspecialchars($title,       ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        $charset     = htmlspecialchars($charset,     ENT_QUOTES, 'UTF-8');
        $viewport    = htmlspecialchars($viewport,    ENT_QUOTES, 'UTF-8');

        $bodyId    = trim((string) ($meta['body_id'] ?? ''));
        $bodyClass = trim((string) ($meta['body_class'] ?? ''));
        $bodyAttr  = '';
        if ($bodyId !== '') {
            $bodyAttr .= ' id="' . htmlspecialchars($bodyId, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($bodyClass !== '') {
            $bodyAttr .= ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"';
        }

        // z-index is assigned in descending order (highest for first section) so that
        // navigation dropdowns in the header section can overlap later sections.
        // isolation:isolate scopes internal z-index wars without locking the section
        // below siblings — combining with a unique positive z-index achieves both goals.
        // Firefox enforces stacking contexts strictly; explicit descending z-index fixes
        // dropdown-behind-content bugs that Chrome often masks.
        $stackFixCss = '<style id="lp-reverse-stack-context">'
            . '.lp-reverse-section-root{isolation:isolate;position:relative;width:100%;box-sizing:border-box}'
            . '</style>';

        $sectionCount = count($sections);
        $sectionsHtml = '';
        $sectionIndex = 0;
        foreach ($sections as $section) {
            $chunk = $this->processSection($section, $elemData);
            if (trim($chunk) === '') {
                continue;
            }
            // First section (nav/header) gets the highest z-index so its dropdowns
            // can paint above all subsequent sections.
            $zIndex = $sectionCount - $sectionIndex + 10;
            $sectionIndex++;
            $secId = htmlspecialchars((string) ($section['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $sectionsHtml .= '<div class="lp-reverse-section-root" data-lp-section="' . $secId . '" style="z-index:' . $zIndex . '">'
                . $chunk . "</div>\n";
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="{$charset}">
<meta name="viewport" content="{$viewport}">
<meta name="description" content="{$description}">
<title>{$title}</title>
{$headExtra}
{$bodySnip}
{$stackFixCss}
</head>
<body{$bodyAttr}>
{$sectionsHtml}
</body>
</html>
HTML;

        // ── Apply asset URL map: absolute URLs → local paths ──────────────
        $html = $this->applyAssetMap($html, $assetMapOverride);

        return $html;
    }

    /**
     * site_map.json を参照して output/ 以下の全ページを生成する
     *
     * @param array<string,mixed> $siteMap site_map.json の内容
     * @param array<string,mixed> $clientData client_data.json の内容
     * @param string $outputDir output/ws_* の絶対パス（末尾スラッシュ任意）
     * @param array<string,string> $assetMap asset_map.json に相当するマップ（空ならファイルを読む）
     * @return array{generated: int, skipped: int, errors: list<string>, skipped_coordinates: list<string>, generated_keys: list<string>}
     */
    public function generateFromSiteMap(
        array $siteMap,
        array $clientData,
        string $outputDir,
        array $assetMap
    ): array {
        $dataDir = $this->workspaceDataDirFromOutput($outputDir);
        $structurePath = $dataDir . 'lp_structure.json';
        if (!is_readable($structurePath)) {
            throw new RuntimeException('サイト構造JSONが見つかりません。先にURLを解析してください。');
        }

        $mainStructure = json_decode((string) file_get_contents($structurePath), true);
        if (!is_array($mainStructure)) {
            throw new RuntimeException('サイト構造JSONの読み込みに失敗しました。');
        }

        $pages = $siteMap['pages'] ?? null;
        if (!is_array($pages)) {
            throw new RuntimeException('site_map.json の pages が不正です。');
        }

        $generated = 0;
        $skipped = 0;
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<string> $skipped_coordinates */
        $skipped_coordinates = [];
        /** @var list<string> $generated_keys */
        $generated_keys = [];

        $assetOverride = $assetMap !== [] ? $assetMap : null;

        foreach ($pages as $pageKey => $page) {
            if (!is_array($page)) {
                continue;
            }

            if (($page['status'] ?? '') === 'error') {
                ++$skipped;
                $coord = trim((string) ($page['coordinate'] ?? ''));
                if ($coord !== '') {
                    $skipped_coordinates[] = $coord;
                }

                continue;
            }

            $structure = null;
            if ($pageKey === 'index') {
                $structure = $mainStructure;
            } elseif (preg_match('/^internal_(\d+)$/', (string) $pageKey, $mm)) {
                $idx = (int) $mm[1];
                $internals = $mainStructure['internal_pages'] ?? [];
                if (!is_array($internals) || !isset($internals[$idx]) || !is_array($internals[$idx])) {
                    ++$skipped;
                    $errors[] = 'internal page missing in lp_structure.json: ' . $pageKey;

                    continue;
                }

                $manifest = $internals[$idx];
                if (empty($manifest['fetch_ok'])) {
                    ++$skipped;
                    $coord = trim((string) ($page['coordinate'] ?? sprintf('internal[%d]', $idx)));
                    if ($coord !== '') {
                        $skipped_coordinates[] = $coord;
                    }

                    continue;
                }

                $sf = (string) ($manifest['structure_file'] ?? '');
                $subPath = $dataDir . $sf;
                if ($sf === '' || !is_readable($subPath)) {
                    ++$skipped;
                    $errors[] = 'structure_file unreadable for ' . $pageKey;

                    continue;
                }

                $decoded = json_decode((string) file_get_contents($subPath), true);
                $structure = is_array($decoded) ? $decoded : null;
            } else {
                ++$skipped;
                $errors[] = 'unknown site_map page key: ' . $pageKey;

                continue;
            }

            if ($structure === null) {
                ++$skipped;
                $errors[] = 'could not load structure for ' . $pageKey;

                continue;
            }

            $localPathRel = trim((string) ($page['local_path'] ?? ''));
            if ($localPathRel === '') {
                ++$skipped;
                $errors[] = 'empty local_path for ' . $pageKey;

                continue;
            }

            try {
                $targetFile = $this->resolveSiteMapLocalPath($outputDir, $localPathRel);
            } catch (Throwable $e) {
                ++$skipped;
                $errors[] = $e->getMessage();

                continue;
            }

            $targetDir = dirname($targetFile);
            if (!is_dir($targetDir)) {
                if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    throw new RuntimeException('出力ディレクトリを作成できません: ' . $targetDir);
                }
            }

            $html = $this->generate($structure, $clientData, $dataDir, $assetOverride);

            $regions = $page['data_io_regions'] ?? [];
            if (!is_array($regions)) {
                $regions = [];
            }
            $html = LpIoNeutralizer::applyNeutralization($html, $regions);

            $urlMap = self::buildInternalUrlToPageKeyMap($siteMap);
            $origin = self::entryOriginFromSiteMap($siteMap);
            if ($origin !== '' && $urlMap !== []) {
                $depth = self::computeLocalPathDepth($localPathRel);
                $html = $this->injectClickInterceptorScript($html, $origin, $urlMap, $depth);
            }

            if (file_put_contents($targetFile, $html) === false) {
                ++$skipped;
                $errors[] = 'failed to write: ' . $targetFile;

                continue;
            }

            ++$generated;
            $generated_keys[] = (string) $pageKey;
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'errors' => $errors,
            'skipped_coordinates' => $skipped_coordinates,
            'generated_keys' => $generated_keys,
        ];
    }

    /**
     * site_map の internal_* ページ用: source_url → page key（クリックインターセプター用）
     *
     * @param array<string,mixed> $siteMap
     * @return array<string,string>
     */
    public static function buildInternalUrlToPageKeyMap(array $siteMap): array
    {
        $map = [];
        foreach ($siteMap['pages'] ?? [] as $key => $page) {
            if ($key === 'index' || !is_string($key) || !is_array($page)) {
                continue;
            }
            if (!preg_match('/^internal_\d+$/', $key)) {
                continue;
            }
            $u = trim((string) ($page['source_url'] ?? ''));
            if ($u === '') {
                continue;
            }
            $noTrailing = rtrim($u, '/');
            $withTrailing = $noTrailing . '/';
            foreach ([$u, $noTrailing, $withTrailing] as $variant) {
                $map[$variant] = $key;
            }
        }

        return $map;
    }

    /**
     * クリックインターセプター用 ORIGIN（例: https://example.com）
     *
     * @param array<string,mixed> $siteMap
     */
    public static function entryOriginFromSiteMap(array $siteMap): string
    {
        $url = trim((string) (($siteMap['meta'] ?? [])['entry_url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $port = isset($p['port']) ? ':' . (string) $p['port'] : '';

        return (string) $p['scheme'] . '://' . (string) $p['host'] . $port;
    }

    /**
     * Computes how many directory levels deep a local_path is relative to the output root.
     * e.g. "output/ws_XXX/news/index.html" → 1, "output/ws_XXX/index.html" → 0
     */
    public static function computeLocalPathDepth(string $localPath): int
    {
        $rel = (string) preg_replace('~^output/[^/]+/~', '', str_replace('\\', '/', $localPath));
        $rel = ltrim($rel, '/');
        if ($rel === '') {
            return 0;
        }
        $dir = dirname($rel);
        if ($dir === '.') {
            return 0;
        }
        return substr_count($dir, '/') + 1;
    }

    /**
     * サブディレクトリに置かれたページ（depth ≥ 1）の HTML に含まれる
     * 出力ルート相対アセットパス（assets/img/… 等）を、正しい相対深さに補正する。
     *
     * LpAssetDownloader はすべてのアセットを output/ws_XXX/assets/ 以下に保存し、
     * HTML 内のパスを "assets/img/foo.png" のようなルート相対形式に書き換える。
     * ページが output/ws_XXX/items/gold.html のように1段深い場合は
     * "../assets/img/foo.png" と書かなければブラウザが解決できない。
     *
     * 対象属性: src, href, srcset, および CSS url() 内のパス
     *
     * @param string $html       生成済み HTML
     * @param int    $depth      ページの深さ（computeLocalPathDepth の戻り値）
     * @return string            パス補正済み HTML
     */
    public static function fixOutputAssetPaths(string $html, int $depth): string
    {
        if ($depth <= 0) {
            return $html;
        }

        $prefix = str_repeat('../', $depth);

        // 1. src="assets/  /  href="assets/
        $html = (string) preg_replace(
            '/\b(src|href)="assets\//i',
            '$1="' . $prefix . 'assets/',
            $html
        );

        // 2. url('assets/  /  url("assets/  /  url(assets/
        $html = (string) preg_replace(
            '/url\(([\'"]?)assets\//i',
            'url($1' . $prefix . 'assets/',
            $html
        );

        // 3. srcset="..."  (カンマ区切りのエントリそれぞれを補正)
        $html = (string) preg_replace_callback(
            '/\bsrcset="([^"]*)"/i',
            static function (array $m) use ($prefix): string {
                // エントリ先頭の "assets/" を補正（スペース・カンマ・先頭）
                $fixed = (string) preg_replace(
                    '/((?:^|,)\s*)assets\//m',
                    '$1' . $prefix . 'assets/',
                    $m[1]
                );
                return 'srcset="' . $fixed . '"';
            },
            $html
        );

        return $html;
    }

    /**
     * &lt;/body&gt; 直前にクリックインターセプター JS を注入する（静的プレビュー内リンク → generate_internal）
     *
     * @param array<string,string> $internalUrlMap source_url variant → internal_N
     */
    public function injectClickInterceptorScript(string $html, string $entryOrigin, array $internalUrlMap, int $pageDepth = 0): string
    {
        $entryOrigin = trim($entryOrigin);
        if ($entryOrigin === '' || $internalUrlMap === []) {
            return $html;
        }

        $mapJson = json_encode($internalUrlMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($mapJson === false) {
            return $html;
        }
        $originJson = json_encode($entryOrigin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cmsPath = '/current/lp_reverse_cms/store/generate_internal.php';
        $cmsJson = json_encode($cmsPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rootPath = $pageDepth > 0 ? str_repeat('../', $pageDepth) : '';
        $rootPathJson = json_encode($rootPath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $script =
            '<script data-lp-interceptor>' . "\n"
            . '(function(){' . "\n"
            . '  var ORIGIN   = ' . $originJson . ';' . "\n"
            . '  var CMS      = ' . $cmsJson . ';' . "\n"
            . '  var MAP      = ' . $mapJson . ';' . "\n"
            . '  var LP_ROOT  = ' . $rootPathJson . ';' . "\n"
            . '  document.addEventListener(\'click\', function(e){' . "\n"
            . '    var a = e.target.closest(\'a[href]\');' . "\n"
            . '    if (!a) return;' . "\n"
            . '    try {' . "\n"
            . '      var url = new URL(a.href);' . "\n"
            . '      if (url.origin !== ORIGIN) return;' . "\n"
            . '    } catch (err) { return; }' . "\n"
            . '    var abs = a.href;' . "\n"
            . '    var key = MAP[abs] || MAP[abs.replace(/' . '\\/' . '$/, \'\')];' . "\n"
            . '    if (!key) {' . "\n"
            . '      var rel = a.getAttribute(\'href\') || \'\';' . "\n"
            . '      var isLocal = rel === \'\' || /^(#|javascript:|internal_\\d+\\/|_p\\/)/i.test(rel);' . "\n"
            . '      if (!isLocal) e.preventDefault();' . "\n"
            . '      return;' . "\n"
            . '    }' . "\n"
            . '    e.preventDefault();' . "\n"
            . '    fetch(CMS, {' . "\n"
            . '      method: \'POST\',' . "\n"
            . '      headers: {\'Content-Type\': \'application/json\'},' . "\n"
            . '      body: JSON.stringify({key: key})' . "\n"
            . '    })' . "\n"
            . '    .then(function(r){ return r.json(); })' . "\n"
            . '    .then(function(d){' . "\n"
            . '      if (d.preview_relative) {' . "\n"
            . '        window.location.href = LP_ROOT + String(d.preview_relative);' . "\n"
            . '      } else if (d.local_path && typeof d.local_path === \'string\') {' . "\n"
            . '        var p = String(d.local_path).replace(/' . '^output' . '\\/[^/]+\\/' . '/, \'\');' . "\n"
            . '        window.location.href = LP_ROOT + p;' . "\n"
            . '      }' . "\n"
            . '    })' . "\n"
            . '    .catch(function(){});' . "\n"
            . '  });' . "\n"
            . '})();' . "\n"
            . '</script>';

        if (preg_match('~</body>~i', $html)) {
            return (string) (preg_replace('~</body>~i', $script . "\n</body>", $html, 1) ?? $html);
        }

        return $html . "\n" . $script;
    }

    /**
     * site_map の index / internal_N に対応する lp_structure を読み込む
     *
     * @param array<string,mixed> $mainStructure lp_structure.json ルート
     * @return array{0:?array<string,mixed>, 1:?string} [構造または null, エラー理由]
     */
    public function loadStructureForSiteMapPageKey(string $pageKey, array $mainStructure, string $dataDir): array
    {
        $dataDir = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR;

        if ($pageKey === 'index') {
            return [$mainStructure, null];
        }

        if (!preg_match('/^internal_(\d+)$/', $pageKey, $mm)) {
            return [null, 'unsupported page key'];
        }

        $idx = (int) $mm[1];
        $internals = $mainStructure['internal_pages'] ?? [];
        if (!is_array($internals) || !isset($internals[$idx]) || !is_array($internals[$idx])) {
            return [null, 'internal page missing in lp_structure'];
        }

        $manifest = $internals[$idx];
        if (empty($manifest['fetch_ok'])) {
            return [null, 'internal page fetch failed'];
        }

        $sf = (string) ($manifest['structure_file'] ?? '');
        $subPath = $dataDir . $sf;
        if ($sf === '' || !is_readable($subPath)) {
            return [null, 'structure file unreadable'];
        }

        $decoded = json_decode((string) file_get_contents($subPath), true);

        return is_array($decoded) ? [$decoded, null] : [null, 'invalid structure JSON'];
    }

    /**
     * output/ws_* から対応する data/ws_* を求める（LpWorkspace と同じ構成）
     */
    private function workspaceDataDirFromOutput(string $outputDir): string
    {
        $out = rtrim($outputDir, '/\\');
        $wsFolder = basename($out);
        $cmsRoot = dirname(dirname($out));

        return $cmsRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $wsFolder . DIRECTORY_SEPARATOR;
    }

    /**
     * site_map の local_path をワークスペース output の絶対パスへ解決する
     */
    public function filesystemPathForSiteMapLocal(string $outputDir, string $localPathFromSiteMap): string
    {
        return $this->resolveSiteMapLocalPath($outputDir, $localPathFromSiteMap);
    }

    /**
     * site_map の local_path（output/ws_* 配下の相対パス）をワークスペース output ディレクトリ上の絶対パスに変換する
     */
    private function resolveSiteMapLocalPath(string $outputDir, string $localPathFromSiteMap): string
    {
        $out = rtrim(str_replace('\\', '/', $outputDir), '/') . '/';
        $wsFolder = basename(rtrim($out, '/'));
        $prefix = 'output/' . $wsFolder . '/';
        $norm = str_replace('\\', '/', trim($localPathFromSiteMap));

        if (str_starts_with($norm, $prefix)) {
            return $out . substr($norm, strlen($prefix));
        }

        $stripped = preg_replace('~^output/~', '', $norm) ?? $norm;

        return $out . ltrim($stripped, '/');
    }

    /**
     * Replace all absolute asset URLs in the HTML with their local equivalents
     * as recorded in data/asset_map.json.
     *
     * Handles:
     *  - Plain replacement in href/src/url() contexts
     *  - HTML-entity-encoded (&amp; etc.)
     *  - Protocol-relative form (//example.com/...)
     *  - Longest-key-first to avoid partial substring collisions
     */
    /**
     * @param array<string, string>|null $mapOverride
     */
    private function applyAssetMap(string $html, ?array $mapOverride = null): string
    {
        $map = $mapOverride;
        if ($map === null) {
            $mapFile = $this->dataDir . 'asset_map.json';
            if (!file_exists($mapFile)) {
                return $html;
            }

            $map = json_decode((string) file_get_contents($mapFile), true);
        }

        if (!is_array($map) || empty($map)) {
            return $html;
        }

        // Windows: dirname() can produce "https://host\path" — breaks str_replace vs asset_map
        $html = $this->normalizeMalformedWindowsUrls($html);

        /*
         * Two-pass replacement:
         *  1) Absolute keys (http/https//) — longest first. Protocol-relative keys must not
         *     use plain str_replace: "//host/..." appears inside "https://host/..." and would
         *     yield "https:assets/..." if matched. Use (?<![/:])// only for // keys.
         *  2) Relative keys — only inside quoted href/src/poster/data-* and srcset candidates,
         *     never global str_replace (avoids "assets/css/a.css" → "assets/assets/css/...").
         */
        $absoluteExpanded = [];
        $relativeExpanded = [];
        foreach ($map as $originalUrl => $localPath) {
            if (!$originalUrl || !$localPath) {
                continue;
            }
            $isAbs = str_starts_with((string) $originalUrl, 'http://')
                || str_starts_with((string) $originalUrl, 'https://')
                || str_starts_with((string) $originalUrl, '//');
            if ($isAbs) {
                $aliases = array_unique(array_merge(
                    LpUrlContext::httpHttpsAssetUrlVariants((string) $originalUrl),
                    LpUrlContext::httpHttpsAssetUrlVariants(
                        LpUrlContext::canonicalHttpUrlForFetch((string) $originalUrl)
                    ),
                ));
                foreach ($aliases as $alias) {
                    $absoluteExpanded[$alias] = (string) $localPath;
                    if (str_starts_with($alias, 'https://')) {
                        $absoluteExpanded['//' . substr($alias, 8)] = (string) $localPath;
                    } elseif (str_starts_with($alias, 'http://')) {
                        $absoluteExpanded['//' . substr($alias, 7)] = (string) $localPath;
                    }
                }
            } else {
                $relativeExpanded[(string) $originalUrl] = (string) $localPath;
            }
        }

        $html = $this->applyAbsoluteAssetReplacements($html, $absoluteExpanded);
        $html = $this->applyRelativeAssetReplacements($html, $relativeExpanded);

        return $html;
    }

    /**
     * @param array<string, string> $expanded
     */
    private function applyAbsoluteAssetReplacements(string $html, array $expanded): string
    {
        if ($expanded === []) {
            return $html;
        }
        uksort($expanded, static fn($a, $b) => strlen($b) - strlen($a));
        foreach ($expanded as $from => $to) {
            if ($from === '' || $to === '') {
                continue;
            }
            if (!str_contains($html, $from)) {
                continue;
            }
            if (str_starts_with($from, '//')) {
                $qf = preg_quote($from, '~');
                $html = preg_replace('~(?<![/:])' . $qf . '~', $to, $html) ?? $html;
                $encFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
                if ($encFrom !== $from) {
                    $eq = preg_quote($encFrom, '~');
                    $encTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
                    $html = preg_replace('~(?<![/:])' . $eq . '~', $encTo, $html) ?? $html;
                }
            } else {
                $html = str_replace($from, $to, $html);
                $encFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
                $encTo   = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
                if ($encFrom !== $from && str_contains($html, $encFrom)) {
                    $html = str_replace($encFrom, $encTo, $html);
                }
            }
        }

        return $html;
    }

    /**
     * @param array<string, string> $expanded
     */
    private function applyRelativeAssetReplacements(string $html, array $expanded): string
    {
        if ($expanded === []) {
            return $html;
        }
        uksort($expanded, static fn($a, $b) => strlen($b) - strlen($a));
        $attrs = ['href', 'src', 'poster', 'data-src', 'data-bg', 'data-lazy-src', 'data-original'];
        foreach ($expanded as $from => $to) {
            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }
            if (str_starts_with($from, 'http://') || str_starts_with($from, 'https://') || str_starts_with($from, '//')) {
                continue;
            }
            if (!str_contains($html, $from)) {
                continue;
            }
            $qf = preg_quote($from, '~');
            foreach ($attrs as $attr) {
                $html = preg_replace(
                    '~(?i)(?<![\w-])' . $attr . '\s*=\s*(")' . $qf . '(")~',
                    $attr . '=$1' . $to . '$2',
                    $html
                ) ?? $html;
                $html = preg_replace(
                    "~(?i)(?<![\w-])" . $attr . "\s*=\s*(')" . $qf . "(')~",
                    $attr . '=$1' . $to . '$2',
                    $html
                ) ?? $html;
            }
            $html = preg_replace_callback(
                '~(?i)\bsrcset\s*=\s*(")([^"]*)(")~',
                static function (array $m) use ($from, $to): string {
                    if (!str_contains($m[2], $from)) {
                        return $m[0];
                    }
                    $parts = preg_split('~\s*,\s*~', $m[2]) ?: [];
                    $newParts = [];
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if ($part === '') {
                            continue;
                        }
                        $tok  = preg_split('~\s+~', $part, 2);
                        $u    = $tok[0] ?? '';
                        $desc = isset($tok[1]) ? ' ' . $tok[1] : '';
                        $newParts[] = ($u === $from) ? ($to . $desc) : $part;
                    }

                    return 'srcset=' . $m[1] . implode(', ', $newParts) . $m[3];
                },
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * Fix absolute URLs where PHP on Windows produced a backslash after the host
     * (e.g. https://example.com\assets/foo.css or https://example.com%5C/assets/...).
     */
    private function normalizeMalformedWindowsUrls(string $html): string
    {
        // This is a Windows-only artifact (PHP dirname() producing backslashes).
        // On Linux production, the method is a no-op.
        if (DIRECTORY_SEPARATOR !== '\\') {
            return $html;
        }

        $extra = '[]:_-';
        // Delimiter ~ matches preg_quote second arg; avoids "#" in lookahead terminating #-patterns.
        $hostClass = '[a-zA-Z0-9.' . preg_quote($extra, '~') . ']+';
        $html = preg_replace(
            '~(https?://' . $hostClass . ')\\\\(?=[/a-zA-Z0-9_%?#])~',
            '$1/',
            $html
        ) ?? $html;

        $html = preg_replace(
            '~(https?://' . $hostClass . ')(%5[Cc])(/)~',
            '$1$3',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Process a single section: inject client data into the HTML template.
     */
    private function processSection(array $section, array $elemData): string
    {
        $originalHtml = $section['html'] ?? '';
        if (!$originalHtml) {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Wrap in a neutral container so loadHTML doesn't invent wrappers
        $wrappedHtml = '<?xml encoding="UTF-8"><div id="__lp_root__">' . $originalHtml . '</div>';
        $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpathBoot = new DOMXPath($dom);
        $wrapRoot  = $xpathBoot->query('//*[@id="__lp_root__"]')->item(0);
        if ($wrapRoot instanceof DOMElement) {
            LpDomScriptCleanup::stripScriptsAndJsSpills($wrapRoot);
        }

        $xpath = new DOMXPath($dom);

        foreach ($section['elements'] as $element) {
            $id       = $element['id'];
            $type     = $element['type'];
            $override = $elemData[$id] ?? null;

            $nodes = $xpath->query('//*[@data-lp-id="' . $id . '"]');
            if (!$nodes || $nodes->length === 0) {
                continue;
            }
            /** @var DOMElement $node */
            $node = $nodes->item(0);

            if ($type === 'image') {
                $newSrc = $override['src'] ?? $element['original_src'] ?? null;
                $newAlt = $override['text'] ?? $element['original_text'] ?? '';
                if ($newSrc) {
                    $node->setAttribute('src', $newSrc);
                }
                $node->setAttribute('alt', $newAlt);

                $memoStruct = trim((string) ($element['image_embedded_text_memo'] ?? ''));
                if (is_array($override) && array_key_exists('image_embedded_text_memo', $override)) {
                    $memoOut = trim((string) $override['image_embedded_text_memo']);
                } else {
                    $memoOut = $memoStruct;
                }
                if ($memoOut !== '') {
                    $node->setAttribute('data-lp-image-text-memo', $memoOut);
                } else {
                    $node->removeAttribute('data-lp-image-text-memo');
                }

                $anchor = $node->parentNode;
                while ($anchor !== null
                    && (!($anchor instanceof DOMElement) || strtolower($anchor->tagName) !== 'a')
                ) {
                    $anchor = $anchor->parentNode;
                }
                if ($anchor instanceof DOMElement) {
                    if (is_array($override) && isset($override['href']) && $override['href'] !== '') {
                        $anchor->setAttribute('href', $override['href']);
                    } elseif (!empty($element['internal_relative_href'])) {
                        $anchor->setAttribute('href', (string) $element['internal_relative_href']);
                    }
                    if (is_array($override) && isset($override['target']) && $override['target'] !== '') {
                        $anchor->setAttribute('target', $override['target']);
                    }
                }
            } else {
                $newText = ($override !== null && isset($override['text']) && $override['text'] !== '')
                    ? $override['text']
                    : null;

                if ($newText !== null) {
                    $this->setNodeText($node, $newText, $dom);
                }

                if (in_array($type, ['button', 'link'], true)) {
                    if (is_array($override) && isset($override['href']) && $override['href'] !== '') {
                        $node->setAttribute('href', $override['href']);
                    } elseif (!empty($element['internal_relative_href'])) {
                        $node->setAttribute('href', (string) $element['internal_relative_href']);
                    }
                }
            }
        }

        // Apply lazy-load promotion after per-element overrides so space.gif style
        // placeholders are finalized to real image URLs in static clone output.
        if ($wrapRoot instanceof DOMElement) {
            $this->promoteLazyLoadAttributes($wrapRoot);
        }

        // Extract the root wrapper's children as HTML
        $root = $xpath->query('//*[@id="__lp_root__"]');
        if (!$root || $root->length === 0) {
            return $originalHtml;
        }

        $result = '';
        foreach ($root->item(0)->childNodes as $child) {
            $childHtml = $dom->saveHTML($child);
            if ($childHtml !== false) {
                $result .= $childHtml;
            }
        }

        // CSS 背景画像オーバーライド: client_data で差し替えられた場合に <style> を先頭に注入
        $secId = (string) ($section['id'] ?? '');
        $styleOverrides = '';
        foreach ($section['css_background_hints'] ?? [] as $bgIdx => $hint) {
            $syntheticId = 'css_bg_' . $secId . '_' . $bgIdx;
            $bgOverride  = $elemData[$syntheticId] ?? null;
            if (!is_array($bgOverride) || empty($bgOverride['src'])) {
                continue;
            }
            $token  = trim((string) ($hint['token'] ?? ''));
            $newSrc = trim((string) $bgOverride['src']);
            if ($token === '' || $newSrc === '') {
                continue;
            }
            $styleOverrides .= $token . '{background-image:url(' . json_encode($newSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ')!important}' . "\n";
        }
        if ($styleOverrides !== '') {
            $result = '<style>' . "\n" . $styleOverrides . '</style>' . "\n" . $result;
        }

        return $result;
    }

    /**
     * Replace all child nodes of $node with a single text node.
     * Preserves the element's tag and attributes.
     */
    private function setNodeText(DOMElement $node, string $text, DOMDocument $dom): void
    {
        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }
        $node->appendChild($dom->createTextNode($text));
    }

    /**
     * Inline lazy-loading attributes because clone output strips runtime scripts.
     */
    private function promoteLazyLoadAttributes(DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return;
        }

        $xp = new DOMXPath($doc);
        $nodes = $xp->query('.//*[@data-src or @data-lazy-src or @data-original or @data-srcset]');
        if (!$nodes) {
            return;
        }

        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($node->tagName);
            $dataSrc = trim((string) (
                $node->getAttribute('data-src')
                ?: $node->getAttribute('data-lazy-src')
                ?: $node->getAttribute('data-original')
            ));

            if ($dataSrc !== '') {
                if ($tag === 'img') {
                    $src = trim((string) $node->getAttribute('src'));
                    if ($src === '' || str_contains($src, 'space.gif')) {
                        $node->setAttribute('src', $dataSrc);
                    }
                } elseif ($tag === 'iframe') {
                    $src = trim((string) $node->getAttribute('src'));
                    if ($src === '' || str_contains($src, 'space.gif')) {
                        $node->setAttribute('src', $dataSrc);
                    }
                }
            }

            $dataSrcset = trim((string) $node->getAttribute('data-srcset'));
            if ($dataSrcset !== '') {
                $srcset = trim((string) $node->getAttribute('srcset'));
                if ($srcset === '') {
                    $node->setAttribute('srcset', $dataSrcset);
                }
            }
        }
    }
}
