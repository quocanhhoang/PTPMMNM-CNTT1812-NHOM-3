<?php
declare(strict_types=1);

function app_storage_root(): string
{
    $configured = getenv('FILESHARE_DATA_DIR');
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $root = $configured !== false && $configured !== ''
        ? $configured
        : dirname(__DIR__) . '/fileshare-private';
    if (($configured === false || $configured === '') && $documentRoot !== false
        && ($root === $documentRoot || str_starts_with($root, $documentRoot . DIRECTORY_SEPARATOR))) {
        $root = dirname($documentRoot) . '/fileshare-private-' . substr(hash('sha256', __DIR__), 0, 12);
    }
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Không thể tạo thư mục lưu trữ riêng.');
    }
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || ($documentRoot !== false
        && ($resolvedRoot === $documentRoot || str_starts_with($resolvedRoot, $documentRoot . DIRECTORY_SEPARATOR)))) {
        throw new RuntimeException('Thư mục lưu trữ phải nằm ngoài document root của máy chủ web.');
    }
    if (!is_writable($root)) {
        throw new RuntimeException('Thư mục lưu trữ riêng không có quyền ghi.');
    }
    if (!chmod($root, 0700)) {
        throw new RuntimeException('Không thể giới hạn quyền truy cập thư mục lưu trữ riêng.');
    }
    return $root;
}

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    }
    $_SESSION['csrf_token'] = $_SESSION['csrf'];
}

function app_initial_state(): array
{
    return ['users' => [], 'folders' => [], 'files' => [], 'links' => [], 'audit' => [], 'login_attempts' => []];
}

function app_db_connection(): PgSql\Connection
{
    global $conn;
    if (!isset($conn) || !$conn instanceof PgSql\Connection) {
        require_once __DIR__ . '/config/database.php';
    }
    if (!$conn instanceof PgSql\Connection) {
        throw new RuntimeException('Kết nối PostgreSQL chưa sẵn sàng.');
    }
    return $conn;
}

function app_database_error_message(string $databaseError, string $fallback): string
{
    if (str_contains($databaseError, 'permission denied for table file_permissions')) {
        return 'DB_USER cần quyền SELECT, INSERT trên public.file_permissions để cấp quyền tải tệp; xem lệnh GRANT trong README.md.';
    }
    if (str_contains($databaseError, 'permission denied for sequence file_permissions_id_seq')) {
        return 'DB_USER cần quyền USAGE trên sequence public.file_permissions_id_seq để cấp quyền tải tệp; xem lệnh GRANT trong README.md.';
    }
    if (str_contains($databaseError, 'permission denied for table')
        || str_contains($databaseError, 'permission denied for sequence')) {
        return 'Tài khoản PostgreSQL của ứng dụng chưa đủ quyền. Hãy chạy các lệnh cấp quyền trong phần cấu hình database của README.md.';
    }
    if (preg_match('/column .* does not exist|relation .* does not exist/', $databaseError) === 1) {
        return 'Cơ sở dữ liệu chưa có đủ cấu trúc cho chức năng này. Hãy chạy các migration 001_file_link_password.sql, 002_file_cleanup.sql và 003_file_folders.sql theo README.md.';
    }
    return $fallback;
}

function app_with_state(callable $callback): mixed
{
    $root = app_storage_root();
    $lock = fopen($root . '/state.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Không thể khóa kho dữ liệu.');
    }
    chmod($root . '/state.lock', 0600);
    try {
        $path = $root . '/state.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            $state = app_initial_state();
        } else {
            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new RuntimeException('Kho dữ liệu không hợp lệ.');
            }
            $state += app_initial_state();
        }
        $result = $callback($state);
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = tempnam($root, 'state-');
        if ($temp === false || file_put_contents($temp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Không thể ghi kho dữ liệu.');
        }
        chmod($temp, 0600);
        if (!rename($temp, $path)) {
            unlink($temp);
            throw new RuntimeException('Không thể cập nhật kho dữ liệu.');
        }
        chmod($path, 0600);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function app_user(): ?array
{
    app_session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_string($userId) || !ctype_digit($userId)) {
        return null;
    }
    $result = pg_query_params(
        app_db_connection(),
        'SELECT id, username, full_name, email, password_hash, storage_limit
         FROM public.users
         WHERE id = $1 AND status = $2',
        [$userId, 'active']
    );
    if ($result === false) {
        error_log('Unable to load authenticated user from PostgreSQL: ' . pg_last_error(app_db_connection()));
        throw new AppDatabaseException('PostgreSQL từ chối đọc public.users. Hãy cấp quyền SELECT trên bảng này cho DB_USER.');
    }
    $row = pg_fetch_assoc($result);
    if ($row === false) {
        return null;
    }
    return [
        'id' => (string)$row['id'],
        'username' => $row['username'],
        'name' => $row['full_name'],
        'email' => $row['email'],
        'password_hash' => $row['password_hash'],
        'quota' => (int)$row['storage_limit'],
    ];
}

