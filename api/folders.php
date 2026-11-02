<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function folder_database_error(PgSql\Result $result, string $operation): never
{
    $sqlState = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
    error_log($operation . ': ' . pg_result_error($result));

    if ($sqlState === '42P01') {
        json_response(['error' => 'Chưa khởi tạo bảng thư mục. Hãy chạy migration database/migrations/003_file_folders.sql rồi tải lại trang.'], 500);
    }
    if ($sqlState === '42501') {
        json_response([
            'error' => 'Tài khoản PostgreSQL của ứng dụng chưa đủ quyền. Cấp quyền SELECT, INSERT trên public.folders và USAGE trên sequence public.folders_id_seq; xem hướng dẫn trong README.md.',
        ], 500);
    }

    json_response(['error' => 'Không thể thao tác với thư mục trong PostgreSQL. Kiểm tra log máy chủ để biết chi tiết.'], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $defaultFolder = pg_query_params(
        $conn,
        'INSERT INTO public.folders (owner_id, name)
         VALUES ($1, $2)
         ON CONFLICT (owner_id, name) DO NOTHING',
        [FILESHARE_CURRENT_USER_ID, 'Tệp của tôi']
    );
    if ($defaultFolder === false) {
        folder_database_error($defaultFolder, 'Failed to ensure default folder');
    }

    $result = pg_query_params(
        $conn,
        'SELECT id, name, created_at
         FROM public.folders
         WHERE owner_id = $1
         ORDER BY created_at, id',
        [FILESHARE_CURRENT_USER_ID]
    );
    if ($result === false) {
        folder_database_error($result, 'Failed to list folders');
    }

    $folders = [];
    while ($folder = pg_fetch_assoc($result)) {
        $folders[] = [
            'id' => (string)$folder['id'],
            'name' => $folder['name'],
            'created_at' => $folder['created_at'],
        ];
    }
    json_response(['folders' => $folders]);
}

require_method('POST');
require_csrf_token();
$body = json_request_body();
$name = trim((string)($body['name'] ?? ''));
if ($name === '' || strlen($name) > 80 || preg_match('//u', $name) !== 1) {
    json_response(['error' => 'Tên thư mục phải có từ 1 đến 80 ký tự.'], 422);
}

$result = pg_query_params(
    $conn,
    'INSERT INTO public.folders (owner_id, name)
     VALUES ($1, $2)
     ON CONFLICT (owner_id, name) DO NOTHING
     RETURNING id, name, created_at',
    [FILESHARE_CURRENT_USER_ID, $name]
);
if ($result === false) {
    folder_database_error($result, 'Failed to create folder');
}
$folder = pg_fetch_assoc($result);
if ($folder === false) {
    json_response(['error' => 'Thư mục này đã tồn tại.'], 409);
}

json_response([
    'folder' => [
        'id' => (string)$folder['id'],
        'name' => $folder['name'],
        'created_at' => $folder['created_at'],
    ],
], 201);
