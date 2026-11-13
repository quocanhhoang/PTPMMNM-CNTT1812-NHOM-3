<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();

$account = pg_query_params(
    $conn,
    'SELECT coins, storage_limit
     FROM users WHERE id = $1',
    [$userId]
);
$online = pg_query_params(
    $conn,
    'SELECT online_seconds
     FROM online_daily
     WHERE user_id = $1 AND activity_day = CURRENT_DATE',
    [$userId]
);
$boosts = pg_query_params(
    $conn,
    'SELECT COALESCE(SUM(quota_bytes), 0)::bigint AS active_boost_bytes,
            MIN(expires_at) AS next_expiry
     FROM storage_boosts
     WHERE user_id = $1 AND starts_at <= CURRENT_TIMESTAMP AND expires_at > CURRENT_TIMESTAMP',
    [$userId]
);
if (!$account || !$online || !$boosts || !($accountRow = pg_fetch_assoc($account))) {
    error_log('Failed to load rewards page: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tải thông tin xu và nhiệm vụ.'], 500);
}

$periods = [
    ['type' => 'day', 'label' => 'Hôm nay', 'goal' => 5, 'coins' => 10],
    ['type' => 'week', 'label' => 'Tuần này', 'goal' => 20, 'coins' => 20],
    ['type' => 'month', 'label' => 'Tháng này', 'goal' => 50, 'coins' => 30],
];
$periodStartQueries = [
    'day' => 'SELECT CURRENT_DATE::date AS period_start',
    'week' => "SELECT date_trunc('week', CURRENT_DATE)::date AS period_start",
    'month' => "SELECT date_trunc('month', CURRENT_DATE)::date AS period_start",
];
$tasks = [];
foreach ($periods as $period) {
    $periodStartQuery = pg_query($conn, $periodStartQueries[$period['type']]);
    if (!$periodStartQuery) {
        error_log('Failed to calculate reward period: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tải tiến độ nhiệm vụ.'], 500);
    }
    $periodStart = (string) pg_fetch_result($periodStartQuery, 0, 'period_start');
    $periodEnd = match ($period['type']) {
        'day' => '1 day',
        'week' => '7 days',
        default => '1 month',
    };
    foreach (['upload' => ['Tải tệp', 'upload'], 'link_created' => ['Chia sẻ liên kết', 'share']] as $action => [$title, $key]) {
        $progressResult = pg_query_params(
            $conn,
            'SELECT COUNT(*)::integer
             FROM activity_logs
             WHERE user_id = $1 AND action = $2
               AND created_at >= $3::date AND created_at < $3::date + $4::interval',
            [$userId, $action, $periodStart, $periodEnd]
        );
        $claimResult = pg_query_params(
            $conn,
            'SELECT 1 FROM reward_claims
             WHERE user_id = $1 AND period_type = $2 AND period_start = $3::date AND mission_key = $4',
            [$userId, $period['type'], $periodStart, $key]
        );
        if (!$progressResult || !$claimResult) {
            error_log('Failed to load mission progress: ' . pg_last_error($conn));
            json_response(['error' => 'Không thể tải tiến độ nhiệm vụ.'], 500);
        }
        $tasks[] = [
            'id' => $period['type'] . '_' . $key,
            'period' => $period['label'],
            'title' => $title,
            'goal' => $period['goal'],
            'progress' => min($period['goal'], (int) pg_fetch_result($progressResult, 0, 0)),
            'coins' => $period['coins'],
            'claimed' => pg_num_rows($claimResult) > 0,
        ];
    }
}

$onlineSeconds = $online && pg_num_rows($online) > 0 ? (int) pg_fetch_result($online, 0, 'online_seconds') : 0;
foreach ([15, 30, 45, 60, 120, 180, 360] as $minutes) {
    $claimResult = pg_query_params(
        $conn,
        'SELECT 1 FROM reward_claims
         WHERE user_id = $1 AND period_type = \'day\' AND period_start = CURRENT_DATE AND mission_key = $2',
        [$userId, 'online_' . $minutes]
    );
    if (!$claimResult) {
        error_log('Failed to load online mission claim: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tải tiến độ online.'], 500);
    }
    $tasks[] = [
        'id' => 'online_' . $minutes,
        'period' => 'Hôm nay',
        'title' => "Online {$minutes} phút",
        'goal' => $minutes * 60,
        'progress' => min($minutes * 60, $onlineSeconds),
        'coins' => 50,
        'claimed' => pg_num_rows($claimResult) > 0,
    ];
}

$boostRow = pg_fetch_assoc($boosts);
json_response([
    'coins' => (int) $accountRow['coins'],
    'storage_quota_bytes' => (int) $accountRow['storage_limit'] + (int) $boostRow['active_boost_bytes'],
    'active_boost_bytes' => (int) $boostRow['active_boost_bytes'],
    'next_boost_expiry' => $boostRow['next_expiry'],
    'online_seconds_today' => $onlineSeconds,
    'tasks' => $tasks,
]);
