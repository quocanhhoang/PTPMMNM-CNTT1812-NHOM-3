<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$userId = require_authenticated_user();

$body = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requestedFileId = $_GET['file_id'] ?? null;
} else {
    require_method('POST');
    require_csrf_token();
    $body = json_request_body();
    $requestedFileId = $body['file_id'] ?? null;
}

$fileId = filter_var($requestedFileId, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($fileId === false) {
    json_response(['error' => 'Tệp được chọn không hợp lệ.'], 400);
}

$ownedFile = pg_query_params(
    $conn,
    'SELECT id FROM files WHERE id = $1 AND owner_id = $2 AND deleted_at IS NULL',
    [$fileId, FILESHARE_CURRENT_USER_ID]
);
if (!$ownedFile) {
    $databaseError = pg_last_error($conn);
    error_log('Failed to verify file ownership: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể xác minh quyền sở hữu tệp.'),
    ], 500);
}
if (pg_num_rows($ownedFile) === 0) {
    json_response(['error' => 'Không tìm thấy tệp.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = pg_query_params(
        $conn,
        'SELECT u.full_name, u.email, fp.created_at AS granted_at, granter.full_name AS granted_by
         FROM file_permissions fp
         JOIN public.users u ON u.id = fp.user_id
         LEFT JOIN public.users granter ON granter.id = fp.granted_by
         WHERE fp.file_id = $1
         ORDER BY fp.created_at DESC, fp.id DESC',
        [$fileId]
    );
    if (!$result) {
        $databaseError = pg_last_error($conn);
        error_log('Failed to list file permissions: ' . $databaseError);
        json_response([
            'error' => app_database_error_message($databaseError, 'Không thể tải danh sách người được cấp quyền.'),
        ], 500);
    }

    $permissions = [];
    while ($permission = pg_fetch_assoc($result)) {
        $permissions[] = $permission;
    }
    json_response(['permissions' => $permissions]);
}

$email = trim((string) ($body['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
    json_response(['error' => 'Địa chỉ email không hợp lệ hoặc dài hơn 100 ký tự.'], 400);
}

$userResult = pg_query_params($conn, 'SELECT id, full_name, email FROM users WHERE lower(email) = lower($1)', [$email]);
if (!$userResult) {
    $databaseError = pg_last_error($conn);
    error_log('Failed to find user for file permission: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể tìm người dùng.'),
    ], 500);
}
$user = pg_fetch_assoc($userResult);
if (!$user) {
    json_response(['error' => 'Email này chưa thuộc tài khoản người dùng nào.'], 404);
}
if ((int) $user['id'] === FILESHARE_CURRENT_USER_ID) {
    json_response(['error' => 'Chủ sở hữu tệp đã có quyền truy cập.'], 409);
}

$transactionStarted = pg_query($conn, 'BEGIN');
if (!$transactionStarted) {
    error_log('Unable to begin file permission transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể lưu quyền truy cập.'], 500);
}

$insertedPermission = pg_query_params(
    $conn,
    'INSERT INTO file_permissions (file_id, user_id, granted_by)
     SELECT $1, $2, $3
     WHERE NOT EXISTS (
         SELECT 1 FROM file_permissions WHERE file_id = $1 AND user_id = $2
     )
     RETURNING id',
    [$fileId, $user['id'], FILESHARE_CURRENT_USER_ID]
);
if (!$insertedPermission) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to grant file permission: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể lưu quyền truy cập.'),
    ], 500);
}
if (pg_num_rows($insertedPermission) === 0) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Email này đã được cấp quyền cho tệp.'], 409);
}

$activity = pg_query_params(
    $conn,
    'INSERT INTO activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)',
    [FILESHARE_CURRENT_USER_ID, $fileId, 'permission_granted']
);
if (!$activity) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to record permission activity: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể ghi nhận hoạt động chia sẻ.'),
    ], 500);
}
if (!pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit file permission transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể lưu quyền truy cập.'], 500);
}

json_response(['permission' => ['id' => (int) $user['id'], 'full_name' => $user['full_name'], 'email' => $user['email']]], 201);
