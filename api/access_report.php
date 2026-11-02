<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();

$result = pg_query_params(
    $conn,
    'SELECT al.created_at, al.action, f.file_name,
            CASE WHEN al.action = $2 THEN $3 ELSE COALESCE(u.full_name, $4) END AS actor_name,
            CASE WHEN al.action = $2 THEN $4 ELSE COALESCE(u.email, $4) END AS actor_email
     FROM activity_logs al
     LEFT JOIN files f ON f.id = al.file_id
     LEFT JOIN users u ON u.id = al.user_id
     WHERE f.owner_id = $1
     ORDER BY al.created_at DESC, al.id DESC',
    [$userId, 'download', 'Người tải (chưa xác thực)', '']
);
if (!$result) {
    error_log('Failed to export access report: ' . pg_last_error($conn));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Không thể xuất báo cáo truy cập.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bao-cao-truy-cap-' . date('Y-m-d') . '.csv"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Thời gian', 'Tệp', 'Hoạt động', 'Người truy cập', 'Email']);
$actionNames = [
    'upload' => 'Tải lên',
    'download' => 'Tải xuống',
    'view' => 'Xem',
    'permission_granted' => 'Cấp quyền',
    'link_created' => 'Tạo liên kết',
    'link_revoked' => 'Thu hồi liên kết',
];
while ($row = pg_fetch_assoc($result)) {
    fputcsv($output, [
        $row['created_at'],
        $row['file_name'] ?? '(tệp đã xóa)',
        $actionNames[$row['action']] ?? $row['action'],
        $row['actor_name'],
        $row['actor_email'],
    ]);
}
fclose($output);
