<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
$userId = require_authenticated_user();
require_csrf_token();
$body = json_request_body();
$fileId = filter_var($body['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($fileId === false) json_response(['error' => 'Tệp được chọn không hợp lệ.'], 400);
$result = pg_query_params(
    $conn,
    'SELECT file_path FROM files WHERE id = $1 AND owner_id = $2',
    [$fileId, $userId]
);
if (!$result) {
    error_log('Failed to look up file for deletion: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xóa tệp.'], 500);
}
$file = pg_fetch_assoc($result);
if (!$file) json_response(['error' => 'Không tìm thấy tệp.'], 404);
if (!pg_query($conn, 'BEGIN')) {
    json_response(['error' => 'Không thể xóa tệp.'], 500);
}
$permissions = pg_query_params($conn, 'DELETE FROM file_permissions WHERE file_id = $1', [$fileId]);
$logs = $permissions ? pg_query_params($conn, 'DELETE FROM activity_logs WHERE file_id = $1', [$fileId]) : false;
$deleted = $logs ? pg_query_params($conn, 'DELETE FROM files WHERE id = $1 AND owner_id = $2', [$fileId, $userId]) : false;
if (!$permissions || !$logs || !$deleted || !pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to delete file metadata: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xóa tệp.'], 500);
}
$root = realpath(FILESHARE_STORAGE_DIR);
$path = $root === false ? false : realpath(FILESHARE_STORAGE_DIR . '/' . $file['file_path']);
if ($path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) && !unlink($path)) {
    error_log('File metadata removed but stored content could not be deleted.');
}
json_response(['success' => true]);
