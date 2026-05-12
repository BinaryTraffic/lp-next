<?php

declare(strict_types=1);

/**
 * Document URL + optional <base href> → consistent resolution for relative URLs.
 */
final class LpUrlContext
{
    public function __construct(
        public readonly string $pageUrl,
        public readonly string $schemeHost,
        /** Directory URL used to resolve relative paths (no trailing slash, except host-only) */
        public readonly string $documentDirUrl,
    ) {
    }

    public static function fromPageAndHtml(string $pageUrl, string $html): self
    {
        $parsed = parse_url($pageUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? '';
        $schemeHost = $scheme . '://' . $host;
        $path = str_replace('\\', '/', (string) ($parsed['path'] ?? '/'));

        $initialDir = self::pathToDirectoryUrl($schemeHost, $path);

        $baseHref = self::extractBaseHrefFromHtml($html);

        $documentDirUrl = $initialDir;
        if ($baseHref !== null && $baseHref !== '') {
            $resolved       = self::resolveRelativeUrl($baseHref, $schemeHost, $initialDir);
            $documentDirUrl = self::urlToDirectoryUrl($resolved);
        }

        return new self($pageUrl, $schemeHost, $documentDirUrl);
    }

    public function resolve(string $url): string
    {
        $url = str_replace('\\', '/', trim($url));
        if ($url === '') {
            return '';
        }
        return self::resolveRelativeUrl($url, $this->schemeHost, $this->documentDirUrl);
    }

    /**
     * Resolve a URL relative to a CSS file's absolute URL (directory = parent of file).
     */
    public static function resolveAgainstCssFile(string $url, string $cssAbsoluteUrl): string
    {
        $url = str_replace('\\', '/', trim($url));
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
            return $url;
        }

        $schemeHost = self::extractSchemeHost($cssAbsoluteUrl);
        $path       = str_replace('\\', '/', (string) (parse_url($cssAbsoluteUrl, PHP_URL_PATH) ?? '/'));
        $dirPath    = dirname($path);
        if ($dirPath === '/' || $dirPath === '.' || $dirPath === '\\') {
            $cssDirUrl = $schemeHost;
        } else {
            $cssDirUrl = $schemeHost . $dirPath;
        }

        return self::resolveRelativeUrl($url, $schemeHost, $cssDirUrl);
    }

    private static function extractSchemeHost(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    private static function extractBaseHrefFromHtml(string $html): ?string
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//head/base[@href]');
        if (!$nodes || $nodes->length === 0) {
            return null;
        }
        /** @var DOMElement $el */
        $el = $nodes->item(0);
        return trim($el->getAttribute('href'));
    }

    private static function pathToDirectoryUrl(string $schemeHost, string $urlPath): string
    {
        if ($urlPath === '' || $urlPath === '/') {
            return $schemeHost;
        }
        // Trailing slash means the path itself is the directory (e.g. "/foo/bar/").
        // dirname() would incorrectly return "/foo" for "/foo/bar/", so handle separately.
        if (str_ends_with($urlPath, '/')) {
            $dir = rtrim($urlPath, '/');
        } else {
            $dir = dirname($urlPath);
        }
        if ($dir === '' || $dir === '/' || $dir === '.' || $dir === '\\') {
            return $schemeHost;
        }
        return $schemeHost . $dir;
    }

    /**
     * Turn a full URL into the "directory URL" used for resolving relative children.
     */
    public static function urlToDirectoryUrl(string $url): string
    {
        $p    = parse_url($url);
        $path = str_replace('\\', '/', $p['path'] ?? '/');

        if ($path !== '/' && !str_ends_with($path, '/')) {
            $path = dirname($path);
            if ($path === '/' || $path === '.' || $path === '\\') {
                $path = '';
            }
        } else {
            $path = rtrim($path, '/');
        }

        $scheme = $p['scheme'] ?? 'https';
        $host   = $p['host']   ?? '';

        if ($path === '' || $path === '/') {
            return $scheme . '://' . $host;
        }
        return $scheme . '://' . $host . $path;
    }