function app_find_user_by_email(string $email): ?array
{
    $connection = app_db_connection();
    $result = pg_query_params(
        $connection,
        'SELECT id, username, full_name, email, password_hash, storage_limit
         FROM public.users
         WHERE lower(email) = lower($1) AND status = $2
         LIMIT 1',
        [$email, 'active']
    );
    if ($result === false) {
        error_log('Unable to look up login account in PostgreSQL: ' . pg_last_error($connection));
        throw new AppDatabaseException('PostgreSQL từ chối đọc public.users. Hãy cấp quyền SELECT trên bảng này cho DB_USER.');
    }
    $row = pg_fetch_assoc($result);
    if ($row === false) {
        return null;
    }
    return [
        'id' => (string)$row['id'],
        'username' => $row['username'],
        'name' => $row['full_name'],
        'email' => $row['email'],
        'password_hash' => $row['password_hash'],
        'quota' => (int)$row['storage_limit'],
    ];
}

function app_register_user(string $email, string $password, string $name): ?array
{
    $connection = app_db_connection();
    $emailPrefix = strtolower((string)strstr($email, '@', true));
    $usernamePrefix = trim((string)preg_replace('/[^a-z0-9_]+/', '_', $emailPrefix), '_');
    $username = substr($usernamePrefix !== '' ? $usernamePrefix : 'user', 0, 35)
        . '_' . bin2hex(random_bytes(5));

    if (!pg_query($connection, 'BEGIN')) {
        error_log('Unable to begin PostgreSQL registration transaction: ' . pg_last_error($connection));
        throw new AppDatabaseException('Không thể bắt đầu giao dịch đăng ký trong PostgreSQL.');
    }
    try {
        $result = pg_query_params(
            $connection,
            'INSERT INTO public.users (username, full_name, email, password_hash, status, storage_limit)
             VALUES ($1, $2, $3, $4, $5, $6)
             ON CONFLICT DO NOTHING
             RETURNING id, username, full_name, email, password_hash, storage_limit',
            [$username, $name, $email, password_hash($password, PASSWORD_DEFAULT), 'active', 10 * 1024 * 1024 * 1024]
        );
        if ($result === false) {
            error_log('Unable to register PostgreSQL user: ' . pg_last_error($connection));
            throw new AppDatabaseException('PostgreSQL từ chối tạo tài khoản. Hãy cấp quyền SELECT, INSERT trên public.users và USAGE trên public.users_id_seq cho DB_USER.');
        }
        $row = pg_fetch_assoc($result);
        if ($row === false) {
            pg_query($connection, 'ROLLBACK');
            return null;
        }
        $user = [
            'id' => (string)$row['id'],
            'username' => $row['username'],
            'name' => $row['full_name'],
            'email' => $row['email'],
            'password_hash' => $row['password_hash'],
            'quota' => (int)$row['storage_limit'],
        ];
        $folderResult = pg_query_params(
            $connection,
            'INSERT INTO public.folders (owner_id, name)
             VALUES ($1, $2)
             ON CONFLICT (owner_id, name) DO NOTHING',
            [$user['id'], 'Tệp của tôi']
        );
        if ($folderResult === false) {
            error_log('Unable to create default PostgreSQL folder: ' . pg_last_error($connection));
            throw new AppDatabaseException('Không thể tạo thư mục mặc định. Hãy chạy migration 003_file_folders.sql và cấp quyền SELECT, INSERT trên public.folders cùng USAGE trên public.folders_id_seq cho DB_USER.');
        }
        app_with_state(static function (array &$state) use ($user): void {
            $state['folders'][] = [
                'id' => bin2hex(random_bytes(12)),
                'user_id' => $user['id'],
                'name' => 'Tệp của tôi',
                'created_at' => gmdate('c'),
            ];
        });
        if (!pg_query($connection, 'COMMIT')) {
            error_log('Unable to commit PostgreSQL registration transaction: ' . pg_last_error($connection));
            throw new AppDatabaseException('Không thể hoàn tất đăng ký trong PostgreSQL.');
        }
        return $user;
    } catch (Throwable $error) {
        pg_query($connection, 'ROLLBACK');
        throw $error;
    }
}

