<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$userId = require_authenticated_user();
$result = pg_query_params(
    $conn,
    "SELECT u.full_name, u.email, u.storage_limit AS storage_quota_bytes,
            (SELECT COALESCE(SUM(f.file_size), 0)::bigint FROM files f WHERE f.owner_id = u.id) AS storage_used_bytes,
            (SELECT COUNT(*)::bigint FROM files f WHERE f.owner_id = u.id) AS file_count,
            (SELECT COUNT(*)::bigint FROM activity_logs al
             JOIN files f ON f.id = al.file_id
             WHERE f.owner_id = u.id AND al.user_id = u.id
               AND al.action = 'download' AND al.created_at >= CURRENT_DATE
               AND al.created_at < CURRENT_DATE + INTERVAL '1 day') AS downloads_today
     FROM users u WHERE u.id = $1",
    [$userId]
);
if (!$result || !($account = pg_fetch_assoc($result))) {
    error_log('Failed to load account quota: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tải thông tin dung lượng.'], 500);
}
json_response(['account' => [
    'full_name' => $account['full_name'],
    'email' => $account['email'],
    'storage_used_bytes' => (int) $account['storage_used_bytes'],
    'storage_quota_bytes' => (int) $account['storage_quota_bytes'],
    'file_count' => (int) $account['file_count'],
    'downloads_today' => (int) $account['downloads_today'],
]]);
