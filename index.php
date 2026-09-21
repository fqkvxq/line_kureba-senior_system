<?php
/**
 * ルート index.php
 * ドメイン直下または任意のサブディレクトリにアクセスされた場合に管理画面へ安全に転送
 */
if (file_exists(__DIR__ . '/public_html/admin/index.html')) {
    header('Location: ./public_html/admin/index.html', true, 302);
} elseif (file_exists(__DIR__ . '/admin/index.html')) {
    header('Location: ./admin/index.html', true, 302);
} else {
    header('Location: ./admin/', true, 302);
}
exit;
