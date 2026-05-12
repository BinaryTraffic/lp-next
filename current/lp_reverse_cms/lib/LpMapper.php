<?php

declare(strict_types=1);

/**
 * LpMapper — enriches the raw structure produced by LpAnalyzer
 * with UI-friendly metadata (icons, counts, edit-readiness flags).
 */
class LpMapper
{
    private const TYPE_META = [
        'nav'          => ['icon' => 'bi-list-ul',                     'label' => 'ナビゲーション'],
        'hero'         => ['icon' => 'bi-star-fill',                   'label' => 'ヒーロー'],
        'features'     => ['icon' => 'bi-check2-circle',               'label' => '特徴・メリット'],
        'testimonials' => ['icon' => 'bi-chat-quote-fill',             'label' => 'お客様の声'],
        'cta'          => ['icon' => 'bi-arrow-right-circle-fill',     'label' => 'CTA / お問い合わせ'],
        'pricing'      => ['icon' => 'bi-tag-fill',                    'label' => '料金プラン'],
        'faq'          => ['icon' => 'bi-question-circle-fill',        'label' => 'よくある質問'],
        'footer'       => ['icon' => 'bi-layout-text-window-reverse',  'label' => 'フッター'],
        'general'      => ['icon' => 'bi-layout-text-sidebar-reverse', 'label' => 'セクション'],
    ];

    private const ELEMENT_TYPE_LABELS = [
        'heading'   => '見出し',
        'paragraph' => 'テキスト',
        'image'     => '画像',
        'button'    => 'ボタン',
        'link'      => 'リンク',
    ];

    /**
     * Enrich the structure with display metadata.
     */
    public function enrich(array $structure): array
    {
        $structure['total_elements'] = 0;

        foreach ($structure['sections'] as &$section) {
            $type = $section['type'] ?? 'general';
            $meta = self::TYPE_META[$type] ?? self::TYPE_META['general'];

            $section['type_icon']  = $meta['icon'];
            $section['type_label'] = $meta['label'];

            foreach ($section['elements'] as &$element) {
                $element['type_label'] = self::elementTypeLabel($element);
                $element['editable']   = true;
            }
            unset($element);

            $section['element_count']           = count($section['elements']);
            $structure['total_elements']        += $section['element_count'];
        }
        unset($section);

        $structure['button_objects'] = self::collectButtonObjects($structure);

        return $structure;
    }

    /**
     * @param array{type?: string, original_href?: ?string, label?: string, original_text?: string, original_src?: string} $element
     */
    private static function elementTypeLabel(array $element): string
    {
        $t = $element['type'] ?? '';
        if ($t === 'image' && !empty($element['original_href'])) {
            return '画像（リンク付き）';
        }

        return self::ELEMENT_TYPE_LABELS[$t] ?? $t;
    }

    /**
     * クローン LP 上の「ボタン扱い」オブジェクト一覧（ラスタ＋囲みリンク等）。
     * ツール・API がセクションを走査せずに参照できる。
     *
     * @return list<array<string, mixed>>
     */
    private static function collectButtonObjects(array $structure): array
    {
        $out = [];
        foreach ($structure['sections'] ?? [] as $sec) {
            $secType = $sec['type'] ?? 'general';
            foreach ($sec['elements'] ?? [] as $el) {
                if (($el['type'] ?? '') !== 'image') {
                    continue;
                }
                $src = (string) ($el['original_src'] ?? '');
                if ($src === '' || preg_match('#favicon\\.ico#i', $src)) {
                    continue;
                }
                $href = isset($el['original_href']) && is_string($el['original_href']) ? $el['original_href'] : '';
                $scoreInfo = self::scoreRasterButton($el, $secType);
                if ($href === '' && $scoreInfo['score'] < 1) {
                    continue;
                }
                $bo = [
                    'element_id'     => $el['id'] ?? '',
                    'section_id'     => $sec['id'] ?? '',
                    'section_type'   => $secType,
                    'section_label'  => $sec['label'] ?? ($sec['id'] ?? ''),
                    'label'          => $el['label'] ?? '',
                    'image_src'      => $src,
                    'href'           => $href !== '' ? $href : null,
                    'target'         => $el['wrap_target'] ?? null,
                    'rel'            => $el['wrap_rel'] ?? null,
                    'alt'            => (string) ($el['original_text'] ?? ''),
                    'button_score'   => $scoreInfo['score'] + ($href !== '' ? 1 : 0),
                    'button_reasons' => array_merge(
                        $scoreInfo['reasons'],
                        $href !== '' ? ['囲みa要素'] : []
                    ),
                ];
                if (isset($el['original_width'], $el['original_height'])) {
                    $ow = (int) $el['original_width'];
                    $oh = (int) $el['original_height'];
                    if ($ow >= 16 && $oh >= 16) {
                        $bo['original_width']  = $ow;
                        $bo['original_height'] = $oh;
                    }
                }
                $out[] = $bo;
            }
        }

        usort($out, static fn (array $a, array $b): int => ($b['button_score'] ?? 0) <=> ($a['button_score'] ?? 0));

        return $out;
    }

    /**
     * @return array{score: int, reasons: list<string>}
     */
    private static function scoreRasterButton(array $el, string $secType): array
    {
        $src   = strtolower((string) ($el['original_src'] ?? ''));
        $label = strtolower((string) ($el['label'] ?? ''));
        $alt   = trim((string) ($el['original_text'] ?? ''));

        $score   = 0;
        $reasons = [];
        if (preg_match('#/btn[^/]*\\.(jpg|jpeg|png|webp)#i', $src)) {
            $score += 3;
            $reasons[] = 'ファイル名btn';
        }
        if (preg_match('/btn\d|ボタン|\bbutton\b/u', $label)) {
            $score += 2;
            $reasons[] = 'ラベル';
        }
        if ($secType === 'cta') {
            $score += 1;
            $reasons[] = 'CTA節';
        }
        if ($alt !== '' && mb_strlen($alt) <= 72 && !preg_match('#^https?://#i', $alt)) {
            $score += 1;
            $reasons[] = '短いalt';
        }

        return ['score' => $score, 'reasons' => $reasons];
    }
}
