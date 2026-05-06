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
}

