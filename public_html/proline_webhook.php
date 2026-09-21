<?php
/**
 * プロライン (ProLine) 外部連携 Webhook 受信エンドポイント
 * 
 * プロラインの「登録発生時に外部システムにデータを送信する」機能から送信される
 * フォーム送信（form.send）やイベント予約（event.reserve）データを自動受信し、
 * 受講生カルテの「特記事項・指導メモ」「次回レッスン日」等へ自動反映＆スタッフ通知を行います。
 */

// タイムゾーンとエラー設定 (日本時間 / JST)
date_default_timezone_set('Asia/Tokyo');
ini_set('date.timezone', 'Asia/Tokyo');
putenv('TZ=Asia/Tokyo');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// アカウント判定
$accountKey = $_REQUEST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? null);
if (!empty($accountKey)) {
    setActiveAccountKey($accountKey);
}

$activeKey = getActiveAccountKey();
$db = getDbConnection($activeKey);

// GETリクエスト時: 稼働ステータスと受信ログを表示する情報画面
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $currentUrl = $baseUrl . $_SERVER['SCRIPT_NAME'];
    $accList = getAccountList();
    $logFile = __DIR__ . '/proline_events.log';
    $logContent = file_exists($logFile) ? htmlspecialchars(implode('', array_slice(file($logFile), -30))) : '受信ログはまだありません。プロラインでフォーム送信や予約が発生すると記録されます。';

    echo <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>プロライン 外部送信連携 Webhook 受信ステータス</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #1e293b; background: #f8fafc; margin: 0; padding: 30px 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07); border: 1px solid #e2e8f0; }
        h1 { font-size: 20px; color: #0f172a; margin-top: 0; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; }
        .badge-ok { background: #dcfce7; color: #15803d; padding: 3px 10px; border-radius: 9999px; font-size: 13px; font-weight: bold; }
        .url-box { background: #f1f5f9; padding: 12px 16px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: monospace; font-size: 13.5px; word-break: break-all; margin: 12px 0; }
        .info-card { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 4px; margin: 16px 0; font-size: 13.5px; color: #1e40af; }
        .log-box { background: #0f172a; color: #38bdf8; font-family: monospace; padding: 14px; border-radius: 8px; font-size: 12px; max-height: 350px; overflow-y: auto; white-space: pre-wrap; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 13.5px; }
        th, td { padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f8fafc; color: #475569; }
    </style>
</head>
<body>
    <div class="container">
        <h1><span>🔗 プロライン (ProLine) 外部連携 Webhook 受信口</span> <span class="badge-ok">稼働中 (HTTP 200)</span></h1>
        
        <p>このURLは、プロラインの「<strong>登録発生時に外部システムにデータを送信する</strong>」機能から送信されるフォーム回答やレッスン予約データを受信し、受講生カルテへ自動反映するエンドポイントです。</p>

        <div class="info-card">
            <strong>📋 プロライン側での設定手順:</strong><br>
            プロライン管理画面の「フォーム送信後アクション」または「登録発生時に外部システムにデータを送信する」の送信先URLに、以下のURLを設定してください。
        </div>

        <div style="margin-top: 18px;">
            <strong>現在のアカウント用 Webhook 受信URL:</strong>
            <div class="url-box">{$currentUrl}?account={$activeKey}</div>
        </div>

        <h3>各アカウント別 URL 一覧</h3>
        <table>
            <thead>
                <tr><th>アカウント名</th><th>アカウントID</th><th>プロライン送信先 Webhook URL</th></tr>
            </thead>
            <tbody>
HTML;

    foreach ($accList as $acc) {
        $accUrl = "{$currentUrl}?account=" . urlencode($acc['id']);
        echo "<tr><td><strong>" . htmlspecialchars($acc['name']) . "</strong></td><td><code>" . htmlspecialchars($acc['id']) . "</code></td><td><code style='user-select:all;'>" . htmlspecialchars($accUrl) . "</code></td></tr>";
    }

    echo <<<HTML
            </tbody>
        </table>

        <h3 style="margin-top: 28px;">直近のプロライン受信ログ (最新30件)</h3>
        <div class="log-box">{$logContent}</div>
    </div>
</body>
</html>
HTML;
    exit;
}

// POSTリクエスト処理
$rawInput = file_get_contents('php://input');
$nowJst = date('Y-m-d H:i:s');

// ログ記録ヘルパー
function writeProlineEventLog(string $message, $data = null) {
    global $nowJst;
    $logFile = __DIR__ . '/proline_events.log';
    $line = "[{$nowJst}] " . $message;
    if ($data !== null) {
        $line .= " | " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $line .= "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    @chmod($logFile, 0666);
}

// JSONパースまたはPOSTフォームパース
$payload = json_decode($rawInput, true);
if (!$payload && !empty($_POST)) {
    $payload = $_POST;
    if (isset($payload['user_data']) && is_string($payload['user_data'])) {
        $payload['user_data'] = json_decode($payload['user_data'], true) ?: $payload['user_data'];
    }
    if (isset($payload['form_data']) && is_string($payload['form_data'])) {
        $payload['form_data'] = json_decode($payload['form_data'], true) ?: $payload['form_data'];
    }
}

if (empty($payload)) {
    writeProlineEventLog("⚠️ 空のリクエストまたは無効なデータを受信", $rawInput);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'No payload received']);
    exit;
}

writeProlineEventLog("📩 プロラインWebhook受信", [
    'event' => $payload['event'] ?? 'unknown',
    'form_name' => $payload['form_name'] ?? '',
    'linename' => $payload['user_data']['linename'] ?? ($payload['user_data']['snsname'] ?? ''),
    'uid' => $payload['uid'] ?? ''
]);

// ユーザー情報の抽出
$event = $payload['event'] ?? 'form.send';
$userData = $payload['user_data'] ?? [];
$prolineUid = trim((string)($payload['uid'] ?? ''));
$lineName = trim((string)($userData['linename'] ?? ($userData['snsname'] ?? ($userData['sei'] . ' ' . $userData['mei']))));
$phone = trim((string)($userData['phone'] ?? ''));
$email = trim((string)($userData['email'] ?? ''));
$address = trim((string)($userData['address1'] ?? ($userData['address2'] ?? '')));

if (empty($lineName)) {
    $lineName = !empty($prolineUid) ? "受講生_{$prolineUid}" : '新規受講生';
}

// 受講生カルテの検索（LINE UID、プロラインUID、またはLINE登録名で特定）
$customer = null;
if (!empty($prolineUid)) {
    $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $prolineUid]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$customer && !empty($lineName)) {
    $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_name = :uname ORDER BY id DESC LIMIT 1");
    $stmt->execute([':uname' => $lineName]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// まだ存在しない場合は自動新規登録
if (!$customer) {
    $newUid = !empty($prolineUid) ? $prolineUid : ('proline_' . substr(md5(uniqid('', true)), 0, 16));
    $insStmt = $db->prepare("
        INSERT INTO customer_cars (
            user_id, user_name, picture_url, car_model, car_number,
            memo, last_interaction_at, last_interaction_type, last_interaction_preview,
            created_at, updated_at
        ) VALUES (
            :uid, :uname, '', 'パソコン基本・相談', '',
            '', :now1, 'proline_event', 'プロライン連携登録',
            :now2, :now3
        )
    ");
    $insStmt->execute([
        ':uid' => $newUid,
        ':uname' => $lineName,
        ':now1' => $nowJst,
        ':now2' => $nowJst,
        ':now3' => $nowJst
    ]);
    $customerId = $db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM customer_cars WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    writeProlineEventLog("✨ 新規受講生を自動作成", ['id' => $customerId, 'name' => $lineName]);
}

$custId = $customer['id'];
$currentMemo = (string)($customer['memo'] ?? '');
$updateFields = [];
$updateParams = [':id' => $custId, ':updated_at' => $nowJst];
$interactionType = 'proline_event';
$interactionPreview = 'プロライン連携';
$notificationDetails = [];

// ================= イベント別のカルテ更新ロジック =================

// 1. フォーム送信イベント (form.send)
if ($event === 'form.send' || !empty($payload['form_name']) || !empty($payload['form_data'])) {
    $formName = trim((string)($payload['form_name'] ?? 'フォーム送信'));
    $formData = $payload['form_data'] ?? [];

    $formattedAnswers = [];
    if (is_array($formData)) {
        foreach ($formData as $k => $v) {
            $valStr = is_array($v) ? implode(', ', $v) : trim((string)$v);
            if ($valStr !== '') {
                $formattedAnswers[] = "・{$k}: {$valStr}";
            }
        }
    }

    $formLogBlock = "【📝 プロラインフォーム回答: {$formName}】 ({$nowJst})\n";
    if (!empty($phone)) $formLogBlock .= "・電話番号: {$phone}\n";
    if (!empty($email)) $formLogBlock .= "・メール: {$email}\n";
    if (!empty($address)) $formLogBlock .= "・住所: {$address}\n";
    if (!empty($formattedAnswers)) {
        $formLogBlock .= implode("\n", $formattedAnswers) . "\n";
    }

    // カルテの特記事項（memo）に追記
    $newMemo = trim($currentMemo . "\n\n" . $formLogBlock);
    $updateFields[] = "memo = :memo";
    $updateParams[':memo'] = $newMemo;

    $interactionType = 'form_submit';
    $interactionPreview = "📝 フォーム回答: {$formName}";
    $notificationDetails[] = "フォーム名: {$formName}";
    if (!empty($formattedAnswers)) {
        $notificationDetails[] = "回答内容:\n" . implode("\n", array_slice($formattedAnswers, 0, 5));
    }
}

// 2. レッスン予約イベント (event.reserve / 予約系)
if (str_contains($event, 'reserve') || str_contains($event, 'booking') || !empty($payload['reserve_date']) || !empty($payload['event_date'])) {
    $reserveDateStr = $payload['reserve_date'] ?? ($payload['event_date'] ?? ($payload['date'] ?? ''));
    
    // 日時の正規化 (例: 2026-09-25 14:00 または 2026-09-25)
    $parsedTime = strtotime($reserveDateStr);
    if ($parsedTime !== false) {
        $formattedDate = date('Y-m-d', $parsedTime);
        $updateFields[] = "oil_next_date = :oil_date";
        $updateParams[':oil_date'] = $formattedDate;
        
        $interactionType = 'booking';
        $interactionPreview = "📅 レッスン予約: " . date('Y/m/d H:i', $parsedTime);
        $notificationDetails[] = "予約日時: " . date('Y/m/d H:i', $parsedTime);
    }
}

// 基本情報の更新（名前・最終やり取り）
$updateFields[] = "user_name = :uname";
$updateParams[':uname'] = $lineName;
$updateFields[] = "last_interaction_at = :last_at";
$updateParams[':last_at'] = $nowJst;
$updateFields[] = "last_interaction_type = :last_type";
$updateParams[':last_type'] = $interactionType;
$updateFields[] = "last_interaction_preview = :last_prev";
$updateParams[':last_prev'] = $interactionPreview;
$updateFields[] = "updated_at = :updated_at";

$sql = "UPDATE customer_cars SET " . implode(', ', $updateFields) . " WHERE id = :id";
$db->prepare($sql)->execute($updateParams);

writeProlineEventLog("✅ 受講生カルテを更新完了", [
    'customerId' => $custId,
    'userName' => $lineName,
    'preview' => $interactionPreview
]);

// ================= スタッフ・講師への通知発火 =================
$notifyTitle = ($interactionType === 'booking')
    ? "📅【プロライン】レッスン予約が入りました"
    : "📝【プロライン】フォーム回答を受信しました";

$notifyBody = "受講生: {$lineName} 様\n" . implode("\n", $notificationDetails) . "\n日時: {$nowJst}";

// 1. Discord通知
try {
    if (function_exists('sendDiscordNotification')) {
        sendDiscordNotification($db, $notifyTitle, $notifyBody, '#0284c7');
    }
} catch (Exception $e) { }

// 2. Slack通知
try {
    if (function_exists('sendSlackNotification')) {
        sendSlackNotification($db, $notifyTitle, $notifyBody);
    }
} catch (Exception $e) { }

// 3. 管理者個人LINE通知
try {
    if (function_exists('sendAdminLineNotification')) {
        sendAdminLineNotification($db, "{$notifyTitle}\n\n{$notifyBody}");
    }
} catch (Exception $e) { }

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => 'Proline event processed successfully',
    'customer_id' => (int)$custId,
    'user_name' => $lineName,
    'interaction' => $interactionPreview
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
