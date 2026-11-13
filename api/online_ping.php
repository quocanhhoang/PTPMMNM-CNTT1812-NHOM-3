<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reward_helpers.php';
require_method('POST');
require_csrf_token();
$userId = require_authenticated_user();

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin online tracking transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể cập nhật thời gian online.'], 500);
}

$insert = pg_query_params(
    $conn,
    'INSERT INTO online_daily (user_id, activity_day, online_seconds, last_heartbeat)
     VALUES ($1, CURRENT_DATE, 0, CURRENT_TIMESTAMP)
     ON CONFLICT (user_id, activity_day) DO NOTHING',
    [$userId]
);
$current = $insert
    ? pg_query_params(
        $conn,
        'SELECT online_seconds, last_heartbeat, activity_day::text FROM online_daily
         WHERE user_id = $1 AND activity_day = CURRENT_DATE FOR UPDATE',
        [$userId]
    )
    : false;
$row = $current ? pg_fetch_assoc($current) : false;
if (!$row) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to load online heartbeat: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể cập nhật thời gian online.'], 500);
}

$updated = pg_query_params(
    $conn,
    'UPDATE online_daily
     SET online_seconds = online_seconds + LEAST(60, GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - last_heartbeat)))::integer)),
         last_heartbeat = CURRENT_TIMESTAMP
     WHERE user_id = $1 AND activity_day = CURRENT_DATE
     RETURNING online_seconds, activity_day::text',
    [$userId]
);
if (!$updated) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to update online time: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể cập nhật thời gian online.'], 500);
}
$onlineSeconds = (int) pg_fetch_result($updated, 0, 'online_seconds');
$activityDay = (string) pg_fetch_result($updated, 0, 'activity_day');
foreach ([15, 30, 45, 60, 120, 180, 360] as $minutes) {
    if ($onlineSeconds < $minutes * 60) continue;
    try {
        grant_reward_claim(
            $conn,
            $userId,
            'day',
            $activityDay,
            'online_' . $minutes,
            50,
            "Hoàn thành mốc online {$minutes} phút"
        );
    } catch (Throwable $error) {
        pg_query($conn, 'ROLLBACK');
        error_log('Failed to award online mission: ' . $error->getMessage());
        json_response(['error' => 'Không thể cập nhật thưởng nhiệm vụ online.'], 500);
    }
}

if (!pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit online time: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể lưu thời gian online.'], 500);
}
json_response(['online_seconds_today' => $onlineSeconds]);
