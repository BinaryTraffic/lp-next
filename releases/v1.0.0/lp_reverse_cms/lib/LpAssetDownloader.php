<?php

declare(strict_types=1);

require_once __DIR__ . '/LpUrlContext.php';

/**
 * LpAssetDownloader v2.1
 *
 * Robustly downloads CSS, images and JS referenced in an HTML document.
 *
 * Improvements over v1:
 *  - Uses DOMDocument/DOMXPath instead of regex for HTML parsing
 *  - Handles lazy-loading attributes: data-src, data-bg, data-original, etc.
 *  - Downloads images referenced inside CSS files via url()
 *  - Updates downloaded CSS to use local relative paths for images (../img/)
 *  - Handles <picture><source srcset="..."> and img srcset
 *  - Adds both absolute and protocol-relative URL forms to the map
 */
class LpAssetDownloader
{
    private string $sourceUrl = '';
    private string $baseUrl   = '';     // e.g. https://example.com
    private string $sourceDir = '';     // e.g. https://example.com/lp
    private string $outputDir;

    /** absolute URL => local path relative to output/  e.g. "assets/css/style.css" */
    private array $urlMap = [];

    /** already-attempted absolute URLs (prevents re-fetch / infinite loops) */
    private array $done = [];

    /** type/filename => absUrl — collision registry */
    private array $fileRegistry = [];

    /** Absolute URLs that returned HTTP error or empty body */
    private array $failedFetches = [];

    private LpUrlContext $urlCtx;

    private const CURL_TIMEOUT = 20;

