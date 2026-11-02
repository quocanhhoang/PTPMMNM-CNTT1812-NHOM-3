<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');

$format = $_GET['format'] ?? 'json';
if (!in_array($format, ['json', 'csv'], true)) {
    json_response(['error' => 'Định dạng báo cáo không được hỗ trợ.'], 400);
}

$result = pg_query_params(
    $conn,
    'SELECT activity.id, activity.file_id, activity.action, activity.created_at,
            files.file_name
     FROM public.activity_logs activity
     LEFT JOIN public.files files
       ON files.id = activity.file_id AND files.owner_id = activity.user_id
     WHERE activity.user_id = $1
     ORDER BY activity.created_at DESC, activity.id DESC',
    [FILESHARE_CURRENT_USER_ID]
);

if ($result === false) {
    $databaseError = pg_last_error($conn);
    error_log('Failed to load access history: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể tải lịch sử truy cập.'),
    ], 500);
}

$events = [];
while ($row = pg_fetch_assoc($result)) {
    $events[] = [
        'id' => (int)$row['id'],
        'file_id' => $row['file_id'] === null ? null : (int)$row['file_id'],
        'event' => $row['action'],
        'created_at' => $row['created_at'],
        'file_name' => $row['file_name'] ?? '',
        'ip' => '',
        'details' => [],
    ];
}

if ($format === 'json') {
    json_response(['events' => array_slice($events, 0, 100)]);
}

$eventLabels = [
    'download' => 'Tải xuống',
    'file_missing' => 'Tệp bị thiếu trên ổ đĩa',
    'link_created' => 'Tạo liên kết',
    'link_revoked' => 'Thu hồi liên kết',
    'permission_granted' => 'Cấp quyền',
    'file_uploaded' => 'Tải tệp lên',
    'upload' => 'Tải tệp lên',
    'file_deleted' => 'Xóa tệp',
    'file_cleanup' => 'Dọn tệp',
];
$stream = fopen('php://temp', 'r+');
if ($stream === false) {
    error_log('Unable to allocate access report stream.');
    json_response(['error' => 'Không thể tạo báo cáo truy cập.'], 500);
}

fputcsv($stream, ['Thời gian', 'Sự kiện', 'Tên tệp', 'Địa chỉ IP', 'Chi tiết']);
foreach ($events as $event) {
    $values = [
        $event['created_at'],
        $eventLabels[$event['event']] ?? $event['event'],
        $event['file_name'],
        '',
        '',
    ];
    $values = array_map(
        static fn(string $value): string => preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value,
        $values
    );
    fputcsv($stream, $values);
}
rewind($stream);
$csv = stream_get_contents($stream);
fclose($stream);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bao-cao-truy-cap.csv"');
echo "\xEF\xBB\xBF" . $csv;
