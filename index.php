<?php
/**
 * ルート index.php
 * ドメイン直下または任意のサブディレクトリにアクセスされた場合に管理画面（/admin/）へ安全に完全絶対URL転送
 */
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);
$proto = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
$dir = str_replace('\\', '/', $dir);
$base = rtrim($dir, '/');

// 管理画面への安全な完全絶対URL (public_html/admin/ または admin/ を自動判定)
$adminPath = is_dir(__DIR__ . '/public_html/admin') ? '/public_html/admin/index.html' : '/admin/index.html';
$targetUrl = "{$proto}://{$host}{$base}{$adminPath}";

header("Location: {$targetUrl}", true, 302);
exit;

