<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
require_csrf_token();

$body = json_request_body();
$fileId = filter_var($body['file_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($fileId === false) {
    json_response(['error' => 'Tệp được chọn không hợp lệ.'], 400);
}

$transactionStarted = pg_query($conn, 'BEGIN');
if (!$transactionStarted) {
    error_log('Unable to begin link revocation transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể thu hồi liên kết.'], 500);
}

$result = pg_query_params(
    $conn,
    'UPDATE files
     SET is_link_active = FALSE, updated_at = CURRENT_TIMESTAMP
     WHERE id = $1 AND owner_id = $2 AND is_link_active = TRUE AND deleted_at IS NULL
     RETURNING id',
    [$fileId, FILESHARE_CURRENT_USER_ID]
);
if (!$result) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to revoke file link: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể thu hồi liên kết.'),
    ], 500);
}
if (pg_num_rows($result) === 0) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Không tìm thấy tệp.'], 404);
}

if (!pg_query_params(
    $conn,
    'INSERT INTO activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)',
    [FILESHARE_CURRENT_USER_ID, $fileId, 'link_revoked']
)) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to record link revocation: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể ghi nhận việc thu hồi liên kết.'),
    ], 500);
}
if (!pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit link revocation: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể hoàn tất thu hồi liên kết.'], 500);
}

json_response(['success' => true]);
