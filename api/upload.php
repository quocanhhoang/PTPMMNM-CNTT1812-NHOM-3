<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reward_helpers.php';
require_method('POST');
$userId = require_authenticated_user();
require_csrf_token();

$upload = $_FILES['file'] ?? null;
if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
    json_response(['error' => 'Vui lòng chọn tệp cần tải lên.'], 400);
}

if ($upload['error'] !== UPLOAD_ERR_OK) {
    $message = match ($upload['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Tệp vượt quá giới hạn tải lên của máy chủ.',
        UPLOAD_ERR_PARTIAL => 'Tệp chỉ được tải lên một phần. Vui lòng thử lại.',
        UPLOAD_ERR_NO_FILE => 'Vui lòng chọn tệp cần tải lên.',
        default => 'Không thể nhận tệp tải lên.',
    };
    json_response(['error' => $message], 400);
}

$fileSize = (int) $upload['size'];
if ($fileSize < 1 || $fileSize > FILESHARE_MAX_UPLOAD_BYTES) {
    json_response(['error' => 'Kích thước tệp phải từ 1 byte đến 1 GB.'], 400);
}

$fileName = trim((string) ($_POST['fileName'] ?? ''));
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (
    $fileName === ''
    || strlen($fileName) > 255
    || str_contains($fileName, '/')
    || str_contains($fileName, '\\')
    || preg_match('/[\x00-\x1F\x7F]/', $fileName)
) {
    json_response(['error' => 'Tên tệp không hợp lệ (tối đa 255 byte).'], 400);
}
if (!in_array($extension, FILESHARE_ALLOWED_EXTENSIONS, true)) {
    json_response(['error' => 'Định dạng tệp không được hỗ trợ. Hãy chọn một trong các định dạng được liệt kê trên màn hình tải lên.'], 415);
}

$accessType = $_POST['access'] ?? '';
if (!in_array($accessType, ['public', 'private'], true)) {
    json_response(['error' => 'Quyền truy cập không hợp lệ.'], 400);
}

$limitInput = trim((string) ($_POST['limit'] ?? ''));
$downloadLimit = $limitInput === '' ? null : filter_var($limitInput, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($downloadLimit === false) {
    json_response(['error' => 'Giới hạn lượt tải phải là số nguyên dương.'], 400);
}

$expiry = trim((string) ($_POST['expiry'] ?? ''));
if ($expiry !== '') {
    $expiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry);
    if (!$expiryDate || $expiryDate->format('Y-m-d') !== $expiry) {
        json_response(['error' => 'Ngày hết hạn không hợp lệ.'], 400);
    }
    if ($expiryDate < new DateTimeImmutable('today')) {
        json_response(['error' => 'Ngày hết hạn không được ở trong quá khứ.'], 400);
    }
} else {
    $expiry = null;
}

if (!is_uploaded_file($upload['tmp_name'])) {
    json_response(['error' => 'Tệp tải lên không hợp lệ.'], 400);
}

$folderId = $_POST['folder_id'] ?? null;
if ($folderId === '') $folderId = null;
if ($folderId !== null) {
    $folderId = filter_var($folderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($folderId === false) json_response(['error' => 'Thư mục lưu tệp không hợp lệ.'], 400);
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($upload['tmp_name']) ?: 'application/octet-stream';
$allowedMimeTypes = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip'],
    'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip'],
    'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip'],
    'txt' => ['text/plain'],
    'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
    'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
    'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
    'zip' => ['application/zip', 'application/x-zip-compressed'],
];
if (!in_array($mimeType, $allowedMimeTypes[$extension] ?? [], true)) {
    json_response(['error' => 'Nội dung tệp không khớp với phần mở rộng hoặc loại tệp không được hỗ trợ.'], 415);
}

