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
 * クロール深さは {@see INTERNAL_LINK_CRAWL_MAX_DEPTH} のとおり **1 のみ**。
 * 対象 URL は **エントリページ解析結果のセクションに現れた内部リンクだけ**であり、
 * 内部ページの HTML を解析してもそこから見つかったリンクは **追記・追従しない**。
 */
final class LpInternalPagesPipeline
{
    /** トップ（エントリ）からのリンクのみ。内部ページ内で見つかったリンクはクロールしない */
    public const INTERNAL_LINK_CRAWL_MAX_DEPTH = 1;

    public const MAX_PAGES = 20;
    private const INTERNAL_ASSET_MAX_NEW_DOWNLOADS = 220;
    private const INTERNAL_ASSET_MAX_ELAPSED_SECONDS = 75;
    private const PIPELINE_MAX_ELAPSED_SECONDS = 300;

    /**
     * @param callable(array<string, mixed>): void|null $emit NDJSON progress のときのみ
     */
    public static function run(
        array &$structure,
        string $dataDir,
        string $outputDir,
        ?callable $emit = null
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

        /* 深さ1: エントリの $structure だけから URL を列挙（サブページの構造は見ない） */
        $urls = self::collectInternalDocumentUrlsFromEntryStructure($structure);
        $urls = array_values(array_filter($urls, static fn(string $u): bool => $u !== $entryCanon));
        $urls = array_values(array_unique($urls));
        sort($urls);
        $urls = array_slice($urls, 0, self::MAX_PAGES);

        $manifest = [];

        $fetcher    = new LpFetcher();
        $downloader = new LpAssetDownloader($outputDir);
        $analyzer   = new LpAnalyzer();
        $mapper     = new LpMapper();

        $assetPath       = $dataDir . 'asset_map.json';
        $den             = max(1, count($urls));
        $mapCanonToOutput = [];
        /** @var array<string, array{structure_file: string, output_file: string, section_count: int, resolved_identity: string}> */
        $visitedIdentityArtifacts = [];
        /** @var array<string, array{structure_file: string, output_file: string, section_count: int, resolved_identity: string}> */
        $visitedNormSourceArtifacts = [];
        $pipelineStartedAt = microtime(true);

        foreach ($urls as $i => $canonUrl) {
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

            // link_redirect_check が 52〜58 を使用するため、ここは 60〜99 の帯域にする
            $pct = 60 + (int) round(39 * (($i + 1) / $den));
            $pct = min(99, $pct);
            $emitInternal = static function (string $detailJa) use ($emit, $pct, $i, $den): void {
                if ($emit === null) {
                    return;
                }
                $emit([
                    'type'      => 'progress',
                    'phase'     => 'internal_pages',
                    'pct'       => $pct,
                    'detail_ja' => sprintf(
                        '内部ページ取得・解析 (%s / %s) %s',
                        (string) ($i + 1),
                        (string) $den,
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
                $emitInternal('visited: エントリ由来の同一ドキュメントのため再利用します…');
                $prev = $visitedNormSourceArtifacts[$normSource];
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
                    'max_new_downloads' => self::INTERNAL_ASSET_MAX_NEW_DOWNLOADS,
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
                $sub = $analyzer->analyze($html, $finalUrl);
                unset($sub['parse_diagnostics']);
                $sub = $mapper->enrich($sub);
                $sub['internal_pages'] = [];

                $structureRel = 'internal_pages/' . $slug . '.json';
                self::storagePut($dataDir . $structureRel, json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $outputRel = 'pages/' . $slug . '.html';
                $manifest[] = [
                    'canonical_url'    => $identity,
                    'source_canonical' => $canonUrl,
                    'structure_file'  => $structureRel,
                    'output_file'     => $outputRel,
                    'fetch_ok'        => true,
                    'final_fetch_url' => $finalUrl,
                    'section_count'   => count($sub['sections'] ?? []),
                    'asset_sync_limited' => $assetSyncLimited,
                    'asset_new_downloads' => $downloader->getNewDownloadCount(),
                ];

                $artifact = [
                    'structure_file'     => $structureRel,
                    'output_file'        => $outputRel,
                    'section_count'      => count($sub['sections'] ?? []),
                    'resolved_identity'  => $identity,
                ];
                $visitedIdentityArtifacts[$identity]    = $artifact;
                $visitedNormSourceArtifacts[$normSource] = $artifact;
                $mapCanonToOutput[$identity] = $outputRel;
                $mapCanonToOutput[$canonUrl] = $outputRel;
            } catch (Throwable $e) {
                $manifest[] = [
                    'canonical_url'  => $canonUrl,
                    'structure_file' => null,
                    'output_file'    => null,
                    'fetch_ok'       => false,
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
    private static function collectInternalDocumentUrlsFromEntryStructure(array $structure): array
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
    private static function patchInternalRelativeHrefs(array &$structure, array $urlToOutput): void
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
     * @throws RuntimeException
     */
    private static function storagePut(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('内部ページ構造の保存に失敗しました: ' . $path);
        }
    }
}
