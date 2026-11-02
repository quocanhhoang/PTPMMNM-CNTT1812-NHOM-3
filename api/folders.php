<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function folder_database_error(PgSql\Result|false $result, string $operation): never
{
    global $conn;
    $errorMessage = $result === false ? pg_last_error($conn) : pg_result_error($result);
    $sqlState = $result === false ? null : pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
    error_log($operation . ': ' . $errorMessage);

    if ($sqlState === '42P01' || str_contains($errorMessage, 'relation "public.folders" does not exist')) {
        json_response(['error' => 'Chưa khởi tạo bảng thư mục. Hãy chạy migration database/migrations/001_accounts_folders_quota.sql rồi tải lại trang.'], 500);
    }
    if (
        $sqlState === '42703'
        || str_contains($errorMessage, 'column "parent_id"')
        || str_contains($errorMessage, 'column "updated_at"')
    ) {
        json_response([
            'error' => 'Cấu trúc bảng thư mục chưa được cập nhật. Hãy chạy migration database/migrations/004_folder_hierarchy.sql bằng tài khoản PostgreSQL quản trị rồi tải lại trang.',
        ], 500);
    }
    if ($sqlState === '42501') {
        json_response([
            'error' => 'Tài khoản PostgreSQL của ứng dụng chưa đủ quyền trên public.folders hoặc sequence public.folders_id_seq; xem hướng dẫn trong README.md.',
        ], 500);
    }

    json_response(['error' => 'Không thể thao tác với thư mục trong PostgreSQL. Kiểm tra log máy chủ để biết chi tiết.'], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $defaultFolder = pg_query_params(
        $conn,
        "INSERT INTO public.folders (owner_id, name)
         SELECT $1, 'Tệp của tôi'
         WHERE NOT EXISTS (
             SELECT 1 FROM public.folders WHERE owner_id = $1 AND parent_id IS NULL
         )",
        [FILESHARE_CURRENT_USER_ID]
    );
    if ($defaultFolder === false) {
        folder_database_error($defaultFolder, 'Failed to ensure default folder');
    }

    $result = pg_query_params(
        $conn,
        'SELECT f.id, f.name, f.parent_id, f.created_at,
                (SELECT COUNT(*)::int FROM public.files WHERE folder_id = f.id AND owner_id = f.owner_id AND deleted_at IS NULL) AS file_count,
                (SELECT COUNT(*)::int FROM public.folders child
                 WHERE child.parent_id = f.id AND child.owner_id = f.owner_id) AS folder_count
         FROM public.folders f
         WHERE f.owner_id = $1
         ORDER BY f.name, f.id',
        [FILESHARE_CURRENT_USER_ID]
    );
    if ($result === false) {
        folder_database_error($result, 'Failed to list folders');
    }

    $folders = [];
    while ($folder = pg_fetch_assoc($result)) {
        $folders[] = [
            'id' => (int)$folder['id'],
            'name' => $folder['name'],
            'parent_id' => $folder['parent_id'] === null ? null : (int)$folder['parent_id'],
            'file_count' => (int)$folder['file_count'],
            'folder_count' => (int)$folder['folder_count'],
            'created_at' => $folder['created_at'],
        ];
    }
    json_response(['folders' => $folders]);
}

require_method('POST');
require_csrf_token();
$body = json_request_body();
$action = $body['action'] ?? 'create';

if ($action === 'create') {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        json_response(['error' => 'Tên thư mục không hợp lệ (tối đa 255 byte).'], 400);
    }

    $parentId = $body['parent_id'] ?? null;
    if ($parentId !== null && $parentId !== '') {
        $parentId = filter_var($parentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parentId === false) {
            json_response(['error' => 'Thư mục cha không hợp lệ.'], 400);
        }
        $parent = pg_query_params(
            $conn,
            'SELECT 1 FROM public.folders WHERE id = $1 AND owner_id = $2',
            [$parentId, FILESHARE_CURRENT_USER_ID]
        );
        if ($parent === false) {
            folder_database_error($parent, 'Failed to validate parent folder');
        }
        if (pg_num_rows($parent) === 0) {
            json_response(['error' => 'Không tìm thấy thư mục cha.'], 404);
        }
    } else {
        $parentId = null;
    }

    $result = pg_query_params(
        $conn,
        'INSERT INTO public.folders (owner_id, name, parent_id)
         VALUES ($1, $2, $3)
         RETURNING id, name, parent_id, created_at',
        [FILESHARE_CURRENT_USER_ID, $name, $parentId]
    );
    if ($result === false) {
        folder_database_error($result, 'Failed to create folder');
    }
    json_response(['folder' => pg_fetch_assoc($result)], 201);
}

