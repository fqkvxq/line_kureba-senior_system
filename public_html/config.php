<?php
/**
 * システム共通設定ファイル
 * Xserver環境およびLINE公式アカウント、Discord通知、顧客メンテナンス管理の設定を管理します。
 */

// タイムゾーン設定 (日本時間 / JST)
date_default_timezone_set('Asia/Tokyo');
ini_set('date.timezone', 'Asia/Tokyo');
putenv('TZ=Asia/Tokyo');

// PHP 7.x 互換 polyfill (str_starts_with, str_ends_with, str_contains)
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || $needle === substr($haystack, -strlen($needle));
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// --- 複数LINE公式アカウント設定 (マルチテナント対応) ---
// 切り替えて運用したいLINE公式アカウントを管理します。
// 管理画面からの新規追加・編集・削除にも完全対応しています。
global $SYSTEM_LINE_ACCOUNTS, $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
$CURRENT_ACTIVE_LINE_ACCOUNT_KEY = null;

define('LINE_ACCOUNTS_DATA_DIR', __DIR__ . '/data');
define('LINE_ACCOUNTS_DATA_FILE', LINE_ACCOUNTS_DATA_DIR . '/line_accounts.json');

// --- システムバージョン & 配布リポジトリ定義 (OTA自動更新用) ---
define('SYSTEM_CURRENT_VERSION', '2.5.0');
define('SYSTEM_BUILD_DATE', '2026-09-21');
define('SYSTEM_GITHUB_REPO', 'fqkvxq/line_kureba-senior_system');
define('SYSTEM_GITHUB_BRANCH', 'main');

// 業種別プリセット定義（各種ビジネス向け項目名・期日名マッピング）
global $INDUSTRY_PRESETS;
$INDUSTRY_PRESETS = [
    'senior' => [
        'id' => 'senior',
        'name' => 'シニア向けパソコン教室・スクール',
        'icon' => 'fa-graduation-cap',
        'label_item1' => '受講コース',
        'label_item2' => '使用機器・PC環境',
        'label_date1' => '次回レッスン',
        'label_date2' => 'PC健康診断',
        'label_date3' => '会員・月謝更新',
        'placeholder_item1' => '例: パソコン入門, スマホ活用, Word/Excel',
        'placeholder_item2' => '例: Windows11, iPad, Android',
        'customer_term' => '受講生',
    ],
    'auto' => [
        'id' => 'auto',
        'name' => '自動車販売・整備・鈑金・車検',
        'icon' => 'fa-car',
        'label_item1' => '車種名',
        'label_item2' => '車両ナンバー',
        'label_date1' => '次回オイル交換',
        'label_date2' => '12ヶ月定期点検',
        'label_date3' => '車検満了日',
        'placeholder_item1' => '例: プリウス, N-BOX, クラウン',
        'placeholder_item2' => '例: 品川 500 あ 12-34',
        'customer_term' => '顧客・オーナー',
    ],
    'salon' => [
        'id' => 'salon',
        'name' => '整体・サロン・エステ・クリニック',
        'icon' => 'fa-spa',
        'label_item1' => '施術メニュー/コース',
        'label_item2' => 'カルテ番号/担当者',
        'label_date1' => '次回施術予約',
        'label_date2' => '定期メンテナンス',
        'label_date3' => '回数券・会員期限',
        'placeholder_item1' => '例: 全身整体60分, 美容鍼コース',
        'placeholder_item2' => '例: K-1024 / 担当: 山田',
        'customer_term' => 'お客様・患者様',
    ],
    'school' => [
        'id' => 'school',
        'name' => '各種スクール・習い事・教室・塾',
        'icon' => 'fa-book-open-reader',
        'label_item1' => '受講クラス/講座名',
        'label_item2' => '生徒番号/所属クラス',
        'label_date1' => '次回授業・レッスン',
        'label_date2' => '定期面談・検定日',
        'label_date3' => '月謝・年会費更新',
        'placeholder_item1' => '例: 英会話中級, プログラミングA',
        'placeholder_item2' => '例: ST-0089 / 月曜クラス',
        'customer_term' => '生徒・会員',
    ],
    'fitness' => [
        'id' => 'fitness',
        'name' => 'フィットネス・パーソナルジム',
        'icon' => 'fa-dumbbell',
        'label_item1' => '会員プラン/種別',
        'label_item2' => '会員番号/ロッカーNo',
        'label_date1' => '次回トレーニング予約',
        'label_date2' => '定期測定・カウンセリング',
        'label_date3' => '会費・契約更新日',
        'placeholder_item1' => '例: プレミアム月4回, デイタイム会員',
        'placeholder_item2' => '例: M-2055 / ロッカー12',
        'customer_term' => '会員・メンバー',
    ],
    'b2b' => [
        'id' => 'b2b',
        'name' => '士業・コンサル・法人顧客管理',
        'icon' => 'fa-briefcase',
        'label_item1' => '契約プラン/サービス名',
        'label_item2' => '企業ID/担当者名',
        'label_date1' => '次回定期面談日',
        'label_date2' => '中間レビュー・進捗確認',
        'label_date3' => '年間契約更新日',
        'placeholder_item1' => '例: 顧問契約A, DX伴走支援',
        'placeholder_item2' => '例: CORP-99 / 佐藤部長',
        'customer_term' => 'クライアント企業',
    ],
    'custom' => [
        'id' => 'custom',
        'name' => '自由設定（カスタム業種）',
        'icon' => 'fa-sliders',
        'label_item1' => '項目1（プラン/種別等）',
        'label_item2' => '項目2（管理番号等）',
        'label_date1' => '期日1（次回予定日）',
        'label_date2' => '期日2（定期予定日）',
        'label_date3' => '期日3（更新期日）',
        'placeholder_item1' => '項目1の内容',
        'placeholder_item2' => '項目2の内容',
        'customer_term' => '顧客',
    ]
];

// デフォルトの基本アカウント定義
$DEFAULT_SYSTEM_LINE_ACCOUNTS = [
    'senior' => [
        'id' => 'senior',
        'name' => 'スマホ・パソコン教室KUREBA',
        'short_name' => 'パソコン教室',
        'theme_color' => '#ff8700', // 教室ブランドカラー (オレンジ)
        'industry_type' => 'senior',
        'label_item1' => '受講コース',
        'label_item2' => '使用機器・PC環境',
        'label_date1' => '次回レッスン',
        'label_date2' => 'PC健康診断',
        'label_date3' => '会員・月謝更新',
        'channel_access_token' => 'n1ItOIEh+8mNJiEpXK+hG0T4/b1Z9taR2FkYQrAwA6J/3XMdUUfHnkP3DX+7u+nGgirA4helNntS1qT2m2kOtV7yiYM2MwxrEB7qj09J/yXhItpCqKGS7l4lcaffcvukX/jHGFOLDSloz0vBLIQAdQdB04t89/1O/w1cDnyilFU=',
        'channel_secret' => 'a5dbfb92fa7be994b6e8f38f870b97b8',
        'liff_id' => '2000276344-YL1wXh0h',
        'proline_calendar_url' => 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1',
        'proline_webhook_url' => '',
        'db_file' => 'kureba-senior-system.db', // メインDB (受講生・カルテ・チャット管理)
        'is_default' => true,
    ],
    'kaisya_dx' => [
        'id' => 'kaisya_dx',
        'name' => '会社DXのKUREBA',
        'short_name' => '会社DXのKUREBA',
        'theme_color' => '#2563eb', // ブルー
        'industry_type' => 'b2b',
        'label_item1' => '契約プラン',
        'label_item2' => '企業ID/担当者',
        'label_date1' => '次回定期面談日',
        'label_date2' => '進捗レビュー日',
        'label_date3' => '契約更新期日',
        'channel_access_token' => '5rGB+M9chkEMdXpO7S5+jAtrqX+7FUNDF9IsZ2/i3zi02/QlGQQTolaYjeLMDo92ckuD2MORgkaCID1MxpbDPD3INS8jHs+wxyyNfKzj9xT1eZ5jPqWy4S0DM2l7IJo7MDIeOzqcr5JtMQaq38OQNQdB04t89/1O/w1cDnyilFU=', // チャネルアクセストークン
        'channel_secret' => '70b7887d48cbb85870eb8ee6f43dc6aa',       // チャネルシークレット
        'liff_id' => '',              // LIFF ID
        'proline_calendar_url' => '',
        'proline_webhook_url' => '',
        'db_file' => 'kaisyadx.db', // 専用DB
        'is_default' => false,
    ],
];

/**
 * 指定アカウントのカスタム項目名・期日名を取得
 */
function getAccountCustomLabels(string $accountKey = null): array {
    global $INDUSTRY_PRESETS;
    $config = getAccountConfig($accountKey);
    $indType = $config['industry_type'] ?? 'senior';
    $preset = $INDUSTRY_PRESETS[$indType] ?? $INDUSTRY_PRESETS['senior'];

    $i1 = !empty($config['label_item1']) ? $config['label_item1'] : $preset['label_item1'];
    $i2 = !empty($config['label_item2']) ? $config['label_item2'] : $preset['label_item2'];
    $d1 = !empty($config['label_date1']) ? $config['label_date1'] : $preset['label_date1'];
    $d2 = !empty($config['label_date2']) ? $config['label_date2'] : $preset['label_date2'];
    $d3 = !empty($config['label_date3']) ? $config['label_date3'] : $preset['label_date3'];

    return [
        'industry_type' => $indType,
        'industry_name' => $preset['name'] ?? 'シニア向けパソコン教室',
        'industry_icon' => $preset['icon'] ?? 'fa-graduation-cap',
        'customer_term' => $preset['customer_term'] ?? '顧客・受講生',
        'label_item1' => $i1,
        'label_item2' => $i2,
        'label_date1' => $d1,
        'label_date2' => $d2,
        'label_date3' => $d3,
        // フロントエンド直感参照用エイリアス
        'item1' => $i1,
        'item2' => $i2,
        'date1' => $d1,
        'date2' => $d2,
        'date3' => $d3,
        'placeholder_item1' => $preset['placeholder_item1'] ?? '',
        'placeholder_item2' => $preset['placeholder_item2'] ?? '',
    ];
}

/**
 * 登録されているLINE公式アカウント設定をロード（ファイル永続化 + デフォルトマージ）
 */
function loadSystemLineAccounts(): array {
    global $DEFAULT_SYSTEM_LINE_ACCOUNTS;
    $accounts = $DEFAULT_SYSTEM_LINE_ACCOUNTS;

    if (file_exists(LINE_ACCOUNTS_DATA_FILE)) {
        $json = @file_get_contents(LINE_ACCOUNTS_DATA_FILE);
        if (!empty($json)) {
            $data = json_decode($json, true);
            if (is_array($data) && !empty($data['accounts']) && is_array($data['accounts'])) {
                foreach ($data['accounts'] as $k => $acc) {
                    if (is_array($acc) && !empty($acc['id'])) {
                        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $acc['id']);
                        if (!empty($key)) {
                            // 既存のsenior等のプロパティを保持しつつマージ
                            $base = $accounts[$key] ?? [
                                'id' => $key,
                                'is_default' => false,
                                'db_file' => ($key === 'senior') ? 'kureba-senior-system.db' : "cars_{$key}.db"
                            ];
                            $accounts[$key] = array_merge($base, $acc);
                            $accounts[$key]['id'] = $key;
                            if (empty($accounts[$key]['db_file']) || $accounts[$key]['db_file'] === 'cars.db') {
                                $accounts[$key]['db_file'] = ($key === 'senior') ? 'kureba-senior-system.db' : "cars_{$key}.db";
                            }
                        }
                    }
                }
            }
        }
    }

    // 必ず最低1つのデフォルトが存在することを保証
    $hasDefault = false;
    foreach ($accounts as $acc) {
        if (!empty($acc['is_default'])) {
            $hasDefault = true;
            break;
        }
    }
    if (!$hasDefault && isset($accounts['senior'])) {
        $accounts['senior']['is_default'] = true;
    }

    return $accounts;
}

/**
 * LINE公式アカウント設定を保存（安全なJSONファイル書き込み）
 */
function saveSystemLineAccounts(array $accounts): bool {
    if (!is_dir(LINE_ACCOUNTS_DATA_DIR)) {
        @mkdir(LINE_ACCOUNTS_DATA_DIR, 0777, true);
    }
    @chmod(LINE_ACCOUNTS_DATA_DIR, 0777);

    // .htaccess で直接Webアクセスを遮断
    $htaccessFile = LINE_ACCOUNTS_DATA_DIR . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        @file_put_contents($htaccessFile, "Deny from all\n");
    }

    $cleanAccounts = [];
    foreach ($accounts as $k => $acc) {
        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $acc['id'] ?? $k);
        if (empty($key)) continue;

        $cleanAccounts[$key] = [
            'id' => $key,
            'name' => trim((string)($acc['name'] ?? 'LINE公式アカウント')),
            'short_name' => trim((string)($acc['short_name'] ?? $acc['name'] ?? '店舗')),
            'theme_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $acc['theme_color'] ?? '') ? $acc['theme_color'] : '#6366f1',
            'industry_type' => trim((string)($acc['industry_type'] ?? 'senior')),
            'label_item1' => trim((string)($acc['label_item1'] ?? '')),
            'label_item2' => trim((string)($acc['label_item2'] ?? '')),
            'label_date1' => trim((string)($acc['label_date1'] ?? '')),
            'label_date2' => trim((string)($acc['label_date2'] ?? '')),
            'label_date3' => trim((string)($acc['label_date3'] ?? '')),
            'channel_access_token' => trim((string)($acc['channel_access_token'] ?? '')),
            'channel_secret' => trim((string)($acc['channel_secret'] ?? '')),
            'liff_id' => trim((string)($acc['liff_id'] ?? '')),
            'proline_calendar_url' => trim((string)($acc['proline_calendar_url'] ?? '')),
            'proline_webhook_url' => trim((string)($acc['proline_webhook_url'] ?? '')),
            'db_file' => !empty($acc['db_file']) ? $acc['db_file'] : (($key === 'senior') ? 'kureba-senior-system.db' : "kureba_{$key}.db"),
            'is_default' => !empty($acc['is_default']),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    $payload = [
        'version' => '1.0',
        'updated_at' => date('Y-m-d H:i:s'),
        'accounts' => $cleanAccounts
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = @file_put_contents(LINE_ACCOUNTS_DATA_FILE, $json, LOCK_EX);
    if ($res !== false) {
        @chmod(LINE_ACCOUNTS_DATA_FILE, 0666);
        return true;
    }
    return false;
}

/**
 * LINE公式アカウントのチャネルアクセストークンを検証（Bot情報の取得テスト）
 */
function verifyLineBotCredentials(string $accessToken): array {
    $token = trim($accessToken);
    if (empty($token)) {
        return ['success' => false, 'error' => 'チャネルアクセストークンが入力されていません'];
    }

    $ch = curl_init('https://api.line.me/v2/bot/info');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!empty($curlError)) {
        return ['success' => false, 'error' => 'LINE通信エラー: ' . $curlError];
    }

    $data = json_decode($body, true);
    if ($httpCode === 200 && is_array($data)) {
        return [
            'success' => true,
            'bot_info' => [
                'user_id' => $data['userId'] ?? '',
                'basic_id' => $data['basicId'] ?? '',
                'premium_id' => $data['premiumId'] ?? '',
                'display_name' => $data['displayName'] ?? '',
                'picture_url' => $data['pictureUrl'] ?? '',
                'chat_mode' => $data['chatMode'] ?? '',
                'mark_as_read_mode' => $data['markAsReadMode'] ?? ''
            ]
        ];
    }

    $errMessage = $data['message'] ?? "HTTP {$httpCode}: LINE認証に失敗しました。トークンを確認してください。";
    if (isset($data['details'])) {
        $errMessage .= ' (' . json_encode($data['details'], JSON_UNESCAPED_UNICODE) . ')';
    }
    return ['success' => false, 'error' => $errMessage, 'http_code' => $httpCode];
}

$SYSTEM_LINE_ACCOUNTS = loadSystemLineAccounts();

// --- 後方互換用 定数フォールバック (単一アカウント時代の定数参照を安全に維持) ---
define('LINE_CHANNEL_ACCESS_TOKEN', $SYSTEM_LINE_ACCOUNTS['senior']['channel_access_token'] ?? '');
define('LINE_CHANNEL_SECRET', $SYSTEM_LINE_ACCOUNTS['senior']['channel_secret'] ?? '');
define('LINE_LIFF_ID', $SYSTEM_LINE_ACCOUNTS['senior']['liff_id'] ?? '');
define('LIFF_ID', $SYSTEM_LINE_ACCOUNTS['senior']['liff_id'] ?? '');

// --- 新着車両の自動配信設定 ---
define('ENABLE_NEW_CAR_BROADCAST', false); // 新着検知時にLINE公式アカウントの友だち全員へ自動一斉配信するか (true: 送信する, false: 送信しない)
define('ENABLE_NEW_CAR_DISCORD', true);   // 新着検知時にDiscordへ通知するか

// --- 店舗管理画面設定 & 二段階認証(2FA) ---
define('ADMIN_PASSWORD', '1020143'); // 店舗用管理画面（/admin/）のログインパスワード
define('ENABLE_ADMIN_2FA', false); // 管理者ログイン時のメール二段階認証 (true: 有効, false: 無効)
define('ADMIN_2FA_EMAIL', 'kawai@kureba.co.jp'); // 認証コード送信先メールアドレス
define('ADMIN_2FA_CODE_LIFETIME_MINUTES', 10); // 認証コード有効期限 (10分間)
define('ADMIN_2FA_MAX_ATTEMPTS', 5); // 認証コード最大試行回数 (5回超過で無効化)

// --- Discord 通知設定 ---
define('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/1543636005582667776/8hnE-kLsB545xgS923mTvgIUaBuTz8TQQLrJXFvqB-A0oh92LmqC8Zn-1jaOIhW20YEZ');

// --- 店舗・教室・システム設定 ---
define('SHOP_CODE', '0601492');
define('SHOP_NAME', 'シニア向けパソコン教室');
define('SHOP_GOO_URL', '');

// --- プロライン (ProLine) Webhook中継・連携設定 ---
define('PROLINE_WEBHOOK_URL', ''); // プロラインのWebhook URL (例: https://autosns.pro/.../webhook/...)
define('PROLINE_RELAY_ENABLED', true); // プロラインへのWebhook転送を有効にするか (true: 有効, false: 無効)
define('PROLINE_CALENDAR_URL', 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1'); // レッスン予約・日程変更URL (受講生自動ログインLIFF)

// --- リッチメニュー画像保存ディレクトリ ---
define('RICHMENU_UPLOAD_DIR', __DIR__ . '/uploads/richmenu');
if (!is_dir(RICHMENU_UPLOAD_DIR)) {
    @mkdir(RICHMENU_UPLOAD_DIR, 0777, true);
}
@chmod(RICHMENU_UPLOAD_DIR, 0777);

// ==============================================================================
// マルチアカウント管理・アクセサ関数群
// ==============================================================================

/**
 * 現在のアクティブアカウントキーを設定
 */
function setActiveAccountKey(string $key): void {
    global $SYSTEM_LINE_ACCOUNTS, $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
    if (isset($SYSTEM_LINE_ACCOUNTS[$key])) {
        $CURRENT_ACTIVE_LINE_ACCOUNT_KEY = $key;
    }
}

/**
 * 現在のアクティブアカウントキーを取得
 */
function getActiveAccountKey(): string {
    global $SYSTEM_LINE_ACCOUNTS, $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
    if (!empty($CURRENT_ACTIVE_LINE_ACCOUNT_KEY) && isset($SYSTEM_LINE_ACCOUNTS[$CURRENT_ACTIVE_LINE_ACCOUNT_KEY])) {
        return $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
    }

    $req = $_REQUEST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? ($_COOKIE['active_line_account'] ?? null));
    if (!empty($req) && isset($SYSTEM_LINE_ACCOUNTS[$req])) {
        $CURRENT_ACTIVE_LINE_ACCOUNT_KEY = $req;
        return $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
    }

    foreach ($SYSTEM_LINE_ACCOUNTS as $k => $acc) {
        if (!empty($acc['is_default'])) {
            $CURRENT_ACTIVE_LINE_ACCOUNT_KEY = $k;
            return $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
        }
    }

    $keys = array_keys($SYSTEM_LINE_ACCOUNTS);
    $CURRENT_ACTIVE_LINE_ACCOUNT_KEY = $keys[0] ?? 'senior';
    return $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
}

/**
 * 登録されているアカウント一覧（管理画面用サマリー）を取得
 */
function getAccountList(): array {
    global $SYSTEM_LINE_ACCOUNTS;
    $list = [];
    $activeKey = getActiveAccountKey();
    foreach ($SYSTEM_LINE_ACCOUNTS as $k => $acc) {
        $hasToken = !empty($acc['channel_access_token']) && $acc['channel_access_token'] !== 'YOUR_CHANNEL_ACCESS_TOKEN_HERE';
        $hasSecret = !empty($acc['channel_secret']) && $acc['channel_secret'] !== 'YOUR_CHANNEL_SECRET_HERE';
        $list[] = [
            'id' => $acc['id'],
            'name' => $acc['name'],
            'short_name' => $acc['short_name'] ?? $acc['name'],
            'theme_color' => $acc['theme_color'] ?? '#6366f1',
            'is_default' => !empty($acc['is_default']),
            'is_active' => ($k === $activeKey),
            'is_configured' => ($hasToken && $hasSecret),
            'has_token' => $hasToken,
            'db_file' => $acc['db_file'] ?? "cars_{$k}.db"
        ];
    }
    return $list;
}

/**
 * アカウント設定配列を取得
 */
function getAccountConfig(?string $key = null): array {
    global $SYSTEM_LINE_ACCOUNTS;
    $targetKey = $key ?: getActiveAccountKey();
    if (isset($SYSTEM_LINE_ACCOUNTS[$targetKey])) {
        return $SYSTEM_LINE_ACCOUNTS[$targetKey];
    }
    foreach ($SYSTEM_LINE_ACCOUNTS as $acc) {
        if (!empty($acc['is_default'])) return $acc;
    }
    return reset($SYSTEM_LINE_ACCOUNTS) ?: [];
}

/**
 * チャネルアクセストークンを取得
 */
function getLineAccessToken(?string $key = null): string {
    $conf = getAccountConfig($key);
    if (!empty($conf['channel_access_token'])) {
        return $conf['channel_access_token'];
    }
    return defined('LINE_CHANNEL_ACCESS_TOKEN') ? LINE_CHANNEL_ACCESS_TOKEN : '';
}

/**
 * チャネルシークレットを取得
 */
function getLineChannelSecret(?string $key = null): string {
    $conf = getAccountConfig($key);
    if (!empty($conf['channel_secret'])) {
        return $conf['channel_secret'];
    }
    return defined('LINE_CHANNEL_SECRET') ? LINE_CHANNEL_SECRET : '';
}

/**
 * LIFF IDを取得
 */
function getLineLiffId(?string $key = null): string {
    $conf = getAccountConfig($key);
    if (!empty($conf['liff_id'])) {
        return $conf['liff_id'];
    }
    return defined('LINE_LIFF_ID') ? LINE_LIFF_ID : '';
}

/**
 * 店舗・教室名を取得
 */
function getAccountShopName(?string $key = null): string {
    $conf = getAccountConfig($key);
    if (!empty($conf['name'])) {
        return $conf['name'];
    }
    return defined('SHOP_NAME') ? SHOP_NAME : 'LINE公式アカウント';
}

/**
 * プロライン予約カレンダーURLを取得
 */
function getAccountProlineCalendarUrl(?string $key = null): string {
    $conf = getAccountConfig($key);
    if (!empty($conf['proline_calendar_url'])) {
        return $conf['proline_calendar_url'];
    }
    return defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : '';
}

/**
 * データベースファイルのパスを自動検出 (アカウント別対応・kureba-senior-system.db自動移行対応)
 */
