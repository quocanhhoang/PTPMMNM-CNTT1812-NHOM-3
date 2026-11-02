<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();

$result = pg_query_params(
    $conn,
    'SELECT id, file_name, file_size, expires_at, download_limit, download_count, is_link_active
     FROM files
     WHERE owner_id = $1
       AND share_code IS NOT NULL
       AND (
           is_link_active = FALSE
           OR (expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP)
           OR (download_limit IS NOT NULL AND download_count >= download_limit)
       )
     ORDER BY expires_at NULLS LAST, id',
    [$userId]
);
if (!$result) {
    error_log('Failed to preview files for cleanup: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xem trước danh sách tệp cần dọn.'], 500);
}

$files = [];
while ($file = pg_fetch_assoc($result)) {
    $files[] = [
        'id' => (int) $file['id'],
        'file_name' => $file['file_name'],
        'file_size' => (int) $file['file_size'],
        'expires_at' => $file['expires_at'],
        'download_limit' => $file['download_limit'] === null ? null : (int) $file['download_limit'],
        'download_count' => (int) $file['download_count'],
        'is_link_active' => $file['is_link_active'] === 't',
    ];
}

json_response(['files' => $files]);
