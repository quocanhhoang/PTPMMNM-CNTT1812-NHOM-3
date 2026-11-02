<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Cách dùng: php bin/set_user_quota.php email@example.com dung_luong_MB\n");
    exit(2);
}
$megabytes = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1048576]]);
if ($megabytes === false) {
    fwrite(STDERR, "Dung lượng phải là số nguyên dương MB (tối đa 1 TB).\n");
    exit(2);
}
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
$quota = $megabytes * 1024 * 1024;
$result = pg_query_params(
    $conn,
    'UPDATE users SET storage_limit = $1, storage_quota_bytes = $1
     WHERE lower(email) = lower($2)
       AND $1::bigint >= (SELECT COALESCE(SUM(file_size), 0) FROM files WHERE owner_id = users.id)
     RETURNING id',
    [$quota, $argv[1]]
);
if (!$result || pg_num_rows($result) === 0) {
    fwrite(STDERR, "Không tìm thấy tài khoản hoặc dung lượng mới nhỏ hơn lượng đang sử dụng.\n");
    exit(1);
}
printf("Đã đặt quota %d MB cho tài khoản.\n", $megabytes);
