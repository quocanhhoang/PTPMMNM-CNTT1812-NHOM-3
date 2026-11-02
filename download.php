<?php
declare(strict_types=1);

function fail_download(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit($message);
}

try {
    require_once __DIR__ . '/config/app.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/lib.php';
} catch (Throwable $error) {
    error_log('Download initialization failed: ' . $error->getMessage());
    fail_download('Dịch vụ hiện không khả dụng.', 500);
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    fail_download('Phương thức yêu cầu không được hỗ trợ.', 405);
}

$shareCode = $_GET['code'] ?? '';
if (!is_string($shareCode) || !preg_match('/^[a-f0-9]{32}$/', $shareCode)) {
    fail_download('Liên kết chia sẻ không hợp lệ.', 400);
}

$result = pg_query_params(
    $conn,
    'SELECT id, owner_id, file_name, file_path, file_type, download_limit, download_count,
            expires_at, access_type, is_link_active, link_password_hash
     FROM public.files WHERE share_code = $1 AND deleted_at IS NULL',
    [$shareCode]
);
if ($result === false) {
    $databaseError = pg_last_error($conn);
    error_log('Failed to look up shared file: ' . $databaseError);
    fail_download(app_database_error_message($databaseError, 'Không thể tải tệp lúc này.'), 500);
}
$file = pg_fetch_assoc($result);
if (!$file || $file['is_link_active'] !== 't') {
    fail_download('Liên kết này không còn hoạt động.', 404);
}
if (!in_array($file['access_type'], ['public', 'private'], true)) {
    fail_download('Tệp này không được chia sẻ công khai.', 403);
}
$currentUserId = null;
if ($file['access_type'] === 'private') {
    try {
        app_session_start();
        $currentUser = app_user();
    } catch (Throwable $error) {
        error_log('Unable to verify private file download permission: ' . $error->getMessage());
        fail_download('Không thể xác minh quyền tải tệp lúc này.', 500);
    }

    if ($currentUser === null) {
        fail_download('Đăng nhập bằng tài khoản được cấp quyền để tải tệp này.', 401);
    }
    $currentUserId = $currentUser['id'];
    if ((string)$currentUser['id'] !== (string)$file['owner_id']) {
        $permission = pg_query_params(
            $conn,
            'SELECT 1 FROM public.file_permissions
             WHERE file_id = $1 AND user_id = $2
             LIMIT 1',
            [$file['id'], $currentUser['id']]
        );
        if ($permission === false) {
            $databaseError = pg_last_error($conn);
            error_log('Failed to verify file permission: ' . $databaseError);
            fail_download(app_database_error_message($databaseError, 'Không thể xác minh quyền tải tệp lúc này.'), 500);
        }
        if (pg_num_rows($permission) === 0) {
            fail_download('Tài khoản này chưa được cấp quyền tải tệp.', 403);
        }
    }
}
if ($file['expires_at'] !== null && strtotime($file['expires_at']) <= time()) {
    fail_download('Liên kết chia sẻ đã hết hạn.', 410);
}
if ($file['download_limit'] !== null && (int)$file['download_count'] >= (int)$file['download_limit']) {
    fail_download('Tệp đã đạt giới hạn lượt tải.', 410);
}

if ($file['link_password_hash'] !== null
    && !password_verify((string)($_POST['password'] ?? ''), $file['link_password_hash'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $escapedName = htmlspecialchars($file['file_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $message = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? '<p class="error">Mật khẩu không chính xác. Vui lòng thử lại.</p>'
        : '';
    echo '<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nhập mật khẩu — FileShare</title><style>body{font:16px system-ui,sans-serif;background:#090d18;color:#edf1fb;display:grid;min-height:100vh;place-items:center;margin:0}.card{width:min(400px,calc(100% - 40px));padding:28px;border:1px solid #293548;border-radius:16px;background:#111827}label{display:grid;gap:8px}input,button{font:inherit;padding:12px;border-radius:8px}input{border:1px solid #354257;background:#0c1421;color:inherit}button{margin-top:16px;width:100%;border:0;background:#6855e8;color:white;cursor:pointer}.error{color:#ff9ba8}</style><main class="card"><h1>Nhập mật khẩu</h1><p>Tệp: ' . $escapedName . '</p><form method="post"><label>Mật khẩu liên kết<input type="password" name="password" required autofocus autocomplete="current-password"></label><button type="submit">Tiếp tục tải tệp</button></form>' . $message . '</main></html>';
    exit;
}

$storageRoot = realpath(FILESHARE_STORAGE_DIR);
$filePath = realpath(FILESHARE_STORAGE_DIR . '/' . $file['file_path']);
if (
    $storageRoot === false
    || $filePath === false
    || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR)
    || !is_file($filePath)
    || !is_readable($filePath)
) {
    error_log('Shared file is missing from private storage.');
    $missingActivity = pg_query_params(
        $conn,
        'INSERT INTO public.activity_logs (user_id, file_id, action)
         SELECT $1, $2, \'file_missing\'
         WHERE NOT EXISTS (
             SELECT 1 FROM public.activity_logs
             WHERE user_id = $1 AND file_id = $2 AND action = \'file_missing\'
         )',
        [$file['owner_id'], $file['id']]
    );
    if ($missingActivity === false) {
        error_log('Failed to record missing shared file: ' . pg_last_error($conn));
    }
    fail_download('Không tìm thấy nội dung tệp.', 404);
}

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin download transaction: ' . pg_last_error($conn));
    fail_download('Không thể ghi nhận lượt tải xuống.', 500);
}

$updated = pg_query_params(
    $conn,
    'UPDATE public.files
     SET download_count = download_count + 1, updated_at = CURRENT_TIMESTAMP
     WHERE id = $1
       AND is_link_active = TRUE
       AND deleted_at IS NULL
       AND access_type = $2
       AND (download_limit IS NULL OR download_count < download_limit)
       AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
       AND (
           $2 = $4
           OR (
               EXISTS (
                   SELECT 1 FROM public.users u
                   WHERE u.id = $3 AND u.status = \'active\'
               )
               AND (
                   public.files.owner_id = $3
                   OR EXISTS (
                       SELECT 1 FROM public.file_permissions fp
                       WHERE fp.file_id = public.files.id AND fp.user_id = $3
                   )
               )
           )
       )
     RETURNING id',
    [$file['id'], $file['access_type'], $currentUserId, 'public']
);
if ($updated === false) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to update download count: ' . $databaseError);
    fail_download(app_database_error_message($databaseError, 'Không thể tải tệp lúc này.'), 500);
}
if (pg_num_rows($updated) === 0) {
    pg_query($conn, 'ROLLBACK');
    fail_download('Liên kết đã hết hạn hoặc đạt giới hạn lượt tải.', 410);
}

$activity = pg_query_params(
    $conn,
    'INSERT INTO public.activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)',
    [$file['owner_id'], $file['id'], 'download']
);
if ($activity === false) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to record successful download: ' . $databaseError);
    fail_download(app_database_error_message($databaseError, 'Không thể ghi nhận lượt tải xuống.'), 500);
}
if (!pg_query($conn, 'COMMIT')) {
    $databaseError = pg_last_error($conn);
    pg_query($conn, 'ROLLBACK');
    error_log('Failed to commit download history: ' . $databaseError);
    fail_download(app_database_error_message($databaseError, 'Không thể hoàn tất lượt tải xuống.'), 500);
}

header('Content-Type: ' . ($file['file_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($file['file_name']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
