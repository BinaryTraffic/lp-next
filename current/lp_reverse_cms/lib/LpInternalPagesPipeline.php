<?php

declare(strict_types=1);

require_once __DIR__ . '/LpAnalyzer.php';
require_once __DIR__ . '/LpAssetDownloader.php';
require_once __DIR__ . '/LpFetcher.php';
require_once __DIR__ . '/LpMapper.php';
require_once __DIR__ . '/LpUrlContext.php';

/**
 * エントリーページから辿れる同一ホストの HTML リンクを取得・解析し、成果物に複製ページとして載せる。
 *
 * クロール深さは run() の $crawlDepth パラメータで指定する（デフォルト=1）。
 * 実際のサイトの最大深さは scanLinkDepth() で事前に計測できる。
 */
final class LpInternalPagesPipeline
{
    /** デフォルトのクロール深さ */
    public const INTERNAL_LINK_CRAWL_MAX_DEPTH = 1;

    public const MAX_PAGES = 100;
    private const INTERNAL_ASSET_MAX_NEW_DOWNLOADS = 220;
    private const INTERNAL_ASSET_MAX_ELAPSED_SECONDS = 75;
    private const PIPELINE_MAX_ELAPSED_SECONDS = 300;

    /**
     * エントリ構造から深さ1の内部ページ候補 URL を返す（重複除去・MAX_PAGES 制限済み）。
     * 複数深さのURL収集は run() の $crawlDepth パラメータで制御する。
     *
     * @param array<string,mixed> $structure
     * @return list<string>
     */
    public static function extractCandidateUrls(array $structure, string $entryUrl): array
    {
        $entryCanon = LpUrlContext::canonicalHttpDocumentIdentity($entryUrl);
        $urls = self::collectInternalDocumentUrlsFromEntryStructure($structure);
        $urls = array_values(array_filter($urls, static fn(string $u): bool => $u !== $entryCanon));
        $urls = array_values(array_unique($urls));
        sort($urls);

        return array_slice($urls, 0, self::MAX_PAGES);
    }

    /**
     * 内部ページ 1 件を取得・解析してマニフェストエントリを返す
     *
     * @return array{
     *   fetch_ok: bool,
     *   canonical_url: string,
     *   source_canonical: string,
     *   structure_file: string|null,
     *   final_fetch_url?: string,
     *   section_count: int,
     *   asset_new_downloads: int,
     *   asset_sync_limited?: bool,
     *   error?: string
     * }
     */
    /**
     * @param callable(string):void|null $log heartbeat logger — receives a plain message string
     */
    public static function processSingleUrl(string $canonUrl, string $dataDir, string $outputDir, ?callable $log = null): array
    {
        $dataDir   = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR;
        $outputDir = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($dataDir . 'internal_pages')) {
            mkdir($dataDir . 'internal_pages', 0755, true);
        }

        $assetPath = $dataDir . 'asset_map.json';
        $existing = [];
        if (is_readable($assetPath)) {
            $dec = json_decode((string) file_get_contents($assetPath), true);
            if (is_array($dec)) {
                /** @var array<string, string> $existing */
                $existing = $dec;
            }
        }

        $fetcher    = new LpFetcher();
        $downloader = new LpAssetDownloader($outputDir);
        $analyzer   = new LpAnalyzer();
        $mapper     = new LpMapper();

