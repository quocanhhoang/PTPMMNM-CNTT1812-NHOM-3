<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
require_csrf_token();
$userId = require_authenticated_user();
$body = json_request_body();

$fileId = filter_var($body['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$recipientId = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($fileId === false || $recipientId === false) {
    json_response(['error' => 'Tệp hoặc người dùng được chọn không hợp lệ.'], 400);
}

$ownedFile = pg_query_params(
    $conn,
    'SELECT id FROM files WHERE id = $1 AND owner_id = $2',
    [$fileId, $userId]
);
if (!$ownedFile) {
    error_log('Failed to verify file ownership for permission revocation: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xác minh quyền sở hữu tệp.'], 500);
}
if (pg_num_rows($ownedFile) === 0) {
    json_response(['error' => 'Không tìm thấy tệp.'], 404);
}

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin permission revocation transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể thu hồi quyền truy cập.'], 500);
}

$deletedPermission = pg_query_params(
    $conn,
    'DELETE FROM file_permissions WHERE file_id = $1 AND user_id = $2 RETURNING id',
    [$fileId, $recipientId]
);
if (!$deletedPermission) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to revoke file permission: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể thu hồi quyền truy cập.'], 500);
}
if (pg_num_rows($deletedPermission) === 0) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Người dùng này không còn được cấp quyền cho tệp.'], 404);
}

$activity = pg_query_params(
    $conn,
    'INSERT INTO activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)',
    [$userId, $fileId, 'permission_revoked']
);
if (!$activity) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to record permission revocation: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể ghi nhận hoạt động thu hồi quyền.'], 500);
}
if (!pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit permission revocation: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể thu hồi quyền truy cập.'], 500);
}

json_response(['success' => true]);
