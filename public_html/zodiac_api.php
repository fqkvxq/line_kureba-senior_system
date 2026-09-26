<?php
/**
 * ユーザー星座設定 API エクステンション
 */
function handleZodiacAction(string $action, ?PDO $db, array $req): array {
    if (!$db) {
        return ['status' => 'error', 'message' => 'Database not connected'];
    }

    // テーブル自動作成
    $db->exec("CREATE TABLE IF NOT EXISTS user_zodiacs (
        user_id TEXT PRIMARY KEY,
        zodiac TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    switch ($action) {
        case 'save_user_zodiac': {
            $userId = trim($req['user_id'] ?? '');
            $zodiac = trim($req['zodiac'] ?? '');
            if (empty($userId) || empty($zodiac)) {
                return ['status' => 'error', 'message' => 'user_id and zodiac are required'];
            }
            $stmt = $db->prepare("INSERT INTO user_zodiacs (user_id, zodiac, updated_at) VALUES (:u, :z, CURRENT_TIMESTAMP) ON CONFLICT(user_id) DO UPDATE SET zodiac = :z, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([':u' => $userId, ':z' => $zodiac]);
            return ['status' => 'success', 'user_id' => $userId, 'zodiac' => $zodiac, 'message' => '星座を設定しました'];
        }

        case 'get_user_zodiac': {
            $userId = trim($req['user_id'] ?? '');
            if (empty($userId)) {
                return ['status' => 'error', 'message' => 'user_id is required'];
            }
            $stmt = $db->prepare("SELECT zodiac, updated_at FROM user_zodiacs WHERE user_id = :u");
            $stmt->execute([':u' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'status' => 'success',
                'user_id' => $userId,
                'zodiac' => $row['zodiac'] ?? null,
                'updated_at' => $row['updated_at'] ?? null
            ];
        }

        case 'list_user_zodiacs': {
            $stmt = $db->query("SELECT user_id, zodiac, updated_at FROM user_zodiacs");
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return ['status' => 'success', 'count' => count($list), 'users' => $list];
        }

        default:
            return ['status' => 'error', 'message' => 'Unknown zodiac action'];
    }
}
