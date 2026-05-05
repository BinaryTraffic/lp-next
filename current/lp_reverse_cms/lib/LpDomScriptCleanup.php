<?php

declare(strict_types=1);

/**
 * libxml の HTML パースは script 内にリテラルの終端タグ断片があると途中で終端し、
 * 残りの JS がテキストノードとして body に露出することがある。静的クローンではスクリプトを実行しないため除去する。
 */
final class LpDomScriptCleanup
{
    /**
     * Preprocess raw HTML before libxml parsing: keep <script ...></script> tags
     * but remove inline script bodies in O(n) scan to avoid regex backtracking blowups.
     */
    public static function stripInlineScriptBodiesFromHtml(string $html): string
    {
        if ($html === '' || stripos($html, '<script') === false) {
            return $html;
        }

        $out = '';
        $pos = 0;
        $len = strlen($html);
        while ($pos < $len) {
            $open = stripos($html, '<script', $pos);
            if ($open === false) {
                $out .= substr($html, $pos);
                break;
            }

            $out .= substr($html, $pos, $open - $pos);
            $tagEnd = strpos($html, '>', $open);
            if ($tagEnd === false) {
                $out .= substr($html, $open);
                break;
            }

            $startTag = substr($html, $open, $tagEnd - $open + 1);
            $closePos = stripos($html, '</script>', $tagEnd + 1);
            if ($closePos === false) {
                $out .= $startTag;
                break;
            }

            // keep external script tags untouched
            if (preg_match('/\ssrc\s*=/i', $startTag) === 1) {
                $out .= substr($html, $open, ($closePos + 9) - $open);
            } else {
                $out .= $startTag . '</script>';
            }

            $pos = $closePos + 9;
        }

        return $out;
    }

    /**
     * &lt;script&gt; 要素を削除し、パース崩れで露出した JS らしいテキストノードも削除する。
     */
    public static function stripScriptsAndJsSpills(DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return;
        }

        $scripts = [];
        foreach ($root->getElementsByTagName('script') as $n) {
            $scripts[] = $n;
        }
        foreach ($scripts as $n) {
            $n->parentNode?->removeChild($n);
        }

        $xp = new DOMXPath($doc);
        $textNodes = $xp->query('.//text()', $root);
        if (!$textNodes) {
            return;
        }

        $toRemove = [];
        foreach ($textNodes as $tn) {
            if (!($tn instanceof DOMText)) {
                continue;
            }
            $parent = $tn->parentNode;
            if (!$parent instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($parent->tagName);
            if (in_array($tag, ['script', 'style', 'textarea', 'pre', 'code'], true)) {
                continue;
            }
            if (!self::textLooksLikeJavaScriptSpill($tn->textContent)) {
                continue;
            }
            $toRemove[] = $tn;
        }
        foreach ($toRemove as $tn) {
            $tn->parentNode?->removeChild($tn);
        }

        self::stripLibxmlTemplateLiteralArtifactNodes($root);

        self::stripTemplatePlaceholderUrlAttributes($root);
    }

    /**
     * libxml が &lt;script&gt; 内のテンプレートリテラルに含まれた HTML を実ノードとして解釈したときに
     * 残るゴミ（例: wd_no_icon の IMG のみの div、` : ` 周りのバッククォート）を除去する。
     * 事前の script body 削除は大きなページで構造抽出を壊すため、確実なノード単位のみ後処理する。
     */
    private static function stripLibxmlTemplateLiteralArtifactNodes(DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return;
        }

        $xp = new DOMXPath($doc);

        // `<div class="wd_no_icon">IMG</div>` from inline JS template literals (search widget clones).
        $wd = $xp->query(
            './/div[contains(concat(\' \', normalize-space(@class), \' \'), \' wd_no_icon \')]',
            $root
        );
        $removeEls = [];
        if ($wd) {
            foreach ($wd as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                $hasElementChild = false;
                foreach ($node->childNodes as $ch) {
                    if ($ch instanceof DOMElement) {
                        $hasElementChild = true;
                        break;
                    }
                }
                if ($hasElementChild) {
                    continue;
                }
                if (preg_replace('/\s+/u', '', (string) $node->textContent) === 'IMG') {
                    $removeEls[] = $node;
                }
            }
        }

