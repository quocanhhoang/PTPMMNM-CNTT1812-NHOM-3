<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();
$folderId = $_GET['folder_id'] ?? null;
if ($folderId === '') $folderId = null;
if ($folderId !== null) {
    $folderId = filter_var($folderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) json_response(['error' => 'Thư mục được chọn không hợp lệ.'], 400);
}

$result = pg_query_params(
    $conn,
    'SELECT f.id, f.file_name, f.file_path, f.file_size, f.file_type, f.share_code, f.access_type,
            f.download_limit, f.download_count, f.expires_at, f.is_link_active,
            f.link_password_hash, f.created_at, f.folder_id, folders.name AS folder_name
     FROM public.files f
     LEFT JOIN public.folders folders ON folders.id = f.folder_id AND folders.owner_id = f.owner_id
     WHERE f.owner_id = $1
       AND f.deleted_at IS NULL
       AND ($2::bigint IS NULL OR f.folder_id = $2)
     ORDER BY f.created_at DESC, f.id DESC',
    [FILESHARE_CURRENT_USER_ID, $folderId]
);

if (!$result) {
    $databaseError = pg_last_error($conn);
    error_log('Failed to list files: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể tải danh sách tệp.'),
    ], 500);
}

$quotaResult = pg_query_params(
    $conn,
    'SELECT storage_limit FROM public.users WHERE id = $1',
    [FILESHARE_CURRENT_USER_ID]
);
if ($quotaResult === false || pg_num_rows($quotaResult) === 0) {
    $databaseError = pg_last_error($conn);
    error_log('Failed to load storage quota: ' . $databaseError);
    json_response([
        'error' => app_database_error_message($databaseError, 'Không thể tải hạn mức lưu trữ.'),
    ], 500);
}
$quota = (int)pg_fetch_result($quotaResult, 0, 'storage_limit');

$files = [];
while ($file = pg_fetch_assoc($result)) {
    $storageRoot = realpath(FILESHARE_STORAGE_DIR);
    $filePath = $storageRoot === false
        ? false
        : realpath(FILESHARE_STORAGE_DIR . '/' . $file['file_path']);
    $missing = $storageRoot === false
        || $filePath === false
        || !str_starts_with($filePath, $storageRoot . DIRECTORY_SEPARATOR)
        || !is_file($filePath)
        || !is_readable($filePath);
    if ($missing) {
        $logged = pg_query_params(
            $conn,
            'INSERT INTO public.activity_logs (user_id, file_id, action)
             SELECT $1, $2, \'file_missing\'
             WHERE NOT EXISTS (
                 SELECT 1 FROM public.activity_logs
                 WHERE user_id = $1 AND file_id = $2 AND action = \'file_missing\'
             )',
            [FILESHARE_CURRENT_USER_ID, $file['id']]
        );
        if ($logged === false) {
            $databaseError = pg_last_error($conn);
            error_log('Failed to record missing file: ' . $databaseError);
            json_response([
                'error' => app_database_error_message($databaseError, 'Không thể ghi nhận trạng thái tệp.'),
            ], 500);
        }
    }

    $active = $file['is_link_active'] === 't'
        && $file['access_type'] === 'public'
        && ($file['expires_at'] === null || strtotime($file['expires_at']) > time())
        && ($file['download_limit'] === null || (int)$file['download_count'] < (int)$file['download_limit']);
    if ($active) {
        $linkStatus = 'active';
    } elseif ($file['is_link_active'] !== 't') {
        $linkStatus = 'revoked';
    } elseif ($file['access_type'] !== 'public') {
        $linkStatus = 'private';
    } elseif ($file['expires_at'] !== null && strtotime($file['expires_at']) <= time()) {
        $linkStatus = 'expired';
    } else {
        $linkStatus = 'limit_reached';
    }
    $files[] = [
        'id' => (int) $file['id'],
        'file_name' => $file['file_name'],
        'file_size' => (int) $file['file_size'],
        'file_type' => $file['file_type'],
        'folder_id' => $file['folder_id'] === null ? null : (int)$file['folder_id'],
        'is_missing' => $missing,
        'share_code' => $file['share_code'],
        'access_type' => $file['access_type'],
        'download_limit' => $file['download_limit'] === null ? null : (int) $file['download_limit'],
        'download_count' => (int) $file['download_count'],
        'expires_at' => $file['expires_at'],
        'is_link_active' => $active,
        'link_status' => $linkStatus,
        'password_protected' => $file['link_password_hash'] !== null,
        'missing' => $missing,
        'download_url' => $file['share_code'] === null
            ? null
            : app_base_url() . '/download.php?code=' . rawurlencode($file['share_code']),
        'created_at' => $file['created_at'],
        'folder_name' => $file['folder_name'] ?? 'Tệp của tôi',
    ];
}

json_response(['files' => $files, 'quota' => $quota]);
