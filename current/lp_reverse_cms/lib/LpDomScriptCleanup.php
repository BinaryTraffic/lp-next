<?php

declare(strict_types=1);

/**
 * libxml の HTML パースは script 内にリテラルの終端タグ断片があると途中で終端し、
 * 残りの JS がテキストノードとして body に露出することがある。静的クローンではスクリプトを実行しないため除去する。
 */
final class LpDomScriptCleanup
{
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
    }

    private static function textLooksLikeJavaScriptSpill(string $t): bool
    {
        $len = strlen($t);
        if ($len < 28) {
            return false;
        }

        if ($len >= 40 && str_contains($t, 'html +=')) {
            return true;
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
}
