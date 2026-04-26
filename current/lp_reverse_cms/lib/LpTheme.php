<?php

declare(strict_types=1);

/**
 * LP-NEXT サイト全体テーマ（パレット・ボタン板プリセット・UI トークン）の単一ソース。
 *
 * - ツール・API: store/lp_theme.php（JSON）
 * - 静的ページ用 CSS 変数: store/lp_theme.css.php
 * - 上書き（任意）: data/lp_theme.local.json（Git 管理外の data/ に配置。palette / button_plate / ui を再帰マージ）
 *
 * HTML 側ではパレット項目の class（例: lp-theme-palette lp-theme-palette--blue）を
 * ボタン・スウォッチに付与し、--lp-palette-{id} と組み合わせてサイト全体で統一します。
 */
final class LpTheme
{
    public const SCHEMA_VERSION = 1;

    /** @var array<string, mixed>|null */
    private static ?array $mergedCache = null;

    /**
     * マージ済み定義（base + data/lp_theme.local.json）。
     *
     * @return array<string, mixed>
     */
    public static function mergedDefinition(): array
    {
        if (self::$mergedCache !== null) {
            return self::$mergedCache;
        }

        $base = self::baseDefinition();
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'lp_theme.local.json';
        if (is_readable($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) {
                $base = self::deepMerge($base, $raw);
            }
        }

        self::$mergedCache = $base;

        return self::$mergedCache;
    }

    public static function resetMergedCacheForTests(): void
    {
        self::$mergedCache = null;
    }

    /**
     * フロント・ツール向け JSON（schema_version / palette / button_plate / ui / classes）。
     *
     * @return array<string, mixed>
     */
    public static function forApi(): array
    {
        $d = self::mergedDefinition();

        return [
            'schema_version' => $d['schema_version'] ?? self::SCHEMA_VERSION,
            'classes'        => $d['classes'] ?? [],
            'palette'        => $d['palette'] ?? [],
            'button_plate'   => $d['button_plate'] ?? [],
            'ui'             => $d['ui'] ?? [],
        ];
    }

    public static function isValidButtonPlateShape(string $id): bool
    {
        foreach (self::mergedDefinition()['button_plate']['shapes'] ?? [] as $row) {
            if (($row['id'] ?? '') === $id) {
                return true;
            }
        }

        return false;
    }