    private const IMAGE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp', 'tiff',
    ];

    private const FONT_EXTENSIONS = ['woff', 'woff2', 'ttf', 'otf', 'eot'];

    /** data-* attribute names that carry an image URL (lazy loading patterns) */
    private const LAZY_IMAGE_ATTRS = [
        'data-src', 'data-lazy-src', 'data-original', 'data-lazy',
        'data-bg', 'data-background', 'data-image', 'data-img',
    ];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    public function __construct(string $outputDir)
    {
        $this->outputDir = rtrim($outputDir, '/\\');
    }

    /**
     * @return array<string,string>  URL map { "https://..." => "assets/css/file.css", ... }
     */
    public function downloadAll(string $html, string $sourceUrl): array
    {
        $this->urlMap        = [];
        $this->done          = [];
        $this->fileRegistry  = [];
        $this->failedFetches = [];

        $this->sourceUrl = $sourceUrl;
        $this->urlCtx    = LpUrlContext::fromPageAndHtml($sourceUrl, $html);
        $this->baseUrl   = $this->urlCtx->schemeHost;
        $this->sourceDir = $this->urlCtx->documentDirUrl;

        foreach (['css', 'img', 'js', 'fonts'] as $sub) {
            $d = $this->outputDir . '/assets/' . $sub;
            if (!is_dir($d)) {
                mkdir($d, 0755, true);
            }
        }

        // Parse with DOMDocument — far more robust than regex for real-world HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $this->collectStylesheets($xpath);
        $this->collectImages($xpath);
        $this->collectScripts($xpath);

        return $this->urlMap;
    }

    // -----------------------------------------------------------------------
    // Collectors
    // -----------------------------------------------------------------------

    private function collectStylesheets(DOMXPath $xpath): void
    {
        // <link rel="stylesheet" href="..."> および favicon / icon
        foreach ($xpath->query('//link[@href]') ?? [] as $link) {
            /** @var DOMElement $link */
            $rel = strtolower(trim($link->getAttribute('rel')));
            if ($rel === 'stylesheet') {
                $this->downloadUrl($link->getAttribute('href'), 'css');
            } elseif (str_contains($rel, 'icon')) {
                $this->downloadUrl($link->getAttribute('href'), 'img');
            }
        }

        // @import inside <style> blocks
        foreach ($xpath->query('//style') ?? [] as $style) {
            preg_match_all('/@import\s+(?:url\()?["\']?([^"\')\s;]+)["\']?\)?/i',
                $style->textContent, $m);
            foreach ($m[1] as $url) {
                $this->downloadUrl(trim($url), 'css');
            }
        }
    }

    private function collectImages(DOMXPath $xpath): void
    {
        // ── Standard <img> ────────────────────────────────────────────────
        foreach ($xpath->query('//img[@src]') ?? [] as $img) {
            /** @var DOMElement $img */
            $this->downloadUrl($img->getAttribute('src'), 'img');
        }
        foreach ($xpath->query('//img[@srcset]') ?? [] as $img) {
            /** @var DOMElement $img */
            $this->parseSrcset($img->getAttribute('srcset'));
        }

        // ── <picture> / <source> ──────────────────────────────────────────
        foreach ($xpath->query('//source[@srcset]') ?? [] as $source) {
            /** @var DOMElement $source */
            $this->parseSrcset($source->getAttribute('srcset'));
        }
        foreach ($xpath->query('//source[@src]') ?? [] as $source) {
            /** @var DOMElement $source */
            $this->downloadUrl($source->getAttribute('src'), 'img');
        }

        // ── Lazy-loading data attributes ──────────────────────────────────
        foreach (self::LAZY_IMAGE_ATTRS as $attr) {
            foreach ($xpath->query('//*[@' . $attr . ']') ?? [] as $node) {
                /** @var DOMElement $node */
                $val = trim($node->getAttribute($attr));
                if (!$val || str_starts_with($val, 'data:') || str_starts_with($val, '#')) {
                    continue;
                }
                // Some plugins store srcset-style values here
                if (str_contains($val, ',')) {
                    $this->parseSrcset($val);
                } else {
                    $this->downloadUrl($val, 'img');
                }
            }
        }

        // data-srcset (explicitly separate)
        foreach ($xpath->query('//*[@data-srcset]') ?? [] as $node) {
            /** @var DOMElement $node */
            $this->parseSrcset($node->getAttribute('data-srcset'));
        }

        // ── Background images in inline style="" ──────────────────────────
        foreach ($xpath->query('//*[@style]') ?? [] as $node) {
            /** @var DOMElement $node */
            $style = $node->getAttribute('style');
            preg_match_all('/url\(\s*["\']?([^)"\']+)["\']?\s*\)/i', $style, $m);
            foreach ($m[1] as $url) {
                $url = trim($url);
                if (!$url || str_starts_with($url, 'data:')) {
                    continue;
                }
                $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                if (in_array($ext, self::IMAGE_EXTENSIONS, true) || $ext === '') {
                    $this->downloadUrl($url, 'img');
                }
            }
        }
    }

    private function collectScripts(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//script[@src]') ?? [] as $script) {
            /** @var DOMElement $script */
            $this->downloadUrl($script->getAttribute('src'), 'js');
        }
    }

    // -----------------------------------------------------------------------
    // Core download logic
    // -----------------------------------------------------------------------

    /**
     * Resolve, download, and register one asset.
     * Returns the local path (e.g. "assets/img/hero.jpg") or null on failure.
     */
    private function downloadUrl(string $url, string $type): ?string
    {
        $url = trim($url);
        if (!$url
            || str_starts_with($url, 'data:')
            || str_starts_with($url, 'blob:')
            || str_starts_with($url, '#')
            || str_starts_with($url, 'javascript:')
        ) {
            return null;
        }

        $absUrl = $this->absolutize($url);
        if (!$absUrl) {
            return null;
        }

        // Already handled — just ensure original variant is also mapped
        if (isset($this->done[$absUrl])) {
            $existing = $this->urlMap[$absUrl] ?? null;
            if ($existing && $url !== $absUrl && !isset($this->urlMap[$url])) {
                $this->urlMap[$url] = $existing;
            }
            return $existing;
        }
        $this->done[$absUrl] = true;

        $content = $this->curlGet($absUrl);
        if ($content === null) {
            $this->failedFetches[$absUrl] = $absUrl;
            return null;
        }

        // CSS: absolutize/download url() references, update paths to local
        if ($type === 'css') {
            $content = $this->processCssContent($content, $absUrl);
        }

        $filename  = $this->allocateFilename($absUrl, $type);
        $savePath  = $this->outputDir . '/assets/' . $type . '/' . $filename;
        file_put_contents($savePath, $content);

        $localPath = 'assets/' . $type . '/' . $filename;

        $this->urlMap[$absUrl] = $localPath;
        if ($url !== $absUrl) {
            $this->urlMap[$url] = $localPath;
        }

        // Also map protocol-relative form
        if (str_starts_with($absUrl, 'https://')) {
            $protoRel = '//' . substr($absUrl, 8);
            $this->urlMap[$protoRel] = $localPath;
        } elseif (str_starts_with($absUrl, 'http://')) {
            $protoRel = '//' . substr($absUrl, 7);
            $this->urlMap[$protoRel] = $localPath;
        }

        return $localPath;
    }

    // -----------------------------------------------------------------------
    // CSS processing
    // -----------------------------------------------------------------------

    /**
     * Process a downloaded CSS file:
     *  - Images referenced via url() are downloaded and the CSS is updated
     *    to use a local relative path (../img/filename).
     *  - Non-image url() references (fonts, etc.) are absolutized so they
     *    continue to load from the original CDN.
     */
    private function processCssContent(string $css, string $cssAbsUrl): string
    {
        $cssBase = $this->extractBase($cssAbsUrl);
        $cssPath = str_replace('\\', '/', parse_url($cssAbsUrl, PHP_URL_PATH) ?? '/');
        $dirPath = dirname($cssPath);
        if ($dirPath === '/' || $dirPath === '.' || $dirPath === '\\') {
            $cssDirUrl = $cssBase;
        } else {
            $cssDirUrl = $cssBase . $dirPath;
        }

        return (string) preg_replace_callback(
            '/url\(\s*(["\']?)([^)"\']+)\1\s*\)/i',
            function (array $m) use ($cssBase, $cssDirUrl): string {
                $quote = $m[1];
                $url   = trim($m[2]);

                if (!$url || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                    return $m[0];
                }

                $absUrl = LpUrlContext::resolveRelativeUrl($url, $cssBase, $cssDirUrl);

                $ext = strtolower(
                    pathinfo(parse_url($absUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)
                );

                if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                    $localPath = $this->downloadUrl($absUrl, 'img');
                    if ($localPath) {
                        return "url({$quote}../img/" . basename($localPath) . "{$quote})";
                    }
                }

                if (in_array($ext, self::FONT_EXTENSIONS, true)) {
                    $localPath = $this->downloadUrl($absUrl, 'fonts');
                    if ($localPath) {
                        return "url({$quote}../fonts/" . basename($localPath) . "{$quote})";
                    }
                }

                return "url({$quote}{$absUrl}{$quote})";
            },
            $css
        ) ?? $css;
    }

    // -----------------------------------------------------------------------
    // srcset parser
    // -----------------------------------------------------------------------

    private function parseSrcset(string $srcset): void
    {
        if (!$srcset) {
            return;
        }
        foreach (explode(',', $srcset) as $part) {
            $tokens = preg_split('/\s+/', trim($part)) ?: [];
            $url    = $tokens[0] ?? '';
            if ($url) {
                $this->downloadUrl($url, 'img');
            }
        }
    }

    // -----------------------------------------------------------------------
    // Filename allocation
    // -----------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function getFailedFetches(): array
    {
        return array_values($this->failedFetches);
    }

    private function allocateFilename(string $absUrl, string $type): string
    {
        $defaultExt = ['css' => 'css', 'img' => 'jpg', 'js' => 'js', 'fonts' => 'woff2'][$type] ?? $type;

        $urlPath  = parse_url($absUrl, PHP_URL_PATH) ?? '';
        $basename = basename($urlPath);

        // Strip query string embedded in the basename
        if (($q = strpos($basename, '?')) !== false) {
            $basename = substr($basename, 0, $q);
        }

        // Add extension if missing or suspicious
        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if (!$ext || strlen($ext) > 5) {
            $stem     = $basename ?: 'asset';
            $basename = $stem . '.' . $defaultExt;
        }

        // Sanitize
        $basename = (string) preg_replace('/[^a-zA-Z0-9._\-]/', '_', $basename);
        $basename = (string) preg_replace('/_+/', '_', $basename);
        $basename = trim($basename, '_') ?: ('asset.' . $defaultExt);

        // Collision: same filename, different URL → add hash prefix
        $key = $type . '/' . $basename;
        if (isset($this->fileRegistry[$key]) && $this->fileRegistry[$key] !== $absUrl) {
            $info     = pathinfo($basename);
            $basename = ($info['filename'] ?? 'asset')
                      . '_' . substr(md5($absUrl), 0, 7)
                      . '.' . ($info['extension'] ?? $defaultExt);
            $key = $type . '/' . $basename;
        }

        $this->fileRegistry[$key] = $absUrl;
        return $basename;
    }

    // -----------------------------------------------------------------------
    // URL resolution
    // -----------------------------------------------------------------------

    /**
     * Resolve $url relative to the source page URL.
     */
    private function absolutize(string $url): string
    {
        return $this->absolutizeFrom($url, $this->baseUrl, $this->sourceDir);
    }

    /**
     * General URL absolutizer.
     *
     * @param string $base scheme://host only (e.g. https://example.com)
     * @param string $dir  full URL of the directory containing the HTML page (e.g. https://example.com/lp)
     */
    private function absolutizeFrom(string $url, string $base, string $dir): string
    {
        if (!$url) {
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
            return rtrim($base, '/') . $url;
        }
        if (str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
            return $url;
        }

        // Relative URL: merge with directory path of $dir (never explode the full URL by "/" — breaks "https:")
        $pe = parse_url($dir);
        if (empty($pe['host'])) {
            return rtrim($base, '/') . '/' . ltrim($url, '/');
        }

        $scheme   = $pe['scheme'] ?? 'https';
        $host     = $pe['host'];
        $dirPath  = str_replace('\\', '/', $pe['path'] ?? '/');
        $dirPath  = rtrim($dirPath, '/');

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

    private function extractBase(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    // -----------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------

    private function curlGet(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: */*',
                'Accept-Language: ja,en-US;q=0.9,en;q=0.8',
                'Referer: ' . $this->sourceUrl,
            ],
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            return null;
        }

        return $body;
    }
}
