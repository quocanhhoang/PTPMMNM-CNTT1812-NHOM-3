<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';

$deleteRequested = in_array('--delete', $argv, true);
$unknownArgs = array_values(array_diff(array_slice($argv, 1), ['--delete']));
if ($unknownArgs !== []) {
    fwrite(STDERR, "Cách dùng: php bin/cleanup_expired.php [--delete]\n");
    exit(2);
}

$query = pg_query(
    $conn,
    'SELECT id, file_name, file_path, expires_at
     FROM files
     WHERE expires_at IS NOT NULL AND expires_at < CURRENT_TIMESTAMP
     ORDER BY expires_at, id'
);
if (!$query) {
    fwrite(STDERR, "Không thể lấy danh sách tệp hết hạn: " . pg_last_error($conn) . "\n");
    exit(1);
}
$files = [];
while ($row = pg_fetch_assoc($query)) {
    $files[] = $row;
}

echo "Danh sách tệp hết hạn sẽ được dọn:\n";
if ($files === []) {
    echo "Không có tệp nào cần dọn.\n";
    exit(0);
}
foreach ($files as $file) {
    printf("- #%s | %s | hết hạn: %s\n", $file['id'], $file['file_name'], $file['expires_at']);
}
if (!$deleteRequested) {
    echo "\nĐây chỉ là xem trước. Để xem lại danh sách và xác nhận xóa, chạy: php bin/cleanup_expired.php --delete\n";
    exit(0);
}

echo "\nNhập XOA để xóa các mục trên (mọi giá trị khác sẽ hủy): ";
$confirmation = trim((string) fgets(STDIN));
if ($confirmation !== 'XOA') {
    echo "Đã hủy, không có tệp nào bị xóa.\n";
    exit(0);
}

if (!pg_query($conn, 'BEGIN')) {
    fwrite(STDERR, "Không thể bắt đầu giao dịch dọn tệp.\n");
    exit(1);
}
$ids = array_column($files, 'id');
$idList = '{' . implode(',', array_map('intval', $ids)) . '}';
$current = pg_query_params(
    $conn,
    'SELECT id, file_name, file_path, expires_at FROM files
     WHERE id = ANY($1::bigint[]) AND expires_at < CURRENT_TIMESTAMP
     FOR UPDATE',
    [$idList]
);
$files = [];
if ($current) {
    while ($row = pg_fetch_assoc($current)) {
        $files[] = $row;
    }
}
if (!$current || $files === []) {
    pg_query($conn, 'ROLLBACK');
    echo "Danh sách đã thay đổi; không còn tệp hết hạn để xóa.\n";
    exit(0);
}
$currentIds = '{' . implode(',', array_map(static fn (array $file): int => (int) $file['id'], $files)) . '}';
$permissions = pg_query_params($conn, 'DELETE FROM file_permissions WHERE file_id = ANY($1::bigint[])', [$currentIds]);
$logs = $permissions ? pg_query_params($conn, 'DELETE FROM activity_logs WHERE file_id = ANY($1::bigint[])', [$currentIds]) : false;
$deleted = $logs ? pg_query_params(
    $conn,
    'DELETE FROM files WHERE id = ANY($1::bigint[]) RETURNING id',
    [$currentIds]
) : false;
if (!$permissions || !$logs || !$deleted || !pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    fwrite(STDERR, "Không thể xóa metadata trong PostgreSQL; không xóa tệp trên ổ đĩa.\n");
    error_log('Expired file cleanup failed: ' . pg_last_error($conn));
    exit(1);
}

$root = realpath(FILESHARE_STORAGE_DIR);
$removed = 0;
foreach ($files as $file) {
    $path = $root === false ? false : realpath(FILESHARE_STORAGE_DIR . '/' . $file['file_path']);
    if ($path === false) {
        continue;
    }
    if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        fwrite(STDERR, "Bỏ qua đường dẫn lưu trữ không hợp lệ cho tệp #{$file['id']}.\n");
        continue;
    }
    if (unlink($path)) {
        $removed++;
    } else {
        fwrite(STDERR, "Không thể xóa tệp vật lý #{$file['id']} ({$file['file_name']}).\n");
    }
}
printf("Đã xóa %d bản ghi hết hạn; xóa được %d tệp trên ổ đĩa.\n", pg_num_rows($deleted), $removed);
