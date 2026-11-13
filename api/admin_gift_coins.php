<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
require_csrf_token();
require_admin_user();
$body = json_request_body();
$userId = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$coins = filter_var($body['coins'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000000]]);
if ($userId === false || $coins === false) {
    json_response(['error' => 'Người nhận hoặc số xu không hợp lệ.'], 400);
}

$eligible = pg_query_params(
    $conn,
    "WITH mission_totals AS (
        SELECT u.id, COUNT(rc.id)::bigint AS value
        FROM users u LEFT JOIN reward_claims rc ON rc.user_id = u.id
        WHERE u.role = 'user' GROUP BY u.id
     ), online_totals AS (
        SELECT u.id, COALESCE(SUM(od.online_seconds), 0)::bigint AS value
        FROM users u LEFT JOIN online_daily od ON od.user_id = u.id
        WHERE u.role = 'user' GROUP BY u.id
     ), eligible_users AS (
        (SELECT id FROM users WHERE role = 'user' ORDER BY coins DESC, id LIMIT 10)
        UNION
        (SELECT id FROM mission_totals ORDER BY value DESC, id LIMIT 10)
        UNION
        (SELECT id FROM online_totals ORDER BY value DESC, id LIMIT 10)
     )
     SELECT id, full_name FROM users WHERE id = $1 AND id IN (SELECT id FROM eligible_users)",
    [$userId]
);
if (!$eligible) {
    error_log('Failed to validate administrator coin gift recipient: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xác minh người nhận quà.'], 500);
}
$recipient = pg_fetch_assoc($eligible);
if (!$recipient) {
    json_response(['error' => 'Chỉ có thể tặng xu cho người dùng đang nằm trong một bảng top 10.'], 409);
}

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin administrator coin gift: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tặng xu lúc này.'], 500);
}
$updated = pg_query_params($conn, 'UPDATE users SET coins = coins + $2 WHERE id = $1 RETURNING coins', [$userId, $coins]);
$ledger = $updated
    ? pg_query_params(
        $conn,
        'INSERT INTO reward_ledger (user_id, amount, reason, description) VALUES ($1, $2, $3, $4)',
        [$userId, $coins, 'admin_gift', 'Xu được quản trị viên tặng']
    )
    : false;
if (!$updated || !$ledger) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to record administrator coin gift: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể lưu xu tặng.'], 500);
}
$newBalance = (int) pg_fetch_result($updated, 0, 'coins');
if (!pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit administrator coin gift: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể hoàn tất tặng xu.'], 500);
}

json_response([
    'recipient' => $recipient['full_name'],
    'coins' => $coins,
    'balance' => $newBalance,
]);
