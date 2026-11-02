<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();
$result = pg_query_params(
    $conn,
    "SELECT f.file_name, al.created_at
     FROM activity_logs al
     JOIN files f ON f.id = al.file_id
     WHERE f.owner_id = $1 AND al.user_id = $1 AND al.action = 'upload'
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 5",
    [$userId]
);
if (!$result) {
    error_log('Failed to load recent uploads: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tải hoạt động gần đây.'], 500);
}
$uploads = [];
while ($row = pg_fetch_assoc($result)) {
    $uploads[] = ['file_name' => $row['file_name'], 'created_at' => $row['created_at']];
}
json_response(['uploads' => $uploads]);
