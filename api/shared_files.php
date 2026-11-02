<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_method('GET');
$userId = require_authenticated_user();

$result = pg_query_params(
    $conn,
    'SELECT f.id, f.file_name, f.file_path, f.file_size, f.file_type, f.share_code, f.access_type,
            f.download_count, f.is_link_active,
            GREATEST(
                CASE WHEN f.is_link_active THEN f.updated_at ELSE f.created_at END,
                COALESCE(MAX(fp.created_at), f.created_at)
            ) AS shared_at,
            COUNT(DISTINCT fp.user_id) AS recipient_count,
            COALESCE(
                string_agg(DISTINCT recipient.full_name, \', \' ORDER BY recipient.full_name)
                    FILTER (WHERE recipient.id IS NOT NULL),
                \'\'
            ) AS recipient_names
     FROM files f
     LEFT JOIN file_permissions fp ON fp.file_id = f.id
     LEFT JOIN users recipient ON recipient.id = fp.user_id
     WHERE f.owner_id = $1
       AND ((f.access_type = \'public\' AND f.is_link_active = TRUE) OR fp.id IS NOT NULL)
     GROUP BY f.id
     ORDER BY GREATEST(
         CASE WHEN f.is_link_active THEN f.updated_at ELSE f.created_at END,
         COALESCE(MAX(fp.created_at), f.created_at)
     ) DESC, f.id DESC',
    [$userId]
);
if (!$result) {
    error_log('Failed to load files shared by user: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tải danh sách tệp được chia sẻ.'], 500);
}

$files = [];
while ($file = pg_fetch_assoc($result)) {
    $root = realpath(FILESHARE_STORAGE_DIR);
    $path = $root === false ? false : realpath(FILESHARE_STORAGE_DIR . '/' . $file['file_path']);
    $available = $root !== false && $path !== false
        && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path);
    $files[] = [
        'id' => (int) $file['id'],
        'file_name' => $file['file_name'],
        'file_size' => (int) $file['file_size'],
        'file_type' => $file['file_type'],
        'share_code' => $file['share_code'],
        'access_type' => $file['access_type'],
        'download_count' => (int) $file['download_count'],
        'is_link_active' => $file['is_link_active'] === 't',
        'shared_at' => $file['shared_at'],
        'recipient_count' => (int) $file['recipient_count'],
        'recipient_names' => $file['recipient_names'],
        'is_missing' => !$available,
    ];
}

json_response(['files' => $files]);
