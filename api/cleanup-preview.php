<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');

$result = pg_query_params(
    $conn,
    'SELECT id, file_name, file_size, expires_at, download_limit, download_count
     FROM public.files
     WHERE owner_id = $1
       AND deleted_at IS NULL
       AND share_code IS NOT NULL
       AND (
           is_link_active = FALSE
           OR (expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP)
           OR (download_limit IS NOT NULL AND download_count >= download_limit)
       )
     ORDER BY expires_at NULLS LAST, id',
    [FILESHARE_CURRENT_USER_ID]
);
if ($result === false) {
    error_log('Failed to preview expired files: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xem trước danh sách tệp cần dọn.'], 500);
}

$files = [];
while ($file = pg_fetch_assoc($result)) {
    $files[] = [
        'id' => (int)$file['id'],
        'name' => $file['file_name'],
        'size' => (int)$file['file_size'],
        'expires_at' => $file['expires_at'],
        'download_limit' => $file['download_limit'] === null ? null : (int)$file['download_limit'],
        'download_count' => (int)$file['download_count'],
    ];
}

json_response(['files' => $files]);
