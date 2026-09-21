<?php
/**
 * 純粋なPHPで動作するセルフコンテインド WebPush (RFC 8291 / RFC 8292 / VAPID) 送信エンジン
 * 外部ライブラリ (Composer) 不要で、標準の openssl / curl 拡張のみで動作します。
 */

if (!defined('WEBPUSH_LOADED')) {
    define('WEBPUSH_LOADED', true);
}

/**
 * Base64URL エンコード
 */
function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64URL デコード
 */
function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/**
 * VAPID キーペア（公開鍵・秘密鍵）を取得または自動生成
 */
function getOrCreateVapidKeys(): array {
    $keyFile = __DIR__ . '/data/vapid_keys.json';
    if (file_exists($keyFile)) {
        $json = @file_get_contents($keyFile);
        $keys = json_decode($json, true);
        if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
            return $keys;
        }
    }

    // 新規生成 (prime256v1 / NIST P-256 楕円曲線)
    $config = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];
    $res = openssl_pkey_new($config);
    if (!$res) {
        // フォールバック用の固定シード生成またはデフォルト
        writeDebugLog("VAPIDキー新規生成エラー (OpenSSL pkey_new failed)");
        return [
            'publicKey' => '',
            'privateKey' => ''
        ];
    }

    $details = openssl_pkey_get_details($res);
    $d = $details['ec']['d']; // 秘密鍵 (32 bytes)
    $x = $details['ec']['x']; // 公開鍵X座標 (32 bytes)
    $y = $details['ec']['y']; // 公開鍵Y座標 (32 bytes)

    // 非圧縮公開鍵: 0x04 + X + Y (65 bytes)
    $publicKeyBinary = "\x04" . $x . $y;
    $privateKeyBinary = $d;

    $keys = [
        'publicKey' => base64UrlEncode($publicKeyBinary),
        'privateKey' => base64UrlEncode($privateKeyBinary),
        'created_at' => date('Y-m-d H:i:s')
    ];

    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0755, true);
    }
    @file_put_contents($keyFile, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $keys;
}

/**
 * VAPID JWT ヘッダー (Authorization: vapid t=..., k=...) を生成
 */
function createVapidAuthorizationHeader(string $endpoint, array $vapidKeys, string $subject = 'mailto:admin@kureba.co.jp'): array {
    $parsedUrl = parse_url($endpoint);
    $audience = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $payload = [
        'aud' => $audience,
        'exp' => time() + 43200, // 12時間
        'sub' => $subject
    ];

    $jwtHeader = base64UrlEncode(json_encode($header));
    $jwtPayload = base64UrlEncode(json_encode($payload));
    $jwtUnsigned = $jwtHeader . '.' . $jwtPayload;

    $privateKeyDer = base64UrlDecode($vapidKeys['privateKey']);
    $publicKeyDer = base64UrlDecode($vapidKeys['publicKey']);

    // PEM形式の秘密鍵を構築
    $dHex = bin2hex($privateKeyDer);
    $pubHex = bin2hex($publicKeyDer);
    
    // PKCS#8 / SEC1 形式のEC秘密鍵PEM
    $asn1 = "30770201010420{$dHex}a00a06082a8648ce3d030107a144034200{$pubHex}";
    $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode(hex2bin($asn1)), 64, "\n") . "-----END EC PRIVATE KEY-----\n";

    $signature = '';
    $pkey = openssl_pkey_get_private($pem);
    if ($pkey) {
        openssl_sign($jwtUnsigned, $rawDerSig, $pkey, OPENSSL_ALGO_SHA256);
        // ASN.1 DER 署名 (ECDSA) を R || S (64 bytes) に変換
        $signature = convertDerToRawSignature($rawDerSig);
    }

    $jwt = $jwtUnsigned . '.' . base64UrlEncode($signature);

    return [
        'Authorization' => 'vapid t=' . $jwt . ', k=' . $vapidKeys['publicKey'],
        'Crypto-Key' => 'p256ecdsa=' . $vapidKeys['publicKey']
    ];
}

/**
 * OpenSSLのASN.1 DER署名を Raw R+S (64 bytes) に変換
 */
function convertDerToRawSignature(string $der): string {
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) return '';
    $len = ord($der[$pos++]);
    if ($len & 0x80) {
        $pos += ($len & 0x7f);
    }

    // R
    if (ord($der[$pos++]) !== 0x02) return '';
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;

    // S
    if (ord($der[$pos++]) !== 0x02) return '';
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

    return substr($r, -32) . substr($s, -32);
}

/**
 * WebPush ペイロードを RFC 8291 (aes128gcm) で暗号化
 */
