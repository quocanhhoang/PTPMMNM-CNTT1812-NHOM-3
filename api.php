<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
app_session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$action = (string)($_GET['action'] ?? '');

if ($action === 'download') {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
        app_require_method('GET');
    }
    $token = (string)($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        http_response_code(404);
        exit('Không tìm thấy liên kết.');
    }
    $input = app_input();
    $downloadUser = app_user();
    $linkInfo = app_with_state(static function (array &$state) use ($token, $input, $downloadUser): array {
        $hash = hash('sha256', $token);
        foreach ($state['links'] as &$link) {
            if (!hash_equals($link['token_hash'], $hash)) {
                continue;
            }
            if (app_link_status($link) !== 'active') {
                return ['error' => 'Liên kết đã hết hạn, bị thu hồi hoặc hết lượt tải.', 'status' => 410];
            }
            if (!empty($link['password_hash']) && !password_verify((string)($_POST['password'] ?? $input['password'] ?? ''), $link['password_hash'])) {
                return ['error' => 'Liên kết yêu cầu mật khẩu.', 'status' => 403, 'password_required' => true];
            }
            if ($link['access'] === 'private' && ($downloadUser === null || $downloadUser['id'] !== $link['user_id'])) {
                return ['error' => 'Liên kết này chỉ dành cho người được cấp quyền.', 'status' => 403];
            }
            foreach ($state['files'] as $file) {
                if ($file['id'] !== $link['file_id'] || !empty($file['deleted_at'])) {
                    continue;
                }
                $path = app_file_path($file);
                if (!is_file($path) || !is_readable($path)) {
                    foreach ($state['files'] as &$missingFile) {
                        if ($missingFile['id'] === $file['id'] && empty($missingFile['missing_notified_at'])) {
                            $missingFile['missing_notified_at'] = gmdate('c');
                            break;
                        }
                    }
                    unset($missingFile);
                    app_audit($state, $link['user_id'], 'file_missing', $file['id'], ['link_id' => $link['id']]);
                    return ['error' => 'Tệp gốc hiện không có trên ổ đĩa. Vui lòng liên hệ chủ sở hữu.', 'status' => 404];
                }
                $link['downloads']++;
                foreach ($state['files'] as &$storedFile) {
                    if ($storedFile['id'] === $file['id']) {
                        $storedFile['downloads'] = ($storedFile['downloads'] ?? 0) + 1;
                        break;
                    }
                }
                unset($storedFile);
                app_audit($state, $link['user_id'], 'download', $file['id'], ['link_id' => $link['id']]);
                return ['file' => $file, 'path' => $path];
            }
            return ['error' => 'Không tìm thấy tệp.', 'status' => 404];
        }
        return ['error' => 'Không tìm thấy liên kết.', 'status' => 404];
    });
    if (isset($linkInfo['error'])) {
        http_response_code($linkInfo['status']);
        if (!empty($linkInfo['password_required'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Nhập mật khẩu</title><form method="post"><label>Mật khẩu liên kết <input name="password" type="password" required autofocus></label><button type="submit">Tiếp tục</button></form><p>' . htmlspecialchars($linkInfo['error'], ENT_QUOTES, 'UTF-8') . '</p></html>';
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        exit($linkInfo['error']);
    }
    $mime = $linkInfo['file']['mime'];
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)$linkInfo['file']['size']);
    header('Content-Disposition: attachment; filename="' . addcslashes($linkInfo['file']['original_name'], '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($linkInfo['file']['original_name']));
    header('Cache-Control: private, no-store');
    readfile($linkInfo['path']);
    exit;
}

try {
    if ($action === 'register' || $action === 'login' || $action === 'logout') {
        app_require_method('POST');
        app_require_csrf();
        $input = app_input();
        if ($action === 'register') {
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $password = (string)($input['password'] ?? '');
            $name = trim((string)($input['name'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                app_json(['error' => 'Vui lòng nhập email hợp lệ, tối đa 100 ký tự.'], 422);
            }
            if (strlen($password) < 10 || strlen($password) > 200) {
                app_json(['error' => 'Mật khẩu cần có từ 10 đến 200 ký tự.'], 422);
            }
            if ($name === '' || preg_match('//u', $name) !== 1 || preg_match_all('/./us', $name) > 100) {
                app_json(['error' => 'Tên hiển thị không hợp lệ hoặc dài hơn 100 ký tự.'], 422);
            }
            $user = app_register_user($email, $password, $name);
            if ($user === null) {
                app_json(['error' => 'Email này đã được đăng ký.'], 409);
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $_SESSION['csrf'];
            app_json(['user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']], 'csrf' => $_SESSION['csrf']]);
        }
        if ($action === 'login') {
            $email = strtolower(trim((string)($input['email'] ?? '')));
            $password = (string)($input['password'] ?? '');
            $now = time();
            $bucket = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $email);
            $attempts = app_with_state(static function (array &$state) use ($bucket, $now): array {
                $attempts = $state['login_attempts'][$bucket] ?? ['count' => 0, 'started' => $now];
                if ($now - $attempts['started'] > 900) {
                    $attempts = ['count' => 0, 'started' => $now];
                    $state['login_attempts'][$bucket] = $attempts;
                }
                return $attempts;
            });
            if ($attempts['count'] >= 8) {
                app_json(['error' => 'Quá nhiều lần đăng nhập thất bại. Vui lòng thử lại sau 15 phút.'], 429);
            }
            $user = app_find_user_by_email($email);
            if ($user === null || !password_verify($password, $user['password_hash'])) {
                app_with_state(static function (array &$state) use ($bucket, $attempts): void {
                    $current = $state['login_attempts'][$bucket] ?? $attempts;
                    $current['count']++;
                    $state['login_attempts'][$bucket] = $current;
                });
                app_json(['error' => 'Email hoặc mật khẩu không chính xác.'], 401);
            }
            app_with_state(static function (array &$state) use ($bucket): void {
                unset($state['login_attempts'][$bucket]);
            });
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $_SESSION['csrf'];
            app_json(['user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']], 'csrf' => $_SESSION['csrf']]);
        }
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $_SESSION['csrf'];
        app_json(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }

    if ($action === 'session') {
        app_require_method('GET');
        $user = app_user();
        app_json(['user' => $user === null ? null : ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']], 'csrf' => $_SESSION['csrf']]);
    }

    if ($action === 'list') {
        app_require_method('GET');
        $user = app_require_user();
        $result = app_with_state(static function (array &$state) use ($user): array {
            $folders = array_values(array_filter($state['folders'], static fn(array $folder): bool => $folder['user_id'] === $user['id']));
            $folderNames = [];
            foreach ($folders as $folder) {
                $folderNames[$folder['id']] = $folder['name'];
            }
            $files = [];
            $notifications = [];
            $used = 0;
            foreach ($state['files'] as &$file) {
                if ($file['user_id'] !== $user['id'] || !empty($file['deleted_at'])) {
                    continue;
                }
                $exists = is_file(app_file_path($file));
                if (!$exists && empty($file['missing_notified_at'])) {
                    $file['missing_notified_at'] = gmdate('c');
                    app_audit($state, $user['id'], 'file_missing', $file['id'], ['name' => $file['original_name']]);
                    $notifications[] = 'Không tìm thấy tệp "' . $file['original_name'] . '" trên ổ đĩa.';
                }
                if ($exists) {
                    $used += (int)$file['size'];
                }
                $files[] = [
                    'id' => $file['id'],
                    'name' => $file['original_name'],
                    'size' => (int)$file['size'],
                    'mime' => $file['mime'],
                    'created_at' => $file['created_at'],
                    'downloads' => (int)($file['downloads'] ?? 0),
                    'folder_id' => $file['folder_id'],
                    'folder_name' => $folderNames[$file['folder_id']] ?? 'Đã xóa',
                    'missing' => !$exists,
                ];
            }
            unset($file);
            $links = [];
            foreach ($state['links'] as $link) {
                if ($link['user_id'] !== $user['id']) {
                    continue;
                }
                $link['password_protected'] = !empty($link['password_hash']);
                unset($link['password_hash'], $link['token_hash']);
                $link['url'] = rtrim(app_base_url(), '/') . '/api.php?action=download&token=' . rawurlencode($link['token']);
                $link['status'] = app_link_status($link);
                $links[] = $link;
            }
            $audit = array_values(array_filter($state['audit'], static fn(array $event): bool => $event['user_id'] === $user['id']));
            usort($audit, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
            return [
                'files' => $files,
                'folders' => $folders,
                'links' => $links,
                'audit' => array_slice($audit, 0, 100),
                'notifications' => $notifications,
                'used' => $used,
                'quota' => (int)$user['quota'],
            ];
        });
        app_json($result);
    }

    if ($action === 'cleanup-preview') {
        app_require_method('GET');
        $user = app_require_user();
        $candidates = app_with_state(static function (array &$state) use ($user): array {
            $result = [];
            foreach ($state['files'] as $file) {
                if ($file['user_id'] !== $user['id'] || !empty($file['deleted_at']) || !is_file(app_file_path($file))) {
                    continue;
                }
                $hasLink = false;
                $hasActive = false;
                foreach ($state['links'] as $link) {
                    if ($link['file_id'] !== $file['id']) {
                        continue;
                    }
                    $hasLink = true;
                    if (app_link_status($link) === 'active') {
                        $hasActive = true;
                        break;
                    }
                }
                if ($hasLink && !$hasActive) {
                    $result[] = ['id' => $file['id'], 'name' => $file['original_name'], 'size' => $file['size']];
                }
            }
            return $result;
        });
        app_json(['files' => $candidates]);
    }

    $user = app_require_user();
    app_require_csrf();
    app_require_method('POST');
    $input = app_input();

    if ($action === 'folder-create') {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 320) {
            app_json(['error' => 'Tên thư mục phải từ 1 đến 80 ký tự.'], 422);
        }
        $folder = app_with_state(static function (array &$state) use ($user, $name): ?array {
            foreach ($state['folders'] as $existing) {
                if ($existing['user_id'] === $user['id'] && strcasecmp($existing['name'], $name) === 0) {
                    return null;
                }
            }
            $folder = ['id' => bin2hex(random_bytes(12)), 'user_id' => $user['id'], 'name' => $name, 'created_at' => gmdate('c')];
            $state['folders'][] = $folder;
            return $folder;
        });
        app_json($folder ? ['folder' => $folder] : ['error' => 'Thư mục này đã tồn tại.'], $folder ? 201 : 409);
    }

    if ($action === 'upload') {
        $maxUpload = (int)(getenv('FILESHARE_MAX_UPLOAD_BYTES') ?: 104857600);
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxUpload + 2 * 1024 * 1024) {
            app_json(['error' => 'Tệp vượt quá giới hạn tải lên ' . app_human_size($maxUpload) . '.'], 413);
        }
        if (!isset($_FILES['file'])
            || !is_array($_FILES['file'])
            || !is_string($_FILES['file']['name'] ?? null)
            || !is_string($_FILES['file']['tmp_name'] ?? null)
            || $_FILES['file']['error'] !== UPLOAD_ERR_OK
            || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            app_json(['error' => 'Tải tệp lên thất bại. Vui lòng chọn lại tệp.'], 422);
        }
        $upload = $_FILES['file'];
        if ($upload['size'] < 1 || $upload['size'] > $maxUpload) {
            app_json(['error' => 'Tệp phải có kích thước từ 1 byte đến ' . app_human_size($maxUpload) . '.'], 413);
        }
        $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $allowed = app_file_mime_map();
        if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
            app_json(['error' => 'Định dạng tệp không được hỗ trợ hoặc nội dung không khớp định dạng. Chấp nhận PDF, TXT, CSV, PNG, JPG, GIF, WEBP.'], 415);
        }
        $originalName = basename(str_replace('\\', '/', (string)($input['file_name'] ?? $upload['name'])));
        if ($originalName === '' || strlen($originalName) > 240 || preg_match('/[\x00-\x1f\x7f]/', $originalName)) {
            app_json(['error' => 'Tên tệp không hợp lệ.'], 422);
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== $extension) {
            app_json(['error' => 'Không thể đổi phần mở rộng của tệp.'], 422);
        }
        $folderId = (string)($input['folder_id'] ?? '');
        $linkOptions = [
            'access' => $input['access'] ?? 'public',
            'expiry_days' => $input['expiry_days'] ?? 7,
            'max_downloads' => $input['max_downloads'] ?? '',
            'password' => $input['password'] ?? '',
        ];
        $storedPath = null;
        try {
            $result = app_with_state(static function (array &$state) use ($user, $upload, $originalName, $extension, $mime, $folderId, $linkOptions, &$storedPath): array {
            $used = 0;
            $folderExists = false;
            foreach ($state['files'] as $existing) {
                if ($existing['user_id'] === $user['id'] && empty($existing['deleted_at'])) {
                    if (is_file(app_file_path($existing))) {
                        $used += $existing['size'];
                    }
                    if (!is_file(app_file_path($existing)) && empty($existing['missing_notified_at'])) {
                        app_audit($state, $user['id'], 'file_missing', $existing['id'], ['name' => $existing['original_name']]);
                        foreach ($state['files'] as &$candidate) {
                            if ($candidate['id'] === $existing['id']) {
                                $candidate['missing_notified_at'] = gmdate('c');
                            }
                        }
                        unset($candidate);
                    }
                }
            }
            foreach ($state['folders'] as $folder) {
                if ($folder['id'] === $folderId && $folder['user_id'] === $user['id']) {
                    $folderExists = true;
                    break;
                }
            }
            if (!$folderExists || $used + (int)$upload['size'] > (int)$user['quota']) {
                throw new DomainException(!$folderExists ? 'Vui lòng chọn một thư mục hợp lệ.' : 'Không đủ dung lượng lưu trữ cho tệp này.');
            }
            $directory = app_storage_root() . '/files/' . $user['id'];
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Không thể tạo thư mục tệp riêng.');
            }
            $diskName = bin2hex(random_bytes(24));
            $storedPath = $directory . '/' . $diskName;
            if (!move_uploaded_file($upload['tmp_name'], $storedPath)) {
                throw new RuntimeException('Không thể lưu tệp đã tải lên.');
            }
            chmod($storedPath, 0600);
            $file = [
                'id' => bin2hex(random_bytes(12)),
                'user_id' => $user['id'],
                'folder_id' => $folderId,
                'original_name' => $originalName,
                'disk_name' => $diskName,
                'extension' => $extension,
                'mime' => $mime,
                'size' => (int)$upload['size'],
                'created_at' => gmdate('c'),
                'downloads' => 0,
                'missing_notified_at' => null,
            ];
            $state['files'][] = $file;
            app_audit($state, $user['id'], 'file_uploaded', $file['id'], ['name' => $originalName, 'size' => $file['size']]);
            try {
                return ['file' => $file, 'share' => app_create_link($state, $user, $file, $linkOptions)];
            } catch (Throwable $error) {
                unlink($storedPath);
                $storedPath = null;
                throw $error;
            }
            });
        } catch (Throwable $error) {
            if ($storedPath !== null && is_file($storedPath)) {
                unlink($storedPath);
            }
            throw $error;
        }
        $result['share']['link'] = app_public_link($result['share']['link'], $result['share']['token']);
        app_json(['file' => $result['file'], 'link' => $result['share']['link']], 201);
    }

    if ($action === 'link-create') {
        $fileId = (string)($input['file_id'] ?? '');
        $result = app_with_state(static function (array &$state) use ($user, $fileId, $input): array {
            $file = app_user_file($state, $fileId, $user['id']);
            if ($file === null) {
                throw new OutOfBoundsException('Không tìm thấy tệp.');
            }
            return app_create_link($state, $user, $file, $input);
        });
        $result['link'] = app_public_link($result['link'], $result['token']);
        app_json(['link' => $result['link']], 201);
    }

    if ($action === 'link-revoke') {
        $linkId = (string)($input['link_id'] ?? '');
        $revoked = app_with_state(static function (array &$state) use ($user, $linkId): bool {
            foreach ($state['links'] as &$link) {
                if ($link['id'] === $linkId && $link['user_id'] === $user['id'] && empty($link['revoked_at'])) {
                    $link['revoked_at'] = gmdate('c');
                    app_audit($state, $user['id'], 'link_revoked', $link['file_id'], ['link_id' => $link['id']]);
                    return true;
                }
            }
            return false;
        });
        app_json($revoked ? ['ok' => true] : ['error' => 'Không tìm thấy liên kết còn hiệu lực.'], $revoked ? 200 : 404);
    }

    if ($action === 'file-delete') {
        $fileId = (string)($input['file_id'] ?? '');
        $deleted = app_with_state(static function (array &$state) use ($user, $fileId): bool {
            $file = app_user_file($state, $fileId, $user['id']);
            if ($file === null) {
                return false;
            }
            $path = app_file_path($file);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Không thể xóa tệp khỏi ổ đĩa.');
            }
            foreach ($state['files'] as &$storedFile) {
                if ($storedFile['id'] === $fileId) {
                    $storedFile['deleted_at'] = gmdate('c');
                }
            }
            unset($storedFile);
            foreach ($state['links'] as &$link) {
                if ($link['file_id'] === $fileId && empty($link['revoked_at'])) {
                    $link['revoked_at'] = gmdate('c');
                }
            }
            unset($link);
            app_audit($state, $user['id'], 'file_deleted', $fileId, ['name' => $file['original_name']]);
            return true;
        });
        app_json($deleted ? ['ok' => true] : ['error' => 'Không tìm thấy tệp.'], $deleted ? 200 : 404);
    }

    if ($action === 'report') {
        $csv = app_with_state(static function (array &$state) use ($user): string {
            $rows = [['Thời gian UTC', 'Sự kiện', 'Tên tệp', 'IP', 'Chi tiết']];
            $files = [];
            foreach ($state['files'] as $file) {
                if ($file['user_id'] === $user['id']) {
                    $files[$file['id']] = $file['original_name'];
                }
            }
            foreach ($state['audit'] as $event) {
                if ($event['user_id'] !== $user['id']) {
                    continue;
                }
                $row = [$event['created_at'], $event['event'], $files[$event['file_id'] ?? ''] ?? '', $event['ip'], json_encode($event['details'], JSON_UNESCAPED_UNICODE)];
                $rows[] = array_map(static function (string $value): string {
                    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
                }, $row);
            }
            $stream = fopen('php://temp', 'r+');
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
            rewind($stream);
            return "\xEF\xBB\xBF" . stream_get_contents($stream);
        });
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bao-cao-truy-cap.csv"');
        echo $csv;
        exit;
    }

    app_json(['error' => 'Không tìm thấy chức năng.'], 404);
} catch (AppDatabaseException $error) {
    error_log('FileShare PostgreSQL error: ' . $error->getMessage());
    app_json(['error' => $error->getMessage()], 503);
} catch (InvalidArgumentException | DomainException $error) {
    app_json(['error' => $error->getMessage()], 422);
} catch (OutOfBoundsException $error) {
    app_json(['error' => $error->getMessage()], 404);
} catch (Throwable $error) {
    error_log('FileShare API error: ' . $error->getMessage());
    app_json(['error' => 'Đã xảy ra lỗi máy chủ. Vui lòng thử lại sau.'], 500);
}

function app_human_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576) . ' MB' : round($bytes / 1024) . ' KB';
}
