<?php
declare(strict_types=1);

define('FILESHARE_MAX_UPLOAD_BYTES', 1024 * 1024 * 1024);
define('FILESHARE_DEFAULT_QUOTA_BYTES', 10 * 1024 * 1024 * 1024);
$allowedExtensionSetting = getenv('FILESHARE_ALLOWED_EXTENSIONS')
    ?: 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,md,jpg,jpeg,png,gif,webp,zip';
define('FILESHARE_ALLOWED_EXTENSIONS', array_values(array_filter(array_map(
    static fn (string $extension): string => strtolower(trim($extension)),
    explode(',', $allowedExtensionSetting)
))));
define(
    'FILESHARE_STORAGE_DIR',
    getenv('FILESHARE_STORAGE_DIR')
        ?: dirname(__DIR__, 2) . '/' . basename(dirname(__DIR__)) . '-storage/uploads'
);
$storagePath = FILESHARE_STORAGE_DIR;
$webRoot = realpath(__DIR__ . '/..');
$absoluteStoragePath = str_starts_with($storagePath, DIRECTORY_SEPARATOR)
    ? $storagePath
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . $storagePath;
$normalizedParts = [];
foreach (explode(DIRECTORY_SEPARATOR, $absoluteStoragePath) as $part) {
    if ($part === '' || $part === '.') continue;
    if ($part === '..') array_pop($normalizedParts); else $normalizedParts[] = $part;
}
$absoluteStoragePath = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $normalizedParts);
$resolvedStorage = realpath($absoluteStoragePath);
if ($resolvedStorage === false) {
    $missingParts = [];
    $existingParent = $absoluteStoragePath;
    while (!file_exists($existingParent) && dirname($existingParent) !== $existingParent) {
        array_unshift($missingParts, basename($existingParent));
        $existingParent = dirname($existingParent);
    }
    $resolvedParent = realpath($existingParent);
    $resolvedStorage = ($resolvedParent === false ? $existingParent : $resolvedParent)
        . ($missingParts ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $missingParts) : '');
}
if ($webRoot !== false) {
    if ($resolvedStorage === $webRoot || str_starts_with($resolvedStorage, $webRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('FILESHARE_STORAGE_DIR phải nằm ngoài thư mục web của ứng dụng.');
    }
}
