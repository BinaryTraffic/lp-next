<?php

declare(strict_types=1);

require_once __DIR__ . '/LpUrlContext.php';

/**
 * Collects asset URLs referenced by source HTML + local CSS, compares with asset_map.
 */
final class LpAssetAudit
{
    private const LAZY_ATTRS = [
        'data-src', 'data-lazy-src', 'data-original', 'data-lazy',
        'data-bg', 'data-background', 'data-image', 'data-img',
    ];

    /** @return list<string> */
    public static function collectFromHtml(string $html, string $pageUrl): array
    {
        $ctx  = LpUrlContext::fromPageAndHtml($pageUrl, $html);
        $urls = [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//link[@href]') ?? [] as $link) {
            /** @var DOMElement $link */
            $rel = strtolower(trim($link->getAttribute('rel')));
            $as  = strtolower(trim($link->getAttribute('as')));
            if ($rel === 'stylesheet') {
                self::addAbs($urls, $ctx, $link->getAttribute('href'));
            } elseif ($rel === 'preload' && in_array($as, ['style', 'font', 'image', 'script'], true)) {
                self::addAbs($urls, $ctx, $link->getAttribute('href'));
            } elseif (str_contains($rel, 'icon')) {
                self::addAbs($urls, $ctx, $link->getAttribute('href'));
            }
        }
        foreach ($xpath->query('//script[@src]') ?? [] as $n) {
            self::addAbs($urls, $ctx, $n->getAttribute('src'));
        }
        foreach ($xpath->query('//img[@src]') ?? [] as $n) {
            self::addAbs($urls, $ctx, $n->getAttribute('src'));
        }
        foreach ($xpath->query('//img[@srcset]') ?? [] as $n) {
            self::addSrcset($urls, $ctx, $n->getAttribute('srcset'));
        }
        foreach ($xpath->query('//source[@srcset]') ?? [] as $n) {
            self::addSrcset($urls, $ctx, $n->getAttribute('srcset'));
        }
        foreach ($xpath->query('//source[@src]') ?? [] as $n) {
            self::addAbs($urls, $ctx, $n->getAttribute('src'));
        }

        foreach (self::LAZY_ATTRS as $attr) {
            foreach ($xpath->query('//*[@' . $attr . ']') ?? [] as $n) {
                $v = trim($n->getAttribute($attr));
                if (str_contains($v, ',')) {
                    self::addSrcset($urls, $ctx, $v);
                } else {
                    self::addAbs($urls, $ctx, $v);
                }
            }
        }
        foreach ($xpath->query('//*[@data-srcset]') ?? [] as $n) {
            self::addSrcset($urls, $ctx, $n->getAttribute('data-srcset'));
        }

        foreach ($xpath->query('//*[@style]') ?? [] as $n) {
            preg_match_all('/url\(\s*["\']?([^)"\']+)["\']?\s*\)/i', $n->getAttribute('style'), $m);
            foreach ($m[1] as $u) {
                self::addAbs($urls, $ctx, trim($u));
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * Extract url(...) from CSS text and return absolute URLs.
     *
     * @return list<string>
     */
    public static function collectUrlsFromCss(string $cssText, string $cssAbsoluteUrl): array
    {
        $urls = [];
        preg_match_all('/url\(\s*["\']?([^)"\']+)["\']?\s*\)/i', $cssText, $m);
        foreach ($m[1] as $raw) {
            $raw = trim($raw);
            if ($raw === '' || str_starts_with($raw, 'data:') || str_starts_with($raw, '#')) {
                continue;
            }
            $abs = LpUrlContext::resolveAgainstCssFile($raw, $cssAbsoluteUrl);
            if ($abs && !str_starts_with($abs, 'data:')) {
                $urls[] = $abs;
            }
        }

        preg_match_all('/@import\s+(?:url\()?["\']?([^"\')\s;]+)["\']?\)?/i', $cssText, $im);
        foreach ($im[1] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $abs = LpUrlContext::resolveAgainstCssFile($raw, $cssAbsoluteUrl);
            if ($abs) {
                $urls[] = $abs;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Map local path -> one canonical absolute key from asset_map (first match).
     *
     * @param array<string,string> $assetMap
     * @return array<string,string>
     */
    public static function invertAssetMap(array $assetMap): array
    {
        $inv = [];
        foreach ($assetMap as $abs => $local) {
            if (!isset($inv[$local])) {
                $inv[$local] = $abs;
            }
        }
        return $inv;
    }

    public static function normalizeUrlKey(string $url): string
    {
        $u = str_replace('\\', '/', trim($url));
        $u = preg_replace('#(https?://[^/]+)(%5[Cc])(/)#i', '$1/', $u) ?? $u;
        $u = preg_replace('#(https?://[a-zA-Z0-9.\[\]:_-]+)\\\\(?=/)#', '$1/', $u) ?? $u;
        $frag = strpos($u, '#');
        if ($frag !== false) {
            $u = substr($u, 0, $frag);
        }
        return rtrim($u, '/');
    }

    /**
     * Same logical asset may appear as /img/ vs /images/ or fonts.googleapis vs fonts.gstatic.
     *
     * @return list<string>
     */
    private static function candidateKeysForMapLookup(string $url): array
    {
        $norm = self::normalizeUrlKey($url);
        $out  = array_unique(array_filter([$url, $norm]));

        $p = parse_url($norm);
        if (!empty($p['host']) && !empty($p['path']) && $p['path'] !== '/') {
            $scheme = ($p['scheme'] ?? 'https') . '://';
            $host   = $p['host'];
            $path   = $p['path'];
            $q      = isset($p['query']) ? '?' . $p['query'] : '';

            if (preg_match('#^/img/(.+)$#', $path, $m)) {
                $out[] = $scheme . $host . '/images/' . $m[1] . $q;
            }
            if (preg_match('#^/images/(.+)$#', $path, $m)) {
                $out[] = $scheme . $host . '/img/' . $m[1] . $q;
            }

            if ($host === 'fonts.googleapis.com') {
                $out[] = 'https://fonts.gstatic.com' . $path . $q;
            }
            if ($host === 'fonts.gstatic.com') {
                $out[] = 'https://fonts.googleapis.com' . $path . $q;
            }
        }

        $withProto = [];
        foreach ($out as $c) {
            $withProto[] = $c;
            if (str_starts_with($c, 'https://')) {
                $withProto[] = '//' . substr($c, 8);
            }
            if (str_starts_with($c, 'http://')) {
                $withProto[] = '//' . substr($c, 7);
            }
        }

        return array_values(array_unique(array_filter($withProto)));
    }

    /**
     * Whether $absoluteUrl is considered "in map" (any variant).
     *
     * @param array<string,string> $assetMap
     */
    public static function isUrlMapped(string $absoluteUrl, array $assetMap): bool
    {
        foreach (self::candidateKeysForMapLookup($absoluteUrl) as $try) {
            if (isset($assetMap[$try])) {
                return true;
            }
        }

        $n = self::normalizeUrlKey($absoluteUrl);
        foreach (array_keys($assetMap) as $key) {
            if (self::normalizeUrlKey((string) $key) === $n) {
                return true;
            }
        }
        return false;
    }

    /**
     * マップのローカルパス先に実ファイルがあり、basename が一致する（Google Fonts のホスト差など）。
     *
     * @param array<string,string> $assetMap
     */
    public static function isCoveredByLocalAssets(string $url, string $outputDir, array $assetMap): bool
    {
        $bn = basename(parse_url(self::normalizeUrlKey($url), PHP_URL_PATH) ?? '');
        if ($bn === '' || $bn === '/') {
            return false;
        }

        $root = rtrim($outputDir, '/\\');

        foreach (array_values(array_unique(array_values($assetMap))) as $local) {
            $lp = (string) $local;
            if (basename($lp) !== $bn) {
                continue;
            }
            $full = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $lp);
            if (is_file($full)) {
                return true;
            }
        }

        foreach (['fonts', 'img', 'css', 'js'] as $sub) {
            $dir = $root . '/assets/' . $sub;
            if (is_file($dir . '/' . $bn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $referencedAbsolute
     * @param array<string,string> $assetMap
     * @param list<string> $fetchFailures optional absolute URLs that failed HTTP
     * @return list<array{url:string, reason:string}>
     */
    public static function listUnfetched(
        array $referencedAbsolute,
        array $assetMap,
        array $fetchFailures = [],
        ?string $outputDir = null
    ): array {
        $failSet = [];
        foreach ($fetchFailures as $f) {
            $failSet[self::normalizeUrlKey($f)] = true;
        }

        $out = [];
        foreach ($referencedAbsolute as $u) {
            if ($u === '' || str_starts_with($u, 'data:') || str_starts_with($u, '#')) {
                continue;
            }
            if (str_starts_with($u, 'mailto:') || str_starts_with($u, 'tel:') || str_starts_with($u, 'javascript:')) {
                continue;
            }

            if (self::isUrlMapped($u, $assetMap)) {
                continue;
            }

            if ($outputDir !== null && $outputDir !== '' && self::isCoveredByLocalAssets($u, $outputDir, $assetMap)) {
                continue;
            }

            $reason = 'asset_map に無く、取得されていない可能性';
            if (isset($failSet[self::normalizeUrlKey($u)])) {
                $reason = 'HTTP取得に失敗（fetch_failures.json を参照）';
            }

            $out[] = ['url' => $u, 'reason' => $reason];
        }

        return $out;
    }

    /**
     * Full audit: HTML refs + each output CSS file's url().
     *
     * @param array<string,string> $assetMap
     * @return array{referenced: list<string>, unfetched: list<array{url:string, reason:string}>}
     */
    public static function auditUnfetched(
        string $htmlPath,
        string $pageUrl,
        array $assetMap,
        string $outputDir,
        array $fetchFailures = []
    ): array {
        if (!file_exists($htmlPath)) {
            return ['referenced' => [], 'unfetched' => []];
        }

        $html = (string) file_get_contents($htmlPath);
        $ref  = self::collectFromHtml($html, $pageUrl);

        $inv = self::invertAssetMap($assetMap);

        $cssDir = rtrim($outputDir, '/\\') . '/assets/css';
        if (is_dir($cssDir)) {
            foreach (scandir($cssDir) ?: [] as $file) {
                if (!str_ends_with(strtolower($file), '.css')) {
                    continue;
                }
                $local = 'assets/css/' . $file;
                $cssAbs = $inv[$local] ?? null;
                if (!$cssAbs) {
                    continue;
                }
                $text = (string) file_get_contents($cssDir . '/' . $file);
                foreach (self::collectUrlsFromCss($text, $cssAbs) as $u) {
                    if (!in_array($u, $ref, true)) {
                        $ref[] = $u;
                    }
                }
            }
        }

        $ref = array_values(array_unique($ref));

        return [
            'referenced' => $ref,
            'unfetched'  => self::listUnfetched($ref, $assetMap, $fetchFailures, $outputDir),
        ];
    }

    /** @param list<string> $urls */
    private static function addAbs(array &$urls, LpUrlContext $ctx, string $raw): void
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, 'data:') || str_starts_with($raw, '#')) {
            return;
        }
        $abs = $ctx->resolve($raw);
        if ($abs && !str_starts_with($abs, 'data:')) {
            $urls[] = $abs;
        }
    }

    private static function addSrcset(array &$urls, LpUrlContext $ctx, string $srcset): void
    {
        foreach (explode(',', $srcset) as $part) {
            $u = trim(preg_split('/\s+/', trim($part))[0] ?? '');
            if ($u !== '') {
                self::addAbs($urls, $ctx, $u);
            }
        }
    }
}
