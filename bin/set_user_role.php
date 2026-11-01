<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Cách dùng: php bin/set_user_role.php email@example.com user|admin\n");
    exit(1);
}

$email = strtolower(trim($argv[1]));
$role = $argv[2];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['user', 'admin'], true)) {
    fwrite(STDERR, "Email hoặc vai trò không hợp lệ.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/database.php';
$result = pg_query_params($conn, 'UPDATE users SET role = $1 WHERE lower(email) = lower($2) RETURNING id', [$role, $email]);
if (!$result) {
    fwrite(STDERR, "Không thể cập nhật vai trò: " . pg_last_error($conn) . "\n");
    exit(1);
}
if (pg_num_rows($result) === 0) {
    fwrite(STDERR, "Không tìm thấy tài khoản có email này.\n");
    exit(1);
}

fwrite(STDOUT, "Đã đặt vai trò {$role} cho {$email}.\n");
