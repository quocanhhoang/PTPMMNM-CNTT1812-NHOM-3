<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
require_csrf_token();
$userId = require_authenticated_user();
$body = json_request_body();
$planId = $body['plan_id'] ?? null;

$plans = [
    'week_1' => ['coins' => 500, 'bytes' => 500 * 1024 * 1024, 'days' => 7, 'months' => 0, 'years' => 0, 'label' => '1 tuần'],
    'week_2' => ['coins' => 950, 'bytes' => 1024 * 1024 * 1024, 'days' => 14, 'months' => 0, 'years' => 0, 'label' => '2 tuần'],
    'month_1' => ['coins' => 2500, 'bytes' => 3 * 1024 * 1024 * 1024, 'days' => 0, 'months' => 1, 'years' => 0, 'label' => '1 tháng'],
    'month_3' => ['coins' => 4850, 'bytes' => 9 * 1024 * 1024 * 1024, 'days' => 0, 'months' => 3, 'years' => 0, 'label' => '3 tháng'],
    'month_6' => ['coins' => 7620, 'bytes' => 18 * 1024 * 1024 * 1024, 'days' => 0, 'months' => 6, 'years' => 0, 'label' => '6 tháng'],
    'month_9' => ['coins' => 10300, 'bytes' => 27 * 1024 * 1024 * 1024, 'days' => 0, 'months' => 9, 'years' => 0, 'label' => '9 tháng'],
    'year_1' => ['coins' => 13870, 'bytes' => 20 * 1024 * 1024 * 1024, 'days' => 0, 'months' => 0, 'years' => 1, 'label' => '1 năm'],
];
if (!is_string($planId) || !isset($plans[$planId])) {
    json_response(['error' => 'Gói đổi dung lượng không hợp lệ.'], 400);
}
$plan = $plans[$planId];

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin storage redemption transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể đổi dung lượng lúc này.'], 500);
}
$account = pg_query_params($conn, 'SELECT coins FROM users WHERE id = $1 FOR UPDATE', [$userId]);
$accountRow = $account ? pg_fetch_assoc($account) : false;
if (!$accountRow) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to lock account for storage redemption: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể đọc số dư xu.'], 500);
}
if ((int) $accountRow['coins'] < $plan['coins']) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Bạn chưa có đủ xu để đổi gói này.'], 409);
}

$deducted = pg_query_params($conn, 'UPDATE users SET coins = coins - $2 WHERE id = $1', [$userId, $plan['coins']]);
$ledger = $deducted
    ? pg_query_params(
        $conn,
        'INSERT INTO reward_ledger (user_id, amount, reason, description) VALUES ($1, $2, $3, $4)',
        [$userId, -$plan['coins'], 'storage_redemption', 'Đổi dung lượng tạm thời ' . $plan['label']]
    )
    : false;
$boost = $ledger
    ? pg_query_params(
        $conn,
        'INSERT INTO storage_boosts (user_id, quota_bytes, expires_at, source)
         VALUES (
            $1, $2,
            CURRENT_TIMESTAMP + make_interval(days => $3, months => $4, years => $5),
            $6
         )
         RETURNING expires_at',
        [$userId, $plan['bytes'], $plan['days'], $plan['months'], $plan['years'], 'coin_' . $planId]
    )
    : false;
if (!$deducted || !$ledger || !$boost) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to redeem storage: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể đổi gói dung lượng.'], 500);
}
$expiry = pg_fetch_result($boost, 0, 'expires_at');
$committed = pg_query($conn, 'COMMIT');
if (!$committed) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit storage redemption: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể hoàn tất đổi dung lượng.'], 500);
}

json_response([
    'coins' => (int) $accountRow['coins'] - $plan['coins'],
    'boost_bytes' => $plan['bytes'],
    'expires_at' => $expiry,
    'message' => "Đã đổi gói {$plan['label']}. Dung lượng cộng thêm có hiệu lực đến {$expiry}.",
]);
