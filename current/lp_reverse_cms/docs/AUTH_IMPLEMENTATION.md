# Site Reverse CMS — Google OAuth2 認証実装指示書

## 前提・環境

- URL: `https://lp-next.jitan.app/current/lp_reverse_cms/`
- DocumentRoot: `/home/lp-next`
- open_basedir: `/home/lp-next:/tmp`
- PHP only（Composer・DB 不要）
- `.env` 設置済み: `/home/lp-next/current/lp_reverse_cms/.env`

## 認証フロー

```
アクセス
  ↓
index.php / preview.php がセッション確認
  ↓ 未認証
Google OAuth2 へリダイレクト（Google 側で 2 段階認証）
  ↓
store/auth_callback.php でトークン検証・メール取得
  ↓
UserRegistry で状態確認
  ├─ super_admin  → CMS フルアクセス（ユーザー管理タブ表示）
  ├─ approved / admin   → CMS フルアクセス（プレビューユーザー管理のみ）
  ├─ approved / preview → preview.php のみ
  ├─ pending      → 申請中画面（管理者承認待ち）
  ├─ rejected     → 拒否画面
  └─ 未登録        → pending として自動登録 → 申請中画面
```

---

## ロール定義

| ロール | 対象 | 権限 |
|--------|------|------|
| `super_admin` | `.env` の `CMS_SUPER_ADMIN` 固定 | 全操作 + 管理者作成・削除 |
| `admin` | super_admin が任命 | 全操作 + プレビューユーザー管理のみ |
| `preview` | admin 以上が任命 | preview.php 閲覧のみ |

---

## 作成・修正するファイル一覧

| ファイル | 種別 | 内容 |
|----------|------|------|
| `lib/GoogleAuth.php` | 新規 | OAuth2 フロー（Composer 不要） |
| `lib/UserRegistry.php` | 新規 | users.json 読み書き・ロール判定 |
| `store/auth_callback.php` | 新規 | Google コールバック処理 |
| `store/auth_logout.php` | 新規 | ログアウト |
| `store/user_approve.php` | 新規 | 承認・拒否 API |
| `store/user_manage.php` | 新規 | ロール変更・削除 API |
| `index.php` | 修正 | 認証チェック追加・ユーザー管理タブ追加 |
| `preview.php` | 修正 | 認証チェック追加 |

---

## データファイル

`data/auth_users.json`（自動生成・手動編集不要）

```json
{
  "users": [
    {
      "email": "other@binarytraffic.jp",
      "role": "admin",
      "status": "approved",
      "requested_at": "2026-05-04T10:00:00Z",
      "approved_at": "2026-05-04T10:05:00Z",
      "approved_by": "shimizu@binarytraffic.jp"
    },
    {
      "email": "client@gmail.com",
      "role": "preview",
      "status": "pending",
      "requested_at": "2026-05-04T11:00:00Z"
    }
  ]
}
```

`status` の値: `pending` / `approved` / `rejected`  
`role` の値: `admin` / `preview`（super_admin は .env 固定のため不要）

---

## lib/GoogleAuth.php

Composer 不要の OAuth2 実装。セッションを使用。

```php
<?php
declare(strict_types=1);

class GoogleAuth
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        // env_load.php が既にロード済みの前提
        $this->clientId     = (string) getenv('GOOGLE_CLIENT_ID');
        $this->clientSecret = (string) getenv('GOOGLE_CLIENT_SECRET');
        $this->redirectUri  = (string) getenv('GOOGLE_REDIRECT_URI');
    }

    // ログイン URL を生成して Location リダイレクト
    public function redirectToGoogle(): void
    {
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

    // コールバックで code をトークンに交換し、メール・名前を返す
    // 失敗時は例外をスロー
    public function handleCallback(string $code, string $state): array
    {
        if (empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
            throw new RuntimeException('不正なリクエストです（state 不一致）');
        }
        unset($_SESSION['oauth_state']);

        // code → access_token 交換
        $tokenRes = $this->post('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($tokenRes['access_token'])) {
            throw new RuntimeException('トークン取得失敗');
        }

        // ユーザー情報取得
        $userInfo = $this->get(
            'https://www.googleapis.com/oauth2/v3/userinfo',
            $tokenRes['access_token']
        );

        if (empty($userInfo['email'])) {
            throw new RuntimeException('メールアドレス取得失敗');
        }

        return [
            'email' => $userInfo['email'],
            'name'  => $userInfo['name'] ?? $userInfo['email'],
        ];
    }

    private function post(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return (array) (json_decode($body, true) ?? []);
    }

    private function get(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return (array) (json_decode($body, true) ?? []);
    }
}
```

