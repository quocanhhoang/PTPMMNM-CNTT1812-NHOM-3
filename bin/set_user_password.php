<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 2 || !filter_var($argv[1], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Cách dùng: php bin/set_user_password.php email@example.com\n");
    exit(2);
}
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
fwrite(STDOUT, "Nhập mật khẩu mới (ít nhất 10 ký tự): ");
$password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 10 || strlen($password) > 4096) {
    fwrite(STDERR, "Mật khẩu cần có từ 10 đến 4096 ký tự.\n");
    exit(2);
}
$result = pg_query_params(
    $conn,
    'UPDATE users SET password_hash = $1 WHERE lower(email) = lower($2) RETURNING id',
    [password_hash($password, PASSWORD_DEFAULT), $argv[1]]
);
if (!$result || pg_num_rows($result) === 0) {
    fwrite(STDERR, "Không tìm thấy tài khoản hoặc không thể cập nhật mật khẩu.\n");
    exit(1);
}
echo "Đã đặt mật khẩu cho tài khoản.\n";
