<?php

declare(strict_types=1);

/**
 * Scans generated HTML for URLs that should have been localized.
 */
final class LpOutputAudit
{
    /**
     * @return array{
     *   total:int,
     *   items: list<array{url:string, context:string, reason:string}>
     * }
     */
    public static function scanUnreplaced(string $html): array
    {
        $items = [];

        // コメント内の href/src は実 DOM に載らない — 未置換の誤検知を防ぐ
        $html = preg_replace('#<!--[\s\S]*?-->#u', '', $html) ?? $html;

        $patterns = [
            'attr_https'  => '/(?:href|src|poster|data-src|data-bg|data-lazy-src|data-original)\s*=\s*["\']?(https?:\/\/[^"\'\s>]+)/i',
            'attr_proto'  => '/(?:href|src|poster|data-src|data-bg)\s*=\s*["\']?(\/\/[^"\'\s>]+)/i',
            'srcset'      => '/srcset\s*=\s*["\']([^"\']+)["\']/i',
            'url_css'     => '/url\(\s*["\']?(https?:\/\/[^)"\']+)["\']?\s*\)/i',
            'url_proto'   => '/url\(\s*["\']?(\/\/[^)"\']+)["\']?\s*\)/i',
            'bad_backslash' => '/(https?:\/\/[a-zA-Z0-9.\[\]:_-]+)\\\\(?=\/)/',
            'bad_pct'     => '/(https?:\/\/[a-zA-Z0-9.\[\]:_-]+)(%5[Cc])(\/)/i',
        ];

        foreach ($patterns as $ctx => $re) {
            if (preg_match_all($re, $html, $m)) {
                foreach ($m[1] as $hit) {
                    if ($ctx === 'srcset') {
                        foreach (explode(',', $hit) as $part) {
                            $u = trim(preg_split('/\s+/', trim($part))[0] ?? '');
                            if ($u === '') {
                                continue;
                            }
                            if (preg_match('#^(https?:)?//#', $u) || str_starts_with($u, 'http')) {
                                self::pushUnreplaced($items, $u, 'srcset');
                            }
                        }
                        continue;
                    }
                    if ($ctx === 'bad_backslash' || $ctx === 'bad_pct') {
                        self::pushUnreplaced($items, $hit, 'malformed_windows_url');
                        continue;
                    }
                    self::pushUnreplaced($items, $hit, $ctx);
                }
            }
        }

        // De-duplicate by url + context
        $seen = [];
        $uniq = [];
        foreach ($items as $it) {
            $k = $it['url'] . '|' . $it['context'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $uniq[] = $it;
        }

        return [
            'total' => count($uniq),
            'items' => $uniq,
        ];
    }

    /**
     * @param list<array{url:string, context:string, reason:string}> $items
     */
    private static function pushUnreplaced(array &$items, string $url, string $context): void
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
            return;
        }
        if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:')) {
            return;
        }

        // preconnect / dns-prefetch 用の「ホストだけ」の href は未置換として出さない
        $pu = parse_url($url);
        $host = strtolower($pu['host'] ?? '');
        $path = $pu['path'] ?? '';
        if (($path === '' || $path === '/') && in_array($host, ['fonts.googleapis.com', 'fonts.gstatic.com'], true)) {
            return;
        }

        $reason = match ($context) {
            'malformed_windows_url' => 'Windows由来の不正スラッシュ（置換前の残骸の可能性）',
            'attr_proto', 'url_proto' => 'プロトコル相対URL（//）が残存',
            'attr_https', 'url_css'   => '絶対URLが残存（asset_map未適用または外部のみ）',
            'srcset'                  => 'srcset内に絶対URLが残存',
            default                   => '未分類',
        };

        $items[] = ['url' => $url, 'context' => $context, 'reason' => $reason];
    }

    public static function persist(string $outputIndexPath, string $dataDir): array
    {
        if (!file_exists($outputIndexPath)) {
            $empty = ['total' => 0, 'items' => [], 'generated_at' => null];
            file_put_contents(
                $dataDir . 'output_unreplaced.json',
                json_encode($empty, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            return $empty;
        }

        $html = (string) file_get_contents($outputIndexPath);
        $scan = self::scanUnreplaced($html);
        $scan['generated_at'] = date('Y-m-d H:i:s');

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        file_put_contents(
            $dataDir . 'output_unreplaced.json',
            json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $scan;
    }

    /**
     * 生成物 `output/assets/css/*.css` 内の外部 URL 残存（診断 v1.2+）。
     *
     * @return array{
     *   files_scanned: int,
     *   url_in_css: list<array{file:string, url:string, context:string}>,
     *   import_external: list<array{file:string, url:string, snippet:string}>
     * }
     */
    public static function scanOutputCssForDiagnostics(string $outputDir): array
    {
        $urlHits    = [];
        $importHits = [];
        $cssDir     = rtrim($outputDir, '/\\') . '/assets/css';
        if (!is_dir($cssDir)) {
            return [
                'files_scanned'     => 0,
                'url_in_css'        => [],
                'import_external'   => [],
            ];
        }

        $seenI = [];
        $seenU = [];
        $n     = 0;
        foreach (scandir($cssDir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || !str_ends_with(strtolower($f), '.css')) {
                continue;
            }
            $n++;
            $rel  = 'assets/css/' . $f;
            $path = $cssDir . '/' . $f;
            $text = (string) file_get_contents($path);

            if (preg_match_all('/url\(\s*["\']?(https?:\/\/[^)"\s]+|\/\/[^)"\s]+)["\']?\s*\)/i', $text, $m)) {
                foreach ($m[1] as $u) {
                    $u = trim($u);
                    if ($u === '' || str_starts_with($u, 'data:') || str_starts_with($u, 'mailto:') || str_starts_with($u, 'tel:')) {
                        continue;
                    }
                    $k = $rel . '|' . $u;
                    if (isset($seenU[$k])) {
                        continue;
                    }
                    $seenU[$k]              = true;
                    $urlHits[] = ['file' => $rel, 'url' => $u, 'context' => 'url()'];
                }
            }

            if (preg_match_all('/@import\s+[^;]+;/i', $text, $blocks)) {
                foreach ($blocks[0] as $block) {
                    if (preg_match('/(https?:\/\/[^\s"\';)]+|\/\/[^\s"\';)]+)/i', (string) $block, $im)) {
                        $u = $im[1];
                        if (str_starts_with($u, 'data:')) {
                            continue;
                        }
                        $k = $rel . '|' . $u;
                        if (isset($seenI[$k])) {
                            continue;
                        }
                        $seenI[$k] = true;
                        $importHits[] = [
                            'file'    => $rel,
                            'url'     => $u,
                            'snippet' => trim($block),
                        ];
                    }
                }
            }
        }

        return [
            'files_scanned'   => $n,
            'url_in_css'      => $urlHits,
            'import_external' => $importHits,
        ];
    }
}