---

## lib/UserRegistry.php

```php
<?php
declare(strict_types=1);

class UserRegistry
{
    private string $filePath;
    private string $superAdmin;

    public function __construct(string $dataDir)
    {
        $this->filePath   = rtrim($dataDir, '/') . '/auth_users.json';
        $this->superAdmin = (string) getenv('CMS_SUPER_ADMIN');
    }

    // ロール取得: 'super_admin' | 'admin' | 'preview' | 'pending' | 'rejected' | null（未登録）
    public function getRole(string $email): ?string
    {
        if ($this->superAdmin !== '' && $email === $this->superAdmin) {
            return 'super_admin';
        }
        $user = $this->findUser($email);
        if ($user === null) {
            return null;
        }
        if ($user['status'] === 'pending') {
            return 'pending';
        }
        if ($user['status'] === 'rejected') {
            return 'rejected';
        }
        return $user['role']; // 'admin' | 'preview'
    }

    // 未登録ユーザーを pending として登録
    public function registerPending(string $email, string $name): void
    {
        if ($this->findUser($email) !== null) {
            return;
        }
        $data = $this->load();
        $data['users'][] = [
            'email'        => $email,
            'name'         => $name,
            'role'         => null,
            'status'       => 'pending',
            'requested_at' => date('c'),
        ];
        $this->save($data);
    }

    // 承認
    public function approve(string $email, string $role, string $approvedBy): bool
    {
        $data = $this->load();
        foreach ($data['users'] as &$u) {
            if ($u['email'] === $email) {
                $u['role']        = $role;
                $u['status']      = 'approved';
                $u['approved_at'] = date('c');
                $u['approved_by'] = $approvedBy;
                $this->save($data);
                return true;
            }
        }
        return false;
    }

    // 拒否
    public function reject(string $email, string $rejectedBy): bool
    {
        $data = $this->load();
        foreach ($data['users'] as &$u) {
            if ($u['email'] === $email) {
                $u['status']      = 'rejected';
                $u['rejected_by'] = $rejectedBy;
                $u['rejected_at'] = date('c');
                $this->save($data);
                return true;
            }
        }
        return false;
    }

    // 削除
    public function remove(string $email): bool
    {
        $data = $this->load();
        $before = count($data['users']);
        $data['users'] = array_values(
            array_filter($data['users'], fn($u) => $u['email'] !== $email)
        );
        if (count($data['users']) === $before) {
            return false;
        }
        $this->save($data);
        return true;
    }

    // ロール変更
    public function changeRole(string $email, string $newRole): bool
    {
        $data = $this->load();
        foreach ($data['users'] as &$u) {
            if ($u['email'] === $email && $u['status'] === 'approved') {
                $u['role'] = $newRole;
                $this->save($data);
                return true;
            }
        }
        return false;
    }

    // 申請中一覧
    public function getPending(): array
    {
        return array_values(
            array_filter($this->load()['users'], fn($u) => $u['status'] === 'pending')
        );
    }

    // 承認済み一覧
    public function getApproved(): array
    {
        return array_values(
            array_filter($this->load()['users'], fn($u) => $u['status'] === 'approved')
        );
    }

    private function findUser(string $email): ?array
    {
        foreach ($this->load()['users'] as $u) {
            if ($u['email'] === $email) {
                return $u;
            }
        }
        return null;
    }

    private function load(): array
    {
        if (!file_exists($this->filePath)) {
            return ['users' => []];
        }
        $d = json_decode((string) file_get_contents($this->filePath), true);
        return is_array($d) ? $d : ['users' => []];
    }

    private function save(array $data): void
    {
        file_put_contents(
            $this->filePath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}
```

