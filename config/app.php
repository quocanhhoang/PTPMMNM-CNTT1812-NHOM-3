<?php
declare(strict_types=1);

$ownerId = filter_var(getenv('FILESHARE_OWNER_ID') ?: '2', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($ownerId === false) {
    throw new RuntimeException('FILESHARE_OWNER_ID must be a positive integer.');
}

define('FILESHARE_OWNER_ID', $ownerId);
define('FILESHARE_MAX_UPLOAD_BYTES', 100 * 1024 * 1024);
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
