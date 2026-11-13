<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reward_helpers.php';
require_method('POST');
require_csrf_token();
$body = json_request_body();
$action = $body['action'] ?? '';

if ($action === 'register') {
    $name = trim((string) ($body['full_name'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    if ($name === '' || strlen($name) > 360 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        json_response(['error' => 'Vui lòng nhập họ tên và email hợp lệ.'], 400);
    }
    if (strlen($password) < 10 || strlen($password) > 4096) {
        json_response(['error' => 'Mật khẩu phải có ít nhất 10 ký tự.'], 400);
    }
    $existing = pg_query_params($conn, 'SELECT 1 FROM users WHERE lower(email) = lower($1)', [$email]);
    if (!$existing) {
        error_log('Failed to check duplicate account: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tạo tài khoản lúc này.'], 500);
    }
    if (pg_num_rows($existing) > 0) {
        json_response(['error' => 'Email này đã được đăng ký.'], 409);
    }
    $usernameBase = strtolower(explode('@', $email, 2)[0]);
    $usernameBase = trim((string) preg_replace('/[^a-z0-9_.-]+/', '_', $usernameBase), '._-');
    $usernameBase = substr($usernameBase !== '' ? $usernameBase : 'user', 0, 20);
    $username = $usernameBase;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $nameCheck = pg_query_params($conn, 'SELECT 1 FROM users WHERE lower(username) = lower($1)', [$username]);
        if (!$nameCheck) {
            error_log('Failed to check username: ' . pg_last_error($conn));
            json_response(['error' => 'Không thể tạo tài khoản lúc này.'], 500);
        }
        if (pg_num_rows($nameCheck) === 0) break;
        $username = substr($usernameBase, 0, 11) . '_' . bin2hex(random_bytes(4));
    }
    $created = pg_query_params(
        $conn,
        'INSERT INTO users (username, full_name, email, password_hash, storage_limit, storage_quota_bytes)
         VALUES ($1, $2, $3, $4, $5, $5) RETURNING id, full_name, email, role',
        [$username, $name, $email, password_hash($password, PASSWORD_DEFAULT), FILESHARE_DEFAULT_QUOTA_BYTES]
    );
    if (!$created) {
        $databaseError = pg_last_error($conn);
        error_log('Failed to create account: ' . $databaseError);
        if (str_contains($databaseError, 'duplicate key value violates unique constraint')) {
            json_response(['error' => 'Email hoặc tên đăng nhập đã được sử dụng.'], 409);
        }
        json_response(['error' => 'Không thể tạo tài khoản do lỗi cơ sở dữ liệu. Hãy kiểm tra migration và nhật ký máy chủ.'], 500);
    }
    $user = pg_fetch_assoc($created);
} elseif ($action === 'login') {
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        json_response(['error' => 'Email hoặc mật khẩu không đúng.'], 401);
    }
    $result = pg_query_params(
        $conn,
        'SELECT id, full_name, email, password_hash, role FROM users WHERE lower(email) = lower($1)',
        [$email]
    );
    $record = $result ? pg_fetch_assoc($result) : false;
    if (!$record || empty($record['password_hash']) || !password_verify($password, $record['password_hash'])) {
        json_response(['error' => 'Email hoặc mật khẩu không đúng.'], 401);
    }
    if (password_needs_rehash($record['password_hash'], PASSWORD_DEFAULT)) {
        pg_query_params($conn, 'UPDATE users SET password_hash = $1 WHERE id = $2', [password_hash($password, PASSWORD_DEFAULT), $record['id']]);
    }
    $user = $record;
} else {
    json_response(['error' => 'Thao tác đăng nhập không hợp lệ.'], 400);
}

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin login reward transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể cập nhật thưởng đăng nhập.'], 500);
}
$loginDay = pg_query($conn, 'SELECT CURRENT_DATE::text AS today, EXTRACT(DOW FROM CURRENT_DATE)::integer AS day_of_week');
if (!$loginDay) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to determine login reward date: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xác định ngày nhận thưởng.'], 500);
}
$day = pg_fetch_assoc($loginDay);
try {
    grant_reward_claim(
        $conn,
        (int) $user['id'],
        'day',
        $day['today'],
        'login',
        (int) $day['day_of_week'] === 0 ? 50 : 25,
        (int) $day['day_of_week'] === 0 ? 'Thưởng đăng nhập Chủ nhật' : 'Thưởng đăng nhập hàng ngày'
    );
} catch (Throwable $error) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to grant login reward: ' . $error->getMessage());
    json_response(['error' => 'Không thể ghi nhận thưởng đăng nhập.'], 500);
}
if (!pg_query($conn, 'COMMIT')) {
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit login reward: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể lưu thưởng đăng nhập.'], 500);
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user_name'] = $user['full_name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
json_response(['user' => [
    'id' => (int) $user['id'],
    'full_name' => $user['full_name'],
    'email' => $user['email'],
    'role' => $user['role'],
    'csrf_token' => $_SESSION['csrf_token'],
]]);
