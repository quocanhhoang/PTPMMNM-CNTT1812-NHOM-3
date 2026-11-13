<?php
declare(strict_types=1);

function grant_reward_claim(
    $conn,
    int $userId,
    string $periodType,
    string $periodStart,
    string $missionKey,
    int $coins,
    string $description
): bool {
    $claim = pg_query_params(
        $conn,
        'INSERT INTO reward_claims (user_id, period_type, period_start, mission_key, coins_awarded)
         VALUES ($1, $2, $3::date, $4, $5)
         ON CONFLICT (user_id, period_type, period_start, mission_key) DO NOTHING
         RETURNING id',
        [$userId, $periodType, $periodStart, $missionKey, $coins]
    );
    if (!$claim) {
        throw new RuntimeException('Không thể ghi nhận nhiệm vụ nhận thưởng: ' . pg_last_error($conn));
    }
    if (pg_num_rows($claim) === 0) return false;

    $balance = pg_query_params($conn, 'UPDATE users SET coins = coins + $2 WHERE id = $1', [$userId, $coins]);
    if (!$balance) {
        throw new RuntimeException('Không thể cộng xu thưởng: ' . pg_last_error($conn));
    }
    $ledger = pg_query_params(
        $conn,
        'INSERT INTO reward_ledger (user_id, amount, reason, description) VALUES ($1, $2, $3, $4)',
        [$userId, $coins, $missionKey, $description]
    );
    if (!$ledger) {
        throw new RuntimeException('Không thể lưu lịch sử xu thưởng: ' . pg_last_error($conn));
    }
    return true;
}

function award_activity_missions($conn, int $userId, string $action): void
{
    $missionType = match ($action) {
        'upload' => ['key' => 'upload', 'label' => 'Tải tệp'],
        'link_created' => ['key' => 'share', 'label' => 'Chia sẻ liên kết'],
        default => null,
    };
    if ($missionType === null) return;

    $periods = [
        ['type' => 'day', 'label' => 'ngày', 'goal' => 5, 'coins' => 10],
        ['type' => 'week', 'label' => 'tuần', 'goal' => 20, 'coins' => 20],
        ['type' => 'month', 'label' => 'tháng', 'goal' => 50, 'coins' => 30],
    ];
    $periodStartQueries = [
        'day' => 'SELECT CURRENT_DATE::date AS period_start',
        'week' => "SELECT date_trunc('week', CURRENT_DATE)::date AS period_start",
        'month' => "SELECT date_trunc('month', CURRENT_DATE)::date AS period_start",
    ];
    $periodLengths = ['day' => '1 day', 'week' => '7 days', 'month' => '1 month'];
    foreach ($periods as $period) {
        $periodStart = pg_query($conn, $periodStartQueries[$period['type']]);
        if (!$periodStart) {
            throw new RuntimeException('Không thể xác định chu kỳ nhiệm vụ: ' . pg_last_error($conn));
        }
        $start = (string) pg_fetch_result($periodStart, 0, 'period_start');
        $count = pg_query_params(
            $conn,
            'SELECT COUNT(*)::integer
             FROM activity_logs
             WHERE user_id = $1 AND action = $2
               AND created_at >= $3::date
                 AND created_at < $3::date + $4::interval',
            [$userId, $action, $start, $periodLengths[$period['type']]]
        );
        if (!$count) {
            throw new RuntimeException('Không thể kiểm tra tiến độ nhiệm vụ: ' . pg_last_error($conn));
        }
        if ((int) pg_fetch_result($count, 0, 0) < $period['goal']) continue;

        grant_reward_claim(
            $conn,
            $userId,
            $period['type'],
            $start,
            $missionType['key'],
            $period['coins'],
            "Hoàn thành nhiệm vụ {$missionType['label']} {$period['goal']} lần trong {$period['label']}"
        );
    }
}
