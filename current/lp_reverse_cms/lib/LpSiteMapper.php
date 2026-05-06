<?php

declare(strict_types=1);

require_once __DIR__ . '/LpCmsDetector.php';
require_once __DIR__ . '/LpIoNeutralizer.php';

final class LpSiteMapper
{
    /**
     * @param array<string,mixed> $entryStructure
     * @param array<string,mixed>|null $diag
     * @return array<string,mixed>
     */
    public static function build(array &$entryStructure, string $dataDir, string $outputDir, ?array $diag = null): array
    {
        $sourceUrl = (string) ($entryStructure['source_url'] ?? '');
        $meta = is_array($entryStructure['meta'] ?? null) ? $entryStructure['meta'] : [];
        $fetchedHtml = (string) file_get_contents($dataDir . 'fetched.html');
        $cmsInfo = LpCmsDetector::detect($fetchedHtml);

        $resources = self::buildResources($dataDir, $outputDir);
        $pages = [];

        $entryIoRegions = LpIoNeutralizer::detectRegions($entryStructure, 'entry');
        $pages['index'] = [
            'source_url' => $sourceUrl,
            'local_path' => self::relOutputPath($outputDir, 'index.html'),
            'coordinate' => 'entry',
            'status' => 'ok',
            'page_type' => [
                'template' => 'front-page',
                'post_type' => 'page',
                'post_id' => null,
                'is_archive' => false,
                'is_singular' => true,
                'slug' => 'index',
            ],
            'rendering_notes' => [
                'has_js_dependent_content' => false,
                'has_lazy_load' => self::containsLazyLoad($entryStructure),
                'has_picture_source' => self::containsPictureSource($entryStructure),
                'has_gutenberg_blocks' => (bool) ($cmsInfo['has_gutenberg'] ?? false),
                'inline_styles_count' => self::countInlineStyles($entryStructure),
                'snapshot_reliability' => 'full',
            ],
            'dynamic_regions' => [],
            'data_io_regions' => $entryIoRegions,
            'sections' => self::siteMapSections($entryStructure, 'entry'),
        ];

        $internals = $entryStructure['internal_pages'] ?? [];
        if (is_array($internals)) {
            foreach ($internals as $i => $ip) {
                if (!is_array($ip)) {
                    continue;
                }
                $coord = sprintf('internal[%d]', (int) $i);
                $status = !empty($ip['fetch_ok']) ? 'ok' : 'error';
                $source = (string) ($ip['final_fetch_url'] ?? $ip['canonical_url'] ?? '');
                $key = 'internal_' . (string) $i;
                $page = [
                    'source_url' => $source,
                    'local_path' => self::relOutputPath($outputDir, (string) ($ip['output_file'] ?? '')),
                    'coordinate' => $coord,
                    'status' => $status,
                    'page_type' => ['template' => 'page', 'slug' => $key],
                    'rendering_notes' => ['snapshot_reliability' => $status === 'ok' ? 'full' : 'none'],
                    'dynamic_regions' => [],
                    'data_io_regions' => [],
                    'sections' => [],
                ];
                if ($status !== 'ok') {
                    $page['error'] = [
                        'phase' => 'internal_pages',
                        'severity' => 'fatal',
                        'message' => (string) ($ip['error'] ?? 'internal page fetch failed'),
                    ];
                }
                $pages[$key] = $page;
            }
        }

        return [
            'meta' => [
                'entry_url' => $sourceUrl,
                'cloned_at' => gmdate('c'),
                'charset' => (string) ($meta['charset'] ?? 'UTF-8'),
                'viewport' => (string) ($meta['viewport'] ?? 'width=device-width, initial-scale=1'),
                'base_url' => (string) ($entryStructure['clone_site']['scheme_host'] ?? $sourceUrl),
                'cms' => $cmsInfo,
                'parse_diagnostics' => $diag,
            ],
            'resources' => $resources,
            'pages' => $pages,
        ];
    }

    /**
     * @return array{css:list<array<string,mixed>>,js:list<array<string,mixed>>,fonts:list<array<string,mixed>>,images:list<array<string,mixed>>}
     */
    private static function buildResources(string $dataDir, string $outputDir): array
    {
        $assetMapPath = $dataDir . 'asset_map.json';
        $assetMap = [];
        if (is_readable($assetMapPath)) {
            $dec = json_decode((string) file_get_contents($assetMapPath), true);
            if (is_array($dec)) {
                $assetMap = $dec;
            }
        }

        $out = ['css' => [], 'js' => [], 'fonts' => [], 'images' => []];
        $seen = [];
        $order = 0;
        foreach ($assetMap as $orig => $local) {
            if (!is_string($orig) || !is_string($local) || isset($seen[$local])) {
                continue;
            }
            $seen[$local] = true;
            $lower = strtolower($local);
            $row = [
                'original_url' => $orig,
                'local_path' => self::relOutputPath($outputDir, $local),
                'load_order' => $order++,
            ];
            if (str_contains($lower, '/assets/css/')) {
                $row['applied_to'] = ['index'];
                $row['has_imports'] = false;
                $row['has_variables'] = false;
                $out['css'][] = $row;
            } elseif (str_contains($lower, '/assets/js/')) {
                $row['defer'] = true;
                $row['affects_rendering'] = true;
                $out['js'][] = $row;
            } elseif (str_contains($lower, '/assets/fonts/')) {
                $row['format'] = pathinfo($local, PATHINFO_EXTENSION) ?: 'unknown';
                $row['used_in'] = ['index'];
                $out['fonts'][] = $row;
            } elseif (str_contains($lower, '/assets/img/')) {
                $row['has_srcset'] = false;
                $row['srcset_variants'] = [];
                $row['used_in_picture'] = false;
                $out['images'][] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $structure
     * @return list<array<string,mixed>>
     */
    private static function siteMapSections(array $structure, string $pageCoordinate): array
    {
        $out = [];
        foreach ($structure['sections'] ?? [] as $i => $s) {
            if (!is_array($s)) {
                continue;
            }
            $out[] = [
                'id' => (string) ($s['id'] ?? ('section-' . $i)),
                'coordinate' => sprintf('%s.section[%d]', $pageCoordinate, (int) $i),
                'type' => (string) ($s['section_type'] ?? 'section'),
                'elements' => [],
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $structure
     */
    private static function containsLazyLoad(array $structure): bool
    {
        foreach ($structure['sections'] ?? [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $h = (string) ($s['html'] ?? '');
            if (str_contains($h, 'data-src') || str_contains($h, 'loading="lazy"')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $structure
     */
    private static function containsPictureSource(array $structure): bool
    {
        foreach ($structure['sections'] ?? [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            if (str_contains((string) ($s['html'] ?? ''), '<source')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $structure
     */
    private static function countInlineStyles(array $structure): int
    {
        $count = 0;
        foreach ($structure['sections'] ?? [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $html = (string) ($s['html'] ?? '');
            if ($html === '') {
                continue;
            }
            $count += preg_match_all('/\sstyle=/i', $html);
        }

        return $count;
    }

    private static function relOutputPath(string $outputDir, string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'output/')) {
            return $path;
        }
        $base = basename(rtrim($outputDir, '/\\'));

        return 'output/' . $base . '/' . ltrim($path, '/\\');
    }
}

