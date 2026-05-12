<?php

declare(strict_types=1);

/**
 * 国税庁 法人番号システム Web-API（Ver.4 想定・法人名検索）。
 *
 * - HOUJIN_BANGOU_APP_ID … アプリID（未設定時は検索しない）
 * - HOUJIN_BANGOU_API_BASE … 省略時 https://api.houjin-bangou.nta.go.jp/4
 *
 * 本連携は **必須機能ではない**。.env に鍵を置かなければ UI は AI ヒントのみ（または手入力）で動く。
 * クライアントが反社チェック等の法人確認まわりで別契約・別基盤を持つ場合、**本 CMS にどこまで盛り込むかはクライアントの方針次第**（アプリIDの扱い・本番での有効化範囲を個別に決める想定）。
 *
 * レスポンスは JSON または XML（type=12 は環境により異なるため両対応）。
 *
 * @return array{
 *   configured: bool,
 *   error: ?string,
 *   matches: list<array{
 *     corporate_number: string,
 *     name: string,
 *     prefecture: string,
 *     city: string,
 *     street: string,
 *     kind: string
 *   }>,
 *   http_code?: int,
 *   body_sample?: string
 * }
 */
function lp_reverse_houjin_search_by_name(string $name): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 150) {
        return ['configured' => false, 'error' => null, 'matches' => []];
    }

    $appId = trim((string) (getenv('HOUJIN_BANGOU_APP_ID') ?: ''));
    $base  = rtrim(trim((string) (getenv('HOUJIN_BANGOU_API_BASE') ?: 'https://api.houjin-bangou.nta.go.jp/4')), '/');

    if ($appId === '') {
        return ['configured' => false, 'error' => null, 'matches' => []];
    }

    $url = $base . '/name?id=' . rawurlencode($appId)
        . '&name=' . rawurlencode($name)
        . '&mode=2&type=12';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['Accept: application/json, application/xml, text/xml, */*'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [
            'configured' => true,
            'error'      => 'network',
            'matches'    => [],
            'http_code'  => $code,
            'body_sample'=> $cerr,
        ];
    }

    if ($code !== 200) {
        return [
            'configured' => true,
            'error'      => 'http',
            'matches'    => [],
            'http_code'  => $code,
            'body_sample'=> mb_substr((string) $body, 0, 240),
        ];
    }

    $matches = lp_reverse_houjin_parse_name_response((string) $body);

    return ['configured' => true, 'error' => null, 'matches' => $matches];
}

/**
 * @param mixed $corp
 * @return list<array{corporate_number: string, name: string, prefecture: string, city: string, street: string, kind: string}>
 */
function lp_reverse_houjin_normalize_corporation_list(mixed $corp): array
{
    if ($corp === null) {
        return [];
    }
    if (is_array($corp) && isset($corp['corporateNumber'])) {
        $corp = [$corp];
    }
    if (!is_array($corp)) {
        return [];
    }

    $out = [];
    foreach ($corp as $row) {
        if (!is_array($row)) {
            continue;
        }
        $num = preg_replace('/\D/', '', (string) ($row['corporateNumber'] ?? ''));
        if (strlen($num) !== 13) {
            continue;
        }
        $out[] = [
            'corporate_number' => $num,
            'name'             => trim((string) ($row['name'] ?? '')),
            'prefecture'       => trim((string) ($row['prefectureName'] ?? $row['prefecture'] ?? '')),
            'city'             => trim((string) ($row['cityName'] ?? $row['city'] ?? '')),
            'street'           => trim((string) ($row['streetNumber'] ?? $row['street'] ?? '')),
            'kind'             => trim((string) ($row['kind'] ?? $row['kindName'] ?? '')),
        ];
        if (count($out) >= 30) {
            break;
        }
    }

    return $out;
}

function lp_reverse_houjin_parse_name_response(string $body): array
{
    $body = trim($body);
    if ($body === '') {
        return [];
    }

    $j = json_decode($body, true);
    if (is_array($j)) {
        $raw = $j['corporation'] ?? $j['corporations'] ?? $j['data'] ?? null;
        if (is_array($raw)) {
            return lp_reverse_houjin_normalize_corporation_list($raw);
        }
    }

    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($body);
    if ($sx !== false) {
        $rows = [];
        foreach ($sx->xpath('//corporation') ?: [] as $node) {
            $row = lp_reverse_houjin_xml_corp_to_row($node);
            if ($row !== null) {
                $rows[] = $row;
            }
            if (count($rows) >= 30) {
                break;
            }
        }
        libxml_clear_errors();

        return $rows;
    }
    libxml_clear_errors();

    return [];
}

/** @param \SimpleXMLElement $node */
function lp_reverse_houjin_xml_corp_to_row(\SimpleXMLElement $node): ?array
{
    $g = static function (\SimpleXMLElement $n, string $tag): string {
        $c = $n->{$tag} ?? null;

        return $c !== null ? trim((string) $c) : '';
    };

    $num = preg_replace('/\D/', '', $g($node, 'corporateNumber'));
    if (strlen($num) !== 13) {
        return null;
    }

    return [
        'corporate_number' => $num,
        'name'             => $g($node, 'name'),
        'prefecture'       => $g($node, 'prefectureName'),
        'city'             => $g($node, 'cityName'),
        'street'           => $g($node, 'streetNumber'),
        'kind'             => $g($node, 'kind'),
    ];
}

/**
 * 都道府県・市区町村の入力で API 結果を絞り込む（部分一致）。
 * 絞り込みで 0 件になる場合は呼び出し側で生結果を残すこと。
 *
 * @param list<array<string, string>> $matches
 * @return list<array<string, string>>
 */
function lp_reverse_houjin_narrow_matches_by_address(array $matches, string $pref, string $city): array
{
    $pref = trim($pref);
    $city = trim($city);
    if ($pref === '' && $city === '') {
        return $matches;
    }

    $out = [];
    foreach ($matches as $m) {
        if (!is_array($m)) {
            continue;
        }
        $ok = true;
        if ($pref !== '') {
            $pn = (string) ($m['prefecture'] ?? '');
            if ($pn === '' || mb_strpos($pn, $pref) === false) {
                $ok = false;
            }
        }
        if ($ok && $city !== '') {
            $cn = (string) ($m['city'] ?? '');
            if ($cn === '' || mb_strpos($cn, $city) === false) {
                $ok = false;
            }
        }
        if ($ok) {
            $out[] = $m;
        }
    }

    return $out;
}
