<?php
declare(strict_types=1);

final class AppDatabaseException extends RuntimeException
{
}

if (!extension_loaded('pgsql')) {
    throw new AppDatabaseException('PHP chưa bật extension pgsql. Hãy cài hoặc bật php-pgsql rồi khởi động lại máy chủ PHP.');
}

$localConfigPath = __DIR__ . '/database.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($localConfig)) {
    throw new AppDatabaseException('Cấu hình config/database.local.php phải trả về một mảng.');
}

$getSetting = static function (string $environmentName, string $configName, string $default = '') use ($localConfig): string {
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false && $environmentValue !== '') {
        return (string)$environmentValue;
    }
    $configValue = $localConfig[$configName] ?? null;
    return is_string($configValue) || is_numeric($configValue) ? (string)$configValue : $default;
};

$host = trim($getSetting('DB_HOST', 'host', 'localhost'));
$port = filter_var($getSetting('DB_PORT', 'port', '5432'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
$dbname = trim($getSetting('DB_NAME', 'dbname', 'fileSharing'));
$user = trim($getSetting('DB_USER', 'user'));
$password = $getSetting('DB_PASSWORD', 'password');

if ($host === '' || $dbname === '' || $user === '' || $port === false) {
    throw new AppDatabaseException('Chưa cấu hình tài khoản PostgreSQL. Hãy tạo config/database.local.php từ config/database.local.example.php và điền thông tin kết nối.');
}

$quoteConnectionValue = static function (string $value): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
};
$connectionString = implode(' ', [
    'host=' . $quoteConnectionValue($host),
    'port=' . $port,
    'dbname=' . $quoteConnectionValue($dbname),
    'user=' . $quoteConnectionValue($user),
    'password=' . $quoteConnectionValue($password),
]);

$conn = @pg_connect($connectionString);
if ($conn === false) {
    error_log('PostgreSQL connection failed. Verify DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD and database access rules.');
    throw new AppDatabaseException('Không thể kết nối PostgreSQL. Hãy kiểm tra dịch vụ cơ sở dữ liệu, cấu hình DB_* và quyền truy cập.');
}
