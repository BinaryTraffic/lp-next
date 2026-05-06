<?php

declare(strict_types=1);

final class LpCmsDetector
{
    /**
     * @return array{
     *   detected:string,
     *   confidence:string,
     *   version:?string,
     *   signals:list<string>,
     *   rest_api_base:?string,
     *   has_gutenberg:bool,
     *   theme:?string
     * }
     */
    public static function detect(string $html): array
    {
        $lower = strtolower($html);
        $signals = [];
        $detected = 'unknown';
        $confidence = 'low';
        $restApiBase = null;
        $hasGutenberg = false;
        $theme = null;

        if (preg_match('#<link[^>]+rel=["\']https://api\.w\.org/["\'][^>]*href=["\']([^"\']+)#i', $html, $m)) {
            $signals[] = 'wp-json link header';
            $restApiBase = trim((string) ($m[1] ?? ''));
        }
        if (preg_match('#body[^>]+class=["\'][^"\']*page-id-[0-9]+#i', $html)) {
            $signals[] = 'body.page-id-*';
        }
        if (str_contains($html, '<!-- wp:')) {
            $signals[] = '<!-- wp: block -->';
            $hasGutenberg = true;
        }
        if (str_contains($lower, '/wp-content/')) {
            $signals[] = '/wp-content/';
        }
        if (preg_match('#/wp-content/themes/([a-z0-9_-]+)/#i', $html, $m)) {
            $theme = (string) ($m[1] ?? '');
        }

        if ($signals !== []) {
            $detected = 'wordpress';
            $confidence = count($signals) >= 2 ? 'high' : 'medium';
        } elseif (str_contains($lower, 'cdn.shopify.com') || str_contains($lower, 'shopify.theme')) {
            $detected = 'shopify';
            $confidence = 'medium';
            $signals[] = 'cdn.shopify.com / Shopify.theme';
        } elseif (str_contains($lower, 'static.wixstatic.com') || str_contains($lower, 'wix-bolt')) {
            $detected = 'wix';
            $confidence = 'medium';
            $signals[] = 'static.wixstatic.com / wix-bolt';
        } elseif (str_contains($lower, 'squarespace.com')) {
            $detected = 'squarespace';
            $confidence = 'medium';
            $signals[] = 'squarespace.com';
        }

        return [
            'detected' => $detected,
            'confidence' => $confidence,
            'version' => null,
            'signals' => array_values(array_unique($signals)),
            'rest_api_base' => $restApiBase !== '' ? $restApiBase : null,
            'has_gutenberg' => $hasGutenberg,
            'theme' => $theme !== '' ? $theme : null,
        ];
    }
}

