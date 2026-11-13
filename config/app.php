<?php
declare(strict_types=1);

define('FILESHARE_MAX_UPLOAD_BYTES', 1024 * 1024 * 1024);
define('FILESHARE_DEFAULT_QUOTA_BYTES', 10 * 1024 * 1024 * 1024);
define('FILESHARE_ALLOWED_EXTENSIONS', ['pdf', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'gif', 'webp']);
$storageDirectory = getenv('FILESHARE_STORAGE_DIR')
    ?: dirname(__DIR__, 2) . '/' . basename(dirname(__DIR__)) . '-storage/uploads';
if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0700, true) && !is_dir($storageDirectory)) {
    throw new RuntimeException('Unable to create private file storage directory.');
}

$storageDirectory = realpath($storageDirectory);
$documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
if (
    $storageDirectory === false
    || ($documentRoot !== false
        && ($storageDirectory === $documentRoot
            || str_starts_with($storageDirectory, $documentRoot . DIRECTORY_SEPARATOR)))
) {
    throw new RuntimeException('File storage must be outside the web document root.');
}
if (!chmod($storageDirectory, 0700)) {
    throw new RuntimeException('Unable to restrict access to private file storage.');
}

define('FILESHARE_STORAGE_DIR', $storageDirectory);