if (!pg_query($conn, 'BEGIN')) {
    error_log('Unable to begin upload transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể kiểm tra dung lượng tài khoản.'], 500);
}
$accountResult = pg_query_params(
    $conn,
    'SELECT storage_limit + COALESCE((
        SELECT SUM(quota_bytes) FROM storage_boosts
        WHERE user_id = users.id AND starts_at <= CURRENT_TIMESTAMP AND expires_at > CURRENT_TIMESTAMP
     ), 0)::bigint AS effective_storage_limit
     FROM users WHERE id = $1 FOR UPDATE',
    [$userId]
);
$account = $accountResult ? pg_fetch_assoc($accountResult) : false;
$usageResult = $account ? pg_query_params($conn, 'SELECT COALESCE(SUM(file_size), 0)::bigint AS used FROM files WHERE owner_id = $1', [$userId]) : false;
$usage = $usageResult ? pg_fetch_result($usageResult, 0, 'used') : false;
if (!$account || $usage === false) {
    pg_query($conn, 'ROLLBACK');
    error_log('Unable to check storage quota: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể kiểm tra dung lượng tài khoản.'], 500);
}
if ((int) $usage + $fileSize > (int) $account['effective_storage_limit']) {
    pg_query($conn, 'ROLLBACK');
    json_response(['error' => 'Không đủ dung lượng tài khoản để lưu tệp này.'], 413);
}
if ($folderId !== null) {
    $folder = pg_query_params($conn, 'SELECT 1 FROM folders WHERE id = $1 AND owner_id = $2', [$folderId, $userId]);
    if (!$folder || pg_num_rows($folder) === 0) {
        pg_query($conn, 'ROLLBACK');
        json_response(['error' => 'Không tìm thấy thư mục đã chọn.'], 404);
    }
}

if (!is_dir(FILESHARE_STORAGE_DIR) && !mkdir(FILESHARE_STORAGE_DIR, 0750, true) && !is_dir(FILESHARE_STORAGE_DIR)) {
    pg_query($conn, 'ROLLBACK');
    error_log('Unable to create file storage directory.');
    json_response(['error' => 'Không thể chuẩn bị nơi lưu trữ tệp.'], 500);
}

$storedName = bin2hex(random_bytes(24));
$absolutePath = FILESHARE_STORAGE_DIR . '/' . $storedName;
$relativePath = $storedName;
if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
    pg_query($conn, 'ROLLBACK');
    error_log('Unable to move uploaded file into private storage.');
    json_response(['error' => 'Không thể lưu tệp tải lên.'], 500);
}

$shareCode = bin2hex(random_bytes(16));

try {
    $insertedFile = pg_query_params(
        $conn,
        'INSERT INTO files
            (owner_id, folder_id, file_name, file_path, file_size, file_type, share_code,
             access_type, download_limit, download_count, expires_at, is_link_active)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, 0,
                 ($10::date + INTERVAL \'1 day\' - INTERVAL \'1 second\'), TRUE)
         RETURNING id, created_at',
        [
            $userId,
            $folderId,
            $fileName,
            $relativePath,
            $fileSize,
            $mimeType,
            $shareCode,
            $accessType,
            $downloadLimit,
            $expiry,
        ]
    );
    if (!$insertedFile) {
        throw new RuntimeException(pg_last_error($conn));
    }

    $savedFile = pg_fetch_assoc($insertedFile);
    $activity = pg_query_params(
        $conn,
        'INSERT INTO activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)',
        [$userId, $savedFile['id'], 'upload']
    );
    if (!$activity) {
        throw new RuntimeException(pg_last_error($conn));
    }
    award_activity_missions($conn, $userId, 'upload');
    $notification = pg_query_params(
        $conn,
        "INSERT INTO notifications (user_id, type, title, message)
         VALUES ($1, 'upload', 'Tải tệp lên thành công', $2)",
        [$userId, 'Tệp "' . $fileName . '" đã được tải lên tài khoản của bạn.']
    );
    if (!$notification) {
        throw new RuntimeException(pg_last_error($conn));
    }

    if (!pg_query($conn, 'COMMIT')) {
        throw new RuntimeException(pg_last_error($conn));
    }
} catch (Throwable $error) {
    pg_query($conn, 'ROLLBACK');
    if (is_file($absolutePath)) {
        unlink($absolutePath);
    }
    error_log('Unable to save uploaded file: ' . $error->getMessage());
    json_response(['error' => 'Không thể lưu thông tin tệp vào cơ sở dữ liệu.'], 500);
}

json_response([
    'file' => [
        'id' => (int) $savedFile['id'],
        'file_name' => $fileName,
        'file_size' => $fileSize,
        'file_type' => $mimeType,
        'folder_id' => $folderId === null ? null : (int) $folderId,
        'is_missing' => false,
        'share_code' => $shareCode,
        'access_type' => $accessType,
        'download_limit' => $downloadLimit,
        'download_count' => 0,
        'expires_at' => $expiry,
        'is_link_active' => true,
        'created_at' => $savedFile['created_at'],
    ],
], 201);
