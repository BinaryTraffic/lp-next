<?php

declare(strict_types=1);

require_once __DIR__ . '/LpFetcher.php';
require_once __DIR__ . '/LpUrlContext.php';

/**
 * リンク URL → HEAD（HEAD が拒否される場合は限定 GET）でリダイレクト後の最終 URL を取得し、
 * clone_site.scheme_host と比較して href_scope / href_canonical を確定する。
 */
final class LpLinkRedirectVerifier
{
    /**
     * @param callable(array<string, mixed>): void|null $emit
     */
    public static function verifyAndAnnotate(array &$structure, LpFetcher $fetcher, ?callable $emit = null): void
    {
        $cloneSite = $structure['clone_site'] ?? [];
        if (!is_array($cloneSite)) {
            return;
        }
        $schemeHost = (string) ($cloneSite['scheme_host'] ?? '');
        if ($schemeHost === '') {
            return;
        }

        /** @var list<array{si:int, ei:int}> $targets */
        $targets = [];
        foreach ($structure['sections'] ?? [] as $si => $sec) {
            foreach ($sec['elements'] ?? [] as $ei => $el) {
                if (($el['href_scope'] ?? '') !== 'internal') {
                    continue;
                }
                $href = $el['original_href'] ?? '';
                if (!is_string($href) || !preg_match('#^https?://#i', $href)) {
                    continue;
                }
                $targets[] = ['si' => (int) $si, 'ei' => (int) $ei];
            }
        }

        $total = max(1, count($targets));

        foreach ($targets as $idx => $pos) {
            $si = $pos['si'];
            $ei = $pos['ei'];

            if ($emit !== null) {
                $emit([
                    'type'      => 'progress',
                    'phase'     => 'link_redirect_check',
                    'pct'       => min(58, 52 + (int) round(6 * (($idx + 1) / $total))),
                    'detail_ja' => sprintf(
                        'リンク先 HEAD（リダイレクト確認） %s / %s …',
                        (string) ($idx + 1),
                        (string) count($targets)
                    ),
                ]);
            }

            $href = $structure['sections'][$si]['elements'][$ei]['original_href'] ?? '';
            if (!is_string($href)) {
                continue;
            }

            try {
                $res = $fetcher->resolveEffectiveUrlWithRedirects($href);
            } catch (Throwable) {
                $structure['sections'][$si]['elements'][$ei]['href_redirect_check'] = 'request_failed';

                continue;
            }

            if (!$res['curl_ok']) {
                $structure['sections'][$si]['elements'][$ei]['href_redirect_check'] = 'head_failed';

                continue;
            }

            $final = $res['final_url'];
            $structure['sections'][$si]['elements'][$ei]['href_redirect_final_url'] = $final;

            if (!preg_match('#^https?://#i', $final)) {
                $structure['sections'][$si]['elements'][$ei]['href_redirect_check'] = 'invalid_final';

                continue;
            }

            if (LpUrlContext::classifyHrefScope($final, $schemeHost) !== 'internal') {
                $structure['sections'][$si]['elements'][$ei]['href_scope']             = 'external';
                $structure['sections'][$si]['elements'][$ei]['href_canonical']         = LpUrlContext::canonicalHttpDocumentIdentity($final);
                $structure['sections'][$si]['elements'][$ei]['href_redirect_check'] = 'cross_origin_after_redirect';

                continue;
            }

            $structure['sections'][$si]['elements'][$ei]['href_scope']             = 'internal';
            $structure['sections'][$si]['elements'][$ei]['href_canonical']         = LpUrlContext::canonicalHttpDocumentIdentity($final);
            $structure['sections'][$si]['elements'][$ei]['href_redirect_check'] = 'same_origin_after_redirect';
        }
    }
}
