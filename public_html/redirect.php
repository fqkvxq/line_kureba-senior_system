<?php
/**
 * 車両詳細クリック トラッキング & Discord通知中継リダイレクター
 * LINE Flex Message や LIFF画面からのWeb遷移を検知し、ユーザー情報を添えてDiscordに通知した上でグーネットへ転送します。
 */

require_once __DIR__ . '/config.php';

$carId = trim($_GET['id'] ?? '');
$userId = trim($_GET['uid'] ?? '');
$userNameFromParam = trim($_GET['uname'] ?? '');
$source = trim($_GET['src'] ?? 'LINE Flex Message');
$fallbackUrl = SHOP_GOO_URL;

if (empty($carId)) {
    header("Location: {$fallbackUrl}");
    exit;
}

try {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $carId]);
    $car = $stmt->fetch();

    if ($car) {
        $targetUrl = !empty($car['detail_url']) ? $car['detail_url'] : $fallbackUrl;
        
        // ユーザープロフィールの取得
        $userProfile = null;
        if (!empty($userId)) {
            $userProfile = getLineUserProfile($userId);
        }
        if (empty($userProfile) && !empty($userNameFromParam)) {
            $userProfile = ['displayName' => $userNameFromParam];
        }

        // Discord 通知は送信せず、ログ記録のみ実行 (通知過多防止)
        // sendDiscordNotification($car, $source, $userProfile, $userId);
        
        writeDebugLog("車両詳細リンククリック検知 (グーネット転送)", [
            'id' => $carId,
            'title' => $car['title'],
            'user' => $userProfile['displayName'] ?? $userId ?? '不明',
            'source' => $source
        ]);

        // グーネットへ即座にリダイレクト
        header("Location: {$targetUrl}");
        exit;
    }
} catch (Exception $e) {
    writeDebugLog("リダイレクト時エラー: " . $e->getMessage());
}

// フォールバック
$targetUrl = "https://www.goo-net.com/usedcar/spread/goo/15/{$carId}.html";
header("Location: {$targetUrl}");
exit;
