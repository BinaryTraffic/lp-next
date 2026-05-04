<?php

declare(strict_types=1);

/**
 * Apache の open_basedir が /home/lp-next:/tmp のとき、PHP 既定の session.save_path
 * （例: /var/lib/php/sessions）に書けず、OAuth の oauth_state が失い「アカウント選択の次に進まない」
 * 症状になる。書き込み可能なパス（通常は /tmp）へ寄せてから session_start する。
 */
function lp_reverse_is_dir_writable(string $dir): bool
{
    if ($dir === '') {
        return false;
    }

    return @is_dir($dir) && @is_writable($dir);
}

function lp_reverse_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $path = (string) ini_get('session.save_path');

    if ($path === '' || !lp_reverse_is_dir_writable($path)) {
        $tmp = sys_get_temp_dir();
        if ($tmp !== '' && lp_reverse_is_dir_writable($tmp)) {
            ini_set('session.save_path', $tmp);
        }
    }

    session_start();
}
