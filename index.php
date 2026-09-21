<?php
/**
 * ルート index.php
 * ドメイン直下またはサブディレクトリにアクセスされた場合に管理画面（admin/）へ転送
 */
header('Location: admin/index.html', true, 302);
exit;
