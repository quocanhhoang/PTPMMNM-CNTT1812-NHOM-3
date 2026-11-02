<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        json_response(['error' => 'Phương thức yêu cầu không được hỗ trợ.'], 405);
    }
}

function require_csrf_token(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf'] ?? $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        json_response(['error' => 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.'], 403);
    }
}

function require_authenticated_user(): int
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($userId === false) {
        json_response(['error' => 'Vui lòng đăng nhập để tiếp tục.'], 401);
    }
    return $userId;
}

function require_admin_user(): int
{
    $userId = require_authenticated_user();
    $result = pg_query_params($GLOBALS['conn'], 'SELECT role FROM users WHERE id = $1', [$userId]);
    if (!$result) {
        error_log('Failed to verify administrator role: ' . pg_last_error($GLOBALS['conn']));
        json_response(['error' => 'Không thể xác minh quyền quản trị.'], 500);
    }
    $account = pg_fetch_assoc($result);
    if (!$account || $account['role'] !== 'admin') {
        json_response(['error' => 'Bạn không có quyền truy cập chức năng quản trị.'], 403);
    }
    return $userId;
}

function json_request_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        json_response(['error' => 'Dữ liệu gửi lên không hợp lệ.'], 400);
    }

    return $body;
}

try {
    require_once dirname(__DIR__) . '/config/app.php';
    require_once dirname(__DIR__) . '/config/database.php';
    require_once dirname(__DIR__) . '/lib.php';

    app_session_start();
    $authenticatedUser = app_user();
    if ($authenticatedUser === null) {
        json_response(['error' => 'Vui lòng đăng nhập để tiếp tục.'], 401);
    }
    define('FILESHARE_CURRENT_USER_ID', (int)$authenticatedUser['id']);
} catch (Throwable $error) {
    error_log('API initialization failed: ' . $error->getMessage());
    json_response(['error' => 'Dịch vụ hiện không khả dụng.'], 500);
}
