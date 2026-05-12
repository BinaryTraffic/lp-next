<?php

declare(strict_types=1);

require_once __DIR__ . '/LpUrlContext.php';
require_once __DIR__ . '/LpDomScriptCleanup.php';

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
     * @param float $maxWalkWallSeconds 0 より大きいとき、ツリー走査中にウォール時計で打ち切り（内部ページのハング対策）
     */
    /**
     * @param string $extraCssContent ダウンロード済み外部 CSS ファイルの結合テキスト。
     *                                css_background_hints の検索対象に追加される。
     */
    public function analyze(string $html, string $sourceUrl, ?callable $onWalkProgress = null, float $maxWalkWallSeconds = 0.0, string $extraCssContent = ''): array
    {
        $this->sourceUrl = $sourceUrl;
        $this->urlCtx    = LpUrlContext::fromPageAndHtml($sourceUrl, $html);
        $this->baseUrl   = $this->urlCtx->schemeHost;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $docRoot = $dom->documentElement;
        if ($docRoot instanceof DOMElement) {
            LpDomScriptCleanup::stripScriptsAndJsSpills($docRoot);
        }

        $xpath = new DOMXPath($dom);

        $walkDeadline = $maxWalkWallSeconds > 0.0 ? microtime(true) + $maxWalkWallSeconds : null;
        $checkWalkDeadline = static function (?float $deadline): void {
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new RuntimeException(
                    '構造解析が時間上限を超えました（ページ規模が大きい可能性があります）。'
                );
            }
        };

        /** @var array{walk_total_steps: int, walk_completed_steps: int, sections_planned: int, sections_written: int, section_errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>} $diag */
        $diag = [
            'walk_total_steps'       => 0,
            'walk_completed_steps'   => 0,
            'sections_planned'       => 0,
            'sections_written'       => 0,
            'section_errors'         => [],
            'warnings'               => [],
        ];

        $checkWalkDeadline($walkDeadline);

        $candidates = $this->findStructuralElements($dom, $xpath);
        $diag['sections_planned'] = count($candidates);

        $walkTotal = 0;
        foreach ($candidates as $cand) {
            $checkWalkDeadline($walkDeadline);
            $walkTotal += $this->countTraversalSteps($cand);
        }
        $diag['walk_total_steps'] = $walkTotal;

        $checkWalkDeadline($walkDeadline);

        $lastEmitDone = -1;
        $throttleVisit = function () use (&$diag, $onWalkProgress, &$lastEmitDone, $walkDeadline, $checkWalkDeadline): void {
            $checkWalkDeadline($walkDeadline);
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

        $onVisit = function () use (&$diag, $throttleVisit, $walkDeadline, $checkWalkDeadline): void {
            $checkWalkDeadline($walkDeadline);
            $diag['walk_completed_steps']++;
            $throttleVisit();
        };

        $headExtra = $this->extractHeadExtra($xpath);
        // 外部 CSS のテキストは background-image 検出用 haystack としてのみ使用。
        // head_extra（生成 HTML に埋め込まれる）には含めない（生 CSS テキストが <style> タグなしで
        // <head> に混入するとブラウザが body コンテンツとして表示してしまうため）。
        $cssHaystack = $extraCssContent !== '' ? $headExtra . "\n" . $extraCssContent : $headExtra;
        $bodySnip  = $this->extractBodyDirectChildHeadSnippets($xpath);
        $meta      = array_merge($this->extractMeta($xpath), $this->extractBodyRootAttributes($xpath));

        $sections = $this->extractSections($dom, $xpath, $onVisit, $diag, $cssHaystack, $bodySnip);

        $walkPct = $diag['walk_total_steps'] > 0
            ? round(100.0 * $diag['walk_completed_steps'] / $diag['walk_total_steps'], 2)
            : 100.0;

        return [
            'clone_site'          => [
                'scheme_host' => $this->baseUrl,
                'entry_url'   => $sourceUrl,
            ],
            'source_url'          => $sourceUrl,
            'analyzed_at'         => date('Y-m-d H:i:s'),
            'meta'                => $meta,
            'head_extra'          => $headExtra,
            /** body 直下の style / stylesheet link（ヒーロー直前のページ固有 CSS 等。セクション HTML に含まれないため別途保持） */
            'body_head_snippets'  => $bodySnip,
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

    /**
     * @return array{body_id?: string, body_class?: string}
     */
    private function extractBodyRootAttributes(DOMXPath $xpath): array
    {
        $bodyList = $xpath->query('//body');
        if (!$bodyList || $bodyList->length === 0) {
            return [];
        }
        /** @var DOMElement $b */
        $b = $bodyList->item(0);

        $out = [];
        $id = trim($b->getAttribute('id'));
        if ($id !== '') {
            $out['body_id'] = $id;
        }
        $cls = trim($b->getAttribute('class'));
        if ($cls !== '') {
            $out['body_class'] = $cls;
        }

        return $out;
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
     * Collect &lt;style&gt; and stylesheet &lt;link&gt; that are **direct children** of &lt;body&gt;.
     * Many LP templates place page-local rules (hero/banner layers) here; they are not part of
     * section fragments and would otherwise be dropped at generate time.
     *
     * Order is preserved (DOM child order).
     */
    private function extractBodyDirectChildHeadSnippets(DOMXPath $xpath): string
    {
        $bodyList = $xpath->query('//body');
        if (!$bodyList || $bodyList->length === 0) {
            return '';
        }
        /** @var DOMElement $bodyEl */
        $bodyEl = $bodyList->item(0);
        $parts  = [];

        foreach ($bodyEl->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'style') {
                $media = $child->getAttribute('media');
                $mediaAttr = $media ? ' media="' . htmlspecialchars($media, ENT_QUOTES) . '"' : '';
                $parts[] = '<style' . $mediaAttr . '>' . $child->textContent . '</style>';
                continue;
            }
            if ($tag === 'link') {
                $rel = strtolower(trim($child->getAttribute('rel') ?? ''));
                if ($rel !== 'stylesheet') {
                    continue;
                }
                $href = $child->getAttribute('href');
                if ($href) {
                    $child->setAttribute('href', $this->absolutizeUrl($href));
                }
                $parts[] = $this->serializeLinkTag($child);
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

    /**
     * head / body 直下 CSS 内の url() を、セクション DOM の id/class に近い位置から拾う（編集 UI の参照用。編集値とは別）。
     *
     * @return list<array{token:string, url:string}>
     */
    private function extractCssBackgroundHints(DOMElement $sectionRoot, string $headExtra, string $bodySnippets): array
    {
        $inline = $this->extractInlineBackgroundUrlsFromSubtree($sectionRoot);
        $haystack = $headExtra . "\n" . $bodySnippets;
        if ($haystack === '') {
            return $this->dedupeCssHintsByUrl($inline);
        }

        $tokens = $this->collectCssHintTokens($sectionRoot);
        usort($tokens, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $found = [];
        foreach ($tokens as $tok) {
            $pos    = 0;
            $tokLen = strlen($tok);
            while (($p = strpos($haystack, $tok, $pos)) !== false) {
                // 次の CSS ルール境界（2000 文字）だけを走査。大きすぎると無関係なルールの URL を拾う。
                $window = substr($haystack, $p, 2000);
                if (preg_match_all('/url\(\s*["\']?([^)"\'\\\\]+)["\']?\s*\)/i', $window, $m)) {
                    foreach ($m[1] as $u) {
                        $u = trim($u);
                        if ($u === '' || str_starts_with(strtolower($u), 'data:')) {
                            continue;
                        }
                        if (!preg_match('#^https?://#i', $u)) {
                            $u = $this->absolutizeUrl($u);
                        }
                        // アイコン・スプライト・不正 URL はスキップ（ノイズ削減）
                        if (!$this->isCssBackgroundCandidate($u)) {
                            continue;
                        }
                        $found[] = ['token' => $tok, 'url' => $u];
                    }
                }
                $pos = $p + max(1, $tokLen);
            }
        }

        foreach ($inline as &$row) {
            if (!preg_match('#^https?://#i', $row['url'])) {
                $row['url'] = $this->absolutizeUrl($row['url']);
            }
        }
        unset($row);

        return $this->dedupeCssHintsByUrl(array_merge($found, $inline));
    }

    /**
     * CSS background-image URL として妥当かどうかを判定。
     * アイコン・スプライト・不正エンコードの URL はノイズになるため除外する。
     */
    private function isCssBackgroundCandidate(string $url): bool
    {
        // http/https のみ。エンコードされたクォートが混入している URL は除外。
        if (!preg_match('#^https?://#i', $url)
            || str_contains($url, '%22')
            || str_contains($url, '%27')
        ) {
            return false;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        // 対応拡張子以外は除外
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
            return false;
        }
        // アイコン・スプライト・UI 装飾素材はノイズなので除外
        $base = strtolower(pathinfo($path, PATHINFO_FILENAME));
        // ファイル名先頭・末尾・含有チェック（全拡張子共通）
        $skipAny = ['ui-icon', 'ui_icon', 'sprite', 'favicon', 'pagetop'];
        foreach ($skipAny as $kw) {
            if (str_contains($base, $kw)) {
                return false;
            }
        }
        // icon_ / ico_ 先頭はほぼ必ずアイコン
        if (str_starts_with($base, 'icon_') || str_starts_with($base, 'ico_')) {
            return false;
        }
        // SVG は背景として使われることもあるが、icon 系単語を含む場合は除外
        if ($ext === 'svg') {
            $svgIconWords = ['icon', 'checkbox', 'arrow', 'close', 'delta', 'bullet', 'check', 'modal'];
            foreach ($svgIconWords as $w) {
                if (str_contains($base, $w)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param list<array{token:string, url:string}> $hints
     * @return list<array{token:string, url:string}>
     */
    private function dedupeCssHintsByUrl(array $hints): array
    {
        $seen = [];
        $out  = [];
        foreach ($hints as $row) {
            $u = $row['url'];
            if (isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $out[]    = $row;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function collectCssHintTokens(DOMElement $root): array
    {
        $tokens = [];
        $doc    = $root->ownerDocument;
        if ($doc === null) {
            return [];
        }

        $take = function (DOMElement $n) use (&$tokens): void {
            $id = trim($n->getAttribute('id'));
            if ($id !== '') {
                $tokens['#' . $id] = true;
            }
            foreach (preg_split('/\s+/u', trim($n->getAttribute('class'))) as $c) {
                if ($c === '') {
                    continue;
                }
                // 短いユーティリティクラスは CSS 全体に散在し url を大量に拾うため除外（数字・アンダースコアを含むものは残す）
                if (mb_strlen($c) < 7 && !preg_match('/[0-9_]/', $c)) {
                    continue;
                }
                $tokens['.' . $c] = true;
            }
        };

        $take($root);

        // セクションルート自身のタグ名もトークンに追加（例: header, footer, nav, section）。
        // "header { background: url(...) }" のようなタグセレクタ CSS を拾うため。
        $rootTag = strtolower($root->tagName);
        if (in_array($rootTag, self::SECTION_TAGS, true)) {
            $tokens[$rootTag] = true;
        }

        $xp = new DOMXPath($doc);
        $nodes = $xp->query('.//*', $root);
        $nWalk = 0;
        if ($nodes) {
            foreach ($nodes as $n) {
                if ($nWalk++ > 160) {
                    break;
                }
                if ($n instanceof DOMElement) {
                    $take($n);
                    // 子要素もタグ名をトークンに追加（main, nav など）
                    $childTag = strtolower($n->tagName);
                    if (in_array($childTag, self::SECTION_TAGS, true)) {
                        $tokens[$childTag] = true;
                    }
                }
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return list<array{token:string, url:string}>
     */
    private function extractInlineBackgroundUrlsFromSubtree(DOMElement $root): array
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return [];
        }
        $xp    = new DOMXPath($doc);
        $nodes = $xp->query('.//*[@style]', $root);
        $out   = [];
        if (!$nodes) {
            return [];
        }
        foreach ($nodes as $n) {
            if (!($n instanceof DOMElement)) {
                continue;
            }
            $st = $n->getAttribute('style');
            if ($st === '' || !preg_match('/url\(/i', $st)) {
                continue;
            }
            if (preg_match_all('/url\(\s*["\']?([^)"\'\\\\]+)["\']?\s*\)/i', $st, $m)) {
                foreach ($m[1] as $u) {
                    $u = trim($u);
                    if ($u !== '') {
                        $out[] = ['token' => '(inline style)', 'url' => $u];
                    }
                }
            }
        }

        return $out;
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
        ?array &$diagnosticsOut = null,
        string $headExtra = '',
        string $bodySnippets = ''
    ): array {
        $sections       = [];
        $candidateIndex = 0;
        $writtenIndex   = 0;
        $candidates   = $this->findStructuralElements($dom, $xpath);

        foreach ($candidates as $element) {
            $sectionId = 'sec_' . $writtenIndex;
            $elements  = [];
            $elemIndex = 0;

            try {
                $this->findEditableElements($element, $sectionId, $elemIndex, $elements, $onWalkVisit);

                $html = $this->buildSectionHtml($element, $dom);

                // 編集要素がなくても、背景のみのヒーロー空 div など視覚ブロックは HTML として保持する
                if ($elements === [] && trim($html) === '') {
                    $candidateIndex++;
                    continue;
                }

                if ($html === '' && $diagnosticsOut !== null) {
                    $diagnosticsOut['warnings'][] = [
                        'section_id' => $sectionId,
                        'message'    => 'セクション saveHTML が空でした',
                    ];
                }

                $sections[] = [
                    'id'                   => $sectionId,
                    'type'                 => $this->classifySection($element),
                    'label'                => $this->generateLabel($element, $writtenIndex),
                    'outer_tag'            => strtolower($element->tagName),
                    'html'                 => $html,
                    'elements'             => $elements,
                    'element_count'        => count($elements),
                    'css_background_hints' => $this->extractCssBackgroundHints($element, $headExtra, $bodySnippets),
                ];
                $writtenIndex++;

                if ($diagnosticsOut !== null) {
                    $diagnosticsOut['sections_written']++;
                }
            } catch (Throwable $e) {
                if ($diagnosticsOut !== null) {
                    $diagnosticsOut['section_errors'][] = [
                        'section_id'      => $sectionId,
                        'section_index'   => $candidateIndex,
                        'exception_class' => $e::class,
                        'message'         => $e->getMessage(),
                        'file'            => $e->getFile(),
                        'line'            => $e->getLine(),
                    ];
                }
            }

            $candidateIndex++;
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
            // h1-h6 used as body-level section wrappers (e.g. <h1 id="mv">) are included
            // only when they contain child elements (not bare text headings).
            $isBodyLevelHeading = in_array($tag, self::HEADING_TAGS) && $child->childElementCount > 0;
            if (in_array($tag, self::SECTION_TAGS) || $tag === 'div' || $isBodyLevelHeading) {
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
                // <picture> 内の <img> は fallback 専用。<source srcset> が実際に表示される画像。
                // libxml は <source> を非 void として <img> を内側に入れることがあるため、
                // parentNode 遡りで <picture> を探し、最後の <source srcset> URL を優先する。
                $pictureSrc = $this->bestPictureSourceSrcset($child);
                if ($pictureSrc !== null) {
                    $src = $pictureSrc;
                    $child->setAttribute('src', $src);
                }
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
                    $elements[] = array_merge($row, $this->hrefLinkageFields($wrapHref));
                }
            } elseif ($tag === 'a') {
                $text = trim($child->textContent);
                $href = $child->getAttribute('href') ?: '';
                $absHref = (!str_starts_with($href, '#')
                    && !str_starts_with($href, 'javascript:')
                    && !str_starts_with($href, 'tel:')
                    && !str_starts_with($href, 'mailto:'))
                    ? $this->absolutizeUrl($href)
                    : $href;

                // Only tag anchor if it has meaningful text and is not purely an image link
                if (mb_strlen($text) > 1 && !$child->getElementsByTagName('img')->length) {
                    $id = 'elem_' . $sectionId . '_' . $index++;
                    $child->setAttribute('data-lp-id', $id);
                    if ($absHref) {
                        $child->setAttribute('href', $absHref);
                    }
                    $elements[] = array_merge([
                        'id'            => $id,
                        'type'          => $this->isButtonAnchor($child) ? 'button' : 'link',
                        'tag'           => 'a',
                        'label'         => 'リンク：' . mb_substr($text, 0, 40),
                        'original_text' => $text,
                        'original_src'  => null,
                        'original_href' => $absHref,
                    ], $this->hrefLinkageFields($absHref !== '' ? $absHref : null));
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
                // インライン style="background-image:url(...)" を持つコンテナは background_image 要素として登録し、
                // さらに子要素を再帰走査する（テキスト・img の子も取りこぼさない）。
                $inlineBgSrc = $this->extractInlineBackgroundSrc($child);
                if ($inlineBgSrc !== null) {
                    $id = 'elem_' . $sectionId . '_' . $index++;
                    $child->setAttribute('data-lp-id', $id);
                    $baseName = basename(parse_url($inlineBgSrc, PHP_URL_PATH) ?: $inlineBgSrc);
                    $elements[] = [
                        'id'             => $id,
                        'type'           => 'background_image',
                        'tag'            => $tag,
                        'label'          => 'インライン背景：' . $baseName,
                        'original_text'  => null,
                        'original_src'   => $inlineBgSrc,
                        'original_href'  => null,
                    ];
                }
                $this->findEditableElements($child, $sectionId, $index, $elements, $onVisit);
            }
        }
    }

    /**
     * インライン style 属性から background-image URL を抽出して絶対化。
     * 見つからない場合は null を返す。
     */
    private function extractInlineBackgroundSrc(DOMElement $el): ?string
    {
        $style = $el->getAttribute('style');
        if ($style === '' || !str_contains(strtolower($style), 'background')) {
            return null;
        }
        if (preg_match('/background(?:-image)?\s*:\s*url\(\s*["\']?([^)"\']+)["\']?\s*\)/i', $style, $m)) {
            $u = trim($m[1]);
            if ($u !== '' && !str_starts_with(strtolower($u), 'data:')) {
                return $this->absolutizeUrl($u);
            }
        }
        return null;
    }

    /**
     * Absolutize all asset URLs inside the section element and return its outer HTML.
     */
    private function buildSectionHtml(DOMElement $element, DOMDocument $dom): string
    {
        LpDomScriptCleanup::stripScriptsAndJsSpills($element);

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
     * <img> の祖先に <picture> がある場合、その <picture> を返す。
     */
    private function findPictureAncestor(DOMElement $el): ?DOMElement
    {
        $p = $el->parentNode;
        while ($p instanceof DOMElement) {
            if (strtolower($p->tagName) === 'picture') {
                return $p;
            }
            $p = $p->parentNode;
        }
        return null;
    }

    /**
     * <img> が <picture> 内にある場合、デスクトップ向け <source srcset> の先頭 URL（絶対化済み）を返す。
     * max-width のみの media query（スマホ専用）を持つ <source> はスキップし、
     * media 属性なし or min-width を含む <source> を優先する。
     * デスクトップ向け <source> が存在しない場合は null を返し、<img src> の値をそのまま使わせる。
     * <picture> 外の場合も null を返す。
     */
    private function bestPictureSourceSrcset(DOMElement $img): ?string
    {
        $picture = $this->findPictureAncestor($img);
        if ($picture === null) {
            return null;
        }
        $best = null;
        foreach ($picture->getElementsByTagName('source') as $source) {
            /** @var DOMElement $source */
            $media = trim($source->getAttribute('media'));
            // max-width のみのスマホ専用 source はデスクトップ表示用画像の選択に使わない
            if ($media !== '' && preg_match('/max-width/i', $media) && !preg_match('/min-width/i', $media)) {
                continue;
            }
            $srcset = trim($source->getAttribute('srcset'));
            if ($srcset === '') {
                continue;
            }
            $firstPart = trim(explode(',', $srcset)[0]);
            $url       = trim(explode(' ', $firstPart)[0]);
            if ($url !== '' && !str_starts_with($url, 'data:')) {
                $abs = $this->absolutizeUrl($url);
                if ($abs !== '') {
                    $best = $abs;
                }
            }
        }
        return $best;
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
     * @return array{href_scope: string, href_canonical: ?string}
     */
    private function hrefLinkageFields(?string $storedHref): array
    {
        if ($storedHref === null || trim($storedHref) === '') {
            return ['href_scope' => 'none', 'href_canonical' => null];
        }
        $h = trim($storedHref);
        $scope = LpUrlContext::classifyHrefScope($h, $this->baseUrl);
        $canonical = null;
        if (preg_match('#^https?://#i', $h)) {
            $canonical = LpUrlContext::canonicalHttpDocumentIdentity($h);
        }

        return ['href_scope' => $scope, 'href_canonical' => $canonical];
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
