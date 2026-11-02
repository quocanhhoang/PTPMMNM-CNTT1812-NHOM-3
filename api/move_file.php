<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
$userId = require_authenticated_user();
require_csrf_token();
$body = json_request_body();
$fileId = filter_var($body['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$folderId = $body['folder_id'] ?? null;
if ($folderId === '') $folderId = null;
if ($fileId === false) json_response(['error' => 'Tệp được chọn không hợp lệ.'], 400);
if ($folderId !== null) {
    $folderId = filter_var($folderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) json_response(['error' => 'Thư mục được chọn không hợp lệ.'], 400);
    $folder = pg_query_params($conn, 'SELECT 1 FROM folders WHERE id = $1 AND owner_id = $2', [$folderId, $userId]);
    if (!$folder || pg_num_rows($folder) === 0) json_response(['error' => 'Không tìm thấy thư mục.'], 404);
}
$result = pg_query_params(
    $conn,
    'UPDATE files SET folder_id = $3, updated_at = CURRENT_TIMESTAMP
     WHERE id = $1 AND owner_id = $2 RETURNING id',
    [$fileId, $userId, $folderId]
);
if (!$result) {
    error_log('Failed to move file: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể chuyển tệp.'], 500);
}
if (pg_num_rows($result) === 0) json_response(['error' => 'Không tìm thấy tệp.'], 404);
json_response(['success' => true]);
