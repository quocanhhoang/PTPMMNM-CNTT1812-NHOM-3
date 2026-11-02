<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();
$fileId = filter_var($_GET['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($fileId === false) {
    json_response(['error' => 'Tệp được chọn không hợp lệ.'], 400);
}

$result = pg_query_params(
    $conn,
    'SELECT fp.created_at, recipient.full_name, recipient.email, grantor.full_name AS granted_by_name
     FROM file_permissions fp
     JOIN files f ON f.id = fp.file_id
     JOIN users recipient ON recipient.id = fp.user_id
     LEFT JOIN users grantor ON grantor.id = fp.granted_by
     WHERE fp.file_id = $1 AND f.owner_id = $2
     ORDER BY fp.created_at DESC, fp.id DESC',
    [$fileId, $userId]
);
if (!$result) {
    error_log('Failed to load permission history: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tải lịch sử cấp quyền.'], 500);
}
$history = [];
while ($row = pg_fetch_assoc($result)) {
    $history[] = $row;
}
json_response(['history' => $history]);
