<?php

declare(strict_types=1);

/** getenv の値を .env 由来の改行・前後空白を除いて解釈する */
function composite_getenv_trimmed(string $key): string
{
    $v = getenv($key);
    if (!is_string($v)) {
        return '';
    }
    $v = trim($v, " \t\n\r\0\x0B");
    $v = str_replace("\r", '', $v);

    return $v;
}

/**
 * フォント解決失敗時の短文ヒント（シェルでは find できるが PHP では不可＝open_basedir 等）。
 */
function composite_font_missing_hint(): string
{
    $parts = [];
    $ob = ini_get('open_basedir');
    if (is_string($ob) && $ob !== '') {
        $parts[] = 'Web 用 PHP に open_basedir があります。シェルで /usr/share/fonts が見えても、この PHP からは読めないことがあります。';
        $parts[] = '対策: lp_reverse_cms/fonts/ に NotoSansCJK-Regular.ttc / NotoSansCJK-Bold.ttc をコピーし、.env でその絶対パスを IMAGE_COMPOSITE_FONT / IMAGE_COMPOSITE_FONT_BOLD に書く（または open_basedir に /usr/share/fonts を追加）。';
    }
    $er = composite_getenv_trimmed('IMAGE_COMPOSITE_FONT');
    if ($er !== '' && !is_readable($er)) {
        $parts[] = 'IMAGE_COMPOSITE_FONT は設定されていますが読み取れません（パス誤り・末尾改行・権限）。';
    }
    $eb = composite_getenv_trimmed('IMAGE_COMPOSITE_FONT_BOLD');
    if ($eb !== '' && !is_readable($eb)) {
        $parts[] = 'IMAGE_COMPOSITE_FONT_BOLD も読み取れません。';
    }
    if ($parts === []) {
        return 'fonts-noto-cjk の有無、または lp_reverse_cms/fonts/ への配置と .env の IMAGE_COMPOSITE_FONT* を確認してください（.env.example）。';
    }

    return implode(' ', $parts);
}

/** @return list<string> */
function composite_font_local_bundled_paths(bool $bold): array
{
    $root = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fonts');
    if ($root === false || !is_dir($root)) {
        return [];
    }
    $names = $bold
        ? [
            'NotoSansCJK-Bold.ttc',
            'NotoSansCJKjp-Bold.otf',
            'NotoSansJP-Bold.otf',
            'NotoSansCJK-Regular.ttc',
            'NotoSansCJKjp-Regular.otf',
            'NotoSansJP-Regular.otf',
        ]
        : [
            'NotoSansCJK-Regular.ttc',
            'NotoSansCJKjp-Regular.otf',
            'NotoSansJP-Regular.otf',
        ];
    $out = [];
    foreach ($names as $name) {
        $out[] = $root . DIRECTORY_SEPARATOR . $name;
    }

    return $out;
}

/**
 * fontconfig が使える環境では :lang=ja の最初の読み取り可能なパスを返す。
 */
function composite_font_from_fontconfig(bool $bold): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    $df = ini_get('disable_functions');
    if (is_string($df) && str_contains($df, 'shell_exec')) {
        return '';
    }
    $out = @shell_exec("fc-list -f '%{file}\n' ':lang=ja' 2>/dev/null");
    if (!is_string($out) || trim($out) === '') {
        return '';
    }
    $paths = array_values(array_filter(array_map('trim', explode("\n", $out))));
    if ($paths === []) {
        return '';
    }
    if ($bold) {
        foreach ($paths as $p) {
            if (!is_readable($p)) {
                continue;
            }
            if (preg_match('/Bold|bold/', $p) === 1) {
                return $p;
            }
        }
    }
    foreach ($paths as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }

    return '';
}

/** ディスク上のフォントファイル（.ttc / .otf）。GD 用は composite_expand_font_for_gd で :index を付与。 */
function composite_resolve_font_file(bool $bold): string
{
    if ($bold) {
        $b = composite_getenv_trimmed('IMAGE_COMPOSITE_FONT_BOLD');
        if ($b !== '' && is_readable($b)) {
            return $b;
        }
    }
    $r = composite_getenv_trimmed('IMAGE_COMPOSITE_FONT');
    if ($r !== '' && is_readable($r)) {
        return $r;
    }

    foreach (composite_font_local_bundled_paths($bold) as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }

    $candidates = $bold
        ? [
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKjp-Bold.otf',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJKjp-Bold.otf',
            '/usr/share/fonts/opentype/noto/NotoSansJP-Bold.otf',
            '/usr/share/fonts/truetype/noto/NotoSansJP-Bold.otf',
            '/usr/share/fonts/opentype/noto/NotoSerifCJK-Bold.ttc',
            '/usr/share/fonts/truetype/noto/NotoSerifCJK-Bold.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKjp-Regular.otf',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJKjp-Regular.otf',
            '/usr/share/fonts/opentype/noto/NotoSansJP-Regular.otf',
        ]
        : [
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKjp-Regular.otf',
            '/usr/share/fonts/google-noto-cjk/NotoSansCJKjp-Regular.otf',
            '/usr/share/fonts/opentype/noto/NotoSansJP-Regular.otf',
            '/usr/share/fonts/truetype/noto/NotoSansJP-Regular.otf',
            '/usr/share/fonts/opentype/noto/NotoSerifCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSerifCJK-Regular.ttc',
        ];

    foreach ($candidates as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }

    $fc = composite_font_from_fontconfig($bold);
    if ($fc !== '') {
        return $fc;
    }

    return '';
}

/**
 * Windows 等でバックスラッシュ＋「C:」を含むパスに :index を付けると GD が誤解することがあるため / に揃える。
 */
function composite_normalize_font_path_for_gd(string $path): string
{
    if ($path === '') {
        return '';
    }
    $rp = realpath($path);
    if ($rp !== false) {
        $path = $rp;
    }

    return str_replace('\\', '/', $path);
}

/**
 * GD の imagettftext は .ttc では index が必要なことがある（fontpath:index）。
 * NotoSansCJK の JP face は環境により index が大きい。index 未指定の .ttc は環境によっては動く。
 */
function composite_expand_font_for_gd(string $path): string
{
    if ($path === '' || !is_readable($path)) {
        return '';
    }
    $lower = strtolower($path);
    if (!str_ends_with($lower, '.ttc')) {
        return composite_normalize_font_path_for_gd($path);
    }
    $pathNorm = composite_normalize_font_path_for_gd($path);
    if ($pathNorm === '') {
        return '';
    }
    foreach (['あ', '国', '無'] as $ch) {
        if (@imagettfbbox(12, 0, $pathNorm, $ch) !== false) {
            return $pathNorm;
        }
        for ($i = 0; $i < 48; $i++) {
            $arg = $pathNorm . ':' . $i;
            if (@imagettfbbox(12, 0, $arg, $ch) !== false) {
                return $arg;
            }
        }
    }

    return '';
}
