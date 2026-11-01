<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$userId = require_authenticated_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = pg_query_params(
        $conn,
        'SELECT f.id, f.name, f.parent_id, f.created_at,
                (SELECT COUNT(*)::int FROM files WHERE folder_id = f.id) AS file_count,
                (SELECT COUNT(*)::int FROM folders child
                 WHERE child.parent_id = f.id AND child.owner_id = f.owner_id) AS folder_count
         FROM folders f
         WHERE f.owner_id = $1
         ORDER BY f.name, f.id',
        [$userId]
    );
    if (!$result) {
        error_log('Failed to list folders: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tải danh sách thư mục.'], 500);
    }
    $folders = [];
    while ($folder = pg_fetch_assoc($result)) {
        $folders[] = [
            'id' => (int) $folder['id'],
            'name' => $folder['name'],
            'parent_id' => $folder['parent_id'] === null ? null : (int) $folder['parent_id'],
            'file_count' => (int) $folder['file_count'],
            'folder_count' => (int) $folder['folder_count'],
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
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        json_response(['error' => 'Tên thư mục không hợp lệ (tối đa 255 byte).'], 400);
    }
    $parentId = $body['parent_id'] ?? null;
    if ($parentId !== null && $parentId !== '') {
        $parentId = filter_var($parentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parentId === false) json_response(['error' => 'Thư mục cha không hợp lệ.'], 400);
        $parent = pg_query_params($conn, 'SELECT 1 FROM folders WHERE id = $1 AND owner_id = $2', [$parentId, $userId]);
        if (!$parent) {
            error_log('Failed to validate parent folder: ' . pg_last_error($conn));
            json_response(['error' => 'Không thể xác minh thư mục cha.'], 500);
        }
        if (pg_num_rows($parent) === 0) json_response(['error' => 'Không tìm thấy thư mục cha.'], 404);
    } else {
        $parentId = null;
    }
    $created = pg_query_params(
        $conn,
        'INSERT INTO folders (owner_id, name, parent_id) VALUES ($1, $2, $3) RETURNING id, name, parent_id, created_at',
        [$userId, $name, $parentId]
    );
    if (!$created) {
        error_log('Failed to create folder: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tạo thư mục.'], 500);
    }
    json_response(['folder' => pg_fetch_assoc($created)], 201);
}

if ($action === 'move') {
    $folderId = filter_var($body['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) json_response(['error' => 'Thư mục được chọn không hợp lệ.'], 400);

    $parentId = $body['parent_id'] ?? null;
    if ($parentId !== null && $parentId !== '') {
        $parentId = filter_var($parentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parentId === false) json_response(['error' => 'Thư mục cha không hợp lệ.'], 400);
        $parent = pg_query_params($conn, 'SELECT 1 FROM folders WHERE id = $1 AND owner_id = $2', [$parentId, $userId]);
        if (!$parent) {
            error_log('Failed to validate target parent folder: ' . pg_last_error($conn));
            json_response(['error' => 'Không thể xác minh thư mục cha.'], 500);
        }
        if (pg_num_rows($parent) === 0) json_response(['error' => 'Không tìm thấy thư mục cha.'], 404);

        $cycle = pg_query_params(
            $conn,
            'WITH RECURSIVE descendants(id) AS (
                SELECT id FROM folders WHERE id = $1 AND owner_id = $3
                UNION ALL
                SELECT child.id FROM folders child
                JOIN descendants parent ON child.parent_id = parent.id
                WHERE child.owner_id = $3
             )
             SELECT 1 FROM descendants WHERE id = $2',
            [$folderId, $parentId, $userId]
        );
        if (!$cycle) {
            error_log('Failed to validate folder move: ' . pg_last_error($conn));
            json_response(['error' => 'Không thể xác minh vị trí thư mục.'], 500);
        }
        if (pg_num_rows($cycle) > 0) {
            json_response(['error' => 'Không thể chuyển thư mục vào chính nó hoặc thư mục con của nó.'], 409);
        }
    } else {
        $parentId = null;
    }

    $moved = pg_query_params(
        $conn,
        'UPDATE folders SET parent_id = $3, updated_at = CURRENT_TIMESTAMP
         WHERE id = $1 AND owner_id = $2 RETURNING id',
        [$folderId, $userId, $parentId]
    );
    if (!$moved) {
        error_log('Failed to move folder: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể chuyển thư mục.'], 500);
    }
    if (pg_num_rows($moved) === 0) json_response(['error' => 'Không tìm thấy thư mục.'], 404);
    json_response(['success' => true]);
}

if ($action === 'delete') {
    $folderId = filter_var($body['folder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) json_response(['error' => 'Thư mục được chọn không hợp lệ.'], 400);
    $deleted = pg_query_params(
        $conn,
        'DELETE FROM folders f WHERE f.id = $1 AND f.owner_id = $2
         AND NOT EXISTS (SELECT 1 FROM folders child WHERE child.parent_id = f.id)
         AND NOT EXISTS (SELECT 1 FROM files WHERE folder_id = f.id)
         RETURNING f.id',
        [$folderId, $userId]
    );
    if (!$deleted) {
        error_log('Failed to delete folder: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể xóa thư mục.'], 500);
    }
    if (pg_num_rows($deleted) === 0) {
        json_response(['error' => 'Không tìm thấy thư mục hoặc thư mục chưa trống.'], 409);
    }
    json_response(['success' => true]);
}

json_response(['error' => 'Thao tác thư mục không hợp lệ.'], 400);