        try {
            if ($log) { $log('  fetch start'); }
            $t0       = microtime(true);
            $res      = $fetcher->fetch($canonUrl);
            $html     = $res['html'];
            $finalUrl = $res['final_url'];
            $identity = LpUrlContext::canonicalHttpDocumentIdentity($finalUrl);
            $slug     = self::slugForCanonical($canonUrl);
            if ($log) { $log(sprintf('  fetch done elapsed=%.1fs html_bytes=%d', microtime(true) - $t0, strlen($html))); }

            if ($log) { $log('  asset_sync start existing=' . count($existing)); }
            $t0     = microtime(true);
            $newMap = $downloader->downloadAll($html, $finalUrl, $existing, [
                'max_new_downloads'   => self::INTERNAL_ASSET_MAX_NEW_DOWNLOADS,
                'max_elapsed_seconds' => self::INTERNAL_ASSET_MAX_ELAPSED_SECONDS,
            ]);
            $merged = array_merge($existing, $newMap);
            file_put_contents(
                $assetPath,
                json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
            if ($log) { $log(sprintf('  asset_sync done elapsed=%.1fs new=%d limited=%s', microtime(true) - $t0, count($newMap), $downloader->hasExceededBudget() ? 'true' : 'false')); }

            if ($log) { $log('  analyze start html_bytes=' . strlen($html)); }
            $t0  = microtime(true);
            $sub = $analyzer->analyze($html, $finalUrl);
            unset($sub['parse_diagnostics']);
            if ($log) { $log(sprintf('  analyze done elapsed=%.1fs sections=%d', microtime(true) - $t0, count($sub['sections'] ?? []))); }

            if ($log) { $log('  enrich start'); }
            $t0  = microtime(true);
            $sub = $mapper->enrich($sub);
            $sub['internal_pages'] = [];
            if ($log) { $log(sprintf('  enrich done elapsed=%.1fs', microtime(true) - $t0)); }

            $structureRel = 'internal_pages/' . $slug . '.json';
            self::storagePut(
                $dataDir . $structureRel,
                json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            return [
                'fetch_ok'            => true,
                'canonical_url'       => $identity,
                'source_canonical'    => $canonUrl,
                'structure_file'      => $structureRel,
                'final_fetch_url'     => $finalUrl,
                'section_count'       => count($sub['sections'] ?? []),
                'asset_new_downloads' => $downloader->getNewDownloadCount(),
                'asset_sync_limited'  => $downloader->hasExceededBudget(),
            ];
        } catch (Throwable $e) {
            return [
                'fetch_ok'            => false,
                'canonical_url'       => $canonUrl,
                'source_canonical'    => $canonUrl,
                'structure_file'      => null,
                'section_count'       => 0,
                'asset_new_downloads' => 0,
                'error'               => $e->getMessage(),
            ];
        }
    }
    /** 内部ページ 1 件あたり DOM 走査のウォール時計上限（巨大ページで無限に近い処理にならないようにする） */
    private const INTERNAL_PAGE_ANALYZE_MAX_WALL_SECONDS = 90.0;

    /**
     * エントリURLから同一ホスト内部リンクを BFS で辿り、実際の最大深さを計測する（軽量スキャン）。
     * フェッチのみ実施。アセット取得・構造解析は行わない。
     *
     * @param callable(array{depth:int,found:int}):void|null $emit 深さごとの進捗コールバック
     * @return array{discovered_depth: int, url_count_by_depth: array<int,int>}
     */
    public static function scanLinkDepth(
        string $entryUrl,
        int $maxDepth = 10,
        int $maxPagesTotal = 300,
        ?callable $emit = null
    ): array {
        $entryCanon  = LpUrlContext::canonicalHttpDocumentIdentity($entryUrl);
        $parsedEntry = parse_url($entryCanon);
        $schemeHost  = ($parsedEntry['scheme'] ?? 'https') . '://' . ($parsedEntry['host'] ?? '');

        $fetcher         = new LpFetcher();
        $visited         = [$entryCanon => true];
        $currentLevel    = [$entryCanon];
        $discoveredDepth = 0;
        $urlCountByDepth = [];
        $totalVisited    = 1;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $nextLevel = [];
            foreach ($currentLevel as $url) {
                try {
                    $res   = $fetcher->fetch($url);
                    $links = self::extractRawInternalLinks($res['html'], $res['final_url'], $schemeHost);
                    foreach ($links as $link) {
                        if (!isset($visited[$link]) && $totalVisited < $maxPagesTotal) {
                            $visited[$link] = true;
                            $nextLevel[]    = $link;
                            $totalVisited++;
                        }
                    }
                } catch (Throwable) {
                    // フェッチ失敗はスキップ
                }
            }
            $nextLevel = array_values(array_unique($nextLevel));
            if (empty($nextLevel)) {
                break;
            }
            $discoveredDepth          = $depth;
            $urlCountByDepth[$depth]  = count($nextLevel);
            $currentLevel             = $nextLevel;
            if ($emit !== null) {
                $emit(['depth' => $depth, 'found' => count($nextLevel)]);
            }
        }

        return [
            'discovered_depth'   => $discoveredDepth,
            'url_count_by_depth' => $urlCountByDepth,
        ];
    }

