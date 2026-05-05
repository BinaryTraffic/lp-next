<?php

declare(strict_types=1);

require_once __DIR__ . '/LpUrlContext.php';

/**
 * LpAssetDownloader v1.2
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
        'data-src',
        'data-lazy-src', 'data-original', 'data-lazy',
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

        // Inline JS template placeholders (${item.i}) are not fetchable URLs — skip quietly (no failedFetches noise).
        if (self::isUnresolvedJsTemplateUrl($url) || self::isUnresolvedJsTemplateUrl($absUrl)) {
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

        $filename = $this->allocateFilename($absUrl, $type);
        $savePath = $this->outputDir . '/assets/' . $type . '/' . $filename;

        if ($type === 'css') {
            $content = $this->processCssContentFull((string) $content, $absUrl, $savePath);
        }

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
    // CSS processing (v1.2: @import + url()、保存パス確定後に相対 import)
    // -----------------------------------------------------------------------

    private function processCssContentFull(string $css, string $cssAbsUrl, string $thisCssSavePath): string
    {
        $css = $this->processCssImports($css, $cssAbsUrl, $thisCssSavePath);

        return $this->processCssUrlReferences($css, $cssAbsUrl);
    }

    /**
     * 外部 @import を取得し、ローカル CSS への相対パスに差し替える（再帰は downloadUrl 側）。
     */
    private function processCssImports(string $css, string $cssAbsUrl, string $thisCssSavePath): string
    {
        $patterns = [
            '/@import\s+url\s*\(\s*([^)]+)\)\s*[^;]*;/i',
            '/@import\s+["\']([^"\']+)["\']\s*[^;]*;/i',
        ];

        foreach ($patterns as $pattern) {
            $css = (string) preg_replace_callback(
                $pattern,
                function (array $m) use ($cssAbsUrl, $thisCssSavePath): string {
                    $raw = trim($m[1]);
                    $raw = trim($raw, " \t\n\r\0\x0B\"'");
                    if ($raw === ''
                        || str_starts_with($raw, 'data:')
                        || str_starts_with($raw, 'mailto:')
                        || str_starts_with($raw, 'tel:')
                    ) {
                        return $m[0];
                    }

                    $abs = LpUrlContext::resolveAgainstCssFile($raw, $cssAbsUrl);
                    $abs = $this->stripUrlFragment($abs);
                    if ($abs === '' || !str_starts_with($abs, 'http')) {
                        return $m[0];
                    }

                    $local = $this->downloadUrl($abs, 'css');
                    if ($local === null) {
                        return $m[0];
                    }

                    $rel = $this->relativePathBetweenOutputFiles($thisCssSavePath, $local);
                    return '@import url("' . $rel . '");';
                },
                $css
            );
        }

        return $css;
    }

    private function stripUrlFragment(string $url): string
    {
        $p = strpos($url, '#');

        return $p === false ? $url : substr($url, 0, $p);
    }

    /**
     * $fromFile = 保存予定のこの CSS の絶対パス、$toLocal = output からの相対（例 assets/css/x.css）。
     */
    private function relativePathBetweenOutputFiles(string $fromFileAbs, string $toLocal): string
    {
        $root   = str_replace('\\', '/', rtrim($this->outputDir, '/\\'));
        $from   = str_replace('\\', '/', dirname($fromFileAbs));
        $toFull = $root . '/' . $toLocal;
        $toFull = str_replace('\\', '/', $toFull);
        $toDir  = dirname($toFull);
        $base   = basename($toFull);

        $fromParts = explode('/', $from);
        $toParts   = explode('/', $toDir);
        $i         = 0;
        $max       = min(count($fromParts), count($toParts));
        while ($i < $max && $fromParts[$i] === $toParts[$i]) {
            $i++;
        }
        $up   = count($fromParts) - $i;
        $down = array_slice($toParts, $i);

        return str_repeat('../', $up) . implode('/', array_merge($down, [$base]));
    }

    /**
     * url(...) 内の画像・フォントを取得しローカル相対パスへ。
     */
    private function processCssUrlReferences(string $css, string $cssAbsUrl): string
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

                if ($url === ''
                    || str_starts_with($url, 'data:')
                    || str_starts_with($url, '#')
                    || str_starts_with($url, 'blob:')
                    || str_starts_with($url, 'mailto:')
                    || str_starts_with($url, 'tel:')
                    || str_starts_with($url, 'javascript:')
                    || str_starts_with($url, 'about:')
                ) {
                    return $m[0];
                }

                $absUrl = LpUrlContext::resolveRelativeUrl($url, $cssBase, $cssDirUrl);
                $absUrl = $this->stripUrlFragment($absUrl);

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

    /**
     * srcset の各候補は「URL + 任意の記述子」。URL にカンマが含まれるケースは稀だが、
     * 先頭のトークン（引用符で囲めば 1 トークン）を優先して取り出す。
     */
    private function parseSrcset(string $srcset): void
    {
        if (!$srcset) {
            return;
        }
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^["\']([^"\']+)["\']/', $part, $qm)) {
                $url = $qm[1];
            } else {
                $tokens = preg_split('/\s+/', $part, 2) ?: [];
                $url    = $tokens[0] ?? '';
            }
            $url = trim($url);
            if ($url !== '') {
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

    private static function isUnresolvedJsTemplateUrl(string $url): bool
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
     * Resolve $url relative to the source page URL.
     */
    /**
     * HTML 由来の相対 URL → 絶対（&lt;base href&gt; 含む {@see LpUrlContext} に一本化）。
     */
    private function absolutize(string $url): string
    {
        return $this->urlCtx->resolve($url);
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
