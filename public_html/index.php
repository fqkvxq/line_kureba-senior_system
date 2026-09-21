<?php
/**
 * public_html index.php
 * ドキュメントルート直下にアクセスされた場合に管理画面（/admin/）へ転送
 */
header('Location: ./admin/index.html', true, 302);
exit;
