<?php

declare(strict_types=1);

require_once __DIR__ . '/LpFetcher.php';
require_once __DIR__ . '/LpUrlContext.php';

/**
 * リンク URL → HEAD（HEAD が拒否される場合は限定 GET）でリダイレクト後の最終 URL を取得し、
 * clone_site.scheme_host と比較して href_scope / href_canonical を確定する。
 *
 * original_href 単位で HEAD は一度だけ（同一 URL が多数あるサイトでのタイムアウト回避）。
 */
final class LpLinkRedirectVerifier
{
    /** サイトによっては一意 URL が極端に多く analyze がプロキシ／PHP の壁時間で切れるため上限を設ける */
    private const MAX_UNIQUE_HEAD_URLS = 450;

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

        /** @var array<string, list<array{si:int, ei:int}>> $byHref */
        $byHref = [];
        foreach ($structure['sections'] ?? [] as $si => $sec) {
            foreach ($sec['elements'] ?? [] as $ei => $el) {
                if (($el['href_scope'] ?? '') !== 'internal') {
                    continue;
                }
                $href = $el['original_href'] ?? '';
                if (!is_string($href) || !preg_match('#^https?://#i', $href)) {
                    continue;
                }
                $byHref[$href][] = ['si' => (int) $si, 'ei' => (int) $ei];
            }
        }

        if ($byHref === []) {
            return;
        }

        $hrefKeys = array_keys($byHref);
        sort($hrefKeys);
        $willCheck = min(count($hrefKeys), self::MAX_UNIQUE_HEAD_URLS);
        $den       = max(1, $willCheck);

        foreach ($hrefKeys as $rank => $href) {
            $positions = $byHref[$href];

            if ($rank >= self::MAX_UNIQUE_HEAD_URLS) {
                foreach ($positions as $pos) {
                    $structure['sections'][$pos['si']]['elements'][$pos['ei']]['href_redirect_check'] = 'head_skipped_budget';
                }

                continue;
            }

            if ($emit !== null) {
                $emit([
                    'type'      => 'progress',
                    'phase'     => 'link_redirect_check',
                    'pct'       => min(58, 52 + (int) round(6 * (($rank + 1) / $den))),
                    'detail_ja' => sprintf(
                        'リンク先 HEAD（一意 URL %s / %s・要素総照合は重複除外）…',
                        (string) ($rank + 1),
                        (string) $willCheck
                    ),
                ]);
            }

            try {
                $res = $fetcher->resolveEffectiveUrlWithRedirects($href);
            } catch (Throwable) {
                foreach ($positions as $pos) {
                    $structure['sections'][$pos['si']]['elements'][$pos['ei']]['href_redirect_check'] = 'request_failed';
                }

                continue;
            }

            if (!$res['curl_ok']) {
                foreach ($positions as $pos) {
                    $structure['sections'][$pos['si']]['elements'][$pos['ei']]['href_redirect_check'] = 'head_failed';
                }

                continue;
            }

            $final = $res['final_url'];

            if (!preg_match('#^https?://#i', $final)) {
                foreach ($positions as $pos) {
                    $structure['sections'][$pos['si']]['elements'][$pos['ei']]['href_redirect_final_url'] = $final;
                    $structure['sections'][$pos['si']]['elements'][$pos['ei']]['href_redirect_check']      = 'invalid_final';
                }

                continue;
            }

            if (LpUrlContext::classifyHrefScope($final, $schemeHost) !== 'internal') {
                $canon = LpUrlContext::canonicalHttpDocumentIdentity($final);
                foreach ($positions as $pos) {
                    $si = $pos['si'];
                    $ei = $pos['ei'];
                    $structure['sections'][$si]['elements'][$ei]['href_redirect_final_url'] = $final;
                    $structure['sections'][$si]['elements'][$ei]['href_scope']              = 'external';
                    $structure['sections'][$si]['elements'][$ei]['href_canonical']          = $canon;
                    $structure['sections'][$si]['elements'][$ei]['href_redirect_check']    = 'cross_origin_after_redirect';
                }

                continue;
            }

            $canon = LpUrlContext::canonicalHttpDocumentIdentity($final);
            foreach ($positions as $pos) {
                $si = $pos['si'];
                $ei = $pos['ei'];
                $structure['sections'][$si]['elements'][$ei]['href_redirect_final_url'] = $final;
                $structure['sections'][$si]['elements'][$ei]['href_scope']            = 'internal';
                $structure['sections'][$si]['elements'][$ei]['href_canonical']        = $canon;
                $structure['sections'][$si]['elements'][$ei]['href_redirect_check']   = 'same_origin_after_redirect';
            }
        }
    }
}
