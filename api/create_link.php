<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reward_helpers.php';
require_method('POST');
$userId = require_authenticated_user();
require_csrf_token();
$body = json_request_body();
$fileId = filter_var($body['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($fileId === false) json_response(['error' => 'Tệp được chọn không hợp lệ.'], 400);

$expiry = trim((string) ($body['expiry'] ?? ''));
if ($expiry !== '') {
    $expiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry);
    if (!$expiryDate || $expiryDate->format('Y-m-d') !== $expiry || $expiryDate < new DateTimeImmutable('today')) {
        json_response(['error' => 'Ngày hết hạn không hợp lệ hoặc đã qua.'], 400);
    }
} else {
    $expiry = null;
}

$limitInput = trim((string) ($body['download_limit'] ?? ''));
$downloadLimit = $limitInput === '' ? null : filter_var($limitInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($downloadLimit === false) json_response(['error' => 'Giới hạn lượt tải phải là số nguyên dương.'], 400);
$shareCode = bin2hex(random_bytes(16));

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin link creation transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tạo liên kết.'], 500);
}
$accountLock = pg_query_params($conn, 'SELECT id FROM users WHERE id = $1 FOR UPDATE', [$userId]);
if (!$accountLock || pg_num_rows($accountLock) === 0) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to lock account for link mission: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể cập nhật nhiệm vụ chia sẻ.'], 500);
}
$result = pg_query_params(
    $conn,
    'UPDATE files
     SET share_code = $3, is_link_active = TRUE, download_count = 0,
         download_limit = $4,
         expires_at = CASE WHEN $5::date IS NULL THEN NULL ELSE $5::date + INTERVAL \'1 day\' - INTERVAL \'1 second\' END,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = $1 AND owner_id = $2 AND access_type = \'public\'
     RETURNING id, share_code, expires_at, download_limit, download_count',
    [$fileId, $userId, $shareCode, $downloadLimit, $expiry]
);
if (!$result) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to create file link: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tạo liên kết.'], 500);
}
$file = pg_fetch_assoc($result);
if (!$file) {
    pg_query($conn, 'ROLLBACK');
    $exists = pg_query_params($conn, 'SELECT 1 FROM files WHERE id = $1 AND owner_id = $2', [$fileId, $userId]);
    if ($exists && pg_num_rows($exists) > 0) json_response(['error' => 'Chỉ có thể tạo liên kết cho tệp đang đặt quyền Công khai.'], 409);
    json_response(['error' => 'Không tìm thấy tệp.'], 404);
}

$activity = pg_query_params($conn, 'INSERT INTO activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)', [$userId, $fileId, 'link_created']);
if (!$activity) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to record file link creation: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể ghi nhận hoạt động chia sẻ.'], 500);
}
try {
    award_activity_missions($conn, $userId, 'link_created');
} catch (Throwable $error) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to award link sharing mission: ' . $error->getMessage());
    json_response(['error' => 'Không thể cập nhật thưởng nhiệm vụ chia sẻ.'], 500);
}
if (!pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit file link creation: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể hoàn tất tạo liên kết.'], 500);
}

json_response(['file' => [
    'id' => (int) $file['id'],
    'share_code' => $file['share_code'],
    'expires_at' => $file['expires_at'],
    'download_limit' => $file['download_limit'] === null ? null : (int) $file['download_limit'],
    'download_count' => (int) $file['download_count'],
    'is_link_active' => true,
]]);