if ($action === 'move') {
    $folderId = filter_var($body['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) {
        json_response(['error' => 'Thư mục được chọn không hợp lệ.'], 400);
    }

    $parentId = $body['parent_id'] ?? null;
    if ($parentId !== null && $parentId !== '') {
        $parentId = filter_var($parentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parentId === false) {
            json_response(['error' => 'Thư mục cha không hợp lệ.'], 400);
        }
        $parent = pg_query_params(
            $conn,
            'SELECT 1 FROM public.folders WHERE id = $1 AND owner_id = $2',
            [$parentId, FILESHARE_CURRENT_USER_ID]
        );
        if ($parent === false) {
            folder_database_error($parent, 'Failed to validate target parent folder');
        }
        if (pg_num_rows($parent) === 0) {
            json_response(['error' => 'Không tìm thấy thư mục cha.'], 404);
        }

        $cycle = pg_query_params(
            $conn,
            'WITH RECURSIVE descendants(id) AS (
                SELECT id FROM public.folders WHERE id = $1 AND owner_id = $3
                UNION ALL
                SELECT child.id FROM public.folders child
                JOIN descendants parent ON child.parent_id = parent.id
                WHERE child.owner_id = $3
             )
             SELECT 1 FROM descendants WHERE id = $2',
            [$folderId, $parentId, FILESHARE_CURRENT_USER_ID]
        );
        if ($cycle === false) {
            folder_database_error($cycle, 'Failed to validate folder move');
        }
        if (pg_num_rows($cycle) > 0) {
            json_response(['error' => 'Không thể chuyển thư mục vào chính nó hoặc thư mục con của nó.'], 409);
        }
    } else {
        $parentId = null;
    }

    $moved = pg_query_params(
        $conn,
        'UPDATE public.folders SET parent_id = $3, updated_at = CURRENT_TIMESTAMP
         WHERE id = $1 AND owner_id = $2 RETURNING id',
        [$folderId, FILESHARE_CURRENT_USER_ID, $parentId]
    );
    if ($moved === false) {
        folder_database_error($moved, 'Failed to move folder');
    }
    if (pg_num_rows($moved) === 0) {
        json_response(['error' => 'Không tìm thấy thư mục.'], 404);
    }
    json_response(['success' => true]);
}

if ($action === 'delete') {
    $folderId = filter_var($body['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) {
        json_response(['error' => 'Thư mục được chọn không hợp lệ.'], 400);
    }
    $deleted = pg_query_params(
        $conn,
        'DELETE FROM public.folders f WHERE f.id = $1 AND f.owner_id = $2
         AND NOT EXISTS (SELECT 1 FROM public.folders child WHERE child.parent_id = f.id)
         AND NOT EXISTS (SELECT 1 FROM public.files WHERE folder_id = f.id AND owner_id = f.owner_id)
         RETURNING f.id',
        [$folderId, FILESHARE_CURRENT_USER_ID]
    );
    if ($deleted === false) {
        folder_database_error($deleted, 'Failed to delete folder');
    }
    if (pg_num_rows($deleted) === 0) {
        json_response(['error' => 'Không tìm thấy thư mục hoặc thư mục chưa trống.'], 409);
    }
    json_response(['success' => true]);
}

json_response(['error' => 'Thao tác thư mục không hợp lệ.'], 400);