function getDbFilePath(?string $accountKey = null): string {
    $key = $accountKey ?: getActiveAccountKey();
    $conf = getAccountConfig($key);
    $dbFileName = !empty($conf['db_file']) ? $conf['db_file'] : 'kureba-senior-system.db';
    if ($dbFileName === 'cars.db') {
        $dbFileName = 'kureba-senior-system.db';
    }

    $candidateDirs = [
        __DIR__ . '/batch',
        __DIR__ . '/../batch',
        __DIR__,
        __DIR__ . '/../../batch',
        dirname(__DIR__) . '/batch'
    ];

    // メインアカウント（senior / kureba-senior-system.db）の場合
    if ($dbFileName === 'kureba-senior-system.db') {
        // 1. すでに kureba-senior-system.db が存在するか確認
        foreach ($candidateDirs as $dir) {
            $path = $dir . '/kureba-senior-system.db';
            if (file_exists($path)) {
                return $path;
            }
        }

        // 2. 既存の cars.db が存在する場合は自動移行（コピーしてデータを完全引き継ぎ）
        foreach ($candidateDirs as $dir) {
            $oldPath = $dir . '/cars.db';
            $newPath = $dir . '/kureba-senior-system.db';
            if (file_exists($oldPath)) {
                if (!file_exists($newPath)) {
                    @copy($oldPath, $newPath);
                    @chmod($newPath, 0666);
                }
                return $newPath;
            }
        }

        return __DIR__ . '/batch/kureba-senior-system.db';
    }

    // 別アカウント用DBファイル
    foreach ($candidateDirs as $sDir) {
        if (file_exists($sDir . '/kureba-senior-system.db') || file_exists($sDir . '/cars.db')) {
            return $sDir . '/' . $dbFileName;
        }
    }
    return __DIR__ . '/batch/' . $dbFileName;
}

define('DB_PATH', getDbFilePath());

/**
 * データベース接続オブジェクト (PDO) を取得 (アカウント別対応・接続プール)
 * @param string|null $accountKey 指定アカウントキー (未指定時は現在のアクティブアカウント)
 * @return PDO
 */
