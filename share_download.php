<?php
declare(strict_types=1);

function fail_share_download(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

function render_share_password_form(string $token, string $message = '', int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $csrfToken = htmlspecialchars($_SESSION['share_download_csrf'], ENT_QUOTES, 'UTF-8');
    $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Nhập mật khẩu để tải tệp</title><body style="font:16px system-ui,sans-serif;max-width:460px;margin:12vh auto;padding:20px">';
    echo '<h1>Liên kết được bảo vệ</h1><p>Nhập mật khẩu để tiếp tục tải tệp.</p>';
    if ($safeMessage !== '') {
        echo '<p role="alert">' . $safeMessage . '</p>';
    }
    echo '<form method="post"><input type="hidden" name="token" value="' . $safeToken . '">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
    echo '<label>Mật khẩu <input type="password" name="password" required autofocus autocomplete="current-password"></label> ';
    echo '<button type="submit">Tải tệp</button></form></body></html>';
    exit;
}

try {
    require_once __DIR__ . '/config/app.php';
    require_once __DIR__ . '/config/database.php';
} catch (Throwable $error) {
    error_log('Share download initialization failed: ' . $error->getMessage());
    fail_share_download('Dịch vụ hiện không khả dụng.', 500);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['share_download_csrf'])) {
    $_SESSION['share_download_csrf'] = bin2hex(random_bytes(32));
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    fail_share_download('Phương thức yêu cầu không được hỗ trợ.', 405);
}
$token = $method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    fail_share_download('Liên kết chia sẻ không hợp lệ.', 400);
}

$result = pg_query_params(
    $conn,
    'SELECT sl.id, sl.password_hash, sl.download_limit, sl.download_count,
            sl.expires_at, sl.is_active, sl.failed_attempts, sl.locked_until,
            f.file_name, f.file_path, f.file_type
     FROM share_links sl
     JOIN files f ON f.id = sl.file_id
     WHERE sl.token = $1',
    [$token]
);
if (!$result) {
    error_log('Failed to look up share link: ' . pg_last_error($conn));
    fail_share_download('Không thể tải tệp lúc này.', 500);
}
$share = pg_fetch_assoc($result);
if (
    !$share
    || $share['is_active'] !== 't'
    || strtotime($share['expires_at']) <= time()
    || (int) $share['download_count'] >= (int) $share['download_limit']
) {
    fail_share_download('Liên kết này không còn hiệu lực.', 410);
}

if ($share['locked_until'] !== null && strtotime($share['locked_until']) <= time()) {
    $unlocked = pg_query_params(
        $conn,
        'UPDATE share_links
         SET failed_attempts = 0, locked_until = NULL
         WHERE id = $1 AND locked_until <= CURRENT_TIMESTAMP',
        [$share['id']]
    );
    if (!$unlocked) {
        error_log('Failed to reset share-link password lockout: ' . pg_last_error($conn));
        fail_share_download('Không thể xác minh liên kết lúc này.', 500);
    }
    $share['failed_attempts'] = '0';
    $share['locked_until'] = null;
}
if ($share['locked_until'] !== null && strtotime($share['locked_until']) > time()) {
    if ($method === 'GET' && $share['password_hash'] !== null) {
        render_share_password_form($token, 'Đã thử sai quá nhiều lần. Vui lòng thử lại sau.', 429);
    }
    fail_share_download('Đã thử sai quá nhiều lần. Vui lòng thử lại sau.', 429);
}

if ($share['password_hash'] !== null) {
    if ($method === 'GET') {
        render_share_password_form($token);
    }
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (
        !is_string($csrfToken)
        || !is_string($_SESSION['share_download_csrf'])
        || !hash_equals($_SESSION['share_download_csrf'], $csrfToken)
    ) {
        render_share_password_form($token, 'Phiên không hợp lệ. Vui lòng thử lại.', 403);
    }
    $password = $_POST['password'] ?? '';
    if (
        !is_string($password)
        || strlen($password) > 72
        || !password_verify($password, $share['password_hash'])
    ) {
        $failed = pg_query_params(
            $conn,
            'UPDATE share_links
             SET failed_attempts = failed_attempts + 1,
                 locked_until = CASE
                     WHEN failed_attempts + 1 >= 10
                     THEN CURRENT_TIMESTAMP + INTERVAL \'15 minutes\'
                     ELSE locked_until
                 END
             WHERE id = $1 AND is_active = TRUE',
            [$share['id']]
        );
        if (!$failed) {
            error_log('Failed to record incorrect share-link password: ' . pg_last_error($conn));
            fail_share_download('Không thể xác minh mật khẩu lúc này.', 500);
        }
        render_share_password_form($token, 'Mật khẩu không chính xác.', 403);
    }
} elseif ($method === 'POST') {
    fail_share_download('Yêu cầu không hợp lệ.', 400);
}

$storageRoot = realpath(FILESHARE_STORAGE_DIR);
$filePath = realpath(FILESHARE_STORAGE_DIR . '/' . $share['file_path']);
if (
    $storageRoot === false
    || $filePath === false
    || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR)
    || !is_file($filePath)
) {
    error_log('Shared-link file is missing from private storage.');
    fail_share_download('Không tìm thấy nội dung tệp.', 404);
}

$updated = pg_query_params(
    $conn,
    'UPDATE share_links
     SET download_count = download_count + 1, failed_attempts = 0, locked_until = NULL
     WHERE id = $1
       AND is_active = TRUE
       AND expires_at > CURRENT_TIMESTAMP
       AND download_count < download_limit
     RETURNING id',
    [$share['id']]
);
if (!$updated) {
    error_log('Failed to update share-link download count: ' . pg_last_error($conn));
    fail_share_download('Không thể tải tệp lúc này.', 500);
}
if (pg_num_rows($updated) === 0) {
    fail_share_download('Liên kết đã hết hạn hoặc đạt giới hạn lượt tải.', 410);
}

session_write_close();
header('Content-Type: ' . ($share['file_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($share['file_name']));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