    public static function isValidButtonPlateStyle(string $id): bool
    {
        foreach (self::mergedDefinition()['button_plate']['styles'] ?? [] as $row) {
            if (($row['id'] ?? '') === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public static function buttonPlateDefaults(): array
    {
        $bp = self::mergedDefinition()['button_plate'] ?? [];
        $def = is_array($bp['defaults'] ?? null) ? $bp['defaults'] : [];

        return array_merge([
            'shape'         => 'rounded',
            'style'         => 'flat',
            'color'         => '#0b57d0',
            'inner_color'   => '#ffffff',
            'radius_pct'    => 0.18,
            'stroke_width'  => 2,
        ], $def);
    }

    /**
     * :root 用 CSS カスタムプロパティ（--lp-* と既存ツール向けエイリアス --accent 等）。
     */
    public static function cssCustomProperties(): string
    {
        $d    = self::mergedDefinition();
        $ui   = $d['ui'] ?? [];
        $lines = [
            '/* LP-NEXT theme v' . (int) ($d['schema_version'] ?? self::SCHEMA_VERSION) . ' — lib/LpTheme.php */',
            ':root {',
            '  --lp-schema-version: ' . (int) ($d['schema_version'] ?? self::SCHEMA_VERSION) . ';',
        ];

        foreach ($ui as $k => $v) {
            if (!is_string($v) || $v === '') {
                continue;
            }
            $prop = strtolower(preg_replace('/_+/', '-', (string) $k) ?? '');
            if ($prop === '') {
                continue;
            }
            $lines[] = '  --lp-' . $prop . ': ' . $v . ';';
        }

        foreach ($d['palette'] ?? [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pid = isset($p['id']) ? (string) $p['id'] : '';
            $hex = isset($p['hex']) ? (string) $p['hex'] : '';
            if ($pid === '' || $hex === '' || !preg_match('/^#[0-9a-f]{3,8}$/i', $hex)) {
                continue;
            }
            $safeId = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $pid) ?? '');
            if ($safeId === '') {
                continue;
            }
            $lines[] = '  --lp-palette-' . $safeId . ': ' . $hex . ';';
        }

        $lines[] = '  --accent: var(--lp-accent);';
        $lines[] = '  --bg: var(--lp-bg);';
        $lines[] = '  --card: var(--lp-card);';
        $lines[] = '  --border: var(--lp-border);';
        $lines[] = '  --muted: var(--lp-muted);';
        $lines[] = '  --text: var(--lp-text);';
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseDefinition(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'classes'        => [
                'prefix'              => 'lp-theme',
                'palette_swatch'      => 'lp-theme-palette',
                'button_plate_shape'  => 'lp-plate-shape',
                'button_plate_style'  => 'lp-plate-style',
            ],
            'palette'        => [
                ['id' => 'blue', 'label' => 'ブルー', 'hex' => '#0b57d0', 'class' => 'lp-theme-palette lp-theme-palette--blue'],
                ['id' => 'navy', 'label' => 'ネイビー', 'hex' => '#1a237e', 'class' => 'lp-theme-palette lp-theme-palette--navy'],
                ['id' => 'teal', 'label' => 'ティール', 'hex' => '#00796b', 'class' => 'lp-theme-palette lp-theme-palette--teal'],
                ['id' => 'green', 'label' => 'グリーン', 'hex' => '#2e7d32', 'class' => 'lp-theme-palette lp-theme-palette--green'],
                ['id' => 'orange', 'label' => 'オレンジ', 'hex' => '#e65100', 'class' => 'lp-theme-palette lp-theme-palette--orange'],
                ['id' => 'red', 'label' => 'レッド', 'hex' => '#c62828', 'class' => 'lp-theme-palette lp-theme-palette--red'],
                ['id' => 'rose', 'label' => 'ローズ', 'hex' => '#ad1457', 'class' => 'lp-theme-palette lp-theme-palette--rose'],
                ['id' => 'purple', 'label' => 'パープル', 'hex' => '#6a1b9a', 'class' => 'lp-theme-palette lp-theme-palette--purple'],
                ['id' => 'charcoal', 'label' => 'チャコール', 'hex' => '#37474f', 'class' => 'lp-theme-palette lp-theme-palette--charcoal'],
                ['id' => 'black', 'label' => 'ブラック', 'hex' => '#212121', 'class' => 'lp-theme-palette lp-theme-palette--black'],
            ],
            'button_plate'   => [
                'shapes'   => [
                    ['id' => 'rounded', 'label' => '角丸矩形', 'class' => 'lp-plate-shape lp-plate-shape--rounded'],
                    ['id' => 'pill', 'label' => 'カプセル', 'class' => 'lp-plate-shape lp-plate-shape--pill'],
                    ['id' => 'rect', 'label' => '直角', 'class' => 'lp-plate-shape lp-plate-shape--rect'],
                ],
                'styles'   => [
                    ['id' => 'flat', 'label' => 'フラット', 'class' => 'lp-plate-style lp-plate-style--flat'],
                    ['id' => 'gradient_3d', 'label' => '3D（縦グラデ＋ハイライト）', 'class' => 'lp-plate-style lp-plate-style--gradient-3d'],
                    ['id' => 'soft_flat', 'label' => 'ソフト立体（フラット＋光沢）', 'class' => 'lp-plate-style lp-plate-style--soft-flat'],
                    ['id' => 'outline', 'label' => 'アウトライン', 'class' => 'lp-plate-style lp-plate-style--outline'],
                ],
                'defaults' => [
                    'shape'        => 'rounded',
                    'style'        => 'flat',
                    'color'        => '#0b57d0',
                    'inner_color'  => '#ffffff',
                    'radius_pct'   => 0.18,
                    'stroke_width' => 2,
                ],
            ],
            'ui'             => [
                'accent' => '#0b57d0',
                'bg'     => '#f4f6f8',
                'card'   => '#ffffff',
                'border' => '#1a1a1a',
                'muted'  => '#555555',
                'text'   => '#1a1a1a',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = self::deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}
