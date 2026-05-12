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

/** Apache 単体またはリバプロ経由どちらでも HTTPS とみなせるようにする（Secure Cookie 判定用）。 */
function lp_reverse_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') {
        return true;
    }
    $xfp = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($xfp === 'https') {
        return true;
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
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

    // OAuth は Google のトップレベルへ行き、その後当サイトへ GET リダイレクトで戻る。
    // SameSite=Strict だと戻りの GET にセッション Cookie が載らず state 検証が常に落ちて「進まない」ように見える。
    session_name('LP_REVERSE_CMS_SID');

    $secure = lp_reverse_request_is_https();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
