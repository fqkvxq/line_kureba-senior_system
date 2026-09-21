<?php
/**
 * システム共通設定ファイル (テンプレート)
 * 実際のサーバー環境に合わせて設定し、config.php として保存してください。
 * （※初期セットアップウィザード setup.php から自動生成することも可能です）
 */

// タイムゾーン設定 (日本時間 / JST)
date_default_timezone_set('Asia/Tokyo');
ini_set('date.timezone', 'Asia/Tokyo');
putenv('TZ=Asia/Tokyo');

// --- 複数LINE公式アカウント設定 (マルチテナント対応) ---
global $SYSTEM_LINE_ACCOUNTS, $CURRENT_ACTIVE_LINE_ACCOUNT_KEY;
$CURRENT_ACTIVE_LINE_ACCOUNT_KEY = null;

define('LINE_ACCOUNTS_DATA_DIR', __DIR__ . '/data');
define('LINE_ACCOUNTS_DATA_FILE', LINE_ACCOUNTS_DATA_DIR . '/line_accounts.json');

// デフォルトの基本アカウント定義（setup.php または line_accounts.json で上書きされます）
$DEFAULT_SYSTEM_LINE_ACCOUNTS = [
    'senior' => [
        'id' => 'senior',
        'name' => 'LINE公式アカウント',
        'short_name' => 'メインアカウント',
        'theme_color' => '#2563eb',
        'channel_access_token' => 'YOUR_CHANNEL_ACCESS_TOKEN_HERE',
        'channel_secret' => 'YOUR_CHANNEL_SECRET_HERE',
        'liff_id' => 'YOUR_LIFF_ID_HERE',
        'proline_calendar_url' => '',
        'proline_webhook_url' => '',
        'db_file' => 'system_main.db',
        'is_default' => true,
    ],
];

/**
 * 登録されているLINE公式アカウント設定をロード
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
                            $base = $accounts[$key] ?? [
                                'id' => $key,
                                'is_default' => false,
                                'db_file' => "system_{$key}.db"
                            ];
                            $accounts[$key] = array_merge($base, $acc);
                            $accounts[$key]['id'] = $key;
                        }
                    }
                }
            }
        }
    }
    return $accounts;
}

$SYSTEM_LINE_ACCOUNTS = loadSystemLineAccounts();

// --- システム管理者パスワード ---
define('ADMIN_DEFAULT_PASSWORD', 'admin1234'); // 初期パスワード（初回ログイン後に変更してください）
