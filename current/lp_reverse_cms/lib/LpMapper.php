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
                $element['type_label'] = self::ELEMENT_TYPE_LABELS[$element['type']] ?? $element['type'];
                $element['editable']   = true;
            }
            unset($element);

            $section['element_count']           = count($section['elements']);
            $structure['total_elements']        += $section['element_count'];
        }
        unset($section);

        return $structure;
    }
}
