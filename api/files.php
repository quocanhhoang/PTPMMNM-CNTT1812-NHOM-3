<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();
$folderId = $_GET['folder_id'] ?? null;
if ($folderId === '') $folderId = null;
if ($folderId !== null) {
    $folderId = filter_var($folderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) json_response(['error' => 'Thư mục được chọn không hợp lệ.'], 400);
}

$result = pg_query_params(
    $conn,
    'SELECT id, file_name, file_path, file_size, file_type, share_code, access_type,
            folder_id,
            download_limit, download_count, expires_at, is_link_active, created_at
     FROM files
     WHERE owner_id = $1 AND ($2::bigint IS NULL OR folder_id = $2)
     ORDER BY created_at DESC, id DESC',
    [$userId, $folderId]
);

if (!$result) {
    error_log('Failed to list files: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tải danh sách tệp.'], 500);
}

$files = [];
while ($file = pg_fetch_assoc($result)) {
    $root = realpath(FILESHARE_STORAGE_DIR);
    $path = $root === false ? false : realpath(FILESHARE_STORAGE_DIR . '/' . $file['file_path']);
    $available = $root !== false && $path !== false
        && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path);
    $files[] = [
        'id' => (int) $file['id'],
        'file_name' => $file['file_name'],
        'file_size' => (int) $file['file_size'],
        'file_type' => $file['file_type'],
        'folder_id' => $file['folder_id'] === null ? null : (int) $file['folder_id'],
        'is_missing' => !$available,
        'share_code' => $file['share_code'],
        'access_type' => $file['access_type'],
        'download_limit' => $file['download_limit'] === null ? null : (int) $file['download_limit'],
        'download_count' => (int) $file['download_count'],
        'expires_at' => $file['expires_at'],
        'is_link_active' => $file['is_link_active'] === 't',
        'created_at' => $file['created_at'],
    ];
}

json_response(['files' => $files]);
