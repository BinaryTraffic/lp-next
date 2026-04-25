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
        $dir = dirname($urlPath);
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
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
}
