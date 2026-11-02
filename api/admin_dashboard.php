<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
require_admin_user();

$overview = pg_query(
    $conn,
    'SELECT (SELECT COUNT(*) FROM users)::bigint AS user_count,
            (SELECT COUNT(*) FROM files)::bigint AS file_count,
            (SELECT COALESCE(SUM(download_count), 0) FROM files)::bigint AS download_count,
            (SELECT COALESCE(SUM(file_size), 0) FROM files)::bigint AS storage_bytes'
);
$activity = pg_query(
    $conn,
    'SELECT al.action, al.created_at, f.file_name, u.full_name
     FROM activity_logs al
     LEFT JOIN files f ON f.id = al.file_id
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC, al.id DESC
     LIMIT 8'
);
$topFiles = pg_query(
    $conn,
    'SELECT f.file_name, f.download_count, u.full_name
     FROM files f JOIN users u ON u.id = f.owner_id
     WHERE f.download_count > 0
     ORDER BY f.download_count DESC, f.created_at DESC, f.id DESC
     LIMIT 5'
);
$downloads = pg_query(
    $conn,
    "SELECT created_at::date AS day, COUNT(*)::bigint AS download_count
     FROM activity_logs
     WHERE action = 'download'
       AND created_at >= CURRENT_DATE - INTERVAL '6 days'
       AND created_at < CURRENT_DATE + INTERVAL '1 day'
     GROUP BY created_at::date
     ORDER BY day"
);
if (!$overview || !$activity || !$topFiles || !$downloads) {
    error_log('Failed to load administrator dashboard: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tải dữ liệu dashboard quản trị.'], 500);
}

$overviewRow = pg_fetch_assoc($overview);
$recentActivity = [];
while ($row = pg_fetch_assoc($activity)) {
    $recentActivity[] = [
        'action' => $row['action'],
        'file_name' => $row['file_name'] ?? '(tệp đã xóa)',
        'full_name' => $row['full_name'] ?? 'Tài khoản đã xóa',
        'created_at' => $row['created_at'],
    ];
}
$popularFiles = [];
while ($row = pg_fetch_assoc($topFiles)) {
    $popularFiles[] = [
        'file_name' => $row['file_name'],
        'download_count' => (int) $row['download_count'],
        'owner_name' => $row['full_name'],
    ];
}
$downloadsByDay = [];
while ($row = pg_fetch_assoc($downloads)) {
    $downloadsByDay[$row['day']] = (int) $row['download_count'];
}
$chart = [];
for ($offset = 6; $offset >= 0; $offset--) {
    $day = (new DateTimeImmutable('today'))->modify("-{$offset} days")->format('Y-m-d');
    $chart[] = ['day' => $day, 'download_count' => $downloadsByDay[$day] ?? 0];
}

json_response([
    'overview' => [
        'user_count' => (int) $overviewRow['user_count'],
        'file_count' => (int) $overviewRow['file_count'],
        'download_count' => (int) $overviewRow['download_count'],
        'storage_bytes' => (int) $overviewRow['storage_bytes'],
    ],
    'recent_activity' => $recentActivity,
    'top_files' => $popularFiles,
    'downloads_7_days' => $chart,
]);
