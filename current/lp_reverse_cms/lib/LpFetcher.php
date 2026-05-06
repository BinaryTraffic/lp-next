<?php

declare(strict_types=1);

class LpFetcher
{
    private array $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: ja,en-US;q=0.9,en;q=0.8',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Cache-Control: no-cache',
    ];

    /**
     * Fetch HTML from the given URL via cURL.
     *
     * @return array{html: string, final_url: string, http_code: int}
     * @throws RuntimeException on cURL or HTTP error
     */
    public function fetch(string $url): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'lp_cookie_');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 8,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $this->defaultHeaders,
            CURLOPT_ENCODING       => '',       // accept all encodings; cURL decompresses automatically
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_AUTOREFERER    => true,
        ]);

        $html  = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        @unlink($cookieFile);

        if ($html === false) {
            throw new RuntimeException("cURLエラー: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("HTTPエラー {$httpCode}: {$url}");
        }

        // Ensure UTF-8 encoding
        $html = $this->ensureUtf8($html);

        return [
            'html'      => $html,
            'final_url' => $finalUrl,
            'http_code' => $httpCode,
        ];
    }

    /**
     * HEAD でリダイレクトを追い、最終 URL を取得する（リンク内外判定用）。
     *
     * @return array{curl_ok: bool, http_code: int, final_url: string}
     */
    public function resolveEffectiveUrlWithRedirects(string $url): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'lp_cookie_head_');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL              => $url,
            CURLOPT_NOBODY           => true,
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_MAXREDIRS        => 10,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 14,
            CURLOPT_CONNECTTIMEOUT   => 8,
            CURLOPT_HTTPHEADER       => $this->defaultHeaders,
            CURLOPT_ENCODING         => '',
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_COOKIEJAR        => $cookieFile,
            CURLOPT_COOKIEFILE       => $cookieFile,
            CURLOPT_AUTOREFERER      => true,
        ]);

        curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        @unlink($cookieFile);

        if ($errno !== 0 || $finalUrl === '') {
            return ['curl_ok' => false, 'http_code' => $httpCode, 'final_url' => $url];
        }

        // HEAD が拒否されるサイト向けに軽量 GET（転送は追う・ボディは捨てる）
        if ($httpCode === 405 || $httpCode === 403 || $httpCode === 501) {
            return $this->resolveEffectiveUrlWithRedirectsByGet($url);
        }

        if ($httpCode >= 400) {
            return ['curl_ok' => false, 'http_code' => $httpCode, 'final_url' => $finalUrl !== '' ? $finalUrl : $url];
        }

        return ['curl_ok' => true, 'http_code' => $httpCode, 'final_url' => $finalUrl];
    }

    /**
     * @return array{curl_ok: bool, http_code: int, final_url: string}
     */
    private function resolveEffectiveUrlWithRedirectsByGet(string $url): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'lp_cookie_get_eff_');

        $ch = curl_init();
        $received = 0;
        $cap      = 65536;
        curl_setopt_array($ch, [
            CURLOPT_URL              => $url,
            CURLOPT_HTTPGET          => true,
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_MAXREDIRS        => 10,
            CURLOPT_RETURNTRANSFER   => false,
            CURLOPT_TIMEOUT          => 18,
            CURLOPT_CONNECTTIMEOUT   => 8,
            CURLOPT_HTTPHEADER       => $this->defaultHeaders,
            CURLOPT_ENCODING         => '',
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_COOKIEJAR        => $cookieFile,
            CURLOPT_COOKIEFILE       => $cookieFile,
            CURLOPT_AUTOREFERER      => true,
            CURLOPT_WRITEFUNCTION    => static function ($ch, string $data) use (&$received, $cap): int {
                $received += strlen($data);

                return $received > $cap ? 0 : strlen($data);
            },
        ]);

        curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        @unlink($cookieFile);

        if ($finalUrl === '') {
            return ['curl_ok' => false, 'http_code' => $httpCode, 'final_url' => $url];
        }

        $abortOk = in_array($errno, [
            defined('CURLE_WRITE_ERROR') ? CURLE_WRITE_ERROR : 23,
            defined('CURLE_ABORTED_BY_CALLBACK') ? CURLE_ABORTED_BY_CALLBACK : 42,
        ], true);

        return [
            'curl_ok'    => $httpCode < 400 && ($errno === 0 || $abortOk),
            'http_code'  => $httpCode,
            'final_url'  => $finalUrl,
        ];
    }

    /**
     * Detect and convert HTML encoding to UTF-8.
     */
    private function ensureUtf8(string $html): string
    {
        // Try to detect charset from meta tag
        if (preg_match('/<meta[^>]+charset=["\']?([a-zA-Z0-9_\-]+)/i', $html, $m)) {
            $charset = strtoupper(trim($m[1]));
            if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
                $converted = mb_convert_encoding($html, 'UTF-8', $charset);
                if ($converted !== false) {
                    return $converted;
                }
            }
        }

        // Fallback: assume UTF-8 or convert from detected encoding
        $detected = mb_detect_encoding($html, ['UTF-8', 'Shift_JIS', 'EUC-JP', 'ISO-8859-1'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = mb_convert_encoding($html, 'UTF-8', $detected);
            return $converted !== false ? $converted : $html;
        }

        return $html;
    }
}