    /**
     * HTML から同一ホストの内部ドキュメント URL を抽出する（scanLinkDepth 専用の軽量版）。
     *
     * @return list<string>
     */
    private static function extractRawInternalLinks(string $html, string $baseUrl, string $schemeHost): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        $baseParsed = parse_url(rtrim($baseUrl, '/'));
        $baseDir    = $schemeHost . rtrim(dirname($baseParsed['path'] ?? '/'), '/');
        $scheme     = $baseParsed['scheme'] ?? 'https';

        $out = [];
        /** @var DOMElement $a */
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }
            if (preg_match('#^https?://#i', $href)) {
                $abs = $href;
            } elseif (str_starts_with($href, '//')) {
                $abs = $scheme . ':' . $href;
            } elseif (str_starts_with($href, '/')) {
                $abs = $schemeHost . $href;
            } else {
                $abs = $baseDir . '/' . $href;
            }
            $abs = (string) preg_replace('/#.*$/', '', $abs);
            if (!str_starts_with($abs, $schemeHost)) {
                continue;
            }
            $canon = LpUrlContext::canonicalHttpDocumentIdentity($abs);
            if (!LpUrlContext::isLikelyHtmlDocumentUrl($canon)) {
                continue;
            }
            $out[] = $canon;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param callable(array<string, mixed>): void|null $emit NDJSON progress のときのみ
     * @param int $crawlDepth クロール深さ（1=エントリ直下のみ、2以上=再帰）。scanLinkDepth() の discovered_depth を上限の目安にする。
     */
    public static function run(
        array &$structure,
        string $dataDir,
        string $outputDir,
        ?callable $emit = null,
        int $crawlDepth = self::INTERNAL_LINK_CRAWL_MAX_DEPTH
    ): void {
        $dataDir   = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR;
        $outputDir = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($dataDir . 'internal_pages')) {
            mkdir($dataDir . 'internal_pages', 0755, true);
        }
        foreach (glob($dataDir . 'internal_pages/*.json') ?: [] as $old) {
            @unlink($old);
        }

        $cloneSite = $structure['clone_site'] ?? [];
        $cloneSite = is_array($cloneSite) ? $cloneSite : [];
        $schemeHost = (string) ($cloneSite['scheme_host'] ?? '');
        $entryRaw   = (string) ($cloneSite['entry_url'] ?? $structure['source_url'] ?? '');
        if ($schemeHost === '' || $entryRaw === '') {
            $structure['internal_pages'] = [];

            return;
        }

        $entryCanon = LpUrlContext::canonicalHttpDocumentIdentity($entryRaw);
        if (!preg_match('#^https?://#i', $entryCanon)) {
            $structure['internal_pages'] = [];

            return;
        }

        /* BFS キュー構築: エントリ構造から深さ1のURLを初期投入 */
        $initialUrls = self::collectInternalDocumentUrlsFromEntryStructure($structure);
        $initialUrls = array_values(array_filter($initialUrls, static fn(string $u): bool => $u !== $entryCanon));
        $initialUrls = array_values(array_unique($initialUrls));
        usort($initialUrls, static function (string $a, string $b) use ($entryCanon): int {
            $entryPath = rtrim(parse_url($entryCanon, PHP_URL_PATH) ?? '/', '/') . '/';
            $pa = parse_url($a, PHP_URL_PATH) ?? '/';
            $pb = parse_url($b, PHP_URL_PATH) ?? '/';
            $aUnder = (int) str_starts_with($pa, $entryPath);
            $bUnder = (int) str_starts_with($pb, $entryPath);
            if ($aUnder !== $bUnder) {
                return $bUnder - $aUnder;
            }
            $da = substr_count(trim($pa, '/'), '/');
            $db = substr_count(trim($pb, '/'), '/');
            if ($da !== $db) {
                return $da - $db;
            }
            return strcmp($a, $b);
        });

        // queue: ['url' => string, 'depth' => int]
        $queue   = [];
        $visited = [$entryCanon => true];
        foreach (array_slice($initialUrls, 0, self::MAX_PAGES) as $u) {
            $queue[]         = ['url' => $u, 'depth' => 1];
            $visited[$u]     = true;
        }

        $manifest = [];

        $fetcher    = new LpFetcher();
        $downloader = new LpAssetDownloader($outputDir);
        $analyzer   = new LpAnalyzer();
        $mapper     = new LpMapper();

        $assetPath       = $dataDir . 'asset_map.json';
        $mapCanonToOutput = [];
        /** @var array<string, array{structure_file: string, output_file: string, section_count: int, resolved_identity: string}> */
        $visitedIdentityArtifacts = [];
        /** @var array<string, array{structure_file: string, output_file: string, section_count: int, resolved_identity: string}> */
        $visitedNormSourceArtifacts = [];
        $pipelineStartedAt = microtime(true);
        $processedCount    = 0;

        while (!empty($queue)) {
            if ((microtime(true) - $pipelineStartedAt) >= self::PIPELINE_MAX_ELAPSED_SECONDS) {
                if ($emit !== null) {
                    $emit([
                        'type'      => 'progress',
                        'phase'     => 'internal_pages',
                        'pct'       => 99,
                        'detail_ja' => sprintf(
                            '内部ページ処理の上限時間（%s秒）に到達したため、残りをスキップします',
                            (string) self::PIPELINE_MAX_ELAPSED_SECONDS
                        ),
                    ]);
                }
                break;
            }

            $item      = array_shift($queue);
            $canonUrl  = $item['url'];
            $itemDepth = $item['depth'];
            $processedCount++;

            // link_redirect_check が 52〜58 を使用するため、ここは 60〜99 の帯域にする
            $totalKnown = $processedCount + count($queue);
            $pct = 60 + (int) min(39, round(39 * ($processedCount / max(1, $totalKnown))));
            $emitInternal = static function (string $detailJa) use ($emit, $pct, $processedCount, $itemDepth): void {
                if ($emit === null) {
                    return;
                }
                $emit([
                    'type'      => 'progress',
                    'phase'     => 'internal_pages',
                    'pct'       => $pct,
                    'detail_ja' => sprintf(
                        '内部ページ取得・解析 #%s (深さ%s) %s',
                        (string) $processedCount,
                        (string) $itemDepth,
                        $detailJa
                    ),
                ]);
            };

            $existing = [];
            if (is_readable($assetPath)) {
                $dec = json_decode((string) file_get_contents($assetPath), true);
                if (is_array($dec)) {
                    /** @var array<string, string> $existing */
                    $existing = $dec;
                }
            }

            $normSource = LpUrlContext::canonicalHttpDocumentIdentity($canonUrl);
            if (isset($visitedNormSourceArtifacts[$normSource])) {
                $emitInternal('visited: 同一ドキュメントのため再利用します…');
                $prev     = $visitedNormSourceArtifacts[$normSource];
                $resolved = $prev['resolved_identity'];
                $manifest[] = [
                    'canonical_url'       => $resolved,
                    'source_canonical'    => $canonUrl,
                    'structure_file'      => $prev['structure_file'],
                    'output_file'         => $prev['output_file'],
                    'fetch_ok'            => true,
                    'final_fetch_url'     => $canonUrl,
                    'section_count'       => $prev['section_count'],
                    'asset_sync_limited'  => false,
                    'asset_new_downloads' => 0,
                    'depth'               => $itemDepth,
                    'dedup_reused'        => true,
                    'visited_skip'        => 'norm_source',
                ];
                $mapCanonToOutput[$resolved] = $prev['output_file'];
                $mapCanonToOutput[$canonUrl] = $prev['output_file'];
                continue;
            }

            $emitInternal('HTML を取得しています…');

            $slug = self::slugForCanonical($canonUrl);
            try {
                $res       = $fetcher->fetch($canonUrl);
                $html      = $res['html'];
                $finalUrl  = $res['final_url'];
                $identity  = LpUrlContext::canonicalHttpDocumentIdentity($finalUrl);

                if (isset($visitedIdentityArtifacts[$identity])) {
                    $emitInternal('visited: 最終ドキュメント同一のため再利用します…');
                    $prev = $visitedIdentityArtifacts[$identity];
                    $manifest[] = [
                        'canonical_url'       => $identity,
                        'source_canonical'    => $canonUrl,
                        'structure_file'      => $prev['structure_file'],
                        'output_file'         => $prev['output_file'],
                        'fetch_ok'            => true,
                        'final_fetch_url'     => $finalUrl,
                        'section_count'       => $prev['section_count'],
                        'asset_sync_limited'  => false,
                        'asset_new_downloads' => 0,
                        'depth'               => $itemDepth,
                        'dedup_reused'        => true,
                        'visited_skip'        => 'final_identity',
                    ];
                    $visitedNormSourceArtifacts[$normSource] = $prev;
                    $mapCanonToOutput[$identity] = $prev['output_file'];
                    $mapCanonToOutput[$canonUrl] = $prev['output_file'];
                    continue;
                }

                $emitInternal('アセットを同期しています（取得済みはスキップ）…');
                $newMap = $downloader->downloadAll($html, $finalUrl, $existing, [
                    'max_new_downloads'   => self::INTERNAL_ASSET_MAX_NEW_DOWNLOADS,
                    'max_elapsed_seconds' => self::INTERNAL_ASSET_MAX_ELAPSED_SECONDS,
                ]);
                $merged = array_merge($existing, $newMap);
                file_put_contents(
                    $assetPath,
                    json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );
                $assetSyncLimited = $downloader->hasExceededBudget();
                if ($assetSyncLimited) {
                    $emitInternal('アセット同期は上限到達のため続行します…');
                }

                $emitInternal('構造を解析しています…');
                $sub = $analyzer->analyze($html, $finalUrl, null, self::INTERNAL_PAGE_ANALYZE_MAX_WALL_SECONDS);
                unset($sub['parse_diagnostics']);
                $sub = $mapper->enrich($sub);
                $sub['internal_pages'] = [];

                $structureRel = 'internal_pages/' . $slug . '.json';
                self::storagePut($dataDir . $structureRel, json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $outputRel  = self::mirrorOutputPath($canonUrl, $entryCanon);
                $manifest[] = [
                    'canonical_url'       => $identity,
                    'source_canonical'    => $canonUrl,
                    'structure_file'      => $structureRel,
                    'output_file'         => $outputRel,
                    'fetch_ok'            => true,
                    'final_fetch_url'     => $finalUrl,
                    'section_count'       => count($sub['sections'] ?? []),
                    'asset_sync_limited'  => $assetSyncLimited,
                    'asset_new_downloads' => $downloader->getNewDownloadCount(),
                    'depth'               => $itemDepth,
                ];

                $artifact = [
                    'structure_file'    => $structureRel,
                    'output_file'       => $outputRel,
                    'section_count'     => count($sub['sections'] ?? []),
                    'resolved_identity' => $identity,
                ];
                $visitedIdentityArtifacts[$identity]     = $artifact;
                $visitedNormSourceArtifacts[$normSource] = $artifact;
                $mapCanonToOutput[$identity] = $outputRel;
                $mapCanonToOutput[$canonUrl] = $outputRel;

                // 指定深さに達していなければ、このページのリンクを次の深さとしてキューに追加
                if ($itemDepth < $crawlDepth && count($visited) < self::MAX_PAGES) {
                    $nextUrls = self::collectInternalDocumentUrlsFromEntryStructure($sub);
                    foreach ($nextUrls as $nextUrl) {
                        if (!isset($visited[$nextUrl]) && count($visited) < self::MAX_PAGES) {
                            $visited[$nextUrl] = true;
                            $queue[]           = ['url' => $nextUrl, 'depth' => $itemDepth + 1];
                        }
                    }
                }
            } catch (Throwable $e) {
                $manifest[] = [
                    'canonical_url'  => $canonUrl,
                    'structure_file' => null,
                    'output_file'    => null,
                    'fetch_ok'       => false,
                    'depth'          => $itemDepth,
                    'error'          => $e->getMessage(),
                ];
            }
        }

        $structure['internal_pages'] = $manifest;
        self::patchInternalRelativeHrefs($structure, $mapCanonToOutput);
    }

    /**
     * エントリページの構造のみから内部ドキュメント URL を集める（深さ1）。
     *
     * @return list<string>
     */
    public static function collectInternalDocumentUrlsFromEntryStructure(array $structure): array
    {
        $out = [];
        foreach ($structure['sections'] ?? [] as $sec) {
            foreach ($sec['elements'] ?? [] as $el) {
                if (($el['href_scope'] ?? '') !== 'internal') {
                    continue;
                }
                $c = $el['href_canonical'] ?? null;
                if (!is_string($c) || $c === '') {
                    continue;
                }
                if (!LpUrlContext::isLikelyHtmlDocumentUrl($c)) {
                    continue;
                }
                $out[] = $c;
            }
        }

        return $out;
    }

    private static function slugForCanonical(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }

    /**
     * @param array<string, string> $urlToOutput canonical → pages/foo.html
     */
    public static function patchInternalRelativeHrefs(array &$structure, array $urlToOutput): void
    {
        foreach ($structure['sections'] as &$sec) {
            foreach ($sec['elements'] as &$el) {
                if (($el['href_scope'] ?? '') !== 'internal') {
                    continue;
                }
                $canon = $el['href_canonical'] ?? null;
                if (!is_string($canon) || $canon === '') {
                    continue;
                }
                if (isset($urlToOutput[$canon])) {
                    $el['internal_relative_href'] = $urlToOutput[$canon];
                }
            }
            unset($el);
        }
        unset($sec);
    }

    /**
     * Derives an output-relative path that mirrors the source URL structure.
     * Pages directly under the entry URL preserve their sub-path (e.g. news/ → news/index.html).
     * Pages outside the entry URL path fall back to a hash-based subdirectory.
     */
    private static function mirrorOutputPath(string $pageUrl, string $entryUrl): string
    {
        $entryPath = rtrim(parse_url($entryUrl, PHP_URL_PATH) ?? '/', '/') . '/';
        $pagePath  = parse_url($pageUrl, PHP_URL_PATH) ?? '/';

        if (str_starts_with($pagePath, $entryPath)) {
            $rel = substr($pagePath, strlen($entryPath));
        } else {
            // Sibling or parent path: use hash-based sub-directory to avoid collisions
            return '_p/' . substr(hash('sha256', $pageUrl), 0, 12) . '/index.html';
        }

        $rel = trim($rel, '/');
        if ($rel === '') {
            return 'index.html';
        }
        // File extensions: keep as-is; directory-like paths: append /index.html
        if (preg_match('/\.(html?|php)$/i', $rel)) {
            return $rel;
        }
        return $rel . '/index.html';
    }

    /**
     * @throws RuntimeException
     */
    private static function storagePut(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('内部ページ構造の保存に失敗しました: ' . $path);
        }
    }
}