---

## store/auth_callback.php

```php
<?php
declare(strict_types=1);

session_start();

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/env_load.php';
require_once $cmsRoot . '/lib/GoogleAuth.php';
require_once $cmsRoot . '/lib/UserRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

$auth     = new GoogleAuth();
$dataDir  = LpWorkspace::dataDir($cmsRoot);
$registry = new UserRegistry($dataDir);

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error !== '' || $code === '') {
    // ユーザーがキャンセルした等
    header('Location: ../index.php?auth_error=cancelled');
    exit;
}

try {
    $user  = $auth->handleCallback($code, $state);
    $email = $user['email'];
    $name  = $user['name'];
    $role  = $registry->getRole($email);

    if ($role === null) {
        // 未登録 → pending 登録
        $registry->registerPending($email, $name);
        $role = 'pending';
    }

    $_SESSION['auth'] = [
        'email' => $email,
        'name'  => $name,
        'role'  => $role,
    ];

    // ロール別リダイレクト
    if ($role === 'preview') {
        header('Location: ../preview.php');
    } elseif ($role === 'pending' || $role === 'rejected') {
        header('Location: ../index.php');
    } else {
        header('Location: ../index.php');
    }
    exit;

} catch (RuntimeException $e) {
    header('Location: ../index.php?auth_error=' . urlencode($e->getMessage()));
    exit;
}
```

---

## store/auth_logout.php

```php
<?php
declare(strict_types=1);

session_start();
$_SESSION = [];
session_destroy();
header('Location: ../index.php');
exit;
```

---

## store/user_approve.php

POST API。管理者以上が呼び出す。

```php
<?php
declare(strict_types=1);

session_start();

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/env_load.php';
require_once $cmsRoot . '/lib/UserRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method Not Allowed']));
}

$sessionRole  = $_SESSION['auth']['role']  ?? '';
$sessionEmail = $_SESSION['auth']['email'] ?? '';

// admin 以上のみ
if (!in_array($sessionRole, ['super_admin', 'admin'], true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

$body   = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
$action = (string) ($body['action'] ?? '');  // 'approve' | 'reject'
$email  = (string) ($body['email']  ?? '');
$role   = (string) ($body['role']   ?? 'preview'); // 'admin' | 'preview'

if ($email === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'email required']));
}

// admin は admin を作れない（super_admin のみ可）
if ($role === 'admin' && $sessionRole !== 'super_admin') {
    http_response_code(403);
    exit(json_encode(['error' => 'admin ロールの付与は super_admin のみ可能です']));
}

$dataDir  = LpWorkspace::dataDir($cmsRoot);
$registry = new UserRegistry($dataDir);

if ($action === 'approve') {
    $ok = $registry->approve($email, $role, $sessionEmail);
} elseif ($action === 'reject') {
    $ok = $registry->reject($email, $sessionEmail);
} else {
    http_response_code(400);
    exit(json_encode(['error' => 'invalid action']));
}

echo json_encode(['ok' => $ok]);
```

---

## store/user_manage.php

POST API。ロール変更・削除。

```php
<?php
declare(strict_types=1);

session_start();

$cmsRoot = dirname(__DIR__);
require_once $cmsRoot . '/lib/env_load.php';
require_once $cmsRoot . '/lib/UserRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method Not Allowed']));
}

$sessionRole  = $_SESSION['auth']['role']  ?? '';
$sessionEmail = $_SESSION['auth']['email'] ?? '';

if (!in_array($sessionRole, ['super_admin', 'admin'], true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

$body    = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
$action  = (string) ($body['action'] ?? '');  // 'change_role' | 'remove'
$email   = (string) ($body['email']  ?? '');
$newRole = (string) ($body['role']   ?? '');

if ($email === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'email required']));
}

// admin への昇格は super_admin のみ
if ($action === 'change_role' && $newRole === 'admin' && $sessionRole !== 'super_admin') {
    http_response_code(403);
    exit(json_encode(['error' => 'admin ロールへの変更は super_admin のみ可能です']));
}

$dataDir  = LpWorkspace::dataDir($cmsRoot);
$registry = new UserRegistry($dataDir);

if ($action === 'change_role') {
    $ok = $registry->changeRole($email, $newRole);
} elseif ($action === 'remove') {
    $ok = $registry->remove($email);
} else {
    http_response_code(400);
    exit(json_encode(['error' => 'invalid action']));
}

echo json_encode(['ok' => $ok]);
```

