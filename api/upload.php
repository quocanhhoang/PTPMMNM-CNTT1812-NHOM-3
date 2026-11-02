<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
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
    json_response(['error' => 'Kích thước tệp phải từ 1 byte đến 100 MB.'], 400);
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

$folderId = filter_var($_POST['folder_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($folderId === false) {
    json_response(['error' => 'Vui lòng chọn thư mục lưu tệp.'], 422);
}
$ownedFolder = pg_query_params(
    $conn,
    'SELECT id FROM public.folders WHERE id = $1 AND owner_id = $2',
    [$folderId, FILESHARE_CURRENT_USER_ID]
);
if ($ownedFolder === false) {
    error_log('Failed to verify upload folder ownership: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xác minh thư mục lưu.'], 500);
}
if (pg_num_rows($ownedFolder) === 0) {
    json_response(['error' => 'Thư mục không tồn tại hoặc không thuộc tài khoản của bạn.'], 422);
}

$downloadLimitInput = trim((string)($_POST['limit'] ?? ''));
$downloadLimit = null;
if ($downloadLimitInput !== '') {
    $downloadLimit = filter_var($downloadLimitInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000000],
    ]);
    if ($downloadLimit === false) {
        json_response(['error' => 'Giới hạn lượt tải phải từ 1 đến 1.000.000 lượt hoặc để trống.'], 400);
    }
}

$expiryDays = filter_var($_POST['expiry_days'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 3650],
]);
$linkPassword = (string)($_POST['password'] ?? '');
if (isset($_POST['expiry_days']) && $expiryDays === false) {
    json_response(['error' => 'Thời hạn liên kết phải từ 1 đến 3650 ngày.'], 400);
}
if (strlen($linkPassword) > 200) {
    json_response(['error' => 'Mật khẩu liên kết không được vượt quá 200 byte.'], 400);
}
$expiry = trim((string) ($_POST['expiry'] ?? ''));
if ($expiryDays !== false) {
    $expiry = (new DateTimeImmutable('today'))->modify('+' . $expiryDays . ' days')->format('Y-m-d');
} elseif ($expiry !== '') {
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

$storedName = bin2hex(random_bytes(24));
$relativePath = FILESHARE_CURRENT_USER_ID . '/' . $folderId . '/' . $storedName;
$storageRoot = realpath(FILESHARE_STORAGE_DIR);
$ownerFolderPath = FILESHARE_STORAGE_DIR . '/' . FILESHARE_CURRENT_USER_ID;
$folderPath = $ownerFolderPath . '/' . $folderId;
if ($storageRoot === false) {
    error_log('Private storage root is unavailable.');
    json_response(['error' => 'Thư mục lưu trữ chưa sẵn sàng.'], 500);
}
if (!is_dir($ownerFolderPath) && !mkdir($ownerFolderPath, 0700) && !is_dir($ownerFolderPath)) {
    error_log('Unable to create private account storage folder.');
    json_response(['error' => 'Không thể tạo thư mục lưu trữ tài khoản. Kiểm tra quyền ghi của PHP với FILESHARE_STORAGE_DIR.'], 500);
}
if (!is_dir($folderPath) && !mkdir($folderPath, 0700, true) && !is_dir($folderPath)) {
    error_log('Unable to create private upload folder.');
    json_response(['error' => 'Không thể tạo thư mục lưu tệp trên ổ đĩa. Kiểm tra quyền ghi của PHP với FILESHARE_STORAGE_DIR.'], 500);
}
if (
    realpath($ownerFolderPath) !== $ownerFolderPath
    || !str_starts_with((string)realpath($ownerFolderPath), $storageRoot . DIRECTORY_SEPARATOR)
    || realpath($folderPath) !== $folderPath
    || !str_starts_with((string)realpath($folderPath), $storageRoot . DIRECTORY_SEPARATOR)
    || !chmod($ownerFolderPath, 0700)
    || !chmod($folderPath, 0700)
) {
    error_log('Unable to restrict access to private upload folder.');
    json_response(['error' => 'Không thể bảo vệ thư mục lưu tệp.'], 500);
}
$absolutePath = $folderPath . '/' . $storedName;
$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($upload['tmp_name']) ?: 'application/octet-stream';
$allowedMimeTypes = [
    'pdf' => ['application/pdf'],
    'txt' => ['text/plain'],
    'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
    'png' => ['image/png'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],
];
if (!in_array($mimeType, $allowedMimeTypes[$extension] ?? [], true)) {
    json_response(['error' => 'Nội dung tệp không khớp với phần mở rộng.'], 415);
}

if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
    error_log('Unable to move uploaded file into private storage.');
    json_response(['error' => 'Không thể lưu tệp tải lên.'], 500);
}
if (!chmod($absolutePath, 0600)) {
    unlink($absolutePath);
    error_log('Unable to restrict access to uploaded file.');
    json_response(['error' => 'Không thể bảo vệ tệp đã tải lên.'], 500);
}

$shareCode = bin2hex(random_bytes(16));
$passwordHash = $linkPassword === '' ? null : password_hash($linkPassword, PASSWORD_DEFAULT);
$transactionStarted = pg_query($conn, 'BEGIN');
if (!$transactionStarted) {
    unlink($absolutePath);
    error_log('Unable to begin upload transaction: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể lưu thông tin tệp vào cơ sở dữ liệu.'], 500);
}

try {
    $insertedFile = pg_query_params(
        $conn,
        'INSERT INTO files
            (owner_id, folder_id, file_name, file_path, file_size, file_type, share_code,
             access_type, download_limit, download_count, expires_at, is_link_active,
             link_password_hash)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, 0,
                 ($10::date + INTERVAL \'1 day\' - INTERVAL \'1 second\'), TRUE, $11)
         RETURNING id, created_at',
        [
            FILESHARE_CURRENT_USER_ID,
            $folderId,
            $fileName,
            $relativePath,
            $fileSize,
            $mimeType,
            $shareCode,
            $accessType,
            $downloadLimit,
            $expiry,
            $passwordHash,
        ]
    );
    if (!$insertedFile) {
        throw new RuntimeException(pg_last_error($conn));
    }

    $savedFile = pg_fetch_assoc($insertedFile);
    $activity = pg_query_params(
        $conn,
        'INSERT INTO activity_logs (user_id, file_id, action) VALUES ($1, $2, $3)',
        [FILESHARE_CURRENT_USER_ID, $savedFile['id'], 'upload']
    );
    if (!$activity) {
        throw new RuntimeException(pg_last_error($conn));
    }
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
        'is_missing' => false,
        'share_code' => $shareCode,
        'access_type' => $accessType,
        'download_limit' => $downloadLimit,
        'download_count' => 0,
        'expires_at' => $expiry,
        'is_link_active' => true,
        'password_protected' => $passwordHash !== null,
        'created_at' => $savedFile['created_at'],
        'folder_id' => (int)$folderId,
    ],
], 201);
