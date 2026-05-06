<?php

declare(strict_types=1);

final class LpIoNeutralizer
{
    /**
     * @param array<string,mixed> $structure
     * @return list<array<string,mixed>>
     */
    public static function detectRegions(array &$structure, string $pageCoordinate = 'entry'): array
    {
        $regions = [];
        foreach ($structure['sections'] ?? [] as $si => &$section) {
            if (!is_array($section)) {
                continue;
            }
            $coord = sprintf('%s.section[%d]', $pageCoordinate, (int) $si);
            $html = (string) ($section['html'] ?? '');
            if ($html === '') {
                continue;
            }
            $found = self::detectInSectionHtml($html, $coord);
            if ($found !== []) {
                $regions = array_merge($regions, $found);
            }
        }
        unset($section);

        return $regions;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function detectInSectionHtml(string $html, string $coordinate): array
    {
        $regions = [];
        $lower = strtolower($html);

        if (preg_match('#<form[^>]*action=["\']([^"\']*)["\'][^>]*>#i', $html, $m)) {
            $action = (string) ($m[1] ?? '');
            $type = 'contact_form';
            if (str_contains($lower, 'mailchimp') || str_contains($lower, 'newsletter')) {
                $type = 'newsletter';
            } elseif (str_contains($lower, 'login') || str_contains($lower, 'signin')) {
                $type = 'login';
            }
            $fields = [];
            if (preg_match_all('#<input[^>]*name=["\']([^"\']+)#i', $html, $mm)) {
                foreach (($mm[1] ?? []) as $name) {
                    if (is_string($name) && $name !== '') {
                        $fields[] = $name;
                    }
                }
            }
            $regions[] = [
                'coordinate' => $coordinate,
                'type' => $type,
                'original_action' => $action,
                'fields' => array_values(array_unique($fields)),
                'status' => 'neutralized',
            ];
        }

        if (str_contains($lower, 'stripe') || str_contains($lower, 'checkout')) {
            $regions[] = [
                'coordinate' => $coordinate,
                'type' => 'payment',
                'provider' => str_contains($lower, 'stripe') ? 'stripe' : 'unknown',
                'status' => 'neutralized',
            ];
        }

        if (str_contains($lower, '<iframe') || str_contains($lower, 'youtube.com') || str_contains($lower, 'maps.google')) {
            $regions[] = [
                'coordinate' => $coordinate,
                'type' => 'external_embed',
                'status' => 'neutralized',
            ];
        }

        return $regions;
    }

    /**
     * HTML 文字列に data_io_regions を適用して無効化・属性付与を行う
     *
     * @param list<array<string,mixed>> $ioRegions
     */
    public static function applyNeutralization(string $html, array $ioRegions): string
    {
        if ($ioRegions === []) {
            return $html;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach ($ioRegions as $region) {
            if (!is_array($region)) {
                continue;
            }

            $coordinate = (string) ($region['coordinate'] ?? '');
            $type = (string) ($region['type'] ?? '');
            if ($coordinate === '' || $type === '') {
                continue;
            }

            $sectionEl = self::findSectionRootForCoordinate($xpath, $coordinate);
            if ($sectionEl === null) {
                continue;
            }

            switch ($type) {
                case 'contact_form':
                case 'newsletter':
                case 'login':
                    self::applyFormNeutralization($xpath, $sectionEl, $type, $coordinate, $region);

                    break;

                case 'payment':
                    self::applyPaymentNeutralization($xpath, $sectionEl, $coordinate);

                    break;

                case 'external_embed':
                    self::applyExternalEmbedNeutralization($dom, $xpath, $sectionEl, $coordinate);

                    break;

                default:
                    break;
            }
        }

        return $dom->saveHTML();
    }

    private static function findSectionRootForCoordinate(DOMXPath $xpath, string $coordinate): ?DOMElement
    {
        if (!preg_match('/section\[(\d+)\]/', $coordinate, $m)) {
            return null;
        }

        $idx = (int) $m[1];
        $nodes = $xpath->query(
            '//body/div[contains(concat(\' \', normalize-space(@class), \' \'), \' lp-reverse-section-root \')]'
        );

        if ($nodes === false || $idx < 0 || $idx >= $nodes->length) {
            return null;
        }

        $n = $nodes->item($idx);

        return $n instanceof DOMElement ? $n : null;
    }

    /**
     * @param array<string,mixed> $region
     */
    private static function applyFormNeutralization(
        DOMXPath $xpath,
        DOMElement $sectionEl,
        string $type,
        string $coordinate,
        array $region
    ): void {
        $forms = $xpath->query('.//form', $sectionEl);
        if ($forms === false || $forms->length === 0) {
            return;
        }

        $form = $forms->item(0);
        if (!($form instanceof DOMElement)) {
            return;
        }

        $originalAction = trim((string) ($region['original_action'] ?? $form->getAttribute('action')));
        $form->setAttribute('action', '#');
        $form->setAttribute('data-lp-io-type', $type);
        $form->setAttribute('data-lp-io-original-action', $originalAction);
        $form->setAttribute('data-lp-io-coordinate', $coordinate);
    }

    private static function applyPaymentNeutralization(DOMXPath $xpath, DOMElement $sectionEl, string $coordinate): void
    {
        $nodes = $xpath->query('.//*', $sectionEl);
        if ($nodes === false) {
            return;
        }

        for ($i = 0; $i < $nodes->length; ++$i) {
            $el = $nodes->item($i);
            if (!($el instanceof DOMElement)) {
                continue;
            }

            $removeNames = [];
            foreach ($el->attributes ?? [] as $attr) {
                $name = $attr->nodeName;
                $nl = strtolower($name);
                if (str_starts_with($nl, 'on')) {
                    $removeNames[] = $name;
                }
                if (str_contains($nl, 'stripe')) {
                    $removeNames[] = $name;
                }
            }

            foreach (array_unique($removeNames) as $name) {
                $el->removeAttribute($name);
            }
        }

        $btnPaths = [
            './/button',
            './/input[@type="submit"]',
            './/input[@type="button"]',
        ];
        foreach ($btnPaths as $bp) {
            $hits = $xpath->query($bp, $sectionEl);
            if ($hits !== false && $hits->length > 0) {
                $btn = $hits->item(0);
                if ($btn instanceof DOMElement) {
                    $btn->setAttribute('data-lp-io-type', 'payment');
                    $btn->setAttribute('data-lp-io-coordinate', $coordinate);

                    return;
                }
            }
        }

        $sectionEl->setAttribute('data-lp-io-type', 'payment');
        $sectionEl->setAttribute('data-lp-io-coordinate', $coordinate);
    }

    private static function applyExternalEmbedNeutralization(
        DOMDocument $dom,
        DOMXPath $xpath,
        DOMElement $sectionEl,
        string $coordinate
    ): void {
        $iframes = $xpath->query('.//iframe', $sectionEl);
        if ($iframes === false || $iframes->length === 0) {
            return;
        }

        $iframe = $iframes->item(0);
        if (!($iframe instanceof DOMElement) || $iframe->parentNode === null) {
            return;
        }

        $placeholder = $dom->createElement('div');
        $placeholder->setAttribute('data-lp-io-type', 'external_embed');
        $placeholder->setAttribute('data-lp-io-coordinate', $coordinate);
        $placeholder->setAttribute('style', 'background:#f0f0f0;padding:2rem;text-align:center');
        $placeholder->appendChild($dom->createTextNode('[外部コンテンツ: 後付け実装が必要]'));
        $iframe->parentNode->replaceChild($placeholder, $iframe);
    }
}

