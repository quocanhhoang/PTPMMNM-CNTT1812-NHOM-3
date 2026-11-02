<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$userId = require_authenticated_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = pg_query_params(
        $conn,
        'SELECT id, type, title, message, created_at, read_at
         FROM notifications WHERE user_id = $1
         ORDER BY created_at DESC, id DESC LIMIT 20',
        [$userId]
    );
    $unread = pg_query_params($conn, 'SELECT COUNT(*) FROM notifications WHERE user_id = $1 AND read_at IS NULL', [$userId]);
    if (!$result || !$unread) {
        error_log('Failed to load notifications: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tải thông báo.'], 500);
    }
    $notifications = [];
    while ($notification = pg_fetch_assoc($result)) {
        $notifications[] = [
            'id' => (int) $notification['id'],
            'type' => $notification['type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'created_at' => $notification['created_at'],
            'is_read' => $notification['read_at'] !== null,
        ];
    }
    json_response(['notifications' => $notifications, 'unread_count' => (int) pg_fetch_result($unread, 0, 0)]);
}

require_method('POST');
require_csrf_token();
$body = json_request_body();
if (($body['action'] ?? '') !== 'mark_read') {
    json_response(['error' => 'Thao tác thông báo không hợp lệ.'], 400);
}
$updated = pg_query_params($conn, 'UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = $1 AND read_at IS NULL', [$userId]);
if (!$updated) {
    error_log('Failed to mark notifications as read: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể cập nhật trạng thái thông báo.'], 500);
}
json_response(['success' => true]);
