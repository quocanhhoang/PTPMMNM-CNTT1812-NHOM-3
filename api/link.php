<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('POST');
require_csrf_token();

$body = json_request_body();
$fileId = filter_var($body['file_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$expiryDays = filter_var($body['expiry_days'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 3650],
]);
$downloadLimitInput = trim((string)($body['max_downloads'] ?? ''));
$downloadLimit = null;
if ($downloadLimitInput !== '') {
    $downloadLimit = filter_var($downloadLimitInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000000],
    ]);
}
$password = (string)($body['password'] ?? '');

if ($fileId === false) {
    json_response(['error' => 'Tệp được chọn không hợp lệ.'], 422);
}
if ($expiryDays === false) {
    json_response(['error' => 'Thời hạn liên kết phải từ 1 đến 3650 ngày.'], 422);
}
if ($downloadLimitInput !== '' && $downloadLimit === false) {
    json_response(['error' => 'Giới hạn tải phải từ 1 đến 1.000.000 lượt hoặc để trống.'], 422);
}
if (strlen($password) > 200) {
    json_response(['error' => 'Mật khẩu liên kết không được vượt quá 200 byte.'], 422);
}

$shareCode = bin2hex(random_bytes(16));
$passwordHash = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
$connection = $conn;
if (!pg_query($connection, 'BEGIN')) {
    error_log('Unable to begin link creation transaction: ' . pg_last_error($connection));
    json_response(['error' => 'Không thể tạo liên kết lúc này.'], 500);
}

try {
    $result = pg_query_params(
        $connection,
        'UPDATE public.files
         SET share_code = $1,
             access_type = $2,
             download_limit = $3,
             download_count = 0,
             expires_at = CURRENT_TIMESTAMP + ($4::integer * INTERVAL \'1 day\'),
             link_password_hash = $5,
             is_link_active = TRUE,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = $6 AND owner_id = $7 AND deleted_at IS NULL
         RETURNING id, file_name, share_code, access_type, download_limit, download_count,
                   expires_at, is_link_active, link_password_hash, created_at',
        [
            $shareCode,
            'public',
            $downloadLimit,
            $expiryDays,
            $passwordHash,
            $fileId,
            FILESHARE_CURRENT_USER_ID,
        ]
    );
    if ($result === false) {
        throw new RuntimeException(pg_last_error($connection));
    }
    $file = pg_fetch_assoc($result);
    if ($file === false) {
        pg_query($connection, 'ROLLBACK');
        json_response(['error' => 'Không tìm thấy tệp.'], 404);
    }

    $activity = pg_query_params(
        $connection,
        'INSERT INTO public.activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)',
        [FILESHARE_CURRENT_USER_ID, $file['id'], 'link_created']
    );
    if ($activity === false) {
        throw new RuntimeException(pg_last_error($connection));
    }
    if (!pg_query($connection, 'COMMIT')) {
        throw new RuntimeException(pg_last_error($connection));
    }
} catch (Throwable $error) {
    pg_query($connection, 'ROLLBACK');
    error_log('Unable to create file link: ' . $error->getMessage());
    json_response([
        'error' => app_database_error_message($error->getMessage(), 'Không thể lưu liên kết vào PostgreSQL.'),
    ], 500);
}

json_response([
    'link' => [
        'file_id' => (int)$file['id'],
        'file_name' => $file['file_name'],
        'share_code' => $file['share_code'],
        'url' => app_base_url() . '/download.php?code=' . rawurlencode($file['share_code']),
        'access' => $file['access_type'],
        'max_downloads' => $file['download_limit'] === null ? null : (int)$file['download_limit'],
        'downloads' => (int)$file['download_count'],
        'expires_at' => $file['expires_at'],
        'status' => 'active',
        'password_protected' => $file['link_password_hash'] !== null,
        'created_at' => $file['created_at'],
    ],
], 201);
