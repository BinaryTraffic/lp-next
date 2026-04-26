<?php

declare(strict_types=1);

/**
 * Claude Vision の icons[].label から CMS 内 SVG パスを解決する。
 * 値は lp_reverse_cms からの相対（先頭 /assets/...）。
 *
 * SNS マークは Wikimedia Commons の各ファイルを assets に配置（各ページの出典・ライセンスに従うこと。トレードマークは各社ガイドラインも参照）。
 * LINE: File:LINE_logo.svg / Instagram: File:Instagram_logo_2022.svg / X: File:X_logo_2023.svg（twitter.svg は合成向けに白 fill）
 * Facebook: File:2023_Facebook_icon.svg / YouTube: File:YouTube_full-color_icon_(2017).svg / TikTok: File:Tiktok_icon.svg
 */
final class IconMap
{
    /** @var array<string, string> */
    public const ICON_MAP = [
        'LINE'      => '/assets/icons/line.svg',
        'phone'     => '/assets/icons/phone.svg',
        'arrow'     => '/assets/icons/arrow-right.svg',
        'check'     => '/assets/icons/check-circle.svg',
        'calendar'  => '/assets/icons/calendar.svg',
        'mail'      => '/assets/icons/mail.svg',
        'map'       => '/assets/icons/map-pin.svg',
        'instagram' => '/assets/icons/instagram.svg',
        'twitter'   => '/assets/icons/twitter.svg',
        'X'         => '/assets/icons/twitter.svg',
        'facebook'  => '/assets/icons/facebook.svg',
        'youtube'   => '/assets/icons/youtube.svg',
        'tiktok'    => '/assets/icons/tiktok.svg',
        'other'     => '/assets/icons/star.svg',
    ];
}

/**
 * @return non-empty-string|null 存在する SVG の絶対パス
 */
function icon_map_resolve_absolute_path(string $cmsRoot, string $label): ?string
{
    $label = trim($label);
    if ($label === '') {
        return null;
    }
    $map = IconMap::ICON_MAP;
    $rel = $map[$label] ?? null;
    if ($rel === null) {
        foreach ($map as $k => $v) {
            if (strcasecmp((string) $k, $label) === 0) {
                $rel = $v;
                break;
            }
        }
    }
    if ($rel === null || !is_string($rel)) {
        $rel = $map['other'] ?? null;
    }
    if ($rel === null || !is_string($rel)) {
        return null;
    }
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $full = $cmsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $resolved = realpath($full);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        return null;
    }
    $assetsRoot = realpath($cmsRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icons');
    if ($assetsRoot === false || !str_starts_with($resolved, $assetsRoot)) {
        return null;
    }
    if (!str_ends_with(strtolower($resolved), '.svg')) {
        return null;
    }

    return $resolved;
}
