<?php

declare(strict_types=1);

/**
 * 編集済みメモ文言を Vision の texts[] に反映する（テキスト焼き込み合成用）。
 *
 * @param array<string, mixed> $vision lp_reverse_normalize_claude_vision_array 済み
 * @return array<string, mixed>
 */
function lp_reverse_apply_memo_to_vision_texts(array $vision, string $memoText): array
{
    $memoText = trim($memoText);
    if ($memoText === '') {
        return $vision;
    }

    $norm = str_replace(["\r\n", "\r"], "\n", $memoText);
    /** @var list<string> $lines */
    $lines = array_values(array_filter(array_map('trim', explode("\n", $norm)), static fn (string $s): bool => $s !== ''));
    if ($lines === []) {
        return $vision;
    }

    if (!isset($vision['texts']) || !is_array($vision['texts']) || $vision['texts'] === []) {
        return $vision;
    }

    /** @var list<array<string, mixed>> $texts */
    $texts = $vision['texts'];
    $n     = count($texts);
    $last  = $lines[count($lines) - 1];

    if ($n === 1) {
        $texts[0]['content'] = implode("\n", $lines);
        if (isset($texts[0]['lines']) && is_array($texts[0]['lines'])) {
            $texts[0]['lines'] = $lines;
        }
    } else {
        for ($i = 0; $i < $n; $i++) {
            $line                = $lines[$i] ?? $last;
            $texts[$i]['content'] = $line;
            if (isset($texts[$i]['lines']) && is_array($texts[$i]['lines'])) {
                $texts[$i]['lines'] = [$line];
            }
        }
    }

    $vision['texts'] = $texts;

    return $vision;
}
