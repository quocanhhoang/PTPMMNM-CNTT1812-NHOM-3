<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
$userId = require_authenticated_user();
require_csrf_token();

$body = json_request_body();
$planGb = filter_var($body['storage_gb'] ?? null, FILTER_VALIDATE_INT);
$plans = [5, 10, 100];
if ($planGb === false || !in_array($planGb, $plans, true)) {
    json_response(['error' => 'Gói dung lượng không hợp lệ.'], 400);
}

$newQuota = $planGb * 1024 ** 3;
if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin storage upgrade transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể cập nhật gói dung lượng lúc này.'], 500);
}

$accountLock = pg_query_params(
    $conn,
    'SELECT pg_advisory_xact_lock($1::bigint)',
    [$userId]
);
if ($accountLock === false) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Unable to lock account for storage upgrade: ' . $databaseError);
    json_response(['error' => 'Không thể cập nhật gói dung lượng lúc này.'], 500);
}

$accountResult = pg_query_params(
    $conn,
    'SELECT storage_limit FROM public.users WHERE id = $1',
    [$userId]
);
if ($accountResult === false) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Unable to load account for storage upgrade: ' . $databaseError);
    json_response(['error' => 'Không thể tải thông tin gói dung lượng.'], 500);
}
$account = pg_fetch_assoc($accountResult);
if ($account === false) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Không tìm thấy tài khoản.'], 404);
}
$currentQuota = (int)$account['storage_limit'];
if ($newQuota <= $currentQuota) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Bạn chỉ có thể chọn gói có dung lượng cao hơn gói hiện tại.'], 409);
}

$usageResult = pg_query_params(
    $conn,
    'SELECT COALESCE(SUM(file_size), 0)::bigint
     FROM public.files
     WHERE owner_id = $1 AND deleted_at IS NULL',
    [$userId]
);
if ($usageResult === false) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Unable to check storage usage before upgrade: ' . $databaseError);
    json_response(['error' => 'Không thể kiểm tra dung lượng đang sử dụng.'], 500);
}
$usedBytes = (int)pg_fetch_result($usageResult, 0, 0);
if ($newQuota < $usedBytes) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Dung lượng gói mới thấp hơn dung lượng bạn đang sử dụng.'], 409);
}

$updated = pg_query_params(
    $conn,
    'UPDATE public.users
     SET storage_limit = $1, storage_quota_bytes = $1
     WHERE id = $2
     RETURNING storage_limit',
    [$newQuota, $userId]
);
if ($updated === false || !pg_query($conn, 'COMMIT')) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Unable to save storage upgrade: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể lưu gói dung lượng mới.'),
    ], 500);
}

json_response([
    'quota' => $newQuota,
    'message' => 'Đã nâng cấp lên gói ' . $planGb . ' GB. Thanh toán chưa được tích hợp nên hiện chưa thu phí.',
]);