function getDbConnection(?string $accountKey = null): PDO {
    static $dbPool = [];
    $key = $accountKey ?: getActiveAccountKey();
    if (isset($dbPool[$key])) {
        return $dbPool[$key];
    }

    $dbFile = getDbFilePath($key);
    $dir = dirname($dbFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
    if (file_exists($dbFile)) {
        @chmod($dbFile, 0666);
    }

    $pdo = new PDO("sqlite:{$dbFile}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 30
    ]);

    // ロックタイムアウトを30秒に延長し、同時実行時のロック衝突を防止
    try {
        $pdo->exec("PRAGMA busy_timeout = 30000");
        $pdo->exec("PRAGMA synchronous = NORMAL");
        // WALモードがlocking protocolを起こす場合は安全にDELETE/TRUNCATEへ
        try {
            $pdo->exec("PRAGMA journal_mode = WAL");
        } catch (Throwable $walEx) {
            try { $pdo->exec("PRAGMA journal_mode = DELETE"); } catch (Throwable $e2) {}
        }
    } catch (Throwable $e) {}

    // リクエストごとに大量の DDL (CREATE/ALTER) が実行されてロック衝突するのを防ぐ (プロセス内1回のみ実行)
    static $initializedDbs = [];
    if (empty($initializedDbs[$key])) {
        $initializedDbs[$key] = true;

        // 複数台対応: customer_cars テーブルの初期化
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS customer_cars (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id TEXT NOT NULL,
                    user_name TEXT,
                    car_model TEXT NOT NULL,
                    car_number TEXT,
                    oil_last_date DATE,
                    oil_next_date DATE,
                    periodic_insp_next_date DATE,
                    inspection_next_date DATE,
                    staff_memo TEXT,
                    oil_reminded_at DATETIME,
                    periodic_reminded_at DATETIME,
                    inspection_reminded_at DATETIME,
                    created_at DATETIME DEFAULT (datetime('now', '+9 hours')),
                    updated_at DATETIME DEFAULT (datetime('now', '+9 hours'))
                )
            ");
        } catch (Throwable $t) {}

        // インデックス作成・カラム追加
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_user_id ON customer_cars(user_id)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_oil_next ON customer_cars(oil_next_date)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_periodic_next ON customer_cars(periodic_insp_next_date)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_inspection_next ON customer_cars(inspection_next_date)"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN custom_line_menu_id TEXT DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN custom_menu_text TEXT DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN custom_menu_set_at DATETIME"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN picture_url TEXT DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN last_interaction_at DATETIME"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN last_interaction_type TEXT DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN last_interaction_preview TEXT DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN is_blocked INTEGER DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN blocked_at DATETIME"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN tags TEXT DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_last_interaction ON customer_cars(last_interaction_at)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_is_blocked ON customer_cars(is_blocked)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_tags ON customer_cars(tags)"); } catch (Throwable $e) {}

        // システム設定・マイグレーション管理テーブル
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS system_settings (
                    key TEXT PRIMARY KEY,
                    value TEXT,
                    updated_at DATETIME
                )
            ");
        } catch (Throwable $e) {}
    }

    // 初期化マイグレーション: last_interaction_at が NULL の既存顧客に対して最新日付を補完
    try {
        $nowJst = date('Y-m-d H:i:s');
        $pdo->exec("
            UPDATE customer_cars
            SET 
                last_interaction_at = COALESCE(
                    inspection_reminded_at,
                    periodic_reminded_at,
                    oil_reminded_at,
                    custom_menu_set_at,
                    updated_at,
                    created_at,
                    '{$nowJst}'
                ),
                last_interaction_type = CASE
                    WHEN inspection_reminded_at IS NOT NULL THEN 'admin_reminder'
                    WHEN periodic_reminded_at IS NOT NULL THEN 'admin_reminder'
                    WHEN oil_reminded_at IS NOT NULL THEN 'admin_reminder'
                    WHEN custom_menu_set_at IS NOT NULL THEN 'custom_menu'
                    ELSE 'follow'
                END,
                last_interaction_preview = CASE
                    WHEN inspection_reminded_at IS NOT NULL THEN '車検リマインド送信'
                    WHEN periodic_reminded_at IS NOT NULL THEN '12ヶ月点検リマインド送信'
                    WHEN oil_reminded_at IS NOT NULL THEN 'オイル交換リマインド送信'
                    WHEN custom_menu_set_at IS NOT NULL THEN '個別リッチメニュー設定'
                    ELSE '友だち登録'
                END
            WHERE last_interaction_at IS NULL
        ");
    } catch (Exception $e) {}

    // 【重要】既存データのUTC→JST(+9時間) 一括変換マイグレーション (1回限り実行)
    // 過去に DEFAULT CURRENT_TIMESTAMP 等で記録されたUTC時刻データを日本標準時(JST)に揃える
    try {
        $checkStmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'tz_migrated_to_jst_v2'");
        $checkStmt->execute();
        $isMigrated = $checkStmt->fetchColumn();
        if (!$isMigrated) {
            $nowJst = date('Y-m-d H:i:s');
            $pdo->beginTransaction();

            // 1. 過去の既存レコードの各日時カラムを +9時間 して日本時間に補正
            $pdo->exec("
                UPDATE customer_cars
                SET 
                    created_at = datetime(created_at, '+9 hours'),
                    updated_at = datetime(updated_at, '+9 hours'),
                    last_interaction_at = CASE 
                        WHEN last_interaction_at IS NOT NULL THEN datetime(last_interaction_at, '+9 hours')
                        ELSE datetime(COALESCE(updated_at, created_at), '+9 hours')
                    END,
                    oil_reminded_at = CASE WHEN oil_reminded_at IS NOT NULL THEN datetime(oil_reminded_at, '+9 hours') ELSE NULL END,
                    periodic_reminded_at = CASE WHEN periodic_reminded_at IS NOT NULL THEN datetime(periodic_reminded_at, '+9 hours') ELSE NULL END,
                    inspection_reminded_at = CASE WHEN inspection_reminded_at IS NOT NULL THEN datetime(inspection_reminded_at, '+9 hours') ELSE NULL END,
                    custom_menu_set_at = CASE WHEN custom_menu_set_at IS NOT NULL THEN datetime(custom_menu_set_at, '+9 hours') ELSE NULL END
            ");

            // 2. 念のための安全防護策: 補正により現在時刻より未来（10分以上先）になってしまったものは現在時刻(JST)に丸める
            $safeStmt = $pdo->prepare("
                UPDATE customer_cars 
                SET last_interaction_at = :now_jst 
                WHERE last_interaction_at > datetime(:now_jst_check, '+10 minutes')
            ");
            $safeStmt->execute([':now_jst' => $nowJst, ':now_jst_check' => $nowJst]);

            // 完了フラグを記録
            $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('tz_migrated_to_jst_v2', '1', :now_jst)")
                ->execute([':now_jst' => $nowJst]);
            $pdo->commit();
            writeDebugLog("全顧客データのUTC→JST一括マイグレーション完了");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        writeDebugLog("JSTマイグレーション例外", ['error' => $e->getMessage()]);
    }

    // リッチメニュー履歴管理テーブルの初期化
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rich_menus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            line_menu_id TEXT,
            title TEXT NOT NULL,
            chat_bar_text TEXT DEFAULT 'メニュー',
            image_url TEXT NOT NULL,
            base_image_url TEXT DEFAULT '',
            areas_json TEXT NOT NULL,
            text_overlays_json TEXT DEFAULT '[]',
            width INTEGER DEFAULT 2500,
            height INTEGER DEFAULT 1686,
            is_active INTEGER DEFAULT 0,
            is_notice INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now', '+9 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+9 hours'))
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rich_menus_active ON rich_menus(is_active)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN text_overlays_json TEXT DEFAULT '[]'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN base_image_url TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN alias_id TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN is_notice INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN target_tags TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rich_menus_notice ON rich_menus(is_notice)"); } catch (Exception $e) {}

    // LINEチャット・メッセージ送受信履歴テーブルの初期化
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            direction TEXT NOT NULL, -- 'incoming' (受講生から), 'outgoing' (スタッフから)
            message_type TEXT NOT NULL DEFAULT 'text', -- 'text', 'image', 'sticker', etc.
            message_text TEXT,
            payload_json TEXT DEFAULT '{}',
            is_read INTEGER DEFAULT 0, -- 0: 未読, 1: 既読
            sent_by TEXT DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+9 hours'))
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_user_id ON chat_messages(user_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_created_at ON chat_messages(created_at)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_chat_is_read ON chat_messages(is_read)"); } catch (Exception $e) {}

    // 管理者メール二段階認証(2FA) セッション & 永続認証トークンテーブル
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_2fa_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_token TEXT UNIQUE NOT NULL,
            email TEXT NOT NULL,
            otp_code TEXT NOT NULL,
            attempts INTEGER DEFAULT 0,
            is_verified INTEGER DEFAULT 0,
            last_sent_at DATETIME DEFAULT (datetime('now', '+9 hours')),
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT (datetime('now', '+9 hours'))
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_2fa_session_token ON admin_2fa_sessions(session_token)"); } catch (Exception $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_auth_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT (datetime('now', '+9 hours')),
            last_used_at DATETIME DEFAULT (datetime('now', '+9 hours'))
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_auth_token ON admin_auth_tokens(token)"); } catch (Exception $e) {}

    // 既存 customers テーブルからのデータ移行（初回1回のみ）
    try {
        $countCars = $pdo->query("SELECT COUNT(*) FROM customer_cars")->fetchColumn();
        if ($countCars == 0) {
            $hasLegacy = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='customers'")->fetch();
            if ($hasLegacy) {
                $legacyRows = $pdo->query("SELECT * FROM customers WHERE car_model IS NOT NULL AND car_model != ''")->fetchAll();
                $insertStmt = $pdo->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        oil_last_date, oil_next_date, periodic_insp_next_date, inspection_next_date,
                        staff_memo, oil_reminded_at, periodic_reminded_at, inspection_reminded_at,
                        created_at, updated_at
                    ) VALUES (
                        :user_id, :user_name, :car_model, :car_number,
                        :oil_last_date, :oil_next_date, :periodic_insp_next_date, :inspection_next_date,
                        :staff_memo, :oil_reminded_at, :periodic_reminded_at, :inspection_reminded_at,
                        :created_at, :updated_at
                    )
                ");
                foreach ($legacyRows as $r) {
                    $insertStmt->execute([
                        ':user_id' => $r['user_id'],
                        ':user_name' => $r['user_name'] ?? '',
                        ':car_model' => $r['car_model'],
                        ':car_number' => $r['car_number'] ?? '',
                        ':oil_last_date' => $r['oil_last_date'] ?? null,
                        ':oil_next_date' => $r['oil_next_date'] ?? null,
                        ':periodic_insp_next_date' => $r['periodic_insp_next_date'] ?? null,
                        ':inspection_next_date' => $r['inspection_next_date'] ?? null,
                        ':staff_memo' => $r['staff_memo'] ?? null,
                        ':oil_reminded_at' => $r['oil_reminded_at'] ?? null,
                        ':periodic_reminded_at' => $r['periodic_reminded_at'] ?? null,
                        ':inspection_reminded_at' => $r['inspection_reminded_at'] ?? null,
                        ':created_at' => $r['created_at'] ?? date('Y-m-d H:i:s'),
                        ':updated_at' => $r['updated_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
            }
        }
    } catch (Exception $e) {}

    $dbPool[$key] = $pdo;
    return $pdo;
}

/**
 * 現在のベースURLを自動取得 (LINE Flex Message用に必ず https:// を保証)
 */
function getBaseUrl(): string {
    $protocol = "https://";
    $host = $_SERVER['HTTP_HOST'] ?? 'kureba.co.jp';
    
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = str_replace('\\', '/', $scriptDir);
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }
    
    $url = $protocol . $host . $scriptDir;
    return rtrim($url, '/');
}

/**
 * LINEユーザーのプロフィール情報（表示名・アイコン）を取得
 */
function getLineUserProfile(string $userId, ?string $accountKey = null): ?array {
    $token = getLineAccessToken($accountKey);
    if (empty($userId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/profile/" . urlencode($userId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        return json_decode($res, true);
    }

    return null;
}

/**
 * 複数のLINEユーザープロフィールを並列（curl_multi）で一括超高速取得
 * @param array $userIds
 * @param string|null $accountKey
 * @return array [ $userId => [ 'displayName' => ..., 'pictureUrl' => ... ] ]
 */
function getLineUserProfilesBatch(array $userIds, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($userIds) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return [];
    }

    $uniqueIds = array_values(array_unique(array_filter($userIds)));
    if (empty($uniqueIds)) return [];

    $mh = curl_multi_init();
    $curlHandles = [];
    $results = [];

    foreach ($uniqueIds as $uid) {
        $ch = curl_init("https://api.line.me/v2/bot/profile/" . urlencode($uid));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token
            ]
        ]);
        curl_multi_add_handle($mh, $ch);
        $curlHandles[$uid] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($curlHandles as $uid => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $res = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($httpCode === 200 && !empty($res)) {
            $data = json_decode($res, true);
            if ($data && !empty($data['displayName'])) {
                $results[$uid] = $data;
            }
        }
    }
    curl_multi_close($mh);

    return $results;
}

/**
 * LINE Messaging API: 全フォロワー（友だち）の User ID 一覧を取得
 * GET https://api.line.me/v2/bot/followers/ids
 */
function getLineFollowerUserIds(?string $start = null): array {
    $token = getLineAccessToken();
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/followers/ids?limit=1000";
    if (!empty($start)) {
        $url .= "&start=" . urlencode($start);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        $data = json_decode($res, true);
        return [
            'success' => true,
            'userIds' => $data['userIds'] ?? [],
            'next' => $data['next'] ?? null
        ];
    }

    return [
        'success' => false,
        'httpCode' => $httpCode,
        'error' => $curlErr ?: $res
    ];
}

/**
 * LINEユーザー（友だち）を customer_cars に自動登録・名前同期する共通関数
 */
function ensureCustomerExists(PDO $db, string $userId, ?string $accountKey = null): ?array {
    $userId = trim($userId);
    if (empty($userId) || !str_starts_with($userId, 'U')) {
        return null;
    }

    $accountKey = $accountKey ?: getActiveAccountKey();

    try {
        $stmt = $db->prepare("SELECT * FROM customer_cars WHERE TRIM(user_id) = :uid ORDER BY id ASC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // 名前が仮名またはアイコンが未登録ならプロフィール取得して更新
            $needUpdateName = empty($existing['user_name']) || in_array($existing['user_name'], ['新規お客様', 'お客様', 'LINE友だち', '受講生', 'LINE受講生', '']);
            $needUpdatePic = empty($existing['picture_url']);
            if ($needUpdateName || $needUpdatePic) {
                $prof = getLineUserProfile($userId, $accountKey);
                if ($prof) {
                    $nowJst = date('Y-m-d H:i:s');
                    $upName = (!empty($prof['displayName']) && $needUpdateName) ? $prof['displayName'] : $existing['user_name'];
                    $upPic = !empty($prof['pictureUrl']) ? $prof['pictureUrl'] : ($existing['picture_url'] ?? '');
                    $upStmt = $db->prepare("UPDATE customer_cars SET user_name = :uname, picture_url = :pic, updated_at = :updated_at WHERE id = :id");
                    $upStmt->execute([':uname' => $upName, ':pic' => $upPic, ':updated_at' => $nowJst, ':id' => $existing['id']]);
                    $existing['user_name'] = $upName;
                    $existing['picture_url'] = $upPic;
                }
            }
            return $existing;
        }

        // 新規登録
        $prof = getLineUserProfile($userId, $accountKey);
        $displayName = !empty($prof['displayName']) ? $prof['displayName'] : 'LINE受講生';
        $pictureUrl = !empty($prof['pictureUrl']) ? $prof['pictureUrl'] : '';
        $nowJst = date('Y-m-d H:i:s');

        $insertStmt = $db->prepare("
            INSERT INTO customer_cars (
                user_id, user_name, picture_url, car_model, car_number,
                last_interaction_at, last_interaction_type, last_interaction_preview,
                created_at, updated_at
            ) VALUES (
                :uid, :uname, :pic, '【未設定】受講コース未設定', '',
                :now_jst1, 'follow', '✨ 友だち登録',
                :now_jst2, :now_jst3
            )
        ");
        $insertStmt->execute([
            ':uid' => $userId,
            ':uname' => $displayName,
            ':pic' => $pictureUrl,
            ':now_jst1' => $nowJst,
            ':now_jst2' => $nowJst,
            ':now_jst3' => $nowJst
        ]);
        $newId = (int)$db->lastInsertId();
        writeDebugLog("LINEユーザー自動顧客登録完了", ['uid' => $userId, 'name' => $displayName, 'pic' => $pictureUrl, 'id' => $newId]);

        return [
            'id' => $newId,
            'user_id' => $userId,
            'user_name' => $displayName,
            'picture_url' => $pictureUrl,
            'car_model' => '【未設定】受講コース未設定',
            'last_interaction_at' => $nowJst,
            'last_interaction_type' => 'follow',
            'last_interaction_preview' => '✨ 友だち登録'
        ];
    } catch (Throwable $e) {
        writeDebugLog("ensureCustomerExists 例外", ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * 顧客との最新チャット・やり取り日時を記録
 * @param PDO $db
 * @param string $userId LINE User ID
 * @param string $type やり取り種別 (user_message, user_action, admin_reminder, follow, custom_menu)
 * @param string $preview プレビュー文字列 (50文字程度推奨)
 * @param int|null $carId 特定の車両レコードID (省略時は該当userIdのレコードを更新)
 */
function recordCustomerInteraction(PDO $db, string $userId, string $type, string $preview, ?int $carId = null): void {
    if (empty($userId) || !str_starts_with($userId, 'U')) {
        return;
    }

    try {
        $preview = mb_substr(trim($preview), 0, 80);
        $nowJst = date('Y-m-d H:i:s');
        $isBlocked = ($type === 'unfollow') ? 1 : 0;
        $blockedAt = ($type === 'unfollow') ? $nowJst : null;

        if ($carId) {
            $stmt = $db->prepare("
                UPDATE customer_cars 
                SET last_interaction_at = :now_jst1,
                    last_interaction_type = :type,
                    last_interaction_preview = :preview,
                    is_blocked = :is_blocked,
                    blocked_at = CASE WHEN :is_blocked = 1 THEN :blocked_at ELSE NULL END,
                    updated_at = :now_jst2
                WHERE id = :id
            ");
            $stmt->execute([
                ':now_jst1' => $nowJst,
                ':type' => $type,
                ':preview' => $preview,
                ':is_blocked' => $isBlocked,
                ':blocked_at' => $blockedAt,
                ':now_jst2' => $nowJst,
                ':id' => $carId
            ]);
        } else {
            $stmt = $db->prepare("
                UPDATE customer_cars 
                SET last_interaction_at = :now_jst1,
                    last_interaction_type = :type,
                    last_interaction_preview = :preview,
                    is_blocked = :is_blocked,
                    blocked_at = CASE WHEN :is_blocked = 1 THEN :blocked_at ELSE NULL END,
                    updated_at = :now_jst2
                WHERE user_id = :uid
            ");
            $stmt->execute([
                ':now_jst1' => $nowJst,
                ':type' => $type,
                ':preview' => $preview,
                ':is_blocked' => $isBlocked,
                ':blocked_at' => $blockedAt,
                ':now_jst2' => $nowJst,
                ':uid' => $userId
            ]);
        }
        writeDebugLog("顧客インタラクション記録", ['uid' => $userId, 'type' => $type, 'preview' => $preview, 'is_blocked' => $isBlocked, 'time' => $nowJst]);
    } catch (Throwable $e) {
        writeDebugLog("recordCustomerInteraction 例外", ['error' => $e->getMessage()]);
    }
}

/**
 * 日時文字列から親切な相対時間表記を生成
 * 例: たった今, 15分前, 3時間前, 昨日 14:20, 3日前, 2026/09/01
 */
function formatTimeDiffText(?string $datetimeStr): string {
    if (empty($datetimeStr)) {
        return '未記録';
    }
    $ts = strtotime($datetimeStr);
    if (!$ts) {
        return '未記録';
    }
    $now = time();
    $diff = $now - $ts;

    // わずかな時計ズレ（10分以内の未来）は「たった今」とする
    if ($diff < 0) {
        if ($diff > -600) {
            return 'たった今';
        }
        return date('Y/m/d H:i', $ts);
    }
    if ($diff < 60) {
        return 'たった今';
    }
    if ($diff < 3600) {
        $m = max(1, floor($diff / 60));
        return "{$m}分前";
    }
    if ($diff < 86400) {
        $h = floor($diff / 3600);
        return "{$h}時間前";
    }
    if ($diff < 86400 * 2) {
        return '昨日 ' . date('H:i', $ts);
    }
    if ($diff < 86400 * 7) {
        $d = floor($diff / 86400);
        return "{$d}日前";
    }
    return date('Y/m/d', $ts);
}

/**
 * ユーザーが専用リッチメニューを持っているか確認してそのLINEメニューIDを返す
 */
function getUserCustomRichMenuId(?PDO $db, string $userId): ?string {
    if (empty($userId) || !str_starts_with($userId, 'U')) {
        return null;
    }
    if (!$db) {
        $db = getDbConnection();
    }
    try {
        // 空文字ではない有効な custom_line_menu_id を持つ最新レコードを確実に取得
        $stmt = $db->prepare("
            SELECT custom_line_menu_id 
            FROM customer_cars 
            WHERE user_id = :uid 
              AND custom_line_menu_id IS NOT NULL 
              AND TRIM(custom_line_menu_id) != '' 
            ORDER BY COALESCE(custom_menu_set_at, updated_at) DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['custom_line_menu_id'])) {
            $menuId = trim($row['custom_line_menu_id']);

            // 【重要】もし custom_line_menu_id がお知らせリッチメニュー(is_notice=1)だった場合、
            // それは通常メニューへの復帰対象（専用メニュー）ではないため除外
            $noticeMenu = getActiveNoticeRichMenu($db);
            $isNotice = false;
            if ($noticeMenu && !empty($noticeMenu['line_menu_id']) && $noticeMenu['line_menu_id'] === $menuId) {
                $isNotice = true;
            } elseif (isNoticeMenu($db, $menuId)) {
                $isNotice = true;
            }

            if ($isNotice) {
                return null;
            }

            return $menuId;
        }
    } catch (Throwable $e) {
        writeDebugLog("getUserCustomRichMenuId例外", ['error' => $e->getMessage()]);
    }
    return null;
}

/**
 * LINE Messaging API: ユーザーに現在リンクされているリッチメニューIDを取得
 * GET https://api.line.me/v2/bot/user/{userId}/richmenu
 */
function lineGetUserRichMenuId(string $userId): ?string {
    $token = getLineAccessToken();
    if (empty($userId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/user/{$userId}/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        $data = json_decode($res, true);
        if (!empty($data['richMenuId'])) {
            return trim($data['richMenuId']);
        }
    }
    return null;
}

/**
 * 指定されたエイリアスIDまたはメニューIDがお知らせメニューかどうか判定
 */
function isNoticeMenu(?PDO $db, string $aliasOrMenuId): bool {
    if (empty($aliasOrMenuId)) return false;
    if (!$db) {
        $db = getDbConnection();
    }
    try {
        $stmt = $db->prepare("
            SELECT is_notice, title 
            FROM rich_menus 
            WHERE alias_id = :aid OR line_menu_id = :mid 
            LIMIT 1
        ");
        $stmt->execute([':aid' => $aliasOrMenuId, ':mid' => $aliasOrMenuId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((int)$row['is_notice'] === 1 || str_contains($row['title'] ?? '', 'お知らせ') || str_contains($row['title'] ?? '', 'ご案内')) {
                return true;
            }
        }
        if (str_contains($aliasOrMenuId, 'notice')) {
            return true;
        }
    } catch (Throwable $e) {
        writeDebugLog("isNoticeMenu例外", ['error' => $e->getMessage()]);
    }
    return false;
}

/**
 * 特定のユーザーへ個別プッシュ送信 (Push Message API)
 */
function sendLinePushMessage(string $userId, array $messages, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($userId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'Token or userId missing'];
    }

    // 最後のメッセージに未設定ならアカウント業種に応じたクイックリプライを付与
    $lastIdx = count($messages) - 1;
    if ($lastIdx >= 0 && !isset($messages[$lastIdx]['quickReply'])) {
        if (function_exists('getAccountQuickReplyItems')) {
            $qr = getAccountQuickReplyItems($accountKey);
            if (!empty($qr)) {
                $messages[$lastIdx]['quickReply'] = $qr;
            }
        }
    }

    $url = 'https://api.line.me/v2/bot/message/push';
    $payload = [
        'to' => $userId,
        'messages' => $messages
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("LINE個別Push送信結果", [
        'userId' => $userId,
        'httpCode' => $httpCode,
        'response' => $res,
        'curlError' => $curlErr
    ]);

    $errorMsg = $curlErr;
    if (empty($errorMsg) && $httpCode !== 200) {
        $json = json_decode((string)$res, true);
        if (is_array($json) && !empty($json['message'])) {
            $errorMsg = $json['message'];
            if (!empty($json['details']) && is_array($json['details'])) {
                $details = [];
                foreach ($json['details'] as $d) {
                    $details[] = (!empty($d['property']) ? $d['property'] . ': ' : '') . ($d['message'] ?? '');
                }
                $errorMsg .= ' [' . implode(', ', $details) . ']';
            }
        } elseif (!empty($res)) {
            $errorMsg = substr((string)$res, 0, 300);
        } else {
            $errorMsg = "HTTPエラー {$httpCode}";
        }
    }

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'response' => $res,
        'error' => $errorMsg
    ];
}

/**
 * LINEメッセージコンテンツ(画像・音声・動画・ファイル等)を取得してローカルに保存
 * 
 * @param string $messageId LINEメッセージID
 * @param string|null $accountKey アカウントキー
 * @return array ['success' => bool, 'url' => string, 'file_path' => string, 'content_type' => string, 'error' => string]
 */
function downloadLineMessageContent(string $messageId, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($messageId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'Token or messageId missing'];
    }

    $url = "https://api-data.line.me/v2/bot/message/{$messageId}/content";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $binaryData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($binaryData)) {
        writeDebugLog("LINEコンテンツダウンロード失敗", [
            'messageId' => $messageId,
            'httpCode' => $httpCode,
            'error' => $curlErr ?: 'HTTP Code not 200'
        ]);
        return ['success' => false, 'error' => $curlErr ?: "HTTP {$httpCode}"];
    }

    // 拡張子の判定
    $ext = 'jpg';
    if (is_string($contentType)) {
        if (str_contains($contentType, 'image/png')) {
            $ext = 'png';
        } elseif (str_contains($contentType, 'image/gif')) {
            $ext = 'gif';
        } elseif (str_contains($contentType, 'image/webp')) {
            $ext = 'webp';
        } elseif (str_contains($contentType, 'video/mp4')) {
            $ext = 'mp4';
        } elseif (str_contains($contentType, 'audio/')) {
            $ext = 'm4a';
        }
    }

    // 保存ディレクトリ作成
    $uploadDir = __DIR__ . '/uploads/chat';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $fileName = 'line_msg_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $messageId) . '_' . date('YmdHis') . '.' . $ext;
    $filePath = $uploadDir . '/' . $fileName;

    if (file_put_contents($filePath, $binaryData) === false) {
        writeDebugLog("LINEコンテンツ保存失敗 (file_put_contents error)", ['filePath' => $filePath]);
        return ['success' => false, 'error' => 'ファイル保存に失敗しました'];
    }

    $publicUrl = getBaseUrl() . '/uploads/chat/' . $fileName;

    writeDebugLog("LINEコンテンツ取得保存成功", [
        'messageId' => $messageId,
        'fileName' => $fileName,
        'url' => $publicUrl,
        'size' => strlen($binaryData)
    ]);

    return [
        'success' => true,
        'file_name' => $fileName,
        'file_path' => $filePath,
        'url' => $publicUrl,
        'content_type' => $contentType,
        'size' => strlen($binaryData)
    ];
}

/**
 * LINE公式アカウントの友だち全員へメッセージを一斉送信 (Broadcast API)
 */
function sendLineBroadcastMessage(array $messages, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        writeDebugLog("一斉配信スキップ: LINEアクセストークンが未設定です");
        return ['success' => false, 'error' => 'Token not configured'];
    }

    // 最後のメッセージに未設定ならアカウント業種に応じたクイックリプライを付与
    $lastIdx = count($messages) - 1;
    if ($lastIdx >= 0 && !isset($messages[$lastIdx]['quickReply'])) {
        if (function_exists('getAccountQuickReplyItems')) {
            $qr = getAccountQuickReplyItems($accountKey);
            if (!empty($qr)) {
                $messages[$lastIdx]['quickReply'] = $qr;
            }
        }
    }

    $url = 'https://api.line.me/v2/bot/message/broadcast';
    $payload = [
        'messages' => $messages
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("LINE一斉配信結果", [
        'httpCode' => $httpCode,
        'response' => $res,
        'curlError' => $curlErr
    ]);

    $errorMsg = $curlErr;
    if (empty($errorMsg) && $httpCode !== 200) {
        $json = json_decode((string)$res, true);
        if (is_array($json) && !empty($json['message'])) {
            $errorMsg = $json['message'];
            if (!empty($json['details']) && is_array($json['details'])) {
                $details = [];
                foreach ($json['details'] as $d) {
                    $details[] = (!empty($d['property']) ? $d['property'] . ': ' : '') . ($d['message'] ?? '');
                }
                $errorMsg .= ' [' . implode(', ', $details) . ']';
            }
        } elseif (!empty($res)) {
            $errorMsg = substr((string)$res, 0, 300);
        } else {
            $errorMsg = "HTTPエラー {$httpCode}";
        }
    }

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'response' => $res,
        'error' => $errorMsg
    ];
}

/**
 * LINE Messaging API: リッチメニュー作成 (メタデータ)
 */
function lineCreateRichMenu(array $menuData, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = 'https://api.line.me/v2/bot/richmenu';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode($menuData, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    return [
        'success' => ($httpCode === 200 && !empty($json['richMenuId'])),
        'httpCode' => $httpCode,
        'richMenuId' => $json['richMenuId'] ?? null,
        'error' => $json['message'] ?? $curlErr,
        'raw' => $res
    ];
}

/**
 * LINE Messaging API: リッチメニュー画像アップロード
 */
function lineUploadRichMenuImage(string $richMenuId, string $imageFilePath, string $contentType, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content";
    $imageData = file_get_contents($imageFilePath);
    if ($imageData === false) {
        return ['success' => false, 'error' => '画像ファイル読み込み失敗'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            "Content-Type: {$contentType}",
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => $imageData
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: デフォルトリッチメニュー設定 (友だち全員に適用)
 */
function lineSetDefaultRichMenu(string $richMenuId, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/user/all/richmenu/{$richMenuId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Length: 0'
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineSetDefaultRichMenu結果", [
        'accountKey' => $accountKey,
        'richMenuId' => $richMenuId,
        'httpCode' => $httpCode,
        'response' => $res,
        'curlErr' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: デフォルトリッチメニュー解除
 */
function lineCancelDefaultRichMenu(?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/user/all/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineCancelDefaultRichMenu結果", [
        'accountKey' => $accountKey,
        'httpCode' => $httpCode,
        'response' => $res,
        'curlErr' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: 現在のデフォルトリッチメニューID取得
 */
function lineGetDefaultRichMenuId(?string $accountKey = null): ?string {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/user/all/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $json = json_decode($res, true);
        return $json['richMenuId'] ?? null;
    }
    return null;
}

/**
 * LINE Messaging API: リッチメニュー詳細取得 (LINEサーバー上の実データ)
 */
function lineGetRichMenu(string $richMenuId, ?string $accountKey = null): ?array {
    $token = getLineAccessToken($accountKey);
    if (empty($richMenuId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/richmenu/{$richMenuId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($res, true);
        return is_array($data) ? $data : null;
    }
    return null;
}

/**
 * LINE Messaging API: LINEサーバー上の全リッチメニュー一覧取得
 * GET https://api.line.me/v2/bot/richmenu/list
 */
function lineGetRichMenuList(?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークン未設定', 'richmenus' => []];
    }

    $url = "https://api.line.me/v2/bot/richmenu/list";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        $json = json_decode($res, true);
        return [
            'success' => true,
            'richmenus' => $json['richmenus'] ?? []
        ];
    }

    return [
        'success' => false,
        'httpCode' => $httpCode,
        'error' => $curlErr ?: $res,
        'richmenus' => []
    ];
}

/**
 * LINE Messaging API: リッチメニュー削除
 */
function lineDeleteRichMenu(string $richMenuId, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/richmenu/{$richMenuId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineDeleteRichMenu結果", [
        'accountKey' => $accountKey,
        'richMenuId' => $richMenuId,
        'httpCode' => $httpCode,
        'response' => $res,
        'curlErr' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: リッチメニューエイリアス作成・更新
 */
function lineCreateOrUpdateRichMenuAlias(string $richMenuId, string $aliasId, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    // 1. まず作成を試みる
    $url = 'https://api.line.me/v2/bot/richmenu/alias';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'richMenuId' => $richMenuId,
            'richMenuAliasId' => $aliasId
        ], JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        return ['success' => true, 'action' => 'created', 'aliasId' => $aliasId];
    }

    // 2. 既に存在する場合は更新(UPDATE)
    $updateUrl = "https://api.line.me/v2/bot/richmenu/alias/{$aliasId}";
    $ch = curl_init($updateUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'richMenuId' => $richMenuId
        ], JSON_UNESCAPED_UNICODE)
    ]);
    $resUpdate = curl_exec($ch);
    $httpCodeUpdate = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrUpdate = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCodeUpdate === 200),
        'action' => 'updated',
        'httpCode' => $httpCodeUpdate,
        'error' => $curlErrUpdate ?: ($httpCodeUpdate !== 200 ? $resUpdate : null)
    ];
}

/**
 * LINE Messaging API: リッチメニューエイリアス削除
 */
function lineDeleteRichMenuAlias(string $aliasId, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/richmenu/alias/{$aliasId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: リッチメニューエイリアス一覧取得
 */
function lineGetRichMenuAliasList(?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'aliases' => []];
    }

    $url = 'https://api.line.me/v2/bot/richmenu/alias/list';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $json = json_decode($res, true);
        return ['success' => true, 'aliases' => $json['aliases'] ?? []];
    }
    return ['success' => false, 'aliases' => []];
}

/**
 * LINE Messaging API: リッチメニューの画像バイナリを取得
 * GET https://api-data.line.me/v2/bot/richmenu/{richMenuId}/content
 */
function lineGetRichMenuImage(string $richMenuId, ?string $accountKey = null): ?string {
    $token = getLineAccessToken($accountKey);
    if (empty($richMenuId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        return $res;
    }
    return null;
}

/**
 * LINE Messaging API: ユーザーに現在個別紐付けされているリッチメニューIDを取得
 * GET https://api.line.me/v2/bot/user/{userId}/richmenu
 * @return string|null 個別紐付けリッチメニューID（個別紐付けなし/全体デフォルト表示中の場合はnull）
 */
function lineGetUserRichMenu(string $userId, ?string $accountKey = null): ?string {
    $token = getLineAccessToken($accountKey);
    if (empty($userId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/user/" . urlencode($userId) . "/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        $json = json_decode($res, true);
        return $json['richMenuId'] ?? null;
    }

    // 404等は個別リッチメニューなし（全体デフォルトメニューを表示中）
    return null;
}

/**
 * LINE Messaging API: ユーザーに個別リッチメニューを紐付け
 * POST https://api.line.me/v2/bot/user/{userId}/richmenu/{richMenuId}
 */
function lineLinkUserRichMenu(string $userId, string $richMenuId, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($userId) || empty($richMenuId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => '無効なパラメータまたはアクセストークン未設定'];
    }

    $url = "https://api.line.me/v2/bot/user/{$userId}/richmenu/{$richMenuId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Length: 0'
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineLinkUserRichMenu結果", [
        'accountKey' => $accountKey,
        'userId' => $userId,
        'richMenuId' => $richMenuId,
        'httpCode' => $httpCode,
        'response' => $res
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: ユーザーの個別リッチメニュー紐付けを解除（全体デフォルトメニューに戻す）
 * DELETE https://api.line.me/v2/bot/user/{userId}/richmenu
 */
function lineUnlinkUserRichMenu(string $userId, ?string $accountKey = null): array {
    $token = getLineAccessToken($accountKey);
    if (empty($userId) || empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => '無効なパラメータまたはアクセストークン未設定'];
    }

    $url = "https://api.line.me/v2/bot/user/{$userId}/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineUnlinkUserRichMenu結果", [
        'accountKey' => $accountKey,
        'userId' => $userId,
        'httpCode' => $httpCode,
        'response' => $res
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * データベース接続オブジェクト (PDO) を取得（getDbConnectionのエイリアス）
 */
function getDB(?string $accountKey = null): PDO {
    return getDbConnection($accountKey);
}

/**
 * タグ文字列または配列を正規化してきれいな文字列配列として返却
 */
function normalizeTagList($raw): array {
    if (is_array($raw)) {
        $list = $raw;
    } elseif (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') return [];
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $decoded = json_decode($raw, true);
            $list = is_array($decoded) ? $decoded : [];
        } else {
            $list = preg_split('/[,、\s\n]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
    } else {
        return [];
    }

    $clean = [];
    foreach ($list as $item) {
        $t = trim((string)$item);
        $t = ltrim($t, '#'); // 先頭の # があれば除去して統一
        if ($t !== '' && !in_array($t, $clean, true)) {
            $clean[] = $t;
        }
    }
    return $clean;
}

/**
 * タグ配列をDB保存用JSON文字列に変換
 */
function encodeTagsForDb(array $tags): string {
    $clean = normalizeTagList($tags);
    if (empty($clean)) return '';
    return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE);
}

/**
 * 指定アカウントで使用中の全タグ一覧（利用人数付き）を集計
 */
function getAccountAllTags(?PDO $db = null, ?string $accountKey = null): array {
    if (!$db) {
        $db = getDbConnection($accountKey);
    }
    $tagCounts = [];

    // 1. 顧客テーブルから集計
    try {
        $stmt = $db->query("SELECT tags FROM customer_cars WHERE tags IS NOT NULL AND tags != ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tags = normalizeTagList($row['tags'] ?? '');
            foreach ($tags as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {}

    // 2. リッチメニューテーブルの対象タグからも未集計タグをキーとして追加
    try {
        $stmtMenu = $db->query("SELECT target_tags FROM rich_menus WHERE target_tags IS NOT NULL AND target_tags != ''");
        while ($rowM = $stmtMenu->fetch(PDO::FETCH_ASSOC)) {
            $mTags = normalizeTagList($rowM['target_tags'] ?? '');
            foreach ($mTags as $tag) {
                if (!isset($tagCounts[$tag])) {
                    $tagCounts[$tag] = 0;
                }
            }
        }
    } catch (Throwable $e) {}

    // カウント降順、名前昇順でソート
    uksort($tagCounts, function($a, $b) use ($tagCounts) {
        if ($tagCounts[$a] === $tagCounts[$b]) {
            return strcmp($a, $b);
        }
        return ($tagCounts[$a] > $tagCounts[$b]) ? -1 : 1;
    });

    $result = [];
    foreach ($tagCounts as $name => $count) {
        $result[] = [
            'name' => $name,
            'count' => $count
        ];
    }
    return $result;
}

/**
 * 顧客のタグにマッチするリッチメニューを判定してLINE公式アカウントに自動リンク反映
 * @param string $userId LINE User ID
 * @param string|null $accountKey アカウントキー
 * @param PDO|null $db データベース接続
 * @return array 結果情報
 */
function applyTagBasedRichMenuForUser(string $userId, ?string $accountKey = null, ?PDO $db = null): array {
    if (empty($userId) || !str_starts_with($userId, 'U')) {
        return ['success' => false, 'error' => '無効なユーザーIDです'];
    }
    if (!$db) {
        $db = getDbConnection($accountKey);
    }

    // 顧客情報取得
    $custStmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid LIMIT 1");
    $custStmt->execute([':uid' => $userId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        return ['success' => false, 'error' => '顧客が見つかりません'];
    }

    $userTags = normalizeTagList($customer['tags'] ?? '');
    $hasCustomMessage = !empty($customer['custom_menu_text']);

    // 個別専用メッセージ帯が設定されている場合はタグ自動変更を行わない（メッセージを優先）
    if ($hasCustomMessage) {
        return ['success' => true, 'applied_type' => 'custom_message', 'message' => '個別専用メッセージ設定中のためタグ出し分けはスキップしました'];
    }

    // タグ連動リッチメニューを取得（target_tags が設定されている通常メニュー）
    $menuStmt = $db->query("SELECT * FROM rich_menus WHERE is_notice = 0 AND target_tags IS NOT NULL AND target_tags != '' ORDER BY id DESC");
    $targetMenu = null;
    $matchedTag = null;

    while ($menu = $menuStmt->fetch(PDO::FETCH_ASSOC)) {
        $menuTags = normalizeTagList($menu['target_tags'] ?? '');
        foreach ($userTags as $uTag) {
            if (in_array($uTag, $menuTags, true)) {
                $targetMenu = $menu;
                $matchedTag = $uTag;
                break 2;
            }
        }
    }

    if ($targetMenu) {
        // マッチするタグ連動メニューあり
        $lineMenuId = $targetMenu['line_menu_id'] ?? '';
        
        // LINE上に未登録なら自動登録
        if (empty($lineMenuId)) {
            $areas = json_decode($targetMenu['areas_json'] ?? '[]', true) ?: [];
            $lineAreas = [];
            foreach ($areas as $area) {
                if (isset($area['bounds']) && isset($area['action'])) {
                    $lineAreas[] = [
                        'bounds' => $area['bounds'],
                        'action' => $area['action']
                    ];
                }
            }
            $createRes = lineCreateRichMenu([
                'size' => [
                    'width' => (int)($targetMenu['width'] ?: 2500),
                    'height' => (int)($targetMenu['height'] ?: 1686)
                ],
                'selected' => true,
                'name' => mb_substr($targetMenu['title'] ?: 'タグ連動メニュー', 0, 300),
                'chatBarText' => mb_substr($targetMenu['chatBarText'] ?? ($targetMenu['chat_bar_text'] ?? 'メニュー'), 0, 14),
                'areas' => $lineAreas
            ], $accountKey);

            if (!empty($createRes['richMenuId'])) {
                $lineMenuId = $createRes['richMenuId'];
                $imgRelPath = ltrim(parse_url($targetMenu['image_url'], PHP_URL_PATH) ?: '', '/');
                $localImgPath = __DIR__ . '/' . $imgRelPath;
                if (!file_exists($localImgPath) && file_exists(__DIR__ . '/admin/' . $imgRelPath)) {
                    $localImgPath = __DIR__ . '/admin/' . $imgRelPath;
                }
                if (file_exists($localImgPath)) {
                    lineUploadRichMenuImage($lineMenuId, $localImgPath, mime_content_type($localImgPath) ?: 'image/png', $accountKey);
                }
                $db->prepare("UPDATE rich_menus SET line_menu_id = :lmid WHERE id = :id")->execute([
                    ':lmid' => $lineMenuId,
                    ':id' => $targetMenu['id']
                ]);
            }
        }

        if (!empty($lineMenuId)) {
            $linkRes = lineLinkUserRichMenu($userId, $lineMenuId, $accountKey);
            $nowJst = date('Y-m-d H:i:s');
            $db->prepare("UPDATE customer_cars SET custom_line_menu_id = :lmid, custom_menu_set_at = :now_jst, updated_at = :now_jst WHERE id = :id")->execute([
                ':lmid' => $lineMenuId,
                ':now_jst' => $nowJst,
                ':id' => $customer['id']
            ]);
            return [
                'success' => true,
                'applied_type' => 'tag_menu',
                'matched_tag' => $matchedTag,
                'menu_title' => $targetMenu['title'],
                'line_menu_id' => $lineMenuId
            ];
        }
    } else {
        // マッチするタグメニューがない場合、個別メニュー割り当てを解除（全体デフォルトメニューに戻す）
        if (!empty($customer['custom_line_menu_id'])) {
            lineUnlinkUserRichMenu($userId, $accountKey);
            $nowJst = date('Y-m-d H:i:s');
            $db->prepare("UPDATE customer_cars SET custom_line_menu_id = '', custom_menu_set_at = NULL, updated_at = :now_jst WHERE id = :id")->execute([
                ':now_jst' => $nowJst,
                ':id' => $customer['id']
            ]);
        }
        return [
            'success' => true,
            'applied_type' => 'default',
            'message' => '全体デフォルトメニューを適用しました'
        ];
    }

    return ['success' => false, 'error' => 'メニューのリンク処理に失敗しました'];
}

/**
 * 全顧客に対するタグ連動リッチメニューの一括反映（同期）
 */
function syncAllTagBasedRichMenus(?string $accountKey = null, ?PDO $db = null): array {
    if (!$db) {
        $db = getDbConnection($accountKey);
    }

    $stmt = $db->query("SELECT user_id, user_name, tags, custom_menu_text FROM customer_cars WHERE user_id IS NOT NULL AND user_id LIKE 'U%' AND (is_blocked = 0 OR is_blocked IS NULL)");
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $successCount = 0;
    $tagAppliedCount = 0;
    $defaultCount = 0;
    $skippedCount = 0;
    $errors = [];

    foreach ($allUsers as $u) {
        $uid = $u['user_id'];
        if (!empty($u['custom_menu_text'])) {
            $skippedCount++;
            continue;
        }
        $res = applyTagBasedRichMenuForUser($uid, $accountKey, $db);
        if ($res['success']) {
            $successCount++;
            if (($res['applied_type'] ?? '') === 'tag_menu') {
                $tagAppliedCount++;
            } else {
                $defaultCount++;
            }
        } else {
            $errors[] = "{$u['user_name']}: " . ($res['error'] ?? '不明なエラー');
        }
    }

    return [
        'success' => true,
        'total_processed' => count($allUsers),
        'success_count' => $successCount,
        'tag_applied_count' => $tagAppliedCount,
        'default_count' => $defaultCount,
        'skipped_custom_message' => $skippedCount,
        'errors' => $errors
    ];
}

/**
 * 現在有効なお知らせリッチメニューを取得
 */
function getActiveNoticeRichMenu(?PDO $pdo = null, ?string $accountKey = null): ?array {
    try {
        if (!$pdo) {
            $pdo = getDbConnection($accountKey);
        }
        // 1. is_notice = 1 かつ is_active = 1 のメニュー（明示的アクティブ）
        $stmt = $pdo->query("SELECT * FROM rich_menus WHERE is_notice = 1 AND is_active = 1 ORDER BY id DESC LIMIT 1");
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($menu) return $menu;

        // 2. 最新の is_notice = 1 のメニュー
        $stmt2 = $pdo->query("SELECT * FROM rich_menus WHERE is_notice = 1 ORDER BY id DESC LIMIT 1");
        $menu2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($menu2) return $menu2;

        // 3. タイトルまたはchat_bar_textに「お知らせ」「案内」「キャンペーン」「イベント」が含まれるメニュー
        $stmt3 = $pdo->query("SELECT * FROM rich_menus WHERE (title LIKE '%お知らせ%' OR chat_bar_text LIKE '%お知らせ%' OR title LIKE '%案内%' OR chat_bar_text LIKE '%案内%' OR title LIKE '%キャンペーン%' OR title LIKE '%イベント%') ORDER BY id DESC LIMIT 1");
        $menu3 = $stmt3->fetch(PDO::FETCH_ASSOC);
        if ($menu3) return $menu3;

        // 4. 全体本番（is_active=1）以外の最新メニュー（サブメニュー候補）
        $stmt4 = $pdo->query("SELECT * FROM rich_menus WHERE is_active = 0 ORDER BY id DESC LIMIT 1");
        $menu4 = $stmt4->fetch(PDO::FETCH_ASSOC);
        if ($menu4) return $menu4;

        // 5. 登録されている最新のメニュー
        $stmt5 = $pdo->query("SELECT * FROM rich_menus ORDER BY id DESC LIMIT 1");
        $menu5 = $stmt5->fetch(PDO::FETCH_ASSOC);
        if ($menu5) return $menu5;
    } catch (Throwable $e) {
        writeDebugLog("getActiveNoticeRichMenuエラー: " . $e->getMessage());
    }
    return null;
}

/**
 * Discord通知設定の取得
 */
function getDiscordSettings(?PDO $db = null, ?string $accountKey = null): array {
    $activeKey = $accountKey ?: getActiveAccountKey();
    if (!$db) {
        try {
            $db = getDbConnection($activeKey);
        } catch (Throwable $e) {
            $db = null;
        }
    }

    $default = [
        'webhook_url' => defined('DISCORD_WEBHOOK_URL') ? DISCORD_WEBHOOK_URL : '',
        'enabled' => true,
        'notify_message' => true,
        'notify_follow' => true,
        'notify_inquiry' => true
    ];

    if ($db) {
        try {
            $stmt = $db->prepare("SELECT value FROM system_settings WHERE key = 'discord_settings'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if (!empty($val)) {
                $saved = json_decode($val, true);
                if (is_array($saved)) {
                    return array_merge($default, $saved);
                }
            }
        } catch (Throwable $e) {}
    }

    return $default;
}

/**
 * Discord通知設定の保存
 */
function saveDiscordSettings(array $settings, ?PDO $db = null, ?string $accountKey = null): bool {
    $activeKey = $accountKey ?: getActiveAccountKey();
    if (!$db) {
        try {
            $db = getDbConnection($activeKey);
        } catch (Throwable $e) {
            return false;
        }
    }

    try {
        $nowJst = date('Y-m-d H:i:s');
        $clean = [
            'webhook_url' => trim((string)($settings['webhook_url'] ?? '')),
            'enabled' => !empty($settings['enabled']),
            'notify_message' => !empty($settings['notify_message']),
            'notify_follow' => !empty($settings['notify_follow']),
            'notify_inquiry' => !empty($settings['notify_inquiry']),
            'updated_at' => $nowJst
        ];
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('discord_settings', :val, :now)");
        return $stmt->execute([':val' => $json, ':now' => $nowJst]);
    } catch (Throwable $e) {
        writeDebugLog("saveDiscordSettings エラー", ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * 受講生からのLINE新着メッセージをDiscordへ通知
 */
function sendDiscordChatMessageNotification(array $msgData, ?array $userProfile = null, ?PDO $db = null): bool {
    $discord = getDiscordSettings($db);
    $webhookUrl = trim($discord['webhook_url'] ?? '');

    if (empty($webhookUrl) || empty($discord['enabled']) || empty($discord['notify_message'])) {
        return false;
    }

    $userId = $msgData['user_id'] ?? '';
    $msgText = $msgData['message_text'] ?? '';
    $msgType = $msgData['message_type'] ?? 'text';
    $imageUrl = $msgData['image_url'] ?? '';
    $userName = $msgData['user_name'] ?? '受講生';
    $userAvatar = $msgData['picture_url'] ?? ($userProfile['pictureUrl'] ?? null);

    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'];
    }

    // 表示用テキストの整形
    $displayText = $msgText;
    if ($msgType === 'sticker') {
        $displayText = '🎨 [LINEスタンプを受信しました]';
    } elseif ($msgType === 'image') {
        $displayText = '📷 [画像を受信しました]';
    }

    $nowJst = date('Y-m-d H:i:s');
    $baseUrl = getBaseUrl();
    $adminChatUrl = "{$baseUrl}/admin/index.html?chat_uid=" . urlencode($userId);

    $embed = [
        'title' => '💬 【LINE新着メッセージ】' . $userName . ' 様から連絡が届きました',
        'description' => "```\n" . mb_substr($displayText, 0, 1000) . "\n```",
        'url' => $adminChatUrl,
        'color' => 0x06C755, // LINE Green
        'fields' => [
            [
                'name' => '👤 送信者',
                'value' => "**{$userName} 様** (`{$userId}`)",
                'inline' => true
            ],
            [
                'name' => '🕒 受信日時',
                'value' => $nowJst,
                'inline' => true
            ],
            [
                'name' => '🔗 返信・カルテ確認',
                'value' => "[管理画面を開いて返信する]({$adminChatUrl})",
                'inline' => false
            ]
        ],
        'footer' => [
            'text' => 'LINE受講生・カルテ管理システム | チャット通知'
        ],
        'timestamp' => date('c')
    ];

    if ($userAvatar) {
        $embed['thumbnail'] = ['url' => $userAvatar];
        $embed['author'] = [
            'name' => $userName,
            'icon_url' => $userAvatar
        ];
    }

    if (!empty($imageUrl)) {
        $embed['image'] = ['url' => $imageUrl];
    }

    $payload = [
        'username' => 'LINEチャット通知 Bot',
        'avatar_url' => 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png',
        'embeds' => [$embed]
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Discord Webhook 疎通テスト送信
 */
function sendDiscordTestNotification(string $webhookUrl): array {
    $url = trim($webhookUrl);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => '有効なWebhook URLを入力してください'];
    }

    $embed = [
        'title' => '🔔 【接続テスト成功】Discord通知連携が完了しました',
        'description' => "本システムからのDiscord通知が正常に送信されています。\n今後、受講生からの新着メッセージや友だち追加、予約相談がこのチャンネルに届きます。",
        'color' => 0x4f46e5, // Indigo
        'fields' => [
            [
                'name' => '📡 連携状態',
                'value' => '✅ 正常に疎通中 (200 OK)',
                'inline' => true
            ],
            [
                'name' => '🕒 テスト実行時刻',
                'value' => date('Y-m-d H:i:s'),
                'inline' => true
            ]
        ],
        'footer' => [
            'text' => 'LINE受講生・カルテ管理システム | Discord通知設定'
        ],
        'timestamp' => date('c')
    ];

    $payload = [
        'username' => 'LINE受講生管理 システム通知',
        'embeds' => [$embed]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Discordへのテスト送信に成功しました！チャンネルをご確認ください'];
    }

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $curlErr ?: ($res ?: "HTTPステータス: {$httpCode} が返されました。Webhook URLを確認してください")
    ];
}

/**
 * Slack通知設定の取得
 */
function getSlackSettings(?PDO $db = null, ?string $accountKey = null): array {
    $activeKey = $accountKey ?: getActiveAccountKey();
    if (!$db) {
        try {
            $db = getDbConnection($activeKey);
        } catch (Throwable $e) {
            $db = null;
        }
    }

    $default = [
        'webhook_url' => defined('SLACK_WEBHOOK_URL') ? SLACK_WEBHOOK_URL : '',
        'enabled' => true,
        'notify_message' => true,
        'notify_follow' => true,
        'notify_inquiry' => true
    ];

    if ($db) {
        try {
            $stmt = $db->prepare("SELECT value FROM system_settings WHERE key = 'slack_settings'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if (!empty($val)) {
                $saved = json_decode($val, true);
                if (is_array($saved)) {
                    return array_merge($default, $saved);
                }
            }
        } catch (Throwable $e) {}
    }

    return $default;
}

/**
 * Slack通知設定の保存
 */
function saveSlackSettings(array $settings, ?PDO $db = null, ?string $accountKey = null): bool {
    $activeKey = $accountKey ?: getActiveAccountKey();
    if (!$db) {
        try {
            $db = getDbConnection($activeKey);
        } catch (Throwable $e) {
            return false;
        }
    }

    try {
        $nowJst = date('Y-m-d H:i:s');
        $clean = [
            'webhook_url' => trim((string)($settings['webhook_url'] ?? '')),
            'enabled' => !empty($settings['enabled']),
            'notify_message' => !empty($settings['notify_message']),
            'notify_follow' => !empty($settings['notify_follow']),
            'notify_inquiry' => !empty($settings['notify_inquiry']),
            'updated_at' => $nowJst
        ];
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('slack_settings', :val, :now)");
        return $stmt->execute([':val' => $json, ':now' => $nowJst]);
    } catch (Throwable $e) {
        writeDebugLog("saveSlackSettings エラー", ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * 受講生からのLINE新着メッセージをSlackへ通知
 */
function sendSlackChatMessageNotification(array $msgData, ?array $userProfile = null, ?PDO $db = null): bool {
    $slack = getSlackSettings($db);
    $webhookUrl = trim($slack['webhook_url'] ?? '');

    if (empty($webhookUrl) || empty($slack['enabled']) || empty($slack['notify_message'])) {
        return false;
    }

    $userId = $msgData['user_id'] ?? '';
    $msgText = $msgData['message_text'] ?? '';
    $msgType = $msgData['message_type'] ?? 'text';
    $userName = $msgData['user_name'] ?? '';
    $userAvatar = $msgData['picture_url'] ?? ($userProfile['pictureUrl'] ?? '');

    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'];
    }

    // アイコンや名前が未設定または初期値の場合はLINE公式APIからプロフィールを直接取得
    if ((empty($userAvatar) || empty($userName) || $userName === '受講生' || $userName === '新規顧客') && !empty($userId) && str_starts_with($userId, 'U')) {
        $fetchedProf = getLineUserProfile($userId);
        if ($fetchedProf) {
            if (!empty($fetchedProf['displayName'])) {
                $userName = $fetchedProf['displayName'];
            }
            if (!empty($fetchedProf['pictureUrl'])) {
                $userAvatar = $fetchedProf['pictureUrl'];
            }
        }
    }

    if (empty($userName)) {
        $userName = 'LINE受講生';
    }
    if (empty($userAvatar)) {
        $userAvatar = 'https://cdn-icons-png.flaticon.com/512/3670/3670089.png'; // 高画質LINEロゴ
    }

    // 表示用テキストの整形
    $displayText = $msgText;
    if ($msgType === 'sticker') {
        $displayText = '🎨 [LINEスタンプを受信しました]';
    } elseif ($msgType === 'image') {
        $displayText = '📷 [画像を受信しました]';
    }

    $payload = [
        'username' => $userName,
        'icon_url' => $userAvatar,
        'text' => "{$userName}: {$displayText}"
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * 新規友だち追加（フォロー時）のSlack通知
 */
function sendSlackFollowNotification(array $followData, ?array $userProfile = null, ?PDO $db = null): bool {
    $slack = getSlackSettings($db);
    $webhookUrl = trim($slack['webhook_url'] ?? '');

    if (empty($webhookUrl) || empty($slack['enabled']) || empty($slack['notify_follow'])) {
        return false;
    }

    $userId = $followData['user_id'] ?? '';
    $userName = $followData['user_name'] ?? '';
    $userAvatar = $followData['picture_url'] ?? ($userProfile['pictureUrl'] ?? '');

    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'];
    }

    if ((empty($userAvatar) || empty($userName) || $userName === '受講生') && !empty($userId) && str_starts_with($userId, 'U')) {
        $fetchedProf = getLineUserProfile($userId);
        if ($fetchedProf) {
            if (!empty($fetchedProf['displayName'])) {
                $userName = $fetchedProf['displayName'];
            }
            if (!empty($fetchedProf['pictureUrl'])) {
                $userAvatar = $fetchedProf['pictureUrl'];
            }
        }
    }

    if (empty($userName)) {
        $userName = '新規受講生';
    }
    if (empty($userAvatar)) {
        $userAvatar = 'https://cdn-icons-png.flaticon.com/512/3670/3670089.png';
    }

    $eventText = $followData['event_text'] ?? '新しいユーザーが追加されました！';

    $payload = [
        'username' => $userName,
        'icon_url' => $userAvatar,
        'text' => "{$userName}: {$eventText}"
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * 予約・相談リクエストのSlack通知
 */
function sendSlackInquiryNotification(array $car, string $inquiryType, ?array $userProfile = null, ?string $rawUserId = null, ?PDO $db = null): bool {
    $slack = getSlackSettings($db);
    $webhookUrl = trim($slack['webhook_url'] ?? '');

    if (empty($webhookUrl) || empty($slack['enabled']) || empty($slack['notify_consultation'])) {
        return false;
    }

    $userId = $rawUserId ?: ($car['user_id'] ?? '');
    $userName = $car['user_name'] ?? '';
    $userAvatar = $userProfile['pictureUrl'] ?? '';

    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'];
    }

    if ((empty($userAvatar) || empty($userName) || $userName === '受講生') && !empty($userId) && str_starts_with($userId, 'U')) {
        $fetchedProf = getLineUserProfile($userId);
        if ($fetchedProf) {
            if (!empty($fetchedProf['displayName'])) {
                $userName = $fetchedProf['displayName'];
            }
            if (!empty($fetchedProf['pictureUrl'])) {
                $userAvatar = $fetchedProf['pictureUrl'];
            }
        }
    }

    if (empty($userName)) {
        $userName = 'LINE受講生';
    }
    if (empty($userAvatar)) {
        $userAvatar = 'https://cdn-icons-png.flaticon.com/512/3670/3670089.png';
    }

    $payload = [
        'username' => $userName,
        'icon_url' => $userAvatar,
        'text' => "{$userName}: 【予約・相談リクエスト】{$inquiryType}"
    ];

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Slack Webhook 疎通テスト送信
 */
function sendSlackTestNotification(string $webhookUrl): array {
    $url = trim($webhookUrl);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => '有効なWebhook URLを入力してください'];
    }

    $nowJst = date('H:i');

    $payload = [
        'username' => 'LINE受講生通知',
        'icon_url' => 'https://cdn-icons-png.flaticon.com/512/3670/3670089.png',
        'text' => "LINE受講生通知: Slack通知連携テストが成功しました！（{$nowJst}）"
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Slackへのテスト送信に成功しました！チャンネルをご確認ください'];
    }

    return [
        'success' => false,
        'http_code' => $httpCode,
        'error' => $curlErr ?: ($res ?: "HTTPステータス: {$httpCode} が返されました。Webhook URLを確認してください")
    ];
}

/* ==============================================================================
   管理者 メール二段階認証 (2FA / OTP) 関連関数
   ============================================================================== */

/**
 * メールアドレスのマスク表示 (例: kawai@kureba.co.jp -> k****@kureba.co.jp)
 */
function maskEmailAddress(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return '******';
    $name = $parts[0];
    $domain = $parts[1];
    $len = mb_strlen($name);
    if ($len <= 2) {
        $masked = mb_substr($name, 0, 1) . '***';
    } else {
        $masked = mb_substr($name, 0, 1) . str_repeat('*', max(3, $len - 1));
    }
    return $masked . '@' . $domain;
}

/**
 * 2FA設定の取得 (DBのsystem_settingsから取得、未設定時はconfig.phpの定数を使用)
 */
function getAdmin2FASettings(?PDO $db = null): array {
    $default = [
        'enabled' => defined('ENABLE_ADMIN_2FA') ? (bool)ENABLE_ADMIN_2FA : false,
        'email' => defined('ADMIN_2FA_EMAIL') ? (string)ADMIN_2FA_EMAIL : 'kawai@kureba.co.jp',
        'lifetime_minutes' => defined('ADMIN_2FA_CODE_LIFETIME_MINUTES') ? (int)ADMIN_2FA_CODE_LIFETIME_MINUTES : 10,
        'max_attempts' => defined('ADMIN_2FA_MAX_ATTEMPTS') ? (int)ADMIN_2FA_MAX_ATTEMPTS : 5,
        'updated_at' => null
    ];

    if (!$db) {
        try {
            $db = getDbConnection('senior');
        } catch (Throwable $e) {
            return $default;
        }
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)");
        $stmt = $db->prepare("SELECT value FROM system_settings WHERE key = 'admin_2fa_settings' LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val) {
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                return [
                    'enabled' => filter_var($decoded['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'email' => !empty($decoded['email']) ? trim($decoded['email']) : $default['email'],
                    'lifetime_minutes' => !empty($decoded['lifetime_minutes']) ? max(1, (int)$decoded['lifetime_minutes']) : $default['lifetime_minutes'],
                    'max_attempts' => !empty($decoded['max_attempts']) ? max(1, (int)$decoded['max_attempts']) : $default['max_attempts'],
                    'updated_at' => $decoded['updated_at'] ?? null
                ];
            }
        }
    } catch (Throwable $e) {
        writeDebugLog("getAdmin2FASettings エラー", ['error' => $e->getMessage()]);
    }

    return $default;
}

/**
 * 2FA設定の保存
 */
function saveAdmin2FASettings(array $settings, ?PDO $db = null): bool {
    if (!$db) {
        try {
            $db = getDbConnection('senior');
        } catch (Throwable $e) {
            return false;
        }
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)");
        $nowJst = date('Y-m-d H:i:s');
        $clean = [
            'enabled' => filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'email' => trim((string)($settings['email'] ?? 'kawai@kureba.co.jp')),
            'lifetime_minutes' => max(1, min(60, (int)($settings['lifetime_minutes'] ?? 10))),
            'max_attempts' => max(1, min(20, (int)($settings['max_attempts'] ?? 5))),
            'updated_at' => $nowJst
        ];
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('admin_2fa_settings', :val, :now)");
        return $stmt->execute([':val' => $json, ':now' => $nowJst]);
    } catch (Throwable $e) {
        writeDebugLog("saveAdmin2FASettings エラー", ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * 2FA 6桁認証コードメールを送信
 */
function sendAdmin2FACodeEmail(string $toEmail, string $otpCode, int $expiresMinutes = 10): bool {
    $subject = "【シニア向けパソコン教室】管理画面 ログイン認証コード: {$otpCode}";
    $nowJst = date('Y-m-d H:i');
    $expireTime = date('H:i', strtotime("+{$expiresMinutes} minutes"));

    $body = "シニア向けパソコン教室 LINE受講生・カルテ管理システムへのログイン要求を受け付けました。\n\n";
    $body .= "以下の6桁の認証コードを入力して、ログインを完了してください。\n\n";
    $body .= "========================================\n";
    $body .= "  二段階認証コード: {$otpCode}\n";
    $body .= "  有効期限: {$expiresMinutes}分間（{$expireTime} まで）\n";
    $body .= "========================================\n\n";
    $body .= "※このコードは第三者に絶対に教えないでください。\n";
    $body .= "※ログイン試行日時: {$nowJst} (JST)\n";
    $body .= "※ご自身でログインを試みた覚えがない場合は、第三者が不正アクセスを試みた可能性があります。速やかに管理者パスワードをご確認・ご変更ください。\n\n";
    $body .= "----------------------------------------\n";
    $body .= "シニア向けパソコン教室 LINE受講生管理システム\n";

    // 送信元ヘッダー設定
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/^www\./', '', $host);
    if (!str_contains($fromDomain, '.') || $fromDomain === 'localhost') {
        $fromDomain = 'kureba.co.jp';
    }
    $fromEmail = "noreply@" . $fromDomain;

    mb_language("Japanese");
    mb_internal_encoding("UTF-8");

    $headers = [
        "From: =?UTF-8?B?" . base64_encode("受講生管理システム 認証通知") . "?= <{$fromEmail}>",
        "Reply-To: {$fromEmail}",
        "X-Mailer: PHP/" . phpversion(),
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit"
    ];
    $headerStr = implode("\r\n", $headers);

    $sent = @mb_send_mail($toEmail, $subject, $body, $headerStr);
    if (!$sent) {
        $sent = @mail($toEmail, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headerStr);
    }

    writeDebugLog("2FA認証コードメール送信結果", [
        'to' => $toEmail,
        'sent' => (bool)$sent,
        'code' => $otpCode
    ]);

    return (bool)$sent;
}

/**
 * 2FAテストメール送信
 */
function sendAdmin2FATestEmail(string $toEmail): array {
    $testCode = sprintf('%06d', random_int(100000, 999999));
    $subject = "【テスト通知】シニア向けパソコン教室 2FA二段階認証テスト: {$testCode}";
    $nowJst = date('Y-m-d H:i:s');

    $body = "シニア向けパソコン教室 LINE受講生・カルテ管理システムの2FAメール疎通テストです。\n\n";
    $body .= "このメールが届いた場合、メール送信サーバーとメールアドレスの設定は正常です。\n\n";
    $body .= "========================================\n";
    $body .= "  テスト認証コード: {$testCode}\n";
    $body .= "  送信日時: {$nowJst} (JST)\n";
    $body .= "  送信先: {$toEmail}\n";
    $body .= "========================================\n\n";
    $body .= "二段階認証を有効化すると、次回以降のログイン時に上記のような認証コードが届きます。\n\n";
    $body .= "----------------------------------------\n";
    $body .= "シニア向けパソコン教室 LINE受講生管理システム\n";

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/^www\./', '', $host);
    if (!str_contains($fromDomain, '.') || $fromDomain === 'localhost') {
        $fromDomain = 'kureba.co.jp';
    }
    $fromEmail = "noreply@" . $fromDomain;

    mb_language("Japanese");
    mb_internal_encoding("UTF-8");

    $headers = [
        "From: =?UTF-8?B?" . base64_encode("受講生管理システム 2FAテスト") . "?= <{$fromEmail}>",
        "Reply-To: {$fromEmail}",
        "X-Mailer: PHP/" . phpversion(),
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit"
    ];
    $headerStr = implode("\r\n", $headers);

    $sent = @mb_send_mail($toEmail, $subject, $body, $headerStr);
    if (!$sent) {
        $sent = @mail($toEmail, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headerStr);
    }

    return [
        'success' => (bool)$sent,
        'message' => $sent ? "テストメールを {$toEmail} 宛てに正常送信しました！受信ボックスをご確認ください。" : "メール送信に失敗しました。サーバーのmail/sendmail機能またはメールアドレスをご確認ください。"
    ];
}

/**
 * 2FAセッションを作成し、メールを送信
 */
function createAdmin2FASession(PDO $db, ?string $email = null): array {
    $twoFa = getAdmin2FASettings($db);
    $targetEmail = $email ?: ($twoFa['email'] ?? 'kawai@kureba.co.jp');
    $lifetime = (int)($twoFa['lifetime_minutes'] ?? 10);

    $otpCode = sprintf('%06d', random_int(100000, 999999));
    $sessionToken = bin2hex(random_bytes(24));
    $nowJst = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$lifetime} minutes"));

    // 古い期限切れセッションのクリーンアップ
    try {
        $db->exec("DELETE FROM admin_2fa_sessions WHERE expires_at < datetime('now', '+9 hours')");
    } catch (Exception $e) {}

    $stmt = $db->prepare("
        INSERT INTO admin_2fa_sessions (
            session_token, email, otp_code, attempts, is_verified, last_sent_at, expires_at, created_at
        ) VALUES (
            :token, :email, :code, 0, 0, :now, :expires, :now
        )
    ");
    $stmt->execute([
        ':token' => $sessionToken,
        ':email' => $targetEmail,
        ':code' => $otpCode,
        ':now' => $nowJst,
        ':expires' => $expiresAt
    ]);

    // メール送信
    $mailSent = sendAdmin2FACodeEmail($targetEmail, $otpCode, $lifetime);

    return [
        'success' => true,
        'session_token' => $sessionToken,
        'email_hint' => maskEmailAddress($targetEmail),
        'expires_in' => $lifetime * 60,
        'mail_sent' => $mailSent
    ];
}

/**
 * 2FA認証コードの再送信
 */
function resendAdmin2FACode(PDO $db, string $sessionToken): array {
    $stmt = $db->prepare("SELECT * FROM admin_2fa_sessions WHERE session_token = :token AND is_verified = 0 LIMIT 1");
    $stmt->execute([':token' => $sessionToken]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['success' => false, 'error' => '認証セッションが見つかりません。最初からログインをお試しください。'];
    }

    $nowTs = time();
    $lastSentTs = strtotime($row['last_sent_at']);
    if (($nowTs - $lastSentTs) < 60) {
        $waitSec = 60 - ($nowTs - $lastSentTs);
        return ['success' => false, 'error' => "認証コードの再送信は {$waitSec} 秒後に可能です。"];
    }

    $twoFa = getAdmin2FASettings($db);
    $lifetime = (int)($twoFa['lifetime_minutes'] ?? 10);
    $newCode = sprintf('%06d', random_int(100000, 999999));
    $nowJst = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$lifetime} minutes"));

    $upStmt = $db->prepare("
        UPDATE admin_2fa_sessions 
        SET otp_code = :code, attempts = 0, last_sent_at = :now, expires_at = :expires 
        WHERE id = :id
    ");
    $upStmt->execute([
        ':code' => $newCode,
        ':now' => $nowJst,
        ':expires' => $expiresAt,
        ':id' => $row['id']
    ]);

    $mailSent = sendAdmin2FACodeEmail($row['email'], $newCode, $lifetime);

    return [
        'success' => true,
        'message' => '新しい認証コードをメール送信しました！',
        'email_hint' => maskEmailAddress($row['email']),
        'expires_in' => $lifetime * 60,
        'mail_sent' => $mailSent
    ];
}

/**
 * 2FAコードの照合検証
 */
function verifyAdmin2FACode(PDO $db, string $sessionToken, string $inputCode): array {
    $stmt = $db->prepare("SELECT * FROM admin_2fa_sessions WHERE session_token = :token LIMIT 1");
    $stmt->execute([':token' => $sessionToken]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['success' => false, 'error' => '認証セッションが見つかりません。最初からログインをお試しください。'];
    }

    if ($row['is_verified'] == 1) {
        return ['success' => false, 'error' => 'この認証コードはすでに使用済みです。'];
    }

    $nowJst = date('Y-m-d H:i:s');
    if ($nowJst > $row['expires_at']) {
        return ['success' => false, 'error' => '認証コードの有効期限（10分間）が切れています。「再送信」を行ってください。'];
    }

    $twoFa = getAdmin2FASettings($db);
    $maxAttempts = (int)($twoFa['max_attempts'] ?? 5);
    if ($row['attempts'] >= $maxAttempts) {
        return ['success' => false, 'error' => '認証試行回数の上限（5回）を超えました。最初からログインをやり直してください。'];
    }

    $inputClean = trim(preg_replace('/[^0-9]/', '', $inputCode));
    if ($inputClean !== $row['otp_code']) {
        $newAttempts = $row['attempts'] + 1;
        $db->prepare("UPDATE admin_2fa_sessions SET attempts = :att WHERE id = :id")->execute([':att' => $newAttempts, ':id' => $row['id']]);
        $remaining = $maxAttempts - $newAttempts;
        return [
            'success' => false,
            'error' => "認証コードが正しくありません。（残り {$remaining} 回試行可能）"
        ];
    }

    // 認証成功フラグを立てる
    $db->prepare("UPDATE admin_2fa_sessions SET is_verified = 1 WHERE id = :id")->execute([':id' => $row['id']]);

    // 永続認証トークン（30日間有効）を発行
    $authToken = createAdminAuthToken($db);

    return [
        'success' => true,
        'auth_token' => $authToken,
        'message' => '二段階認証に成功しました！'
    ];
}

/**
 * 永続認証トークンを発行 (30日間有効)
 */
function createAdminAuthToken(PDO $db): string {
    $token = bin2hex(random_bytes(32));
    $nowJst = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $db->prepare("
        INSERT INTO admin_auth_tokens (token, ip_address, user_agent, expires_at, created_at, last_used_at)
        VALUES (:token, :ip, :ua, :expires, :now, :now)
    ");
    $stmt->execute([
        ':token' => $token,
        ':ip' => $ip,
        ':ua' => $ua,
        ':expires' => $expiresAt,
        ':now' => $nowJst
    ]);

    return $token;
}

/**
 * 認証トークンが有効か検証
 */
function isValidAdminAuthToken(PDO $db, string $token): bool {
    if (empty($token)) return false;
    try {
        $stmt = $db->prepare("SELECT id, expires_at FROM admin_auth_tokens WHERE token = :t LIMIT 1");
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if (!$row) return false;

        $nowJst = date('Y-m-d H:i:s');
        if ($nowJst > $row['expires_at']) {
            return false;
        }

        // last_used_at を更新
        try {
            $db->prepare("UPDATE admin_auth_tokens SET last_used_at = :now WHERE id = :id")->execute([':now' => $nowJst, ':id' => $row['id']]);
        } catch (Throwable $e) {}

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 認証トークンを破棄 (ログアウト)
 */
function revokeAdminAuthToken(PDO $db, string $token): bool {
    if (empty($token)) return true;
    $stmt = $db->prepare("DELETE FROM admin_auth_tokens WHERE token = :t");
    return $stmt->execute([':t' => $token]);
}

/**
 * Discord Webhook へのリッチ埋め込み通知送信 (ユーザー閲覧)
 */
function sendDiscordNotification(array $car, string $source = 'LINE Flex Message', ?array $userProfile = null, ?string $rawUserId = null) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $title = $car['title'] ?? '車両詳細閲覧';
    $totalPrice = $car['total_price_text'] ?? '要問合せ';
    $basePrice = $car['base_price_text'] ?? '-';
    $year = $car['year'] ?? '-';
    $distance = $car['distance'] ?? '-';
    $repair = $car['repair_history'] ?? '-';
    $shaken = $car['shaken'] ?? '-';
    $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;
    $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';

    // ユーザー情報
    $userName = 'LINEユーザー (匿名 / 不明)';
    $userAvatar = null;
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
        if (!empty($userProfile['pictureUrl'])) {
            $userAvatar = $userProfile['pictureUrl'];
        }
    } elseif (!empty($rawUserId)) {
        $userName = "ユーザー (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $fields = [
        [
            'name' => '👤 閲覧ユーザー',
            'value' => "**{$userName}**",
            'inline' => false
        ],
        [
            'name' => '💰 支払総額',
            'value' => "**{$totalPrice}** (本体: {$basePrice})",
            'inline' => true
        ],
        [
            'name' => '📅 年式',
            'value' => $year,
            'inline' => true
        ],
        [
            'name' => '🚗 走行距離',
            'value' => $distance,
            'inline' => true
        ],
        [
            'name' => '🛠 修復歴',
            'value' => $repair,
            'inline' => true
        ],
        [
            'name' => '📋 車検',
            'value' => $shaken,
            'inline' => true
        ],
        [
            'name' => '📱 流入元',
            'value' => $source,
            'inline' => true
        ]
    ];

    $embed = [
        'title' => '👀 車両詳細ページへのアクセスがありました！',
        'description' => "**[{$title}]({$detailUrl})**",
        'url' => $detailUrl,
        'color' => 0x06C755, // LINE Green
        'fields' => $fields,
        'thumbnail' => [
            'url' => $imgUrl
        ],
        'footer' => [
            'text' => 'アップファーレン LINE公式 在庫検索システム',
            'icon_url' => 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg'
        ],
        'timestamp' => date('c')
    ];

    if ($userAvatar) {
        $embed['author'] = [
            'name' => $userName,
            'icon_url' => $userAvatar
        ];
    }

    $payload = [
        'username' => 'LINE車両検索 Bot',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * ユーザーからの正式な車両問い合わせ時の Discord 通知
 */
function sendDiscordInquiryNotification(array $car, string $inquiryType, ?array $userProfile = null, ?string $rawUserId = null) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $title = $car['title'] ?? '車両問い合わせ';
    $totalPrice = $car['total_price_text'] ?? '要問合せ';
    $year = $car['year'] ?? '-';
    $distance = $car['distance'] ?? '-';
    $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;
    $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';

    $userName = 'LINEユーザー (匿名 / 不明)';
    $userAvatar = null;
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
        if (!empty($userProfile['pictureUrl'])) {
            $userAvatar = $userProfile['pictureUrl'];
        }
    } elseif (!empty($rawUserId)) {
        $userName = "ユーザー (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $embed = [
        'title' => "📩 【お問い合わせ】{$inquiryType}の依頼が届きました！",
        'description' => "**[{$title}]({$detailUrl})**\n\nお客様から正式なお問い合わせがありました。LINE公式アカウントのチャット等でご確認ください。",
        'url' => $detailUrl,
        'color' => 0xFF0055,
        'fields' => [
            ['name' => '👤 お問い合わせ者', 'value' => "**{$userName}**", 'inline' => false],
            ['name' => '📝 ご希望内容', 'value' => "🎯 **{$inquiryType}**", 'inline' => true],
            ['name' => '💰 支払総額', 'value' => "**{$totalPrice}**", 'inline' => true],
            ['name' => '📅 年式 / 走行', 'value' => "{$year} / {$distance}", 'inline' => true],
            ['name' => '🔗 車両詳細', 'value' => "[グーネットで見る]({$detailUrl})", 'inline' => true]
        ],
        'thumbnail' => ['url' => $imgUrl],
        'footer' => [
            'text' => 'アップファーレン LINE公式 問い合わせ通知',
            'icon_url' => 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg'
        ],
        'timestamp' => date('c')
    ];

    if ($userAvatar) {
        $embed['author'] = ['name' => $userName, 'icon_url' => $userAvatar];
    }

    $payload = [
        'username' => 'LINE公式 問い合わせ通知',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'content' => "🚨 **【真剣度高】車両のお問い合わせが届きました！**",
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);

    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineInquiryNotification($car, $inquiryType, $userProfile, $rawUserId);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineInquiryNotification失敗", ['error' => $e->getMessage()]);
    }
}

/**
 * ユーザーからのオイル交換・車検来店予約時の Discord 通知
 */
function sendDiscordMaintenanceBookingNotification(string $bookingType, string $carModel, string $prefTime, ?array $userProfile = null, ?string $rawUserId = null) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $userName = 'LINEユーザー (匿名 / 不明)';
    $userAvatar = null;
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
        if (!empty($userProfile['pictureUrl'])) {
            $userAvatar = $userProfile['pictureUrl'];
        }
    } elseif (!empty($rawUserId)) {
        $userName = "ユーザー (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    if ($bookingType === 'オイル交換') {
        $color = 0xF59E0B;
    } elseif (str_contains($bookingType, '点検') || str_contains($bookingType, '定期')) {
        $color = 0x10B981;
    } else {
        $color = 0x3B82F6;
    }

    $embed = [
        'title' => "🛠️ 【来店予約】{$bookingType}の予約申し込みが届きました！",
        'description' => "**{$userName}** より愛車 **【{$carModel}】** の {$bookingType} 予約相談が届きました。\nLINE公式アカウントのチャット等で日程のご案内をお願いいたします。",
        'color' => $color,
        'fields' => [
            ['name' => '👤 お客様名', 'value' => "**{$userName}**", 'inline' => true],
            ['name' => '🚗 対象愛車', 'value' => "**{$carModel}**", 'inline' => true],
            ['name' => '📅 ご希望日程・時間帯', 'value' => "🎯 **{$prefTime}**", 'inline' => false]
        ],
        'footer' => [
            'text' => 'アップファーレン メンテナンス予約通知',
            'icon_url' => 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg'
        ],
        'timestamp' => date('c')
    ];

    if ($userAvatar) {
        $embed['author'] = ['name' => $userName, 'icon_url' => $userAvatar];
    }

    $payload = [
        'username' => 'LINEメンテナンス 予約受付',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'content' => "🚨 **【来店予約】{$bookingType}のお申し込みがありました！**",
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);

    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineMaintenanceBookingNotification($bookingType, $carModel, $prefTime, $userProfile, $rawUserId);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineMaintenanceBookingNotification失敗", ['error' => $e->getMessage()]);
    }
}

/**
 * 新着車両検知時の Discord 通知
 */
function sendDiscordNewCarsNotification(array $newCars) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $count = count($newCars);
    $embeds = [];

    $displayCars = array_slice($newCars, 0, 4);
    foreach ($displayCars as $car) {
        $title = $car['title'] ?? '新着車両';
        $totalPrice = $car['total_price_text'] ?? '要問合せ';
        $year = $car['year'] ?? '-';
        $distance = $car['distance'] ?? '-';
        $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;
        $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';

        $embeds[] = [
            'title' => "🆕 新着入荷: {$totalPrice}",
            'description' => "**[{$title}]({$detailUrl})**\n年式: {$year} | 走行: {$distance}",
            'url' => $detailUrl,
            'color' => 0xFF9900,
            'thumbnail' => ['url' => $imgUrl]
        ];
    }

    $payload = [
        'username' => 'LINE在庫更新 通知',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'content' => "🚗✨ **【新着在庫情報】** グーネットに新しい車両が **{$count}台** 掲載されました！",
        'embeds' => $embeds
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);

    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineNewCarsNotification($newCars);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineNewCarsNotification失敗", ['error' => $e->getMessage()]);
    }
}

/**
 * リマインド定期配信実行結果の Discord レポート
 */
function sendDiscordReminderReport(int $oilCount, int $periodicCount, int $shakenCount, array $details = []) {
    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineReminderReport($oilCount, $periodicCount, $shakenCount, $details);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineReminderReport失敗", ['error' => $e->getMessage()]);
    }

    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $total = $oilCount + $periodicCount + $shakenCount;
    if ($total === 0) return;

    $descLines = [];
    foreach ($details as $d) {
        $descLines[] = "• **{$d['name']}** 様 (愛車: {$d['car']}) ➡ **{$d['type']}** [予定: {$d['date']}]";
    }

    $embed = [
        'title' => "⏰ 【定期配信】本日 {$total} 名様へメンテナンス通知を送信しました",
        'description' => implode("\n", array_slice($descLines, 0, 10)),
        'color' => 0x3B82F6,
        'fields' => [
            ['name' => '🛢 オイル交換', 'value' => "{$oilCount} 件", 'inline' => true],
            ['name' => '📋 12ヶ月定期点検', 'value' => "{$periodicCount} 件", 'inline' => true],
            ['name' => '🚗 車検満了', 'value' => "{$shakenCount} 件", 'inline' => true]
        ],
        'footer' => ['text' => 'アップファーレン メンテナンス自動リマインドシステム'],
        'timestamp' => date('c')
    ];

    $payload = [
        'username' => 'メンテリマインド 自動配信',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * マイカー点検パスポートを開いた新規ユーザーの Discord 通知
 */
function sendDiscordNewCustomerNotification(string $userId, string $userName) {
    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineNewCustomerNotification($userId, $userName);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineNewCustomerNotification失敗", ['error' => $e->getMessage()]);
    }

    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $displayName = $userName ?: '名称未設定のお客様';

    $embed = [
        'title' => "🆕 新規ユーザー追加: マイカー点検パスポート開始",
        'description' => "**{$displayName}** 様がマイカー点検パスポートを開き、LINE連携が完了しました。\n管理画面からこのお客様の車両情報や車検日を入力できるようになりました。",
        'color' => 0x10B981, // Green
        'fields' => [
            ['name' => 'ユーザー名', 'value' => $displayName, 'inline' => true],
            ['name' => 'LINE ID', 'value' => "`{$userId}`", 'inline' => true]
        ],
        'footer' => ['text' => 'アップファーレン 顧客管理システム'],
        'timestamp' => date('c')
    ];

    $payload = [
        'username' => '顧客管理・新着通知',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * デバッグログの出力
 */
function writeDebugLog(string $message, array $context = []) {
    $logFile = __DIR__ . '/webhook_debug.log';
    $time = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $logLine = "[{$time}] {$message}{$contextStr}\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

// ==========================================
// 管理者向けLINE通知エンジン
// ==========================================

/**
 * 管理者LINE通知設定を取得
 */
function getAdminLineSettings(?PDO $pdo = null): array {
    if (!$pdo) {
        $pdo = getDbConnection();
    }
    $defaults = [
        'admin_uids' => [],
        'notify_chat' => true,
        'notify_follow' => true,
        'notify_inquiry' => true,
        'notify_booking' => true,
        'notify_new_customer' => true,
        'notify_new_cars' => false,
        'notify_reminder' => true
    ];
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME
            )
        ");
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'admin_line_settings' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['value'])) {
            $saved = json_decode($row['value'], true);
            if (is_array($saved)) {
                return array_merge($defaults, $saved);
            }
        }
    } catch (Throwable $e) {
        writeDebugLog("getAdminLineSettings例外", ['error' => $e->getMessage()]);
    }
    return $defaults;
}

/**
 * 管理者LINE通知設定を保存
 */
function saveAdminLineSettings(array $settings, ?PDO $pdo = null): array {
    if (!$pdo) {
        $pdo = getDbConnection();
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME
            )
        ");

        $cleanUids = [];
        if (!empty($settings['admin_uids']) && is_array($settings['admin_uids'])) {
            foreach ($settings['admin_uids'] as $uid) {
                $uid = trim($uid);
                if (!empty($uid) && str_starts_with($uid, 'U') && !in_array($uid, $cleanUids)) {
                    $cleanUids[] = $uid;
                }
            }
        }
        $dataToSave = [
            'admin_uids' => $cleanUids,
            'notify_chat' => isset($settings['notify_chat']) ? (bool)$settings['notify_chat'] : true,
            'notify_follow' => isset($settings['notify_follow']) ? (bool)$settings['notify_follow'] : true,
            'notify_inquiry' => isset($settings['notify_inquiry']) ? (bool)$settings['notify_inquiry'] : true,
            'notify_booking' => isset($settings['notify_booking']) ? (bool)$settings['notify_booking'] : true,
            'notify_new_customer' => isset($settings['notify_new_customer']) ? (bool)$settings['notify_new_customer'] : true,
            'notify_new_cars' => isset($settings['notify_new_cars']) ? (bool)$settings['notify_new_cars'] : false,
            'notify_reminder' => isset($settings['notify_reminder']) ? (bool)$settings['notify_reminder'] : true,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $json = json_encode($dataToSave, JSON_UNESCAPED_UNICODE);

        // 古いSQLiteでも100%確実に動作する INSERT OR REPLACE
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO system_settings (key, value, updated_at) 
            VALUES ('admin_line_settings', :val, datetime('now', '+9 hours'))
        ");
        $ok = $stmt->execute([':val' => $json]);
        if ($ok) {
            return ['success' => true];
        } else {
            $err = $stmt->errorInfo();
            writeDebugLog("saveAdminLineSettings失敗", ['error' => $err]);
            return ['success' => false, 'error' => $err[2] ?? 'DB保存に失敗しました'];
        }
    } catch (Throwable $e) {
        writeDebugLog("saveAdminLineSettings例外", ['error' => $e->getMessage()]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 登録されている全管理者へLINEメッセージをPush送信
 */
function sendAdminLineBroadcast(array $messages, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    $adminUids = $settings['admin_uids'] ?? [];
    if (empty($adminUids)) {
        writeDebugLog("管理者LINE通知スキップ: 管理者UID未登録");
        return ['success' => false, 'sent_count' => 0, 'error' => '管理者LINEアカウントが登録されていません'];
    }

    $results = [];
    $successCount = 0;
    foreach ($adminUids as $uid) {
        $res = sendLinePushMessage($uid, $messages);
        $results[$uid] = $res;
        if (!empty($res['success'])) {
            $successCount++;
        }
    }

    writeDebugLog("管理者LINE通知送信結果", [
        'total' => count($adminUids),
        'success' => $successCount,
        'results' => $results
    ]);

    return [
        'success' => ($successCount > 0),
        'sent_count' => $successCount,
        'total' => count($adminUids),
        'results' => $results
    ];
}

/**
 * LINEチャット新着メッセージ受信時の管理者LINE通知
 */
function sendAdminLineChatMessageNotification(array $msgData, ?array $userProfile = null, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_chat'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $userName = $msgData['user_name'] ?? 'LINE受講生';
    $msgText = $msgData['message_text'] ?? 'メッセージを受信しました';
    $msgType = $msgData['message_type'] ?? 'text';
    $userId = $msgData['user_id'] ?? '';
    $imageUrl = $msgData['image_url'] ?? '';
    $time = date('H:i');

    $typeIcon = ($msgType === 'image') ? '📷 [画像]' : (($msgType === 'sticker') ? '🎨 [スタンプ]' : '💬');

    $textMsg = "💬【新着LINEメッセージ】({$time})\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 送信者: {$userName} 様\n"
             . "📝 内容:\n{$msgText}\n";

    if (!empty($imageUrl)) {
        $textMsg .= "🖼️ 画像URL: {$imageUrl}\n";
    }

    $textMsg .= "━━━━━━━━━━━━━━\n"
             . "※管理画面のLINEチャットより返信・確認が可能です。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    if (!empty($imageUrl) && str_starts_with($imageUrl, 'https://')) {
        $messages[] = [
            'type' => 'image',
            'originalContentUrl' => $imageUrl,
            'previewImageUrl' => $imageUrl
        ];
    }

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 新規友だち追加・ブロック解除時の管理者LINE通知
 */
function sendAdminLineFollowNotification(string $userId, string $userName, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_follow'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $displayName = $userName ?: '受講生';
    $time = date('Y/m/d H:i');

    $textMsg = "✨【新規友だち追加・ブロック解除】\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 受講生: {$displayName} 様\n"
             . "🆔 LINE UID: {$userId}\n"
             . "📅 日時: {$time}\n"
             . "━━━━━━━━━━━━━━\n"
             . "受講生カルテへ自動登録・名前同期が完了しました。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 車両問い合わせ時の管理者LINE通知
 */
function sendAdminLineInquiryNotification(array $car, string $inquiryType, ?array $userProfile = null, ?string $rawUserId = null, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_inquiry'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $title = $car['title'] ?? '車両問い合わせ';
    $totalPrice = $car['total_price_text'] ?? '要問合せ';
    $year = $car['year'] ?? '-';
    $distance = $car['distance'] ?? '-';
    $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;

    $userName = 'お客様 (名称未設定)';
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
    } elseif (!empty($rawUserId)) {
        $userName = "お客様 (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $textMsg = "🚨【車両お問い合わせ】\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 お客様: {$userName}\n"
             . "🎯 ご希望: {$inquiryType}\n"
             . "🚗 車両: {$title}\n"
             . "💰 総額: {$totalPrice} (年式:{$year} / 走行:{$distance})\n"
             . "━━━━━━━━━━━━━━\n"
             . "LINE公式アカウントのチャット等で詳細をご確認ください。\n"
             . "🔗 車両詳細: {$detailUrl}";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 来店・点検予約時の管理者LINE通知
 */
function sendAdminLineMaintenanceBookingNotification(string $bookingType, string $carModel, string $prefTime, ?array $userProfile = null, ?string $rawUserId = null, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_booking'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $userName = 'お客様 (名称未設定)';
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
    } elseif (!empty($rawUserId)) {
        $userName = "お客様 (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $textMsg = "🛠️【来店・点検予約のお申し込み】\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 お客様: {$userName}\n"
             . "📋 種別: {$bookingType}\n"
             . "🚗 愛車: {$carModel}\n"
             . "📅 ご希望: {$prefTime}\n"
             . "━━━━━━━━━━━━━━\n"
             . "ピット状況を確認し、LINEチャットにて確定日程やお見積もりをご案内してください。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 新規顧客LINE連携時の管理者LINE通知
 */
function sendAdminLineNewCustomerNotification(string $userId, string $userName, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_new_customer'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $displayName = $userName ?: '名称未設定のお客様';

    $textMsg = "🆕【新規顧客登録 (LINE連携)】\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 お客様: {$displayName} 様\n"
             . "🆔 LINE UID: {$userId}\n"
             . "━━━━━━━━━━━━━━\n"
             . "マイカー点検パスポートが開かれました。\n店舗管理画面より車両情報や車検日をご登録いただけます。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 新着在庫車両検知時の管理者LINE通知
 */
function sendAdminLineNewCarsNotification(array $newCars, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_new_cars'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $count = count($newCars);
    if ($count === 0) return ['success' => false, 'reason' => '0件'];

    $lines = ["🚗✨【新着在庫情報】グーネットに新着車両が {$count}台 掲載されました！\n━━━━━━━━━━━━━━"];
    foreach (array_slice($newCars, 0, 5) as $c) {
        $t = $c['title'] ?? '車両';
        $p = $c['total_price_text'] ?? '要問合せ';
        $lines[] = "• {$t} [{$p}]";
    }
    $lines[] = "━━━━━━━━━━━━━━\n店舗在庫一覧: " . SHOP_GOO_URL;

    $messages = [
        ['type' => 'text', 'text' => implode("\n", $lines)]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * リマインド定期配信結果の管理者LINE通知
 */
function sendAdminLineReminderReport(int $oilCount, int $periodicCount, int $shakenCount, array $details = [], ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_reminder'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $total = $oilCount + $periodicCount + $shakenCount;
    if ($total === 0) return ['success' => false, 'reason' => '0件'];

    $lines = [
        "⏰【定期配信完了レポート】\n"
        . "本日 {$total}名様へメンテナンス通知を自動送信しました。\n"
        . "━━━━━━━━━━━━━━\n"
        . "🛢️ オイル交換: {$oilCount}件\n"
        . "📋 12ヶ月点検: {$periodicCount}件\n"
        . "🚗 車検満了: {$shakenCount}件\n"
        . "━━━━━━━━━━━━━━"
    ];
    if (!empty($details)) {
        $lines[] = "送信先（一部）:";
        foreach (array_slice($details, 0, 5) as $d) {
            $lines[] = "• {$d['name']}様 ({$d['car']}) ➡ {$d['type']}";
        }
    }

    $messages = [
        ['type' => 'text', 'text' => implode("\n", $lines)]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 管理者LINE通知テスト送信
 */
function sendAdminLineTestNotification(string $targetUid, ?PDO $pdo = null): array {
    $targetUid = trim($targetUid);
    if (empty($targetUid) || !str_starts_with($targetUid, 'U')) {
        return ['success' => false, 'error' => '有効なLINE UID (Uから始まる33文字のID) を指定してください'];
    }

    $time = date('Y/m/d H:i:s');
    $textMsg = "🔔【管理者LINE通知 テスト送信】\n"
             . "━━━━━━━━━━━━━━\n"
             . "このメッセージはアップファーレン店舗管理システムの動作確認テストです。\n"
             . "送信日時: {$time}\n"
             . "━━━━━━━━━━━━━━\n"
             . "✅ 設定は正常に動作しています！\n"
             . "お客様からの「車両問い合わせ」「点検・来店予約」「新規顧客登録」などの重要通知が、このアカウントへ自動でプッシュ通知されます。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendLinePushMessage($targetUid, $messages);
}

/**
 * Webhook転送先URL文字列（改行またはカンマ区切り）を有効なURLの配列に分解
 */
function parseWebhookUrls(string $input): array {
    $lines = preg_split('/[\r\n,]+/', $input);
    $urls = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!empty($trimmed) && filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $urls[] = $trimmed;
        }
    }
    return array_values(array_unique($urls));
}

/**
 * プロライン & 外部ツール連携設定を取得 (DBとaccounts.jsonを双方向完全同期)
 */
function getProlineSettings(?PDO $pdo = null, ?string $accountKey = null): array {
    $accountKey = $accountKey ?: getActiveAccountKey();
    $accConfig = getAccountConfig($accountKey);
    
    $defaultCalUrl = !empty($accConfig['proline_calendar_url']) 
        ? $accConfig['proline_calendar_url'] 
        : (defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1');
    $defaultWebhookUrl = !empty($accConfig['proline_webhook_url']) 
        ? $accConfig['proline_webhook_url'] 
        : (defined('PROLINE_WEBHOOK_URL') ? PROLINE_WEBHOOK_URL : '');
    $defaultEnabled = defined('PROLINE_RELAY_ENABLED') ? PROLINE_RELAY_ENABLED : true;

    if (!$pdo) {
        try {
            $pdo = getDbConnection($accountKey);
        } catch (Exception $e) {
            $urls = parseWebhookUrls($defaultWebhookUrl);
            return [
                'account' => $accountKey,
                'webhook_url' => $defaultWebhookUrl,
                'webhook_urls' => $urls,
                'relay_enabled' => $defaultEnabled,
                'calendar_url' => $defaultCalUrl,
                'last_relay_at' => '',
                'last_relay_status' => '',
                'last_relay_http_code' => 0
            ];
        }
    }

    try {
        $stmt = $pdo->prepare("SELECT key, value FROM system_settings WHERE key LIKE 'proline_%'");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $urlStr = isset($rows['proline_webhook_url']) && trim((string)$rows['proline_webhook_url']) !== '' 
            ? $rows['proline_webhook_url'] 
            : $defaultWebhookUrl;
        $enabled = isset($rows['proline_relay_enabled']) ? (bool)(int)$rows['proline_relay_enabled'] : $defaultEnabled;
        $calUrl = isset($rows['proline_calendar_url']) && trim((string)$rows['proline_calendar_url']) !== '' 
            ? $rows['proline_calendar_url'] 
            : $defaultCalUrl;

        // 旧URL（fsmk.co や裸のautosns.app）がDBに残っている場合は自動でLIFF個別予約URLへ更新
        if (str_contains($calUrl, 'fsmk.co') || (str_contains($calUrl, 'autosns.app') && !str_contains($calUrl, 'liff.line.me')) || empty($calUrl)) {
            $calUrl = $defaultCalUrl;
            try {
                $upStmt = $pdo->prepare("INSERT INTO system_settings (key, value, updated_at) VALUES ('proline_calendar_url', :val, datetime('now', '+9 hours')) ON CONFLICT(key) DO UPDATE SET value = :val, updated_at = datetime('now', '+9 hours')");
                $upStmt->execute([':val' => $calUrl]);
            } catch (Exception $ign) {}
        }

        $urls = parseWebhookUrls($urlStr);

        return [
            'account' => $accountKey,
            'webhook_url' => trim($urlStr),
            'webhook_urls' => $urls,
            'relay_enabled' => $enabled,
            'calendar_url' => trim($calUrl),
            'last_relay_at' => $rows['proline_last_relay_at'] ?? '',
            'last_relay_status' => $rows['proline_last_relay_status'] ?? '',
            'last_relay_http_code' => (int)($rows['proline_last_relay_http_code'] ?? 0)
        ];
    } catch (Exception $e) {
        $urls = parseWebhookUrls($defaultWebhookUrl);
        return [
            'account' => $accountKey,
            'webhook_url' => $defaultWebhookUrl,
            'webhook_urls' => $urls,
            'relay_enabled' => $defaultEnabled,
            'calendar_url' => $defaultCalUrl,
            'last_relay_at' => '',
            'last_relay_status' => '',
            'last_relay_http_code' => 0
        ];
    }
}

/**
 * プロライン & 外部ツール連携設定を保存 (DBとaccounts.jsonの両方に完全同期保存)
 */
function saveProlineSettings(string $url, bool $enabled, string $calendarUrl = '', ?PDO $pdo = null, ?string $accountKey = null): array {
    $accountKey = $accountKey ?: getActiveAccountKey();
    if (!$pdo) {
        $pdo = getDbConnection($accountKey);
    }

    $rawLines = preg_split('/[\r\n,]+/', trim($url));
    $validUrls = [];
    $invalidLines = [];

    foreach ($rawLines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) continue;
        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $validUrls[] = $trimmed;
        } else {
            $invalidLines[] = $trimmed;
        }
    }

    if (!empty($invalidLines)) {
        return [
            'success' => false,
            'error' => '無効なURL形式が含まれています: ' . implode(', ', array_slice($invalidLines, 0, 3))
        ];
    }

    $cleanUrlStr = implode("\n", array_unique($validUrls));

    $calendarUrl = trim($calendarUrl);
    if (!empty($calendarUrl) && !filter_var($calendarUrl, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => '有効なカレンダーURL形式（https://...）を入力してください'];
    }

    $nowJst = date('Y-m-d H:i:s');
    
    // 1. SQLite DB の system_settings に保存
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES (:key, :val, :updated_at)");
    $stmt->execute([':key' => 'proline_webhook_url', ':val' => $cleanUrlStr, ':updated_at' => $nowJst]);
    $stmt->execute([':key' => 'proline_relay_enabled', ':val' => $enabled ? '1' : '0', ':updated_at' => $nowJst]);
    if (!empty($calendarUrl)) {
        $stmt->execute([':key' => 'proline_calendar_url', ':val' => $calendarUrl, ':updated_at' => $nowJst]);
    }

    // 2. data/accounts.json の該当アカウント設定にも完全同期保存
    try {
        $allAccounts = loadSystemLineAccounts();
        if (isset($allAccounts[$accountKey])) {
            $allAccounts[$accountKey]['proline_webhook_url'] = $cleanUrlStr;
            if (!empty($calendarUrl)) {
                $allAccounts[$accountKey]['proline_calendar_url'] = $calendarUrl;
            }
            saveSystemLineAccounts($allAccounts);
        }
    } catch (Exception $e) {
        error_log("Failed to sync proline settings to accounts.json: " . $e->getMessage());
    }

    return ['success' => true, 'settings' => getProlineSettings($pdo, $accountKey)];
}

/**
 * プロラインおよび登録された外部ツールへWebhookリクエストを完全中継（並列プロキシPOST）
 * 
 * @param string $rawBody LINEから受信した生のJSONペイロード
 * @param string $signature LINE署名（X-Line-Signature）
 * @param PDO|null $pdo DB接続インスタンス
 * @param string|null $accountKey アカウントキー
 * @return array 中継結果
 */
function relayWebhookToProline(string $rawBody, string $signature = '', ?PDO $pdo = null, ?string $accountKey = null): array {
    $accountKey = $accountKey ?: getActiveAccountKey();
    $settings = getProlineSettings($pdo, $accountKey);
    $urls = $settings['webhook_urls'] ?? [];
    $enabled = $settings['relay_enabled'] ?? false;

    if (!$enabled || empty($urls)) {
        return [
            'success' => false,
            'relayed' => false,
            'reason' => empty($urls) ? '転送先Webhook URLが未登録です' : 'Webhook中継が無効化されています',
            'http_code' => 0,
            'results' => []
        ];
    }

    // 署名が空の場合、Channel Secretから自動再計算して補完（FastCGIヘッダー消失対策）
    if (empty($signature)) {
        $secret = getLineChannelSecret(getActiveAccountKey());
        if (!empty($secret) && $secret !== 'YOUR_CHANNEL_SECRET_HERE') {
            $signature = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        }
    }

    $startTime = microtime(true);
    // LINE公式Webhook仕様に厳密に準拠したヘッダー（署名ヘッダーは重複させず単一で送信）
    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'User-Agent: LineBotWebhook/2.0'
    ];
    if (!empty($signature)) {
        $headers[] = 'x-line-signature: ' . $signature;
    }

    // curl_multi による並列非同期送信
    $mh = curl_multi_init();
    $curlHandles = [];

    foreach ($urls as $idx => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,               // タイムアウトを8秒に拡大して外部サーバー遅延に対応
            CURLOPT_CONNECTTIMEOUT => 4,        // 接続タイムアウト4秒
            CURLOPT_SSL_VERIFYPEER => false,    // 外部ホストのSSL証明書差分エラーを防止
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ]);
        curl_multi_add_handle($mh, $ch);
        $curlHandles[$idx] = ['handle' => $ch, 'url' => $url];
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    $nowJst = date('Y-m-d H:i:s');
    $results = [];
    $allSuccess = true;
    $statusSummaryParts = [];
    $lastHttpCode = 200;

    foreach ($curlHandles as $idx => $item) {
        $ch = $item['handle'];
        $url = $item['url'];
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $response = curl_multi_getcontent($ch);

        $isOk = ($curlErrNo === 0 && $httpCode >= 200 && $httpCode < 400);
        if (!$isOk) {
            $allSuccess = false;
        }
        $lastHttpCode = $httpCode;

        $host = parse_url($url, PHP_URL_HOST) ?: 'target';
        $statusSummaryParts[] = "{$host}:" . ($isOk ? "OK({$httpCode})" : "FAIL({$httpCode})");

        $resSnippet = mb_substr(trim((string)$response), 0, 120);

        $results[] = [
            'url' => $url,
            'success' => $isOk,
            'http_code' => $httpCode,
            'error' => $curlError,
            'response_snippet' => $resSnippet
        ];

        // 各URLごとの転送ログ記録
        $statusText = $isOk ? "OK ({$httpCode})" : "FAIL ({$httpCode}: {$curlError})";
        $logLine = "[{$nowJst}] WEBHOOK_RELAY: {$statusText} | URL: {$url} | Sig: " . (!empty($signature) ? 'YES' : 'NO') . " | Res: {$resSnippet}\n";
        @file_put_contents(__DIR__ . '/proline_relay.log', $logLine, FILE_APPEND | LOCK_EX);

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    $durationMs = round((microtime(true) - $startTime) * 1000, 2);
    $finalStatus = ($allSuccess ? "ALL OK" : "PARTIAL/FAIL") . " ({$durationMs}ms) [" . implode(', ', $statusSummaryParts) . "]";

    // DBに直近の中継状況を保存
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES (:key, :val, :updated_at)");
            $stmt->execute([':key' => 'proline_last_relay_at', ':val' => $nowJst, ':updated_at' => $nowJst]);
            $stmt->execute([':key' => 'proline_last_relay_status', ':val' => $finalStatus, ':updated_at' => $nowJst]);
            $stmt->execute([':key' => 'proline_last_relay_http_code', ':val' => (string)$lastHttpCode, ':updated_at' => $nowJst]);
        } catch (Exception $e) {}
    }

    return [
        'success' => $allSuccess,
        'relayed' => true,
        'http_code' => $lastHttpCode,
        'duration_ms' => $durationMs,
        'status' => $finalStatus,
        'results' => $results
    ];
}

// ==========================================================================
// シニア向けスマホ・PCお役立ち情報 & クイックリプライ閲覧エンジン
// ==========================================================================

/**
 * シニア向けお役立ち全10テーマのマスター定義
 */
function getSeniorKnowledgePresets(): array {
    $bookingUrl = defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1';

    return [
        // === 🚨【防犯・トラブル・緊急対策編】（10テーマ） ===
        'scam_virus_alert' => [
            'id' => 'scam_virus_alert',
            'group' => 'security',
            'category' => '🚨 偽警告・詐欺対策',
            'badge_color' => '#e11d48',
            'label' => '🚨 偽警告詐欺対策',
            'title' => '🚨 パソコンの「ウイルス感染警告」は詐欺！慌てず閉じる方法',
            'subtitle' => '画面に突然ピーッと警告音や電話番号が出ても絶対に電話をかけてはいけません！',
            'points' => [
                '画面に表示される電話番号には絶対に電話しない',
                'キーボードの「Esc」長押し、または「Ctrl+Alt+Delete」で画面を閉じる',
                '不安な時は電源ボタン長押しで強制終了し、教室へご相談ください'
            ],
            'advice' => '「警告画面が消えない」「操作が不安」という時は、無理に触らずそのまま教室へお持ちください。スタッフが一緒に安全を確認します！',
            'btn1_label' => '📅 教室で直接相談・予約する',
            'btn1_url' => $bookingUrl
        ],
        'scam_fake_sms' => [
            'id' => 'scam_fake_sms',
            'group' => 'security',
            'category' => '⚠️ 不在通知詐欺対策',
            'badge_color' => '#ea580c',
            'label' => '⚠️ 偽SMS対策',
            'title' => '⚠️ ヤマトや佐川を名乗る偽SMS（不在通知）にご注意！',
            'subtitle' => '「お荷物をお届けにあがりましたが…」というSMSのリンクは絶対に開かないでください！',
            'points' => [
                'SMSに書かれた青い英数字リンク（URL）は絶対に押さない',
                '荷物の確認は、公式アプリやLINEの公式通知から行う',
                '万が一リンクを開いてしまっても、電話番号やパスワードは絶対に入力しない'
            ],
            'advice' => '心当たりのない不審なSMSが届いた時は、削除するか、スクリーンショットを撮って教室でお見せください！',
            'btn1_label' => '📅 教室で直接相談・予約する',
            'btn1_url' => $bookingUrl
        ],
        'scam_fake_pdf' => [
            'id' => 'scam_fake_pdf',
            'group' => 'security',
            'category' => '🚨 偽広告・詐欺対策',
            'badge_color' => '#e11d48',
            'label' => '📄 偽PDFアプリ詐欺',
            'title' => '📄「PDFを見るにはアプリが必要？」シニアを狙う偽広告と危険な悪影響',
            'subtitle' => 'ネット閲覧中に出る「PDFリーダーを更新・入手」の画面は危険な偽広告です！どんな悪影響があるのか解説します。',
            'points' => [
                '【悪影響①】画面中に消えない警告や迷惑広告が大量に出る（乗っ取り・アドウェア）',
                '【悪影響②】「無料」と見せかけて高額な定期購読（月額数千円〜数万円の引き落とし）',
                '【悪影響③】個人情報・連絡先の抜き取りや、スマホ動作が重くなり電池が急減する',
                '【知っておきたい真実】今のスマホやPCは、特別なアプリを入れなくても最初からPDFをそのまま開けます！'
            ],
            'advice' => '「PDFを見るためにインストール」と出たら絶対に押さず画面を閉じてください！万が一入れてしまったり不審な広告が出る場合は、すぐに端末を持って教室にご相談ください（無料点検・削除サポート実施中）。',
            'btn1_label' => '📅 教室でスマホ・PC点検を予約',
            'btn1_url' => $bookingUrl
        ],
        'scam_support_phone' => [
            'id' => 'scam_support_phone',
            'group' => 'security',
            'category' => '📞 偽サポート詐欺対策',
            'badge_color' => '#dc2626',
            'label' => '📞 偽電話サポート罠',
            'title' => '📞「マイクロソフトに電話を」？偽サポート電話詐欺の恐ろしい手口',
            'subtitle' => '画面に表示された電話番号に電話をかけると、片言の日本語で遠隔操作を迫られます！',
            'points' => [
                'マイクロソフトや大手企業が画面に電話番号を出して電話を求めることは100％ありません',
                '電話すると「遠隔操作ソフト」を入れられ、パソコン内の写真や個人情報を盗まれます',
                '「修理代」としてコンビニで電子マネー（Google Playカード等）を買わせるのは典型的な詐欺手口です'
            ],
            'advice' => '電話番号が表示されても絶対に電話をかけてはいけません！もし電話してしまったりカードを買うよう言われたら、すぐ電話を切り教室にご連絡ください。',
            'btn1_label' => '📅 教室で緊急相談・点検予約',
            'btn1_url' => $bookingUrl
        ],
        'scam_line_friend' => [
            'id' => 'scam_line_friend',
            'group' => 'security',
            'category' => '👤 LINE乗っ取り防止',
            'badge_color' => '#e11d48',
            'label' => '👤 LINE乗っ取り詐欺',
            'title' => '👤 友人から「認証番号教えて」と届いたら詐欺！LINE乗っ取りの防ぎ方',
            'subtitle' => '仲の良いお友だちのアカウントから突然届く「携帯が壊れたから番号教えて」は乗っ取り犯です！',
            'points' => [
                '「電話番号と4桁の暗証番号を教えて」というメッセージは絶対に信じてはいけません',
                'SMSに届いた「認証番号（セキュリティコード）」を他人に教えると、あなたのLINEが乗っ取られます',
                '怪しいと思ったらLINEではなく、直接電話してお友だち本人に確認しましょう'
            ],
            'advice' => 'お友だち本人が書いた文章に見えても、文面が不自然な時は要注意です。不安なメッセージが届いたら教室スタッフにお見せください！',
            'btn1_label' => '📅 LINE設定を教室で相談',
            'btn1_url' => $bookingUrl
        ],
        'safe_free_wifi' => [
            'id' => 'safe_free_wifi',
            'group' => 'security',
            'category' => '📶 通信セキュリティ',
            'badge_color' => '#ea580c',
            'label' => '📶 無料Wi-Fiの注意点',
            'title' => '📶 街や病院の「無料Wi-Fi」安全な使い方と危険な落とし穴',
            'subtitle' => 'カフェや商業施設のフリーWi-Fiは便利ですが、使い方を誤ると通信を盗み見られる危険があります！',
            'points' => [
                '鍵マークのない「暗号化されていないWi-Fi」では、パスワードやクレジットカード番号を入力しない',
                '本物そっくりに偽装した「偽アクセスポイント」に自動接続させないよう「Wi-Fi自動接続」はオフ推奨',
                '銀行のネットバンキングや大事な買い物は、自宅のWi-Fiかスマホの携帯電波（4G/5G）で行う'
            ],
            'advice' => '外出先で安全にWi-Fiをつなぐコツや、安全な設定方法は教室でわかりやすくレッスンいたします！',
            'btn1_label' => '📅 スマホ通信設定を教室で相談',
            'btn1_url' => $bookingUrl
        ],
        'pc_numlock_trouble' => [
            'id' => 'pc_numlock_trouble',
            'group' => 'security',
            'category' => '🔢 キーボードトラブル',
            'badge_color' => '#d97706',
            'label' => '🔢 数字が打てない解決',
            'title' => '🔢 キーボード右の数字が打てない！「NumLock」ランプの謎を解決',
            'subtitle' => '「数字を押したのに画面が動くだけで打てない！」シニアの相談件数No.1トラブルです。',
            'points' => [
                'テンキー（右側の数字キー）の上にある「NumLock（ニューロック）」キーを1回押すだけ！',
                'キーボードの「NumLock」ランプが点灯していれば数字入力、消えていると矢印移動になります',
                'ノートパソコンで文字キーを押すと数字が出る場合は「Fn」＋「NumLock」で解除できます'
            ],
            'advice' => 'パソコンの故障ではなく、キーの押し間違いが原因です。教室のキーボードで実際にランプの点き方を確認してみましょう！',
            'btn1_label' => '📅 パソコン操作を教室で相談',
            'btn1_url' => $bookingUrl
        ],
        'pc_freeze_safety' => [
            'id' => 'pc_freeze_safety',
            'group' => 'security',
            'category' => '💻 故障防止・緊急対応',
            'badge_color' => '#0284c7',
            'label' => '💻 画面フリーズ強制終了',
            'title' => '💻 画面がカチコチに固まった！慌てず行う「安全な強制終了」手順',
            'subtitle' => 'マウスも動かない時、いきなりコンセントを抜くのは故障の元！安全な終了手順を覚えましょう。',
            'points' => [
                '【手順①】まずは3分待ってみる（裏で更新作業中の一時的な停止の可能性があるため）',
                '【手順②】パソコン本体の「電源ボタン」を指でグッと約5〜8秒間押し続ける',
                '【手順③】ランプとファンの音が完全に消えたら、1分休ませてから再度電源を入れます'
            ],
            'advice' => '頻繁にフリーズを繰り返す場合は、ハードディスクの寿命やウイルス感染の疑いがあります。無理に使わず教室で無料健康診断をお受けください！',
            'btn1_label' => '🛠️ パソコン健康診断を予約',
            'btn1_url' => $bookingUrl
        ],
        'pc_fan_dust' => [
            'id' => 'pc_fan_dust',
            'group' => 'security',
            'category' => '🧹 パソコン延命ケア',
            'badge_color' => '#059669',
            'label' => '🧹 PCホコリ掃除と異音',
            'title' => '🧹 パソコンが熱い・急に切れる？寿命を延ばす「通気口のホコリ掃除」',
            'subtitle' => '「ファンがゴーッと唸る」「本体がやけどしそうに熱い」のはホコリ詰まりのサインです！',
            'points' => [
                'パソコンの側面や底面にあるスリット（通気口）にホコリがたまると、熱を逃がせず急に電源が落ちます',
                '必ず電源を切り電源コードを抜いてから、通気口のホコリを掃除機で弱く吸い取るか乾いた布で拭く',
                '布団やこたつ布団の上など、通気口がふさがる場所でノートPCを使うのは故障の最大原因です'
            ],
            'advice' => '内部の精密清掃やファンのお手入れは分解が必要な場合もあります。教室にお持ちいただければスタッフが安全に清掃・点検いたします！',
            'btn1_label' => '🛠️ パソコン内部清掃・点検予約',
            'btn1_url' => $bookingUrl
        ],
        'line_unsend_mistake' => [
            'id' => 'line_unsend_mistake',
            'group' => 'security',
            'category' => '💬 LINE誤送信防止',
            'badge_color' => '#7c3aed',
            'label' => '💬 送信間違え送信取消',
            'title' => '💬 LINEで間違えて別の友だちに送っちゃった！24時間以内の「送信取消」',
            'subtitle' => '「相手を間違えてメッセージや写真を送ってしまった！」そんな時の救済ワザです。',
            'points' => [
                '間違えた吹き出しを「指で長押し」してメニューを出す',
                '【超重要】「削除」ではなく「送信取消」を選ぶ（削除は自分の画面から消えるだけ！）',
                '送信後「24時間以内」なら相手のトーク画面からもメッセージを消すことができます'
            ],
            'advice' => '「削除」を押して相手の画面に残ってしまった…というご相談がよくあります。違いを教室のレッスンでマスターしておくと安心です！',
            'btn1_label' => '📅 LINE使い方レッスンを予約',
            'btn1_url' => $bookingUrl
        ],

        // === 📱💻【スマホ・PC快適便利ワザ編】（10テーマ） ===
        'phone_large_text' => [
            'id' => 'phone_large_text',
            'group' => 'tips',
            'category' => '📱 スマホ見やすさ設定',
            'badge_color' => '#0284c7',
            'label' => '📱 スマホ文字拡大',
            'title' => '📱 スマホの文字をもっと大きく！目に優しい簡単設定',
            'subtitle' => '「画面の文字が小さくて読みづらい…」とお悩みの方へ。文字を大きく太くする設定です！',
            'points' => [
                'iPhone: 「設定」→「画面表示と明るさ」→「テキストサイズを変更」',
                'Android: 「設定」→「ディスプレイ」→「フォントサイズと表示サイズ」',
                '「文字を太くする」をオンにすると、さらにクッキリ見やすくなります'
            ],
            'advice' => '教室のレッスンで、ご自身のスマホに合わせて一番読みやすい大きさに一緒に設定調整いたします！',
            'btn1_label' => '📅 スマホ設定を教室で相談する',
            'btn1_url' => $bookingUrl
        ],
        'line_font_size' => [
            'id' => 'line_font_size',
            'group' => 'tips',
            'category' => '💬 LINE便利ワザ',
            'badge_color' => '#7c3aed',
            'label' => '💬 LINE文字特大化',
            'title' => '💬 LINEのメッセージ文字だけを特大サイズにする方法',
            'subtitle' => 'お友だちやご家族からのメッセージがぐんと読みやすくなります！',
            'points' => [
                'LINEの「ホーム」右上の歯車マーク（設定）をタップ',
                '「トーク」→「フォントサイズ」を選ぶ',
                '「特大」を選ぶと、トークの文字が大きく見やすくなります'
            ],
            'advice' => 'スマホ全体の文字は変えずに、LINEだけ大きくすることも可能です。教室で一緒にやってみましょう！',
            'btn1_label' => '📅 レッスン予約・日程変更',
            'btn1_url' => $bookingUrl
        ],
        'phone_voice_input' => [
            'id' => 'phone_voice_input',
            'group' => 'tips',
            'category' => '🗣️ スマホ神ワザ',
            'badge_color' => '#0284c7',
            'label' => '🗣️ らくらく音声入力',
            'title' => '🗣️ キーボード入力不要！マイクで話すだけの「音声入力」超入門',
            'subtitle' => '「文字入力が遅い・ボタンが小さくて押しづらい」という方は、マイクに向かって話すだけでOK！',
            'points' => [
                'キーボードの端にある「マイクのマーク」をポンと1回タップする',
                '「こんにちは」「明日の10時に行きます」とスマホに話しかけるだけで文字が自動入力されます',
                '「まる」と言うと「。」、「てん」と言うと「、」、「かいぎょう」と言うと改行されます'
            ],
            'advice' => '今の音声認識は驚くほど正確です！手が疲れる方やメール作成に時間がかかる方はぜひ教室で練習してみましょう。世界が変わります！',
            'btn1_label' => '📅 音声入力レッスンを予約',
            'btn1_url' => $bookingUrl
        ],
        'pc_mouse_zoom' => [
            'id' => 'pc_mouse_zoom',
            'group' => 'tips',
            'category' => '🔍 パソコン便利技',
            'badge_color' => '#059669',
            'label' => '🔍 画面拡大Ctrl+車輪',
            'title' => '🔍 ホームページの文字が一瞬で特大に！「Ctrl ＋ マウス車輪」',
            'subtitle' => '「インターネットの文字が小さくて読めない…」メガネを探す前にこの操作をお試しください！',
            'points' => [
                'キーボード左下の「Ctrl（コントロール）」キーを押したまま、マウスの真ん中の車輪（ホイール）を上へ回す',
                'ホームページの文字や写真が一瞬でグングン拡大されます（下へ回すと縮小）',
                '元の100%サイズに戻したい時は、「Ctrl」キーを押しながら数字の「0」を押すだけ！'
            ],
            'advice' => 'Yahoo!ニュースやブログ、ネット検索を見るのが劇的に楽になります。教室のレッスンで感覚を掴んでみましょう！',
            'btn1_label' => '📅 パソコン便利技レッスン予約',
            'btn1_url' => $bookingUrl
        ],
        'battery_care' => [
            'id' => 'battery_care',
            'group' => 'tips',
            'category' => '🔋 スマホ長持ちのコツ',
            'badge_color' => '#0284c7',
            'label' => '🔋 電池長持ちの習慣',
            'title' => '🔋 スマートフォンのバッテリーを長持ちさせる3つの習慣',
            'subtitle' => '電池の減りが早くなってきたと感じたら、この使い方を試してみてください！',
            'points' => [
                '充電しながらの長時間の動画視聴や操作を避ける（発熱予防）',
                '画面の明るさを「自動調整」にするか、少し暗めに設定する',
                '使っていない時はWi-FiやBluetoothをこまめにオフにする'
            ],
            'advice' => '「夕方には充電が切れてしまう」「スマホが熱くなる」などの点検も教室で行っています。お気軽に診断へお越しください！',
            'btn1_label' => '🛠️ スマホ・PC健康診断を予約',
            'btn1_url' => $bookingUrl
        ],
        'photo_cleanup' => [
            'id' => 'photo_cleanup',
            'group' => 'tips',
            'category' => '📸 写真・容量整理',
            'badge_color' => '#0284c7',
            'label' => '📸 写真の簡単整理術',
            'title' => '📸 スマホの容量がいっぱい？たまった写真の簡単整理術',
            'subtitle' => 'お孫さんの写真や旅行の写真でメモリがいっぱいになる前の安心お手入れ法です！',
            'points' => [
                'ブレた写真や連写写真、不要なスクリーンショットを先に削除',
                'お気に入りの写真には「♡（ハートマーク）」を付けて整理',
                'Googleフォトやパソコンへ定期バックアップしてスマホをスッキリ'
            ],
            'advice' => '「写真が消えたら怖い」「パソコンへ写真を移したい」時は、USBケーブルを持って教室へお越しください。安全なバックアップ手順をお教えします！',
            'btn1_label' => '📅 写真整理レッスンを予約',
            'btn1_url' => $bookingUrl
        ],
        'pc_restart_magic' => [
            'id' => 'pc_restart_magic',
            'group' => 'tips',
            'category' => '⚡ パソコン快適化',
            'badge_color' => '#059669',
            'label' => '⚡ PC再起動の魔法',
            'title' => '⚡ パソコンが重い・動かない？「再起動」の魔法とシャットダウンの違い',
            'subtitle' => '調子が悪い時は、まず「再起動」を試すのが一番の特効薬です！',
            'points' => [
                'Windowsの「シャットダウン」は前回の状態を一部保存して終了します',
                '「再起動」を選ぶと、メモリが完全にリセットされて動作が軽くなります',
                '週に1〜2回は「スタート」→「電源」→「再起動」を行うのがオススメ'
            ],
            'advice' => '再起動しても動きが遅い・ファンが大きな音で回る場合は、不要ソフトの整理が必要かもしれません。教室でPC健康診断をお受けいただけます！',
            'btn1_label' => '🛠️ パソコン健康診断を予約',
            'btn1_url' => $bookingUrl
        ],
        'pc_shortcuts' => [
            'id' => 'pc_shortcuts',
            'group' => 'tips',
            'category' => '⌨️ パソコン便利技',
            'badge_color' => '#059669',
            'label' => '⌨️ 3大ショートカット',
            'title' => '⌨️ これだけは覚えたい！パソコン3大魔法のショートカットキー',
            'subtitle' => 'マウスで何度もカチカチ探すより、左手ひとつでパッと操作できるようになります！',
            'points' => [
                '【元に戻す】Ctrl ＋ Z（間違えて消してしまった文字や操作が一瞬で復活！）',
                '【コピー】Ctrl ＋ C（選んだ文字や写真をサッと複製）',
                '【貼り付け】Ctrl ＋ V（コピーした内容を好きな場所へペタッと貼る）'
            ],
            'advice' => 'Ctrl（コントロールキー）はキーボードの左下にあります！レッスンで実際に指を置いて練習してみましょう。',
            'btn1_label' => '📅 レッスン予約・日程変更',
            'btn1_url' => $bookingUrl
        ],
        'pc_caps_lock' => [
            'id' => 'pc_caps_lock',
            'group' => 'tips',
            'category' => '🔤 文字入力トラブル',
            'badge_color' => '#059669',
            'label' => '🔤 大文字ロック解除',
            'title' => '🔤 文字が勝手に大文字になる？「Caps Lock」のワンキー解決法',
            'subtitle' => 'パスワードやアルファベットを入力した時、全部大文字になって困ったことはありませんか？',
            'points' => [
                '原因はキーボードの「Shift」と「Caps Lock」を一緒に押してしまったこと',
                '解決法: 「Shift」キーを押しながら「Caps Lock」キーをもう1度押すだけ！',
                'キーボード上の小さなランプ（Aのランプ）が消えれば通常入力に戻ります'
            ],
            'advice' => '入力トラブルの多くはキーボードのちょっとした押し間違いです。焦らず教室スタッフにいつでもご質問ください！',
            'btn1_label' => '📅 教室で質問・予約',
            'btn1_url' => $bookingUrl
        ],
        'disaster_apps' => [
            'id' => 'disaster_apps',
            'group' => 'tips',
            'category' => '🏥 安心・暮らしのデジタル',
            'badge_color' => '#d97706',
            'label' => '🏥 スマホ防災速報',
            'title' => '🏥 いざという時に安心！スマホで見る防災速報・ハザードマップ',
            'subtitle' => '大雨や地震の際、スマホが命を守る一番の味方になります！',
            'points' => [
                '自治体の公式LINEや「Yahoo!防災速報」を登録しておくと警報が即届く',
                'スマホのGoogleマップで近くの「指定避難所」を事前確認しておく',
                '災害用伝言ダイヤル「171」やLINEでの安否確認方法を家族で決めておく'
            ],
            'advice' => '避難所の場所の登録や防災アプリの入れ方がわからない時は、教室でスタッフと一緒に設定しましょう！',
            'btn1_label' => '📅 防災アプリ設定を教室で相談',
            'btn1_url' => $bookingUrl
        ]
    ];
}

/**
 * クイックリプライ設定を取得
 */
function getQuickReplySettings(?string $accountKey = null, ?PDO $db = null): array {
    $activeKey = $accountKey ?: getActiveAccountKey();
    $acc = getAccountConfig($activeKey);
    $indType = strtolower($acc['industry_type'] ?? 'senior');
    $accId = strtolower($acc['id'] ?? $activeKey);
    $isSenior = ($indType === 'senior' || $accId === 'senior');

    $default = [
        'enabled' => $isSenior, // seniorのみ初期ON、他業種は初期OFF
        'mode' => $isSenior ? 'senior_knowledge' : 'none', // none | senior_knowledge | industry_preset | custom
        'custom_items' => [],
        'updated_at' => null
    ];

    if (!$db) {
        try {
            $db = getDbConnection($activeKey);
        } catch (Throwable $e) {
            return $default;
        }
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)");
        $settingKey = "quick_reply_settings_{$activeKey}";
        $stmt = $db->prepare("SELECT value FROM system_settings WHERE key = :key LIMIT 1");
        $stmt->execute([':key' => $settingKey]);
        $val = $stmt->fetchColumn();
        if ($val) {
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                return [
                    'enabled' => filter_var($decoded['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'mode' => !empty($decoded['mode']) ? (string)$decoded['mode'] : ($isSenior ? 'senior_knowledge' : 'none'),
                    'custom_items' => !empty($decoded['custom_items']) && is_array($decoded['custom_items']) ? $decoded['custom_items'] : [],
                    'updated_at' => $decoded['updated_at'] ?? null
                ];
            }
        }
    } catch (Throwable $e) {
        writeDebugLog("getQuickReplySettings エラー", ['error' => $e->getMessage()]);
    }

    return $default;
}

/**
 * クイックリプライ設定を保存
 */
function saveQuickReplySettings(string $accountKey, array $settings, ?PDO $db = null): bool {
    $activeKey = !empty($accountKey) ? $accountKey : getActiveAccountKey();
    if (!$db) {
        try {
            $db = getDbConnection($activeKey);
        } catch (Throwable $e) {
            return false;
        }
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)");
        $nowJst = date('Y-m-d H:i:s');
        $clean = [
            'enabled' => filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'mode' => in_array($settings['mode'] ?? '', ['none', 'senior_knowledge', 'industry_preset', 'custom']) ? $settings['mode'] : 'none',
            'custom_items' => is_array($settings['custom_items'] ?? null) ? array_values($settings['custom_items']) : [],
            'updated_at' => $nowJst
        ];
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $settingKey = "quick_reply_settings_{$activeKey}";
        $stmt = $db->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES (:key, :val, :now)");
        return $stmt->execute([':key' => $settingKey, ':val' => $json, ':now' => $nowJst]);
    } catch (Throwable $e) {
        writeDebugLog("saveQuickReplySettings エラー", ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * 各アカウント・業種に応じた適切なクイックリプライボタンスキーマを生成
 */
function getAccountQuickReplyItems(?string $accountKey = null, ?string $currentTopic = null): ?array {
    $activeKey = $accountKey ?: getActiveAccountKey();
    $settings = getQuickReplySettings($activeKey);

    // クイックリプライが無効、またはモードが 'none' の場合は絶対にクイックリプライを付与しない（null返却）
    if (empty($settings['enabled']) || $settings['mode'] === 'none') {
        return null;
    }

    // シニアお役立ち情報モード
    if ($settings['mode'] === 'senior_knowledge') {
        return getSeniorKnowledgeQuickReplyItems($currentTopic);
    }

    // カスタムアイテムモード
    if ($settings['mode'] === 'custom' && !empty($settings['custom_items'])) {
        $items = [];
        foreach ($settings['custom_items'] as $it) {
            if (empty($it['label'])) continue;
            $label = mb_substr(trim($it['label']), 0, 20);
            $actionType = $it['action_type'] ?? 'postback';
            if ($actionType === 'uri' && !empty($it['uri'])) {
                $items[] = [
                    'type' => 'action',
                    'action' => [
                        'type' => 'uri',
                        'label' => $label,
                        'uri' => trim($it['uri'])
                    ]
                ];
            } elseif ($actionType === 'message' && !empty($it['text'])) {
                $items[] = [
                    'type' => 'action',
                    'action' => [
                        'type' => 'message',
                        'label' => $label,
                        'text' => trim($it['text'])
                    ]
                ];
            } else {
                $data = !empty($it['data']) ? trim($it['data']) : 'action=open_mycar';
                $items[] = [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => $label,
                        'data' => $data
                    ]
                ];
            }
        }
        if (!empty($items)) {
            return ['items' => array_slice($items, 0, 13)];
        }
    }

    // 業種別プリセットモード (industry_preset)
    $acc = getAccountConfig($activeKey);
    $indType = strtolower($acc['industry_type'] ?? 'senior');

    if ($indType === 'auto') {
        return [
            'items' => [
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '🚗 マイカーカルテ',
                        'data' => 'action=open_mycar'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '💬 店舗に質問・相談',
                        'data' => 'action=ask_class&topic=お問い合わせ'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📅 点検・車検予約',
                        'data' => 'action=book_service'
                    ]
                ]
            ]
        ];
    }

    if ($indType === 'salon') {
        return [
            'items' => [
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '✨ マイカルテ/会員証',
                        'data' => 'action=open_mycar'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '💬 サロンに相談',
                        'data' => 'action=ask_class&topic=お問い合わせ'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📅 WEB予約',
                        'data' => 'action=book_service'
                    ]
                ]
            ]
        ];
    }

    if ($indType === 'school') {
        return [
            'items' => [
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '🎓 受講生カルテ',
                        'data' => 'action=open_mycar'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '💬 教室・講師に連絡',
                        'data' => 'action=ask_class&topic=お問い合わせ'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📅 レッスン予約',
                        'data' => 'action=book_service'
                    ]
                ]
            ]
        ];
    }

    if ($indType === 'fitness') {
        return [
            'items' => [
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '🏃 会員証・カルテ',
                        'data' => 'action=open_mycar'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '💬 トレーナーに相談',
                        'data' => 'action=ask_class&topic=お問い合わせ'
                    ]
                ],
                [
                    'type' => 'action',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📅 トレーニング予約',
                        'data' => 'action=book_service'
                    ]
                ]
            ]
        ];
    }

    // B2B・DX・カスタム汎用
    $item1Name = !empty($acc['label_item1']) ? $acc['label_item1'] : 'マイカルテ';
    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => "📋 {$item1Name}",
                    'data' => 'action=open_mycar'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '💬 お問い合わせ・相談',
                    'data' => 'action=ask_class&topic=お問い合わせ'
                ]
            ]
        ]
    ];
}

/**
 * 共通クイックリプライ取得ヘルパー
 */
function getQuickReplyItems(?string $accountKey = null): ?array {
    return getAccountQuickReplyItems($accountKey);
}

/**
 * シニアお役立ち情報用 クイックリプライボタンスキーマ生成
 * LINE Messaging API 仕様準拠（最大13個・postbackアクション・ラベル20文字以内）
 */
function getSeniorKnowledgeQuickReplyItems(?string $currentTopic = null): array {
    $presets = getSeniorKnowledgePresets();
    $items = [];

    // クイックリプライはLINE上限13個。代表的・人気の11テーマを厳選配置
    $highlightTopics = [
        'scam_fake_pdf',      // 📄 偽PDF詐欺
        'scam_virus_alert',   // 🚨 偽警告対策
        'scam_support_phone', // 📞 偽電話サポート
        'scam_fake_sms',      // ⚠️ 偽SMS対策
        'scam_line_friend',   // 👤 LINE乗っ取り
        'phone_large_text',   // 📱 文字拡大
        'line_font_size',     // 💬 LINE特大
        'phone_voice_input',  // 🗣️ 音声入力
        'pc_mouse_zoom',      // 🔍 画面拡大
        'pc_restart_magic',   // ⚡ PC再起動
        'pc_numlock_trouble'  // 🔢 数字打てない
    ];

    foreach ($highlightTopics as $tId) {
        if (!isset($presets[$tId])) continue;
        $data = $presets[$tId];
        $label = mb_substr($data['label'], 0, 20);
        $items[] = [
            'type' => 'action',
            'action' => [
                'type' => 'postback',
                'label' => $label,
                'data' => "action=show_senior_kb&topic={$tId}"
            ]
        ];
    }

    // 教室質問・相談ボタン（サイレントPostback）
    $items[] = [
        'type' => 'action',
        'action' => [
            'type' => 'postback',
            'label' => '💬 教室に質問・相談',
            'data' => 'action=ask_class&topic=お役立ち情報'
        ]
    ];

    // 全テーマ一覧メニュー表示ボタン（サイレントPostback）
    $items[] = [
        'type' => 'action',
        'action' => [
            'type' => 'postback',
            'label' => '📚 全20テーマ一覧',
            'data' => 'action=show_knowledge_menu'
        ]
    ];

    // LINE上限の13個以内に収める
    return [
        'items' => array_slice($items, 0, 13)
    ];
}

/**
 * 1グループ分のカルーセルバブル配列を構築するヘルパー
 */
function buildKnowledgeCarouselBubbles(array $presetGroup): array {
    $bookingUrl = defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1';
    $bubbles = [];

    foreach ($presetGroup as $tId => $data) {
        $category = $data['category'] ?? 'お役立ち情報';
        $badgeColor = $data['badge_color'] ?? '#0284c7';
        $title = $data['title'] ?? 'お役立ち情報';
        $points = $data['points'] ?? [];

        $pointBoxes = [];
        if (!empty($points[0])) {
            $pointBoxes[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'xs',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '①',
                        'weight' => 'bold',
                        'size' => 'xxs',
                        'color' => $badgeColor,
                        'flex' => 1
                    ],
                    [
                        'type' => 'text',
                        'text' => mb_substr((string)$points[0], 0, 32) . (mb_strlen((string)$points[0]) > 32 ? '…' : ''),
                        'size' => 'xxs',
                        'color' => '#334155',
                        'wrap' => true,
                        'flex' => 11
                    ]
                ]
            ];
        }
        if (!empty($points[1])) {
            $pointBoxes[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'xs',
                'margin' => 'xs',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '②',
                        'weight' => 'bold',
                        'size' => 'xxs',
                        'color' => $badgeColor,
                        'flex' => 1
                    ],
                    [
                        'type' => 'text',
                        'text' => mb_substr((string)$points[1], 0, 32) . (mb_strlen((string)$points[1]) > 32 ? '…' : ''),
                        'size' => 'xxs',
                        'color' => '#334155',
                        'wrap' => true,
                        'flex' => 11
                    ]
                ]
            ];
        }

        $bubbles[] = [
            'type' => 'bubble',
            'size' => 'kilo',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'contents' => [
                    // 送信アナウンス
                    [
                        'type' => 'text',
                        'text' => '📢 お役立ち情報をお送りします！',
                        'weight' => 'bold',
                        'size' => 'xxs',
                        'color' => '#0284c7',
                        'margin' => 'none'
                    ],
                    // タイトル
                    [
                        'type' => 'text',
                        'text' => $title,
                        'weight' => 'bold',
                        'size' => 'sm',
                        'margin' => 'sm',
                        'color' => '#0f172a',
                        'wrap' => true,
                        'maxLines' => 3
                    ],
                    // ポイント要約
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'backgroundColor' => '#f8fafc',
                        'paddingAll' => '10px',
                        'cornerRadius' => 'md',
                        'borderColor' => '#e2e8f0',
                        'borderWidth' => '1px',
                        'contents' => array_merge([
                            [
                                'type' => 'text',
                                'text' => '【ポイント要約】',
                                'weight' => 'bold',
                                'size' => 'xxs',
                                'color' => '#64748b'
                            ]
                        ], $pointBoxes)
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'xs',
                'paddingAll' => '12px',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => $badgeColor,
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '📖 詳しく読む',
                            'data' => "action=show_senior_kb&topic={$tId}"
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => '📅 教室で相談・予約',
                            'uri' => $bookingUrl
                        ]
                    ]
                ]
            ]
        ];
    }

    return $bubbles;
}

/**
 * シニア向けお役立ち情報 全20テーマを2つのカルーセルメッセージ（防犯編・便利ワザ編 各10バブル）に分割して構築
 * LINE Flex Message Carousel 仕様（最大12バブル/メッセージ）に完全準拠
 */
function generateSeniorKnowledgeCarouselMessages(bool $attachQuickReply = true): array {
    $presets = getSeniorKnowledgePresets();

    $securityGroup = [];
    $tipsGroup = [];

    foreach ($presets as $tId => $data) {
        if (($data['group'] ?? '') === 'security') {
            $securityGroup[$tId] = $data;
        } else {
            $tipsGroup[$tId] = $data;
        }
    }

    $messages = [];

    // 第1便: 🚨 防犯・トラブル・緊急対策編（10選）
    $secBubbles = buildKnowledgeCarouselBubbles($securityGroup);
    if (!empty($secBubbles)) {
        $messages[] = [
            'type' => 'flex',
            'altText' => 'お役立ち情報をお送りします！🚨【防犯・トラブル対策編】（横スワイプでご覧ください）',
            'contents' => [
                'type' => 'carousel',
                'contents' => array_slice($secBubbles, 0, 12)
            ]
        ];
    }

    // 第2便: 📱💻 スマホ・PC快適便利ワザ編（10選）
    $tipsBubbles = buildKnowledgeCarouselBubbles($tipsGroup);
    if (!empty($tipsBubbles)) {
        $msg2 = [
            'type' => 'flex',
            'altText' => 'お役立ち情報をお送りします！📱💻【スマホ・PC快適便利ワザ編】（横スワイプでご覧ください）',
            'contents' => [
                'type' => 'carousel',
                'contents' => array_slice($tipsBubbles, 0, 12)
            ]
        ];
        if ($attachQuickReply && function_exists('getSeniorKnowledgeQuickReplyItems')) {
            $msg2['quickReply'] = getSeniorKnowledgeQuickReplyItems();
        }
        $messages[] = $msg2;
    }

    return $messages;
}

/**
 * 互換用: 単一カルーセルメッセージを返す関数
 */
function generateSeniorKnowledgeCarouselMessage(bool $attachQuickReply = true): array {
    $msgs = generateSeniorKnowledgeCarouselMessages($attachQuickReply);
    return !empty($msgs) ? $msgs[0] : [];
}

/**
 * 特定のトピックのお役立ちFlex Messageカードを構築
 */
function generateSeniorKnowledgeFlexMessage(string $topicId, bool $attachQuickReply = true): array {
    $presets = getSeniorKnowledgePresets();
    $data = $presets[$topicId] ?? reset($presets);

    $category = $data['category'];
    $badgeColor = $data['badge_color'];
    $title = $data['title'];
    $subtitle = $data['subtitle'];
    $points = $data['points'] ?? [];
    $advice = $data['advice'] ?? '';
    $btn1Label = $data['btn1_label'] ?? '📅 教室で直接相談・予約する';
    $btn1Url = $data['btn1_url'] ?? (defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : '');

    // ポイント一覧
    $pointBoxContents = [];
    $numIcons = ['①', '②', '③', '④', '⑤'];
    foreach ($points as $idx => $pt) {
        $ptText = trim((string)$pt);
        if (empty($ptText)) continue;
        $icon = $numIcons[$idx] ?? '・';
        $item = [
            'type' => 'box',
            'layout' => 'horizontal',
            'spacing' => 'sm',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $icon,
                    'weight' => 'bold',
                    'size' => 'sm',
                    'color' => $badgeColor,
                    'flex' => 1
                ],
                [
                    'type' => 'text',
                    'text' => $ptText,
                    'size' => 'sm',
                    'color' => '#1e293b',
                    'wrap' => true,
                    'weight' => 'bold',
                    'flex' => 11
                ]
            ]
        ];
        if ($idx > 0) {
            $item['margin'] = 'md';
        }
        $pointBoxContents[] = $item;
    }

    $bodyContents = [
        // 送信アナウンスヘッダー（お役立ち情報をお送りします！）
        [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '📢 お役立ち情報をお送りします！',
                    'weight' => 'bold',
                    'size' => 'xs',
                    'color' => '#0284c7'
                ]
            ],
            'margin' => 'none',
            'paddingBottom' => '6px'
        ],
        // タイトル
        [
            'type' => 'text',
            'text' => $title,
            'weight' => 'bold',
            'size' => 'lg',
            'margin' => 'sm',
            'color' => '#0f172a',
            'wrap' => true
        ]
    ];

    if (!empty($subtitle)) {
        $bodyContents[] = [
            'type' => 'text',
            'text' => $subtitle,
            'size' => 'xs',
            'color' => '#475569',
            'margin' => 'sm',
            'wrap' => true
        ];
    }

    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];

    if (!empty($pointBoxContents)) {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'backgroundColor' => '#f8fafc',
            'paddingAll' => '14px',
            'cornerRadius' => 'lg',
            'borderColor' => '#e2e8f0',
            'borderWidth' => '1px',
            'contents' => array_merge([
                [
                    'type' => 'text',
                    'text' => '【覚えておきたいポイント】',
                    'weight' => 'bold',
                    'size' => 'xs',
                    'color' => '#64748b'
                ],
                [
                    'type' => 'separator',
                    'margin' => 'sm'
                ]
            ], $pointBoxContents)
        ];
    }

    if (!empty($advice)) {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'backgroundColor' => '#fffbeb',
            'paddingAll' => '12px',
            'cornerRadius' => 'md',
            'borderColor' => '#fef3c7',
            'borderWidth' => '1px',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '👩‍🏫 教室スタッフからのアドバイス',
                    'weight' => 'bold',
                    'size' => 'xs',
                    'color' => '#b45309'
                ],
                [
                    'type' => 'text',
                    'text' => $advice,
                    'size' => 'xs',
                    'color' => '#78350f',
                    'wrap' => true,
                    'margin' => 'xs'
                ]
            ]
        ];
    }

    $bodyContents[] = [
        'type' => 'text',
        'text' => '※わからない操作や気になる点はお気軽に教室でお尋ねください。',
        'size' => 'xxs',
        'color' => '#94a3b8',
        'margin' => 'md',
        'wrap' => true
    ];

    // フッターボタン
    $footerButtons = [];
    if (!empty($btn1Label)) {
        $footerButtons[] = [
            'type' => 'button',
            'style' => 'primary',
            'color' => $badgeColor,
            'height' => 'sm',
            'action' => [
                'type' => 'uri',
                'label' => mb_substr($btn1Label, 0, 20),
                'uri' => !empty($btn1Url) ? $btn1Url : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1'
            ]
        ];
    }

    // その他のお役立ち情報を見るボタン（全テーマクイックリプライ呼出・サイレント送信）
    $footerButtons[] = [
        'type' => 'button',
        'style' => 'secondary',
        'height' => 'sm',
        'action' => [
            'type' => 'postback',
            'label' => '📚 その他のお役立ち情報',
            'data' => 'action=show_knowledge_menu'
        ]
    ];

    // LINE postback action の data は250バイト以内に収める
    $shortTopic = mb_substr($title, 0, 15);
    $encodedTopic = urlencode($shortTopic);
    while (strlen("action=ask_class&topic={$encodedTopic}") > 250 && mb_strlen($shortTopic) > 0) {
        $shortTopic = mb_substr($shortTopic, 0, -1);
        $encodedTopic = urlencode($shortTopic);
    }
    $footerButtons[] = [
        'type' => 'button',
        'style' => 'secondary',
        'height' => 'sm',
        'action' => [
            'type' => 'postback',
            'label' => '💬 LINEで質問・相談する',
            'data' => "action=ask_class&topic={$encodedTopic}"
        ]
    ];

    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '18px',
            'contents' => $bodyContents
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'paddingAll' => '14px',
            'contents' => $footerButtons
        ]
    ];

    $msg = [
        'type' => 'flex',
        'altText' => mb_substr("お役立ち情報をお送りします！【{$title}】", 0, 400),
        'contents' => $bubble
    ];

    if ($attachQuickReply) {
        $msg['quickReply'] = getSeniorKnowledgeQuickReplyItems($topicId);
    }

    return $msg;
}

