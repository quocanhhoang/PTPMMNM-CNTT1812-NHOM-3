<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
require_admin_user();

$query = $_GET['q'] ?? '';
if (!is_string($query)) {
    json_response(['error' => 'Từ khóa tìm kiếm không hợp lệ.'], 400);
}
$query = trim($query);
if (preg_match_all('/./us', $query, $characters) === false) {
    json_response(['error' => 'Từ khóa tìm kiếm phải là văn bản UTF-8 hợp lệ.'], 400);
}
if (count($characters[0]) > 200) {
    json_response(['error' => 'Từ khóa tìm kiếm không được vượt quá 200 ký tự.'], 400);
}
if ($query === '') {
    json_response(['users' => []]);
}

$result = pg_query_params(
    $conn,
    'SELECT id, full_name, email, role
     FROM users
     WHERE strpos(lower(full_name), lower($1)) > 0
     ORDER BY full_name, id',
    [$query]
);
if (!$result) {
    error_log('Failed to search users in administrator dashboard: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tra cứu người dùng.'], 500);
}

$users = [];
while ($user = pg_fetch_assoc($result)) {
    $users[] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}
json_response(['users' => $users]);
