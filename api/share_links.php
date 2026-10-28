<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$userId = require_authenticated_user();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filesResult = pg_query_params(
        $conn,
        'SELECT id, file_name
         FROM files
         WHERE owner_id = $1
         ORDER BY created_at DESC, id DESC',
        [$userId]
    );
    if (!$filesResult) {
        error_log('Failed to list files for share links: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tải danh sách tệp.'], 500);
    }

    $ownedFiles = [];
    while ($file = pg_fetch_assoc($filesResult)) {
        $ownedFiles[] = ['id' => (int) $file['id'], 'file_name' => $file['file_name']];
    }

    $linksResult = pg_query_params(
        $conn,
        'SELECT sl.id, sl.token, sl.download_limit, sl.download_count, sl.expires_at,
                (sl.password_hash IS NOT NULL) AS password_protected, f.file_name
         FROM share_links sl
         JOIN files f ON f.id = sl.file_id
         WHERE f.owner_id = $1
           AND sl.is_active = TRUE
           AND sl.expires_at > CURRENT_TIMESTAMP
           AND sl.download_count < sl.download_limit
         ORDER BY sl.created_at DESC, sl.id DESC',
        [$userId]
    );
    if (!$linksResult) {
        error_log('Failed to list active share links: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể tải danh sách liên kết.'], 500);
    }

    $links = [];
    while ($link = pg_fetch_assoc($linksResult)) {
        $links[] = [
            'id' => (int) $link['id'],
            'token' => trim($link['token']),
            'file_name' => $link['file_name'],
            'download_limit' => (int) $link['download_limit'],
            'download_count' => (int) $link['download_count'],
            'expires_at' => $link['expires_at'],
            'password_protected' => $link['password_protected'] === 't',
        ];
    }

    json_response(['files' => $ownedFiles, 'links' => $links]);
}

require_method('POST');
require_csrf_token();
$body = json_request_body();

if (($body['action'] ?? '') === 'revoke') {
    $linkId = filter_var($body['link_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($linkId === false) {
        json_response(['error' => 'Liên kết được chọn không hợp lệ.'], 400);
    }

    $revoked = pg_query_params(
        $conn,
        'UPDATE share_links sl
         SET is_active = FALSE
         FROM files f
         WHERE sl.id = $1
           AND f.id = sl.file_id
           AND f.owner_id = $2
         RETURNING sl.id',
        [$linkId, $userId]
    );
    if (!$revoked) {
        error_log('Failed to revoke share link: ' . pg_last_error($conn));
        json_response(['error' => 'Không thể thu hồi liên kết.'], 500);
    }
    if (pg_num_rows($revoked) === 0) {
        json_response(['error' => 'Không tìm thấy liên kết.'], 404);
    }
    json_response(['success' => true]);
}

$fileId = filter_var($body['file_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($fileId === false) {
    json_response(['error' => 'Tệp được chọn không hợp lệ.'], 400);
}

$downloadLimit = filter_var($body['download_limit'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);
if ($downloadLimit === false) {
    json_response(['error' => 'Giới hạn lượt tải phải là số nguyên dương.'], 400);
}

$expiryInput = $body['expires_at'] ?? null;
if (
    !is_string($expiryInput)
    || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $expiryInput)
) {
    json_response(['error' => 'Thời điểm hết hạn không hợp lệ.'], 400);
}
$expiry = DateTimeImmutable::createFromFormat(
    '!Y-m-d\TH:i:s.u\Z',
    $expiryInput,
    new DateTimeZone('UTC')
);
$expiryErrors = DateTimeImmutable::getLastErrors();
if (
    !$expiry
    || ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0))
    || $expiry->format('Y-m-d\TH:i:s.v\Z') !== $expiryInput
) {
    json_response(['error' => 'Thời điểm hết hạn không hợp lệ.'], 400);
}
if ($expiry <= new DateTimeImmutable()) {
    json_response(['error' => 'Thời điểm hết hạn phải ở trong tương lai.'], 400);
}

$password = $body['password'] ?? '';
if (!is_string($password) || ($password !== '' && (strlen($password) < 8 || strlen($password) > 72))) {
    json_response(['error' => 'Mật khẩu tùy chọn phải dài từ 8 đến 72 byte.'], 400);
}
$passwordHash = $password === '' ? null : password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    error_log('Unable to hash share-link password.');
    json_response(['error' => 'Không thể thiết lập mật khẩu cho liên kết.'], 500);
}

$ownedFile = pg_query_params(
    $conn,
    'SELECT id, file_name FROM files WHERE id = $1 AND owner_id = $2',
    [$fileId, $userId]
);
if (!$ownedFile) {
    error_log('Failed to verify share-link file ownership: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể xác minh quyền sở hữu tệp.'], 500);
}
$file = pg_fetch_assoc($ownedFile);
if (!$file) {
    json_response(['error' => 'Không tìm thấy tệp.'], 404);
}

try {
    $token = bin2hex(random_bytes(32));
} catch (Throwable $error) {
    error_log('Unable to generate share-link token: ' . $error->getMessage());
    json_response(['error' => 'Không thể tạo liên kết lúc này.'], 500);
}

$created = pg_query_params(
    $conn,
    'INSERT INTO share_links (file_id, token, password_hash, download_limit, expires_at)
     VALUES ($1, $2, $3, $4, $5)
     RETURNING id, download_count, expires_at',
    [$fileId, $token, $passwordHash, $downloadLimit, $expiry->format('Y-m-d H:i:sP')]
);
if (!$created) {
    error_log('Failed to create share link: ' . pg_last_error($conn));
    json_response(['error' => 'Không thể tạo liên kết chia sẻ.'], 500);
}

$link = pg_fetch_assoc($created);
json_response([
    'link' => [
        'id' => (int) $link['id'],
        'token' => $token,
        'file_name' => $file['file_name'],
        'download_limit' => $downloadLimit,
        'download_count' => (int) $link['download_count'],
        'expires_at' => $link['expires_at'],
        'password_protected' => $passwordHash !== null,
    ],
], 201);
