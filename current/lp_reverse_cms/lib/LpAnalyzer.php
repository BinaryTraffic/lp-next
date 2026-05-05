<?php

declare(strict_types=1);

require_once __DIR__ . '/LpUrlContext.php';

class LpAnalyzer
{
    private string $sourceUrl = '';
    private string $baseUrl   = '';

    private LpUrlContext $urlCtx;

    private const HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const SECTION_TAGS = ['section', 'header', 'footer', 'nav', 'main', 'article', 'aside'];

    /**
     * `source` を含める理由: 元 HTML が &lt;img&gt; の後に誤って &lt;/source&gt; がある等で、
     * libxml が hero の &lt;img&gt; を &lt;source&gt; の子に入れることがあり、その場合に再帰しないと img が一切走査されない。
     */
    private const CONTAINER_TAGS = ['div', 'ul', 'ol', 'li', 'figure', 'figcaption', 'picture', 'source', 'blockquote', 'table', 'tr', 'td', 'th', 'span', 'strong', 'em'];

    /**
     * Analyse fetched HTML and return a structured array for Site Reverse CMS.
     *
     * @param callable(int $doneSteps, int $totalSteps, string $phase, array<string, mixed> $ctx): void|null $onWalkProgress
     *        Called during DOM ツリー走査（進捗可視化用。throttle は呼び出し側でも可）。
     */
    public function analyze(string $html, string $sourceUrl, ?callable $onWalkProgress = null): array
    {
        $this->sourceUrl = $sourceUrl;
        $this->urlCtx    = LpUrlContext::fromPageAndHtml($sourceUrl, $html);
        $this->baseUrl   = $this->urlCtx->schemeHost;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        /** @var array{walk_total_steps: int, walk_completed_steps: int, sections_planned: int, sections_written: int, section_errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} $diag */
        $diag = [
            'walk_total_steps'       => 0,
            'walk_completed_steps'   => 0,
            'sections_planned'       => 0,
            'sections_written'       => 0,
            'section_errors'         => [],
            'warnings'               => [],
        ];

        $candidates = $this->findStructuralElements($dom, $xpath);
        $diag['sections_planned'] = count($candidates);

        $walkTotal = 0;
        foreach ($candidates as $cand) {
            $walkTotal += $this->countTraversalSteps($cand);
        }
        $diag['walk_total_steps'] = $walkTotal;

        $lastEmitDone = -1;
        $throttleVisit = function () use (&$diag, $onWalkProgress, &$lastEmitDone): void {
            if ($onWalkProgress === null) {
                return;
            }
            $done = $diag['walk_completed_steps'];
            if (($done - $lastEmitDone) < 48 && $done < $diag['walk_total_steps']) {
                return;
            }
            $lastEmitDone = $done;
            $onWalkProgress($done, $diag['walk_total_steps'], 'tree_walk', []);
        };

        $onVisit = function () use (&$diag, $throttleVisit): void {
            $diag['walk_completed_steps']++;
            $throttleVisit();
        };

        $sections = $this->extractSections($dom, $xpath, $onVisit, $diag);

        $walkPct = $diag['walk_total_steps'] > 0
            ? round(100.0 * $diag['walk_completed_steps'] / $diag['walk_total_steps'], 2)
            : 100.0;

        return [
            'source_url'          => $sourceUrl,
            'analyzed_at'         => date('Y-m-d H:i:s'),
            'meta'                => $this->extractMeta($xpath),
            'head_extra'          => $this->extractHeadExtra($xpath),
            'sections'            => $sections,
            'parse_diagnostics'   => [
                'walk_total_steps'      => $diag['walk_total_steps'],
                'walk_completed_steps'  => $diag['walk_completed_steps'],
                'walk_pct'              => $walkPct,
                'sections_planned'      => $diag['sections_planned'],
                'sections_written'      => $diag['sections_written'],
                'section_error_count'   => count($diag['section_errors']),
                'section_errors'        => $diag['section_errors'],
                'warnings'                         => $diag['warnings'],
                'walk_incomplete'                  => $diag['walk_total_steps'] > 0
                    && $diag['walk_completed_steps'] < $diag['walk_total_steps'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Meta extraction
    // -----------------------------------------------------------------------

    private function extractMeta(DOMXPath $xpath): array
    {
        $meta = [
            'title'       => '',
            'description' => '',
            'charset'     => 'UTF-8',
            'viewport'    => 'width=device-width, initial-scale=1',
        ];

        $titleNodes = $xpath->query('//title');
        if ($titleNodes && $titleNodes->length > 0) {
            $meta['title'] = trim($titleNodes->item(0)->textContent);
        }

        $metaNodes = $xpath->query('//meta');
        if ($metaNodes) {
            foreach ($metaNodes as $node) {
                /** @var DOMElement $node */
                $name    = strtolower($node->getAttribute('name') ?: $node->getAttribute('property') ?: '');
                $content = $node->getAttribute('content');
                $charset = $node->getAttribute('charset');

                if ($charset) {
                    $meta['charset'] = strtoupper($charset);
                } elseif ($name === 'description' && $content) {
                    $meta['description'] = $content;
                } elseif ($name === 'viewport' && $content) {
                    $meta['viewport'] = $content;
                }
            }
        }

        return $meta;
    }

    private function extractHeadExtra(DOMXPath $xpath): string
    {
        $parts = [];

        // All <link> tags — preserve every attribute, only absolutize href
        $links = $xpath->query('//head/link');
        if ($links) {
            foreach ($links as $link) {
                /** @var DOMElement $link */
                $rel  = $link->getAttribute('rel');
                $href = $link->getAttribute('href');

                if (!$href && !$rel) {
                    continue;
                }

                // Absolutize href and rebuild the full tag preserving all attributes
                $absHref = $href ? $this->absolutizeUrl($href) : '';
                if ($absHref) {
                    $link->setAttribute('href', $absHref);
                }

                $parts[] = $this->serializeLinkTag($link);
            }
        }

        // Inline <style> blocks (preserve content verbatim)
        $styles = $xpath->query('//head/style');
        if ($styles) {
            foreach ($styles as $style) {
                /** @var DOMElement $style */
                $media = $style->getAttribute('media');
                $mediaAttr = $media ? ' media="' . htmlspecialchars($media, ENT_QUOTES) . '"' : '';
                $parts[] = '<style' . $mediaAttr . '>' . $style->textContent . '</style>';
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Serialize a <link> DOMElement back to an HTML string, keeping all attributes.
     */
    private function serializeLinkTag(DOMElement $link): string
    {
        $attrs = '';
        foreach ($link->attributes as $attr) {
            $attrs .= ' ' . $attr->name . '="' . htmlspecialchars($attr->value, ENT_QUOTES) . '"';
        }
        return '<link' . $attrs . '>';
    }

    // -----------------------------------------------------------------------
    // Section extraction
    // -----------------------------------------------------------------------

    /**
     * @param callable(): void|null $onWalkVisit
     * @param array{walk_total_steps: int, walk_completed_steps: int, sections_planned: int, sections_written: int, section_errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}|null $diagnosticsOut
     */
    private function extractSections(
        DOMDocument $dom,
        DOMXPath $xpath,
        ?callable $onWalkVisit = null,
        ?array &$diagnosticsOut = null
    ): array {
        $sections     = [];
        $sectionIndex = 0;
        $candidates   = $this->findStructuralElements($dom, $xpath);

        foreach ($candidates as $element) {
            $sectionId = 'sec_' . $sectionIndex;
            $elements  = [];
            $elemIndex = 0;

            try {
                $this->findEditableElements($element, $sectionId, $elemIndex, $elements, $onWalkVisit);

                if ($elements !== []) {
                    $html = $this->buildSectionHtml($element, $dom);
                    if ($html === '' && $diagnosticsOut !== null) {
                        $diagnosticsOut['warnings'][] = [
                            'section_id' => $sectionId,
                            'message'    => 'セクション saveHTML が空でした',
                        ];
                    }

                    $sections[] = [
                        'id'            => $sectionId,
                        'type'          => $this->classifySection($element),
                        'label'         => $this->generateLabel($element, $sectionIndex),
                        'outer_tag'     => strtolower($element->tagName),
                        'html'          => $html,
                        'elements'      => $elements,
                        'element_count' => count($elements),
                    ];

                    if ($diagnosticsOut !== null) {
                        $diagnosticsOut['sections_written']++;
                    }
                }
            } catch (Throwable $e) {
                if ($diagnosticsOut !== null) {
                    $diagnosticsOut['section_errors'][] = [
                        'section_id'      => $sectionId,
                        'section_index'   => $sectionIndex,
                        'exception_class' => $e::class,
                        'message'         => $e->getMessage(),
                        'file'            => $e->getFile(),
                        'line'            => $e->getLine(),
                    ];
                }
            }

            $sectionIndex++;
        }

        return $sections;
    }

    /**
     * findEditableElements と同じ分岐で走査対象 DOM 要素ノード数を数える（進捗 100% の分母）。
     */
    private function countTraversalSteps(DOMElement $parent): int
    {
        $n = 0;
        foreach ($parent->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $n++;
            $tag = strtolower($child->tagName);

            if ($tag === 'a') {
                $text = trim($child->textContent);
                if (mb_strlen($text) <= 1 || $child->getElementsByTagName('img')->length) {
                    $n += $this->countTraversalSteps($child);
                }
            } elseif (in_array($tag, self::CONTAINER_TAGS, true) || in_array($tag, self::SECTION_TAGS, true)) {
                $n += $this->countTraversalSteps($child);
            }
        }

        return $n;
    }

    /**
     * Find top-level structural elements inside <body>.
     *
     * @return DOMElement[]
     */
    private function findStructuralElements(DOMDocument $dom, DOMXPath $xpath): array
    {
        $body = $xpath->query('//body');
        if (!$body || $body->length === 0) {
            return [];
        }
        /** @var DOMElement $bodyEl */
        $bodyEl = $body->item(0);

        // Prefer direct structural children of body
        $candidates = [];
        foreach ($bodyEl->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::SECTION_TAGS) || $tag === 'div') {
                $candidates[] = $child;
            }
        }

        if (count($candidates) >= 2) {
            return $candidates;
        }

        // Fallback: query semantic section elements anywhere under body
        $fallback = $xpath->query('//body/section | //body/header | //body/footer | //body/nav | //body/main | //body/article');
        if ($fallback) {
            foreach ($fallback as $node) {
                $candidates[] = $node;
            }
        }

        // Final fallback: top-level divs
        if (count($candidates) < 2) {
            $divs = $xpath->query('//body/div');
            if ($divs) {
                foreach ($divs as $div) {
                    $candidates[] = $div;
                }
            }
        }

        // Deduplicate by object identity
        $seen   = [];
        $unique = [];
        foreach ($candidates as $c) {
            $key = spl_object_id($c);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $c;
            }
        }

        return $this->filterDescendantCandidates($unique);
    }

    /**
     * If both an ancestor and a descendant appear as section roots, keep only the outer node.
     * Emitting nested fragments as consecutive siblings breaks layout and z-index.
     *
     * @param DOMElement[] $nodes
     * @return DOMElement[]
     */
    private function filterDescendantCandidates(array $nodes): array
    {
        if (count($nodes) <= 1) {
            return $nodes;
        }
        $out = [];
        foreach ($nodes as $n) {
            $nested = false;
            foreach ($nodes as $m) {
                if ($n !== $m && $this->domElementIsDescendantOf($n, $m)) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $out[] = $n;
            }
        }

        return $out;
    }

    private function domElementIsDescendantOf(DOMElement $node, DOMElement $ancestor): bool
    {
        $p = $node->parentNode;
        while ($p !== null) {
            if ($p === $ancestor) {
                return true;
            }
            $p = $p->parentNode;
        }

        return false;
    }

    /**
     * HTML img の width / height 属性からピクセル整数を得る（% 等は無視）。
     *
     * @return array{width: int, height: int}|null
     */
    private function parseHtmlImgDimensions(DOMElement $img): ?array
    {
        $w = $this->parseHtmlPixelLength($img->getAttribute('width'));
        $h = $this->parseHtmlPixelLength($img->getAttribute('height'));
        if ($w === null || $h === null || $w < 16 || $h < 16 || $w > 8192 || $h > 8192) {
            return null;
        }

        return ['width' => $w, 'height' => $h];
    }

    private function parseHtmlPixelLength(string $raw): ?int
    {
        $s = strtolower(trim($raw));
        if ($s === '') {
            return null;
        }
        if (str_ends_with($s, 'px')) {
            $s = trim(substr($s, 0, -2));
        }
        if ($s === '' || !ctype_digit($s)) {
            return null;
        }
        $v = (int) $s;

        return $v > 0 ? $v : null;
    }

    /**
     * Recursively walk the element tree and tag editable nodes with data-lp-id.
     */
    /**
     * @param callable(): void|null $onVisit 各 DOMElement 子ノードを訪問する直前に 1 回呼ぶ（進捗用）
     */
    private function findEditableElements(
        DOMElement $parent,
        string $sectionId,
        int &$index,
        array &$elements,
        ?callable $onVisit = null
    ): void {
        foreach ($parent->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }

            if ($onVisit !== null) {
                $onVisit();
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::HEADING_TAGS)) {
                $text = trim($child->textContent);
                if ($text !== '') {
                    $id = 'elem_' . $sectionId . '_' . $index++;
                    $child->setAttribute('data-lp-id', $id);
                    $elements[] = [
                        'id'            => $id,
                        'type'          => 'heading',
                        'tag'           => $tag,
                        'label'         => $this->headingLabel($tag) . '：' . mb_substr($text, 0, 40),
                        'original_text' => $text,
                        'original_src'  => null,
                        'original_href' => null,
                    ];
                }
            } elseif ($tag === 'p') {
                $text = trim($child->textContent);
                if (mb_strlen($text) > 3) {
                    $id = 'elem_' . $sectionId . '_' . $index++;
                    $child->setAttribute('data-lp-id', $id);
                    $elements[] = [
                        'id'            => $id,
                        'type'          => 'paragraph',
                        'tag'           => 'p',
                        'label'         => 'テキスト：' . mb_substr($text, 0, 40),
                        'original_text' => $text,
                        'original_src'  => null,
                        'original_href' => null,
                    ];
                }
            } elseif ($tag === 'img') {
                $src = $this->absolutizeUrl($child->getAttribute('src') ?: '');
                $alt = $child->getAttribute('alt');
                if ($src) {
                    $id = 'elem_' . $sectionId . '_' . $index++;
                    $child->setAttribute('data-lp-id', $id);
                    $child->setAttribute('src', $src);
                    $baseName = basename(parse_url($src, PHP_URL_PATH) ?: $src);
                    $cls      = $child->getAttribute('class') ?: '';
                    $isBg     = str_contains($cls, 'bg');
                    $label    = $isBg
                        ? '背景画像：' . $baseName
                        : '画像：' . $baseName;

                    $wrapAnchor = $this->findWrapAnchor($child);
                    $wrapHref   = null;
                    $wrapTarget = null;
                    $wrapRel    = null;
                    if ($wrapAnchor !== null) {
                        $rawHref = $wrapAnchor->getAttribute('href') ?: '';
                        $norm    = $this->normalizeHrefForStorage($rawHref);
                        $wrapHref = $norm !== '' ? $norm : null;
                        $t = $wrapAnchor->getAttribute('target');
                        $wrapTarget = ($t !== '') ? $t : null;
                        $r = $wrapAnchor->getAttribute('rel');
                        $wrapRel = ($r !== '') ? $r : null;
                    }

                    $row = [
                        'id'            => $id,
                        'type'          => 'image',
                        'tag'           => 'img',
                        'label'         => $label,
                        'original_text' => $alt,
                        'original_src'  => $src,
                        'original_href' => $wrapHref,
                        'image_embedded_text_memo' => '',
                    ];
                    $dims = $this->parseHtmlImgDimensions($child);
                    if ($dims !== null) {
                        $row['original_width']  = $dims['width'];
                        $row['original_height'] = $dims['height'];
                    }
                    if ($wrapTarget !== null) {
                        $row['wrap_target'] = $wrapTarget;
                    }
                    if ($wrapRel !== null) {
                        $row['wrap_rel'] = $wrapRel;
                    }
                    $elements[] = $row;
                }
            } elseif ($tag === 'a') {
                $text = trim($child->textContent);
                $href = $child->getAttribute('href') ?: '';
                $absHref = (!str_starts_with($href, '#') && !str_starts_with($href, 'javascript:'))
                    ? $this->absolutizeUrl($href)
                    : $href;

                // Only tag anchor if it has meaningful text and is not purely an image link
                if (mb_strlen($text) > 1 && !$child->getElementsByTagName('img')->length) {
                    $id = 'elem_' . $sectionId . '_' . $index++;
                    $child->setAttribute('data-lp-id', $id);
                    if ($absHref) {
                        $child->setAttribute('href', $absHref);
                    }
                    $elements[] = [
                        'id'            => $id,
                        'type'          => $this->isButtonAnchor($child) ? 'button' : 'link',
                        'tag'           => 'a',
                        'label'         => 'リンク：' . mb_substr($text, 0, 40),
                        'original_text' => $text,
                        'original_src'  => null,
                        'original_href' => $absHref,
                    ];
                } else {
                    // Recurse into anchor's children (might wrap an image)
                    $this->findEditableElements($child, $sectionId, $index, $elements, $onVisit);
                }
            } elseif ($tag === 'button') {
                $text = trim($child->textContent);
                if ($text !== '') {
                    $id = 'elem_' . $sectionId . '_' . $index++;
                    $child->setAttribute('data-lp-id', $id);
                    $elements[] = [
                        'id'            => $id,
                        'type'          => 'button',
                        'tag'           => 'button',
                        'label'         => 'ボタン：' . mb_substr($text, 0, 40),
                        'original_text' => $text,
                        'original_src'  => null,
                        'original_href' => null,
                    ];
                }
            } elseif (in_array($tag, self::CONTAINER_TAGS, true) || in_array($tag, self::SECTION_TAGS, true)) {
                $this->findEditableElements($child, $sectionId, $index, $elements, $onVisit);
            }
        }
    }

    /**
     * Absolutize all asset URLs inside the section element and return its outer HTML.
     */
    private function buildSectionHtml(DOMElement $element, DOMDocument $dom): string
    {
        // Absolutize img src
        foreach ($element->getElementsByTagName('img') as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('src');
            if ($src) {
                $img->setAttribute('src', $this->absolutizeUrl($src));
            }
            $srcset = $img->getAttribute('srcset');
            if ($srcset) {
                $img->setAttribute('srcset', $this->absolutizeSrcset($srcset));
            }
        }

        // Absolutize anchor href (non-fragment, non-js)
        foreach ($element->getElementsByTagName('a') as $a) {
            /** @var DOMElement $a */
            $href = $a->getAttribute('href');
            if ($href && !str_starts_with($href, '#') && !str_starts_with($href, 'javascript:') && !str_starts_with($href, 'mailto:') && !str_starts_with($href, 'tel:')) {
                $a->setAttribute('href', $this->absolutizeUrl($href));
            }
        }

        // Absolutize video/picture <source> src and srcset (art direction / responsive)
        foreach ($element->getElementsByTagName('source') as $source) {
            /** @var DOMElement $source */
            $src = $source->getAttribute('src');
            if ($src) {
                $source->setAttribute('src', $this->absolutizeUrl($src));
            }
            $srcset = $source->getAttribute('srcset');
            if ($srcset) {
                $source->setAttribute('srcset', $this->absolutizeSrcset($srcset));
            }
        }

        $html = $dom->saveHTML($element);
        return $html !== false ? $html : '';
    }

    // -----------------------------------------------------------------------
    // Classification helpers
    // -----------------------------------------------------------------------

    private function classifySection(DOMElement $element): string
    {
        $tag     = strtolower($element->tagName);
        $context = strtolower($element->getAttribute('class') . ' ' . $element->getAttribute('id'));

        if ($tag === 'nav' || str_contains($context, 'nav') || str_contains($context, 'gnav') || str_contains($context, 'menu')) {
            return 'nav';
        }
        if ($tag === 'footer' || str_contains($context, 'footer')) {
            return 'footer';
        }
        if ($tag === 'header'
            || str_contains($context, 'hero')
            || str_contains($context, 'banner')
            || str_contains($context, 'main-visual')
            || str_contains($context, 'mainvisual')
            || str_contains($context, 'mv ')
            || str_contains($context, 'kv ')
            || str_contains($context, 'keyvisual')
            || str_contains($context, 'first-view')
            || str_contains($context, 'fv')
        ) {
            return 'hero';
        }
        if (str_contains($context, 'feature') || str_contains($context, 'benefit') || str_contains($context, 'service') || str_contains($context, 'merit') || str_contains($context, 'point')) {
            return 'features';
        }
        if (str_contains($context, 'testimonial') || str_contains($context, 'review') || str_contains($context, 'voice') || str_contains($context, 'appeal')) {
            return 'testimonials';
        }
        if (str_contains($context, 'cta') || str_contains($context, 'contact') || str_contains($context, 'form') || str_contains($context, 'apply') || str_contains($context, 'entry')) {
            return 'cta';
        }
        if (str_contains($context, 'price') || str_contains($context, 'plan') || str_contains($context, 'cost') || str_contains($context, 'fee')) {
            return 'pricing';
        }
        if (str_contains($context, 'faq') || str_contains($context, 'question') || str_contains($context, 'qa')) {
            return 'faq';
        }

        return 'general';
    }

    private function generateLabel(DOMElement $element, int $index): string
    {
        $labels = [
            'nav'          => 'ナビゲーション',
            'hero'         => 'ヒーローセクション',
            'features'     => '特徴・メリット',
            'testimonials' => 'お客様の声',
            'cta'          => 'お問い合わせ・CTA',
            'pricing'      => '料金プラン',
            'faq'          => 'よくある質問',
            'footer'       => 'フッター',
            'general'      => 'セクション',
        ];
        $type = $this->classifySection($element);
        return ($labels[$type] ?? 'セクション') . ' ' . ($index + 1);
    }

    private function headingLabel(string $tag): string
    {
        return match($tag) {
            'h1'    => 'メイン見出し',
            'h2'    => '見出し（h2）',
            'h3'    => '見出し（h3）',
            default => '見出し（' . $tag . '）',
        };
    }

    private function isButtonAnchor(DOMElement $el): bool
    {
        $classes = strtolower($el->getAttribute('class'));
        return str_contains($classes, 'btn') || str_contains($classes, 'button') || str_contains($classes, 'cta');
    }

    /**
     * 画像が &lt;a&gt; でラップされているとき、その要素（href の所在）を返す。
     */
    private function findWrapAnchor(DOMElement $img): ?DOMElement
    {
        $p = $img->parentNode;
        while ($p !== null) {
            if ($p instanceof DOMElement && strtolower($p->tagName) === 'a') {
                return $p;
            }
            $p = $p->parentNode;
        }

        return null;
    }

    /**
     * 構造 JSON に保存する href（mailto/tel/#/javascript はそのまま、それ以外は absolutize）。
     */
    private function normalizeHrefForStorage(string $href): string
    {
        if ($href === '') {
            return '';
        }
        if (str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            return $href;
        }
        if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return $href;
        }

        return $this->absolutizeUrl($href);
    }

    // -----------------------------------------------------------------------
    // URL helpers
    // -----------------------------------------------------------------------

    private function absolutizeUrl(string $url): string
    {
        if (!$url) {
            return '';
        }
        $url = str_replace('\\', '/', $url);
        if (str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
            return $url;
        }
        return $this->urlCtx->resolve($url);
    }

    private function absolutizeSrcset(string $srcset): string
    {
        return preg_replace_callback('/([^\s,]+)(\s+[\d.]+[wx])?/', function ($matches) {
            $url = trim($matches[1]);
            if (!$url || str_starts_with($url, 'data:')) {
                return $matches[0];
            }
            return $this->absolutizeUrl($url) . ($matches[2] ?? '');
        }, $srcset) ?: $srcset;
    }
}