    /**
     * RFC3986-style merge: relative $url against directory implied by $documentDirUrl.
     */
    public static function resolveRelativeUrl(string $url, string $schemeHost, string $documentDirUrl): string
    {
        if ($url === '') {
            return '';
        }
        $url = str_replace('\\', '/', $url);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return rtrim($schemeHost, '/') . $url;
        }
        if (str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
            return $url;
        }
        // Keep non-hierarchical scheme URLs as-is (tel:, mailto:, javascript:, sms:, etc).
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+\-.]*:[^\/]/', $url)) {
            return $url;
        }

        $pe = parse_url($documentDirUrl);
        if (empty($pe['host'])) {
            return rtrim($schemeHost, '/') . '/' . ltrim($url, '/');
        }

        $scheme  = $pe['scheme'] ?? 'https';
        $host    = $pe['host'];
        $dirPath = str_replace('\\', '/', $pe['path'] ?? '/');
        $dirPath = rtrim($dirPath, '/');

        if ($dirPath === '' || $dirPath === '/') {
            $merged = '/' . ltrim($url, '/');
        } else {
            $merged = $dirPath . '/' . ltrim($url, '/');
        }

        $segments = explode('/', $merged);
        $stack    = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($stack) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $seg;
        }

        return $scheme . '://' . $host . '/' . implode('/', $stack);
    }

    /**
     * Percent-decode each path segment (IRI-style). Root and empty path unchanged.
     */
    public static function decodeHttpUrlPathSegments(string $path): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }
        $parts = explode('/', $path);

        return implode('/', array_map(static fn(string $seg): string => rawurldecode($seg), $parts));
    }

    /**
     * Canonical path encoding: decode then re-encode each segment (UTF-8 → %XX).
     * Leaves ASCII-only paths without "%" unchanged for speed.
     */
    public static function normalizeHttpUrlPathEncoding(string $path): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }
        if (!str_contains($path, '%') && preg_match('/[^\x00-\x7F]/', $path) === 0) {
            return $path;
        }
        $parts = explode('/', $path);

        return implode('/', array_map(
            static fn(string $seg): string => rawurlencode(rawurldecode($seg)),
            $parts
        ));
    }

    /**
     * http(s) URL suitable for dedupe + curl: normalized percent-encoding on path.
     */
    public static function canonicalHttpUrlForFetch(string $absUrl): string
    {
        if (!str_starts_with($absUrl, 'http://') && !str_starts_with($absUrl, 'https://')) {
            return $absUrl;
        }
        $p = parse_url($absUrl);
        if ($p === false || empty($p['host'])) {
            return $absUrl;
        }
        $scheme = $p['scheme'] ?? 'https';
        $host   = $p['host'];
        $port   = isset($p['port']) ? ':' . $p['port'] : '';
        $path   = self::normalizeHttpUrlPathEncoding($p['path'] ?? '');
        $query  = isset($p['query']) ? '?' . $p['query'] : '';
        $frag   = isset($p['fragment']) ? '#' . $p['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $query . $frag;
    }

    /**
     * Equivalent absolute URLs differing only by path percent-encoding (e.g. 新 vs %E6%96%B0).
     *
     * @return list<string>
     */
    public static function httpHttpsAssetUrlVariants(string $absUrl): array
    {
        if (!str_starts_with($absUrl, 'http://') && !str_starts_with($absUrl, 'https://')) {
            return [$absUrl];
        }
        $p = parse_url($absUrl);
        if ($p === false || empty($p['host'])) {
            return [$absUrl];
        }
        $scheme = $p['scheme'] ?? 'https';
        $host   = $p['host'];
        $port   = isset($p['port']) ? ':' . $p['port'] : '';
        $path   = $p['path'] ?? '';
        $query  = isset($p['query']) ? '?' . $p['query'] : '';
        $frag   = isset($p['fragment']) ? '#' . $p['fragment'] : '';

        $encodedPath = self::normalizeHttpUrlPathEncoding($path);
        $decodedPath = self::decodeHttpUrlPathSegments($path);

        $a = $scheme . '://' . $host . $port . $encodedPath . $query . $frag;
        $b = $scheme . '://' . $host . $port . $decodedPath . $query . $frag;

        return array_values(array_unique(array_filter([$absUrl, $a, $b], static fn(string $u): bool => $u !== '')));
    }

    /**
     * 参照パスが `/app/assets/...` のまま書かれ実体だけ `/assets/...` が配信されているサイト向けの別 URL。
     * 取得が 404 のときのみ {@see LpAssetDownloader} が試す。該当しなければ null。
     */
    public static function alternateUrlStripAppAssetsPrefix(string $httpUrl): ?string
    {
        if (!str_starts_with($httpUrl, 'http://') && !str_starts_with($httpUrl, 'https://')) {
            return null;
        }
        $p = parse_url($httpUrl);
        if ($p === false || empty($p['path'])) {
            return null;
        }
        $path   = $p['path'];
        $needle = '/app/assets/';
        $pos    = strpos($path, $needle);
        if ($pos === false) {
            return null;
        }

        $newPath = substr_replace($path, '/assets/', $pos, strlen($needle));

        $scheme = $p['scheme'] ?? 'https';
        $host   = $p['host'] ?? '';
        if ($host === '') {
            return null;
        }
        $port  = isset($p['port']) ? ':' . $p['port'] : '';
        $query = isset($p['query']) ? '?' . $p['query'] : '';
        $frag  = isset($p['fragment']) ? '#' . $p['fragment'] : '';

        return $scheme . '://' . $host . $port . $newPath . $query . $frag;
    }

    /**
     * JS テンプレートのプレースホルダ（例: ${item.i}）がそのまま HTML/CSS に載っている URL。
     * HTTP では取得できないためダウンロード・監査の参照リストから除外する。
     */
    public static function looksLikeJsTemplatePlaceholder(string $url): bool
    {
        if (str_contains($url, '${')) {
            return true;
        }
        // encoded `$` + `{`
        if (stripos($url, '%24%7B') !== false) {
            return true;
        }

        return false;
    }

    /**
     * クローン元ホストと同一か（http(s) 絶対 URL のみ判定。mailto/tel/# は別 scope）。
     *
     * @param string $schemeHost 例 https://example.com（パスなし推奨）
     */
    public static function classifyHrefScope(string $href, string $schemeHost): string
    {
        $h = trim($href);
        if ($h === '') {
            return 'none';
        }
        $low = strtolower($h);
        if (str_starts_with($low, 'javascript:')) {
            return 'javascript';
        }
        if (str_starts_with($low, 'mailto:')) {
            return 'mailto';
        }
        if (str_starts_with($low, 'tel:')) {
            return 'tel';
        }
        if (str_starts_with($h, '#')) {
            return 'fragment';
        }
        if (!preg_match('#^https?://#i', $h)) {
            return 'relative';
        }
        $host = parse_url($h, PHP_URL_HOST);
        $baseHost = parse_url(rtrim($schemeHost, '/') . '/', PHP_URL_HOST);
        if ($host === null || $host === '' || $baseHost === null || $baseHost === '') {
            return 'external';
        }

        return strcasecmp((string) $host, (string) $baseHost) === 0 ? 'internal' : 'external';
    }

    /**
     * フラグメント除去 + ホスト小文字 + {@see canonicalHttpUrlForFetch} で同一ページ判定用キー。
     */
    public static function canonicalHttpDocumentIdentity(string $absUrl): string
    {
        if (!preg_match('#^https?://#i', $absUrl)) {
            return $absUrl;
        }
        $c = self::canonicalHttpUrlForFetch($absUrl);
        $p = parse_url($c);
        if ($p === false) {
            return $c;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $host   = strtolower((string) ($p['host'] ?? ''));
        $port   = isset($p['port']) ? ':' . $p['port'] : '';
        $path   = $p['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($p['query']) ? '?' . $p['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * 内部ページとして HTML を取得してよさそうか（静的アセット URL を除外）。
     */
    public static function isLikelyHtmlDocumentUrl(string $httpUrl): bool
    {
        if (!preg_match('#^https?://#i', $httpUrl)) {
            return false;
        }
        $path = parse_url($httpUrl, PHP_URL_PATH) ?: '';
        if ($path === '' || str_ends_with($path, '/')) {
            return true;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return true;
        }

        static $skip = [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'svgz', 'ico', 'pdf', 'zip',
            'mp4', 'webm', 'mp3', 'css', 'js', 'json', 'xml', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'map',
        ];

        return !in_array($ext, $skip, true);
    }
}
