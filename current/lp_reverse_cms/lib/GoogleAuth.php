<?php

declare(strict_types=1);

/**
 * Composer 不使用の Google OAuth2（ログイン）。
 */
final class GoogleAuth
{
    private string $clientId;

    private string $clientSecret;

    private string $redirectUri;

    public function __construct()
    {
        $this->clientId     = self::envStr('GOOGLE_CLIENT_ID');
        $this->clientSecret = self::envStr('GOOGLE_CLIENT_SECRET');
        $this->redirectUri  = self::envStr('GOOGLE_REDIRECT_URI');
    }

    private static function envStr(string $k): string
    {
        $v = getenv($k);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        $e = $_ENV[$k] ?? null;

        return (is_string($e) && trim($e) !== '') ? trim($e) : '';
    }

    /** @throws RuntimeException */
    public function redirectToGoogle(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->redirectUri === '') {
            throw new RuntimeException('Google OAuth が .env で設定されていません（GOOGLE_CLIENT_ID / SECRET / REDIRECT_URI）');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('セッションが開始されていません');
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);

        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
        exit;
    }

    /**
     * @return array{email: string, name: string}
     *
     * @throws RuntimeException
     */
    public function handleCallback(string $code, string $state): array
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('OAuth クライアント設定が無効です');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('セッションが開始されていません');
        }
        if (empty($_SESSION['oauth_state']) || !hash_equals((string) $_SESSION['oauth_state'], $state)) {
            throw new RuntimeException('不正なリクエストです（state 不一致）');
        }
        unset($_SESSION['oauth_state']);

        $tokenRes = $this->postJson(
            'https://oauth2.googleapis.com/token',
            [
                'code'          => $code,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code',
            ],
        );

        if (empty($tokenRes['access_token']) || !is_string($tokenRes['access_token'])) {
            throw new RuntimeException(
                isset($tokenRes['error_description']) && is_string($tokenRes['error_description'])
                  ? ('トークン取得失敗: ' . $tokenRes['error_description'])
                  : 'トークン取得失敗'
            );
        }

        /** @phpstan-ignore-next-line */
        $token = $tokenRes['access_token'];

        $userInfo = $this->getJson(
            'https://www.googleapis.com/oauth2/v3/userinfo',
            $token,
        );

        if (empty($userInfo['email']) || !is_string($userInfo['email'])) {
            throw new RuntimeException('メールアドレス取得失敗');
        }

        $email = $userInfo['email'];
        $name  = (isset($userInfo['name']) && is_string($userInfo['name']) && $userInfo['name'] !== '')
          ? $userInfo['name']
          : $email;

        return ['email' => $email, 'name' => $name];
    }

    /** @return array<string, mixed> */
    private function postJson(string $url, array $data): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function getJson(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