/**
 * 現在のローカルシステムバージョン情報を取得
 */
function getSystemLocalVersionInfo(): array {
    $versionFile = __DIR__ . '/data/version.json';
    if (file_exists($versionFile)) {
        $content = @file_get_contents($versionFile);
        $json = json_decode((string)$content, true);
        if (is_array($json)) {
            return array_merge([
                'version' => defined('SYSTEM_CURRENT_VERSION') ? SYSTEM_CURRENT_VERSION : '2.5.0',
                'build_date' => defined('SYSTEM_BUILD_DATE') ? SYSTEM_BUILD_DATE : date('Y-m-d'),
                'commit_hash' => 'unknown',
                'commit_message' => 'ローカル稼働中',
                'updated_at' => date('Y-m-d H:i:s')
            ], $json);
        }
    }
    return [
        'version' => defined('SYSTEM_CURRENT_VERSION') ? SYSTEM_CURRENT_VERSION : '2.5.0',
        'build_date' => defined('SYSTEM_BUILD_DATE') ? SYSTEM_BUILD_DATE : date('Y-m-d'),
        'commit_hash' => 'installed',
        'commit_message' => '初期インストールバージョン',
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * GitHubリポジトリ上の最新アップデート情報を確認
 */
function checkSystemRemoteUpdate(): array {
    $local = getSystemLocalVersionInfo();
    $repo = defined('SYSTEM_GITHUB_REPO') ? SYSTEM_GITHUB_REPO : 'fqkvxq/line_kureba-senior_system';
    $branch = defined('SYSTEM_GITHUB_BRANCH') ? SYSTEM_GITHUB_BRANCH : 'main';
    $token = defined('SYSTEM_GITHUB_TOKEN') ? SYSTEM_GITHUB_TOKEN : (getenv('GITHUB_TOKEN') ?: '');

    $url = "https://api.github.com/repos/{$repo}/commits/{$branch}";

    $headers = [
        'User-Agent: KurebaSystemUpdater/2.5',
        'Accept: application/vnd.github.v3+json'
    ];
    if (!empty($token)) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $remoteData = json_decode((string)$res, true);

    if ($httpCode !== 200 || empty($remoteData) || !isset($remoteData['sha'])) {
        // レート制限やプライベートリポジトリの場合: 404/403時はローカル最新として安全に扱う
        if ($httpCode === 404 || $httpCode === 403 || $httpCode === 0) {
            return [
                'success' => true,
                'has_update' => false,
                'current' => $local,
                'remote' => [
                    'version' => $local['version'] ?? '2.5.0',
                    'commit_hash' => $local['commit_hash'] ?? 'latest',
                    'commit_message' => '最新バージョンが適用されています',
                    'date' => $local['updated_at'] ?? date('Y-m-d H:i:s'),
                    'recent_commits' => []
                ],
                'note' => "GitHub API ({$httpCode}): プライベートリポジトリまたはトークン未設定のためローカル最新版として継続します"
            ];
        }

        // raw GitHubからversion.jsonを直接取得試行
        $rawUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}/public_html/data/version.json";
        $rawHeaders = ['User-Agent: KurebaSystemUpdater/2.5'];
        if (!empty($token)) {
            $rawHeaders[] = 'Authorization: Bearer ' . $token;
        }
        $rawCh = curl_init($rawUrl);
        curl_setopt_array($rawCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => $rawHeaders,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $rawRes = curl_exec($rawCh);
        $rawCode = curl_getinfo($rawCh, CURLINFO_HTTP_CODE);
        curl_close($rawCh);

        if ($rawCode === 200 && !empty($rawRes)) {
            $rawJson = json_decode((string)$rawRes, true);
            if (is_array($rawJson)) {
                $remoteSha = $rawJson['commit_hash'] ?? 'latest';
                $hasUpdate = ($remoteSha !== $local['commit_hash']);
                return [
                    'success' => true,
                    'has_update' => $hasUpdate,
                    'current' => $local,
                    'remote' => [
                        'version' => $rawJson['version'] ?? 'latest',
                        'commit_hash' => $remoteSha,
                        'commit_message' => $rawJson['commit_message'] ?? '最新の機能更新',
                        'date' => $rawJson['updated_at'] ?? date('Y-m-d H:i:s'),
                        'recent_commits' => []
                    ],
                    'check_method' => 'raw_fallback'
                ];
            }
        }

        return [
            'success' => true,
            'has_update' => false,
            'current' => $local,
            'remote' => [
                'version' => $local['version'] ?? '2.5.0',
                'commit_hash' => $local['commit_hash'] ?? 'latest',
                'commit_message' => '最新バージョンが適用されています',
                'date' => date('Y-m-d H:i:s')
            ],
            'note' => $curlErr ?: "HTTP {$httpCode}"
        ];
    }

    $remoteSha = substr((string)$remoteData['sha'], 0, 7);
    $fullSha = (string)$remoteData['sha'];
    $remoteDate = isset($remoteData['commit']['committer']['date']) 
        ? date('Y-m-d H:i:s', strtotime($remoteData['commit']['committer']['date'])) 
        : date('Y-m-d H:i:s');
    $commitMsg = $remoteData['commit']['message'] ?? '最新版の更新';

    // 直近のコミット一覧（リリースノート用）を5件取得
    $recentCommits = [];
    $listUrl = "https://api.github.com/repos/{$repo}/commits?sha={$branch}&per_page=5";
    $chList = curl_init($listUrl);
    curl_setopt_array($chList, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'User-Agent: KurebaSystemUpdater/2.5',
            'Accept: application/vnd.github.v3+json'
        ],
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $resList = curl_exec($chList);
    curl_close($chList);
    $commitsData = json_decode((string)$resList, true);
    if (is_array($commitsData)) {
        foreach ($commitsData as $cItem) {
            $recentCommits[] = [
                'sha' => substr((string)$cItem['sha'], 0, 7),
                'message' => explode("\n", $cItem['commit']['message'] ?? '')[0],
                'date' => date('Y-m-d H:i', strtotime($cItem['commit']['committer']['date'] ?? 'now')),
                'author' => $cItem['commit']['author']['name'] ?? 'Maintainer'
            ];
        }
    }

    $localSha = $local['commit_hash'] ?? '';
    $hasUpdate = ($localSha !== $remoteSha && $localSha !== $fullSha);

    return [
        'success' => true,
        'has_update' => $hasUpdate,
        'current' => $local,
        'remote' => [
            'commit_hash' => $remoteSha,
            'full_hash' => $fullSha,
            'commit_message' => explode("\n", $commitMsg)[0],
            'date' => $remoteDate,
            'recent_commits' => $recentCommits
        ],
        'repo' => $repo,
        'branch' => $branch,
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * オンラインアップデート実行
 * 
 * GitHubから最新のZIPアーカイブを取得し、データベースや個別設定を保護しながら
 * システムファイルを安全に上書き更新し、DBマイグレーションを実行します。
 */
function performSystemSelfUpdate(?string $targetBranch = 'main', ?PDO $db = null): array {
    // 実行時間制限を延長
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    $repo = defined('SYSTEM_GITHUB_REPO') ? SYSTEM_GITHUB_REPO : 'fqkvxq/line_kureba-senior_system';
    $branch = $targetBranch ?: (defined('SYSTEM_GITHUB_BRANCH') ? SYSTEM_GITHUB_BRANCH : 'main');

    $tmpDir = __DIR__ . '/uploads/tmp_update_' . date('YmdHis');
    $zipFile = __DIR__ . '/uploads/update_pkg.zip';

    if (!is_dir(__DIR__ . '/uploads')) {
        @mkdir(__DIR__ . '/uploads', 0755, true);
    }

    // 1. 最新ZIPアーカイブのダウンロード
    $zipUrl = "https://github.com/{$repo}/archive/refs/heads/{$branch}.zip";
    $ch = curl_init($zipUrl);
    $fp = fopen($zipFile, 'wb');
    if (!$fp) {
        return ['success' => false, 'error' => '一時ファイルの作成に失敗しました。uploadsディレクトリの書き込み権限をご確認ください。'];
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['User-Agent: KurebaSystemUpdater/2.5'],
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    fclose($fp);
    curl_close($ch);

    if ($httpCode !== 200 || filesize($zipFile) < 1000) {
        @unlink($zipFile);
        return ['success' => false, 'error' => "アップデートZIPのダウンロードに失敗しました (HTTP {$httpCode}) " . $curlErr];
    }

    // 2. ZIPの解凍
    if (!class_exists('ZipArchive')) {
        @unlink($zipFile);
        return ['success' => false, 'error' => 'サーバーのPHP環境で ZipArchive 拡張機能が有効になっていません。'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        @unlink($zipFile);
        return ['success' => false, 'error' => 'ダウンロードしたZIPファイルの展開に失敗しました。'];
    }

    @mkdir($tmpDir, 0755, true);
    $zip->extractTo($tmpDir);
    $zip->close();
    @unlink($zipFile);

    // ZIP内のルートディレクトリを特定 (例: line_kureba-senior_system-main/)
    $extractedItems = scandir($tmpDir);
    $innerRoot = $tmpDir;
    foreach ($extractedItems as $item) {
        if ($item !== '.' && $item !== '..' && is_dir($tmpDir . '/' . $item)) {
            $innerRoot = $tmpDir . '/' . $item;
            break;
        }
    }

    // 3. ファイル上書き処理（保護リストの適用）
    $sourcePublicHtml = is_dir($innerRoot . '/public_html') ? $innerRoot . '/public_html' : $innerRoot;
    $targetPublicHtml = __DIR__;

    $updatedFiles = [];
    $protectedFiles = [];

    // 再帰的コピー関数（安全除外ロジック付き）
    $copyDirectorySafe = function ($src, $dst, $relPath = '') use (&$copyDirectorySafe, &$updatedFiles, &$protectedFiles) {
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        $dir = opendir($src);
        if (!$dir) return;

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            $currentRel = trim($relPath . '/' . $file, '/');

            // --- 保護除外ルール (絶対に上書き・削除しない) ---
            // 1. データベースファイル (*.db, *.sqlite)
            if (preg_match('/\.(db|sqlite)$/i', $file)) {
                $protectedFiles[] = $currentRel . ' (データベース保護)';
                continue;
            }
            // 2. data/ ディレクトリ内の既存アカウント設定やデータベース
            if (str_starts_with($currentRel, 'data/') && file_exists($dstPath)) {
                if ($file !== 'version.json') {
                    $protectedFiles[] = $currentRel . ' (個別設定データ保護)';
                    continue;
                }
            }
            // 3. uploads/ ディレクトリ内の既存アップロード画像・メディア
            if (str_starts_with($currentRel, 'uploads/') && file_exists($dstPath)) {
                $protectedFiles[] = $currentRel . ' (アップロード画像保護)';
                continue;
            }
            // 4. config.php は既存のバックアップを作成してから上書き
            if ($currentRel === 'config.php' && file_exists($dstPath)) {
                @copy($dstPath, $dstPath . '.bak.' . date('YmdHis'));
            }

            if (is_dir($srcPath)) {
                $copyDirectorySafe($srcPath, $dstPath, $currentRel);
            } else {
                if (@copy($srcPath, $dstPath)) {
                    $updatedFiles[] = $currentRel;
                }
            }
        }
        closedir($dir);
    };

    // public_html の更新
    $copyDirectorySafe($sourcePublicHtml, $targetPublicHtml);

    // batch ディレクトリの更新 (存在する場合)
    $sourceBatch = is_dir($innerRoot . '/batch') ? $innerRoot . '/batch' : null;
    $targetBatch = dirname(__DIR__) . '/batch';
    if ($sourceBatch && is_dir($targetBatch)) {
        $copyDirectorySafe($sourceBatch, $targetBatch, 'batch');
    }

    // 4. 一時ディレクトリの完全削除
    $deleteDirRecursive = function ($dirPath) use (&$deleteDirRecursive) {
        if (!is_dir($dirPath)) return;
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $p = $dirPath . '/' . $file;
            is_dir($p) ? $deleteDirRecursive($p) : @unlink($p);
        }
        @rmdir($dirPath);
    };
    $deleteDirRecursive($tmpDir);

    // 5. データベース自動マイグレーション実行
    try {
        if ($db === null) {
            $db = getDbConnection();
        }
        initDatabaseSchema($db);
    } catch (Throwable $e) {
        writeDebugLog("アップデート時DBマイグレーション例外", ['error' => $e->getMessage()]);
    }

    // 6. 最新のリモート情報を取得して version.json を更新
    $remoteInfo = checkSystemRemoteUpdate();
    $newCommit = $remoteInfo['remote']['commit_hash'] ?? 'latest';
    $newDate = $remoteInfo['remote']['date'] ?? date('Y-m-d H:i:s');
    $newMsg = $remoteInfo['remote']['commit_message'] ?? '最新アップデート適用済み';

    $versionData = [
        'version' => defined('SYSTEM_CURRENT_VERSION') ? SYSTEM_CURRENT_VERSION : '2.5.0',
        'build_date' => $newDate,
        'branch' => $branch,
        'commit_hash' => $newCommit,
        'commit_message' => $newMsg,
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_files_count' => count($updatedFiles)
    ];
    @file_put_contents(__DIR__ . '/data/version.json', json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    writeDebugLog("システムアップデート完了", [
        'commit' => $newCommit,
        'updated_count' => count($updatedFiles),
        'protected_count' => count($protectedFiles)
    ]);

    return [
        'success' => true,
        'message' => "システムを最新版 ({$newCommit}) へ正常にアップデートしました！",
        'version_info' => $versionData,
        'updated_files_count' => count($updatedFiles),
        'protected_files_count' => count($protectedFiles)
    ];
}

// ==============================================================================
// 🔔 ブラウザ WebPush 通知 連携
// ==============================================================================
require_once __DIR__ . '/webpush.php';

/**
 * チャット新着メッセージ受信時の WebPush 通知送信
 */
function sendWebPushChatMessageNotification(array $msgData, ?PDO $db = null, string $account = 'senior'): array {
    $uName = !empty($msgData['user_name']) ? $msgData['user_name'] : '受講生';
    $uId = $msgData['user_id'] ?? '';
    $msgType = $msgData['message_type'] ?? 'text';
    $mText = $msgData['message_text'] ?? '';
    $picUrl = $msgData['picture_url'] ?? '';

    $body = $mText;
    if ($msgType === 'image') {
        $body = '📷 [写真・画像を受信しました]';
    } elseif ($msgType === 'sticker') {
        $body = '🎨 [スタンプを受信しました]';
    } elseif ($msgType === 'video' || $msgType === 'audio' || $msgType === 'file') {
        $body = '📎 [ファイルを受信しました]';
    }

    $icon = !empty($picUrl) ? $picUrl : 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png';

    $payload = [
        'title' => "💬 LINE: {$uName} 様",
        'body' => $body,
        'icon' => $icon,
        'image' => !empty($msgData['image_url']) ? $msgData['image_url'] : null,
        'data' => [
            'url' => 'admin/index.html?open_chat=' . urlencode($uId) . '&account=' . urlencode($account),
            'user_id' => $uId,
            'user_name' => $uName,
            'account' => $account
        ]
    ];

    return sendWebPushNotification($payload, $db, $account);
}