function encryptWebPushPayload(string $plaintext, string $userPublicKeyB64, string $userAuthB64): ?array {
    $userPublicKey = base64UrlDecode($userPublicKeyB64);
    $userAuth = base64UrlDecode($userAuthB64);

    if (strlen($userPublicKey) !== 65 || strlen($userAuth) < 16) {
        return null;
    }

    // 1. 送信側ローカル鍵ペア生成 (NIST P-256)
    $localKeyRes = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$localKeyRes) return null;
    $details = openssl_pkey_get_details($localKeyRes);
    $localPublicKey = "\x04" . $details['ec']['x'] . $details['ec']['y'];

    // 2. ECDH 共有秘密 (Shared Secret) の計算
    $userPubHex = bin2hex($userPublicKey);
    $userPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin("3059301306072a8648ce3d020106082a8648ce3d030107034200{$userPubHex}")), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $userKeyResource = openssl_pkey_get_public($userPem);
    if (!$userKeyResource) return null;

    $sharedSecret = openssl_pkey_derive($userKeyResource, $localKeyRes, 32);
    if (!$sharedSecret) return null;

    // 3. Salt (16 bytes) 生成
    $salt = random_bytes(16);

    // 4. HKDF による Key Derivation (RFC 8291 aes128gcm)
    $keyInfo = "WebPush: info\x00" . $userPublicKey . $localPublicKey;
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $userAuth);

    $contentEncryptionKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    // 5. パディング付与 (終端 0x02)
    $paddedData = $plaintext . "\x02";

    // 6. AES-128-GCM 暗号化
    $tag = '';
    $ciphertext = openssl_encrypt($paddedData, 'aes-128-gcm', $contentEncryptionKey, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) return null;

    // 7. aes128gcm ヘッダー作成 (RFC 8291)
    // Salt (16) + Record Size (4: 4096 = 0x00001000) + KeyID Length (1: 65) + Local Public Key (65) + Ciphertext + Tag (16)
    $rs = pack('N', 4096);
    $header = $salt . $rs . chr(strlen($localPublicKey)) . $localPublicKey;
    $body = $header . $ciphertext . $tag;

    return [
        'body' => $body,
        'content_encoding' => 'aes128gcm'
    ];
}

/**
 * 登録されたすべての管理端末へ WebPush 通知を配信
 *
 * @param array $payload ['title' => '...', 'body' => '...', 'icon' => '...', 'data' => [...]]
 * @param PDO|null $db
 * @param string $account
 * @return array ['sent' => int, 'failed' => int, 'errors' => []]
 */
function sendWebPushNotification(array $payload, ?PDO $db = null, string $account = 'senior'): array {
    if ($db === null) {
        try { $db = getDbConnection($account); } catch (Exception $e) { return ['sent' => 0, 'failed' => 0, 'error' => 'DB接続不可']; }
    }

    // テーブル存在チェック・自動作成
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account TEXT NOT NULL DEFAULT 'senior',
                endpoint TEXT NOT NULL UNIQUE,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                user_agent TEXT,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME
            );
            CREATE INDEX IF NOT EXISTS idx_push_acc ON push_subscriptions (account);
        ");
    } catch (Exception $e) {}

    $stmt = $db->prepare("SELECT * FROM push_subscriptions WHERE account = :acc OR account = 'all' OR account = ''");
    $stmt->execute([':acc' => $account]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subscriptions)) {
        return ['sent' => 0, 'failed' => 0, 'message' => '登録されたプッシュ通知端末がありません'];
    }

    $vapidKeys = getOrCreateVapidKeys();
    if (empty($vapidKeys['publicKey']) || empty($vapidKeys['privateKey'])) {
        return ['sent' => 0, 'failed' => 0, 'error' => 'VAPIDキーが生成されていません'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
    $expiredIds = [];

    foreach ($subscriptions as $sub) {
        $endpoint = $sub['endpoint'];
        $p256dh = $sub['p256dh'];
        $auth = $sub['auth'];

        $vapidHeaders = createVapidAuthorizationHeader($endpoint, $vapidKeys);
        $encrypted = encryptWebPushPayload($jsonPayload, $p256dh, $auth);

        $headers = [
            'TTL: 86400',
            'Urgency: high',
            'Topic: line-chat-notif',
            'Authorization: ' . $vapidHeaders['Authorization'],
            'Crypto-Key: ' . $vapidHeaders['Crypto-Key']
        ];

        $postData = '';
        if ($encrypted) {
            $headers[] = 'Content-Type: application/octet-stream';
            $headers[] = 'Content-Encoding: ' . $encrypted['content_encoding'];
            $postData = $encrypted['body'];
            $headers[] = 'Content-Length: ' . strlen($postData);
        } else {
            // ペイロードなしのPing通知 (フォールバック)
            $headers[] = 'Content-Length: 0';
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $results['sent']++;
            // 最終利用日時更新
            try {
                $db->prepare("UPDATE push_subscriptions SET last_used_at = :now WHERE id = :id")
                    ->execute([':now' => date('Y-m-d H:i:s'), ':id' => $sub['id']]);
            } catch (Exception $e) {}
        } else {
            $results['failed']++;
            $results['errors'][] = "HTTP {$httpCode}: {$response} {$curlErr}";
            // 404 / 410 (期限切れ・アンサブスクライブ) の購読は削除
            if ($httpCode === 404 || $httpCode === 410) {
                $expiredIds[] = $sub['id'];
            }
        }
    }

    if (!empty($expiredIds)) {
        try {
            $inClause = implode(',', array_map('intval', $expiredIds));
            $db->exec("DELETE FROM push_subscriptions WHERE id IN ({$inClause})");
            writeDebugLog("無効化されたWebPush購読を自動削除", ['ids' => $expiredIds]);
        } catch (Exception $e) {}
    }

    return $results;
}
