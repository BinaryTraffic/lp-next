<?php

declare(strict_types=1);

/**
 * LpGenerator — rebuilds a complete HTML page from lp_structure + client_data.
 *
 * Strategy:
 *  1. For each section, load the stored HTML fragment into a DOMDocument.
 *  2. Locate every element tagged with data-lp-id.
 *  3. Replace text / src / href with client data (or keep originals).
 *  4. Wrap everything in a full HTML document shell.
 *  5. Apply the asset URL map (absolute → local) produced by LpAssetDownloader.
 */
class LpGenerator
{
    /** Path to data/ directory — set by generate() based on __DIR__ */
    private string $dataDir = '';

    /**
     * @param array $structure   Contents of lp_structure.json
     * @param array $clientData  Contents of client_data.json (may be empty)
     * @return string            Complete HTML of the generated LP
     */
    public function generate(array $structure, array $clientData): string
    {
        $this->dataDir = __DIR__ . '/../data/';

        $meta        = $structure['meta']       ?? [];
        $headExtra   = $structure['head_extra'] ?? '';
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

        $sectionsHtml = '';
        foreach ($sections as $section) {
            $sectionsHtml .= $this->processSection($section, $elemData) . "\n";
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
</head>
<body>
{$sectionsHtml}
</body>
</html>
HTML;

        // ── Apply asset URL map: absolute URLs → local paths ──────────────
        $html = $this->applyAssetMap($html);

        return $html;
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
    private function applyAssetMap(string $html): string
    {
        $mapFile = $this->dataDir . 'asset_map.json';
        if (!file_exists($mapFile)) {
            return $html;
        }

        $map = json_decode((string) file_get_contents($mapFile), true);
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
                $absoluteExpanded[(string) $originalUrl] = (string) $localPath;
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
            if (str_starts_with($from, '//')) {
                $qf = preg_quote($from, '#');
                $html = preg_replace('#(?<![/:])' . $qf . '#', $to, $html) ?? $html;
                $encFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
                if ($encFrom !== $from) {
                    $eq = preg_quote($encFrom, '#');
                    $encTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
                    $html = preg_replace('#(?<![/:])' . $eq . '#', $encTo, $html) ?? $html;
                }
            } else {
                $html = str_replace($from, $to, $html);
                $encFrom = htmlspecialchars($from, ENT_QUOTES, 'UTF-8');
                $encTo   = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
                if ($encFrom !== $from) {
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
            $qf = preg_quote($from, '#');
            foreach ($attrs as $attr) {
                $html = preg_replace(
                    '#(?i)(?<![\w-])' . $attr . '\s*=\s*(")' . $qf . '(")#',
                    $attr . '=$1' . $to . '$2',
                    $html
                ) ?? $html;
                $html = preg_replace(
                    "#(?i)(?<![\w-])" . $attr . "\s*=\s*(')" . $qf . "(')#",
                    $attr . '=$1' . $to . '$2',
                    $html
                ) ?? $html;
            }
            $html = preg_replace_callback(
                '#(?i)\bsrcset\s*=\s*(")([^"]*)(")#',
                static function (array $m) use ($from, $to): string {
                    if (!str_contains($m[2], $from)) {
                        return $m[0];
                    }
                    $parts = preg_split('#\s*,\s*#', $m[2]) ?: [];
                    $newParts = [];
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if ($part === '') {
                            continue;
                        }
                        $tok  = preg_split('/\s+/', $part, 2);
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
        $html = preg_replace(
            '#(https?://[a-zA-Z0-9.\[\]:_-]+)\\(?=[/a-zA-Z0-9_%?#])#',
            '$1/',
            $html
        ) ?? $html;

        $html = preg_replace(
            '#(https?://[a-zA-Z0-9.\[\]:_-]+)(%5[Cc])(/)#',
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
            } else {
                $newText = ($override !== null && isset($override['text']) && $override['text'] !== '')
                    ? $override['text']
                    : null;

                if ($newText !== null) {
                    $this->setNodeText($node, $newText, $dom);
                }

                if (in_array($type, ['button', 'link'], true)) {
                    $newHref = $override['href'] ?? null;
                    if ($newHref) {
                        $node->setAttribute('href', $newHref);
                    }
                }
            }
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
}