---

## index.php の修正方針

ファイル冒頭（`<?php` の直後）に以下を追加する：

```php
session_start();

$cmsRoot = __DIR__;
require_once $cmsRoot . '/lib/env_load.php';
require_once $cmsRoot . '/lib/GoogleAuth.php';
require_once $cmsRoot . '/lib/UserRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

$dataDir  = LpWorkspace::dataDir($cmsRoot);
$registry = new UserRegistry($dataDir);

// 認証チェック
$sessionAuth = $_SESSION['auth'] ?? null;
if ($sessionAuth === null) {
    // 未ログイン → Google へリダイレクト
    (new GoogleAuth())->redirectToGoogle();
}

// セッションのロールを最新状態に更新（承認後の再ログイン不要にする）
$currentRole = $registry->getRole($sessionAuth['email']);
$_SESSION['auth']['role'] = $currentRole;
$sessionAuth['role']      = $currentRole;

// preview ユーザーは preview.php へ
if ($currentRole === 'preview') {
    header('Location: preview.php');
    exit;
}
```

**pending / rejected 画面（既存の Step 表示より前に挿入）：**

```php
if ($currentRole === 'pending') {
    // 申請中画面を表示して exit
    // HTML: 「管理者の承認をお待ちください」メッセージ
    // ログアウトリンク: store/auth_logout.php
}
if ($currentRole === 'rejected') {
    // 拒否画面を表示して exit
}
```

**ユーザー管理タブ（admin 以上のみ表示）：**

```php
// $sessionAuth['role'] が 'super_admin' または 'admin' のとき
// 申請一覧テーブル（pending ユーザー一覧・承認/拒否ボタン）
// 承認済みユーザー一覧（ロール変更・削除ボタン）
// super_admin のみ: 管理者ロールの付与が可能
```

---

## preview.php の修正方針

ファイル冒頭に以下を追加する：

```php
session_start();

$cmsRoot = __DIR__;
require_once $cmsRoot . '/lib/env_load.php';
require_once $cmsRoot . '/lib/UserRegistry.php';
require_once $cmsRoot . '/lib/LpWorkspace.php';

$sessionAuth = $_SESSION['auth'] ?? null;
if ($sessionAuth === null) {
    require_once $cmsRoot . '/lib/GoogleAuth.php';
    (new GoogleAuth())->redirectToGoogle();
}

$dataDir  = LpWorkspace::dataDir($cmsRoot);
$registry = new UserRegistry($dataDir);
$role     = $registry->getRole($sessionAuth['email']);

// preview 以上（admin・super_admin も含む）はアクセス可
if (!in_array($role, ['preview', 'admin', 'super_admin'], true)) {
    header('Location: index.php');
    exit;
}
```

---

## セキュリティ注意事項

- `data/auth_users.json` は既存の `.htaccess` による `data/` 保護範囲内に収まる
- `store/user_approve.php` / `store/user_manage.php` はセッションロールで二重チェック済み
- CSRF 対策: 申請承認・拒否の POST は `fetch()` + JSON body で行い、フォーム直 submit は使わない
- `session_start()` は各ファイル冒頭で必ず呼ぶ（セッション固定攻撃防止のため `session_regenerate_id(true)` をログイン直後に呼ぶこと）

ログイン成功直後（`auth_callback.php` の `$_SESSION['auth'] =` の直前）に追加：

```php
session_regenerate_id(true);
```