function app_require_user(): array
{
    $user = app_user();
    if ($user === null) {
        app_json(['error' => 'Vui lòng đăng nhập để tiếp tục.'], 401);
    }
    return $user;
}

function app_json(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input') ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function app_require_csrf(): void
{
    app_session_start();
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? app_input()['csrf'] ?? '';
    if (!is_string($provided) || !hash_equals($_SESSION['csrf'], $provided)) {
        app_json(['error' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'], 419);
    }
}

function app_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        app_json(['error' => 'Phương thức yêu cầu không được hỗ trợ.'], 405);
    }
}

function app_audit(array &$state, string $userId, string $event, ?string $fileId = null, array $details = []): void
{
    $state['audit'][] = [
        'id' => bin2hex(random_bytes(12)),
        'user_id' => $userId,
        'event' => $event,
        'file_id' => $fileId,
        'details' => $details,
        'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 0, 64),
        'created_at' => gmdate('c'),
    ];
}

function app_user_file(array &$state, string $fileId, string $userId): ?array
{
    foreach ($state['files'] as $file) {
        if ($file['id'] === $fileId && $file['user_id'] === $userId && empty($file['deleted_at'])) {
            return $file;
        }
    }
    return null;
}

function app_file_path(array $file): string
{
    return app_storage_root() . '/files/' . $file['user_id'] . '/' . $file['disk_name'];
}

function app_link_status(array $link): string
{
    if (!empty($link['revoked_at'])) {
        return 'revoked';
    }
    if (strtotime($link['expires_at']) <= time()) {
        return 'expired';
    }
    if ($link['max_downloads'] !== null && $link['downloads'] >= $link['max_downloads']) {
        return 'limit_reached';
    }
    return 'active';
}

function app_create_link(array &$state, array $user, array $file, array $input): array
{
    $days = filter_var($input['expiry_days'] ?? 7, FILTER_VALIDATE_INT);
    if ($days === false || $days < 1 || $days > 3650) {
        throw new InvalidArgumentException('Thời hạn liên kết phải từ 1 đến 3650 ngày.');
    }
    $limitValue = trim((string)($input['max_downloads'] ?? ''));
    $limit = null;
    if ($limitValue !== '') {
        $limit = filter_var($limitValue, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000000) {
            throw new InvalidArgumentException('Giới hạn tải phải từ 1 đến 1.000.000 lượt hoặc để trống.');
        }
    }
    $access = ($input['access'] ?? 'public') === 'private' ? 'private' : 'public';
    $password = (string)($input['password'] ?? '');
    if (strlen($password) > 200) {
        throw new InvalidArgumentException('Mật khẩu liên kết quá dài.');
    }
    $token = bin2hex(random_bytes(24));
    $link = [
        'id' => bin2hex(random_bytes(12)),
        'user_id' => $user['id'],
        'file_id' => $file['id'],
        'token' => $token,
        'token_hash' => hash('sha256', $token),
        'access' => $access,
        'password_hash' => $password === '' ? null : password_hash($password, PASSWORD_DEFAULT),
        'created_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + $days * 86400),
        'max_downloads' => $limit,
        'downloads' => 0,
        'revoked_at' => null,
    ];
    $state['links'][] = $link;
    app_audit($state, $user['id'], 'link_created', $file['id'], [
        'link_id' => $link['id'],
        'access' => $access,
        'expires_at' => $link['expires_at'],
        'max_downloads' => $limit,
        'password_protected' => $password !== '',
    ]);
    return ['link' => $link, 'token' => $token];
}

function app_public_link(array $link, string $token): array
{
    $passwordProtected = !empty($link['password_hash']);
    unset($link['token'], $link['token_hash'], $link['password_hash']);
    $link['url'] = rtrim(app_base_url(), '/') . '/api.php?action=download&token=' . rawurlencode($token);
    $link['status'] = app_link_status($link);
    $link['password_protected'] = $passwordProtected;
    return $link;
}

function app_base_url(): string
{
    $configured = getenv('FILESHARE_BASE_URL');
    if ($configured !== false && $configured !== '') {
        $parts = parse_url($configured);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('FILESHARE_BASE_URL phải là URL HTTP hoặc HTTPS hợp lệ.');
        }
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) {
        throw new RuntimeException('Tên máy chủ yêu cầu không hợp lệ.');
    }
    $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $path = dirname($scriptPath);
    if (basename($path) === 'api') {
        $path = dirname($path);
    }
    return $scheme . '://' . $host . rtrim($path, '/');
}

function app_file_mime_map(): array
{
    return [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];
}