        // Empty predictive grid shells sometimes appear with no real content after script removal.
        $grid = $xp->query(
            './/div[contains(concat(\' \', normalize-space(@class), \' \'), \' wd_predictive_pages_grid \')]',
            $root
        );
        if ($grid) {
            foreach ($grid as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                $hasElementChild = false;
                foreach ($node->childNodes as $ch) {
                    if ($ch instanceof DOMElement) {
                        $hasElementChild = true;
                        break;
                    }
                }
                if ($hasElementChild) {
                    continue;
                }
                if (trim((string) $node->textContent) === '') {
                    $removeEls[] = $node;
                }
            }
        }

        foreach ($removeEls as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    private static function stripTemplatePlaceholderUrlAttributes(DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return;
        }
        $xp = new DOMXPath($doc);
        $nodes = $xp->query('.//*', $root);
        if (!$nodes) {
            return;
        }

        $urlAttrs = [
            'src', 'href', 'poster', 'style',
            'srcset', 'data-src', 'data-srcset', 'data-bg',
            'data-background', 'data-original', 'data-lazy-src',
        ];

        $removeElements = [];
        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            foreach ($urlAttrs as $attr) {
                if (!$node->hasAttribute($attr)) {
                    continue;
                }
                $v = trim($node->getAttribute($attr));
                if ($v === '') {
                    continue;
                }
                if (!self::textLooksLikeTemplatePlaceholderUrl($v)) {
                    continue;
                }

                // Placeholder-only images are always broken; remove node itself.
                if ($attr === 'src' && strtolower($node->tagName) === 'img') {
                    $removeElements[] = $node;
                    continue;
                }
                $node->removeAttribute($attr);
            }
        }

        foreach ($removeElements as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    private static function textLooksLikeJavaScriptSpill(string $t): bool
    {
        $len = strlen($t);
        if ($len < 1) {
            return false;
        }

        // Stray punctuation from ternary / template glue: ` : ` or `?`...`:` next to leaked tags
        if (str_contains($t, '`')) {
            if ($len <= 40 && preg_match('/[?:]/', $t) === 1) {
                return true;
            }
            if ($len <= 32) {
                return true;
            }
        }

        // Tiny comma/quote-only scraps next to mis-parsed scripts
        if ($len <= 16 && preg_match('/^[\s`,\'":;|&]+$/', $t) === 1) {
            return true;
        }

        if ($len < 8) {
            return false;
        }

        // Short fragments from broken script parsing (e.g. trailing quotes before innerHTML builder)
        if (preg_match("/html\\s*\\+=/", $t) === 1 && $len >= 10) {
            return true;
        }
        // Template literal remnants rendered as text (${icon}${item.t})
        if ($len >= 8 && preg_match('/\$\{[^{}]+\}/', $t) === 1) {
            return true;
        }

        if ($len < 28) {
            return false;
        }

        if (str_contains($t, '.forEach(')) {
            return true;
        }
        if (str_contains($t, 'filteredPages') || str_contains($t, 'resultsContainer')) {
            return true;
        }

        if ($len < 100) {
            return false;
        }

        $signals = 0;
        foreach ([
            'function',
            '=>',
            'innerHTML',
            'addEventListener',
            'document.',
            'querySelector',
            'const ',
            'let ',
            'var ',
        ] as $needle) {
            if (str_contains($t, $needle)) {
                $signals++;
                if ($signals >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function textLooksLikeTemplatePlaceholderUrl(string $v): bool
    {
        if (preg_match('/\$\{[^{}]+\}/', $v) === 1) {
            return true;
        }
        if (stripos($v, '%24%7B') !== false) {
            return true;
        }

        return false;
    }
}
