<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mode = $argv[1] ?? '--preview';
if (!in_array($mode, ['--preview', '--execute'], true)) {
    fwrite(STDERR, "Cách dùng: php cleanup.php [--preview|--execute]\n");
    exit(2);
}

try {
    require_once __DIR__ . '/config/app.php';
    require_once __DIR__ . '/config/database.php';
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$findCandidates = static function (PgSql\Connection $connection, bool $lock = false, ?array $onlyIds = null): array {
    if ($onlyIds !== null && $onlyIds === []) {
        return [];
    }
    $query = 'SELECT id, file_name, file_path, file_size
              FROM public.files
              WHERE deleted_at IS NULL
                AND share_code IS NOT NULL '
        . ($onlyIds === null ? '' : 'AND id = ANY($1::bigint[]) ')
        . 'AND (
                    is_link_active = FALSE
                    OR (expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP)
                    OR (download_limit IS NOT NULL AND download_count >= download_limit)
                )
              ORDER BY expires_at NULLS LAST, id';
    $parameters = [];
    if ($onlyIds !== null) {
        $parameters[] = '{' . implode(',', array_map('intval', $onlyIds)) . '}';
    }
    if ($lock) {
        $query .= ' FOR UPDATE';
    }
    $result = $parameters === []
        ? pg_query($connection, $query)
        : pg_query_params($connection, $query, $parameters);
    if ($result === false) {
        throw new RuntimeException('Unable to list cleanup candidates: ' . pg_last_error($connection));
    }

    $candidates = [];
    while ($row = pg_fetch_assoc($result)) {
        $candidates[] = $row;
    }
    return $candidates;
};

$safeFilePath = static function (string $storedPath): string {
    if (!preg_match('/\A(?:[a-f0-9]{48}|[0-9]+\/[0-9]+\/[a-f0-9]{48})\z/', $storedPath)) {
        throw new RuntimeException('Refusing unsafe stored file path.');
    }
    $root = realpath(FILESHARE_STORAGE_DIR);
    if ($root === false) {
        throw new RuntimeException('Private file storage directory is unavailable.');
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $storedPath);
    if ($path === false) {
        return $root . DIRECTORY_SEPARATOR . $storedPath;
    }
    if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        throw new RuntimeException('Refusing to clean a path outside private file storage.');
    }
    return $path;
};

try {
    $preview = $findCandidates($conn);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

echo "Xem trước: " . count($preview) . " tệp đã hết hạn, bị thu hồi hoặc hết lượt tải.\n";
foreach ($preview as $file) {
    echo sprintf(
        "- %s (%s bytes)\n",
        $file['file_name'],
        number_format((int)$file['file_size'])
    );
}

if ($mode === '--preview') {
    echo "Chế độ xem trước: không có tệp nào bị xóa. Chạy php cleanup.php --execute để xác nhận dọn dẹp.\n";
    exit(0);
}

if (!$preview) {
    echo "Không có gì cần dọn dẹp.\n";
    exit(0);
}

fwrite(STDOUT, "Nhập XOA để xóa danh sách hiện tại: ");
if (trim((string)fgets(STDIN)) !== 'XOA') {
    echo "Đã hủy. Không có tệp nào bị xóa.\n";
    exit(0);
}

$quarantined = [];
if (!pg_query($conn, 'BEGIN')) {
    fwrite(STDERR, 'Unable to begin cleanup transaction: ' . pg_last_error($conn) . "\n");
    exit(1);
}

try {
    $previewIds = array_column($preview, 'id');
    $candidates = $findCandidates($conn, true, $previewIds);
    foreach ($candidates as $file) {
        $path = $safeFilePath($file['file_path']);
        if (is_file($path)) {
            $quarantinePath = dirname($path) . DIRECTORY_SEPARATOR . '.cleanup-' . bin2hex(random_bytes(16));
            if (!rename($path, $quarantinePath)) {
                throw new RuntimeException('Unable to quarantine file: ' . $file['file_name']);
            }
            $quarantined[$path] = $quarantinePath;
        }

        $updated = pg_query_params(
            $conn,
            'UPDATE public.files
             SET deleted_at = CURRENT_TIMESTAMP,
                 is_link_active = FALSE,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = $1 AND deleted_at IS NULL
             RETURNING id',
            [$file['id']]
        );
        if ($updated === false || pg_num_rows($updated) !== 1) {
            throw new RuntimeException('Unable to mark file as deleted: ' . $file['file_name']);
        }

        $activity = pg_query_params(
            $conn,
            'INSERT INTO public.activity_logs (user_id, file_id, action)
             SELECT owner_id, id, $2 FROM public.files WHERE id = $1',
            [$file['id'], 'file_cleanup']
        );
        if ($activity === false) {
            throw new RuntimeException('Unable to record cleanup activity: ' . pg_last_error($conn));
        }
    }

    if (!pg_query($conn, 'COMMIT')) {
        throw new RuntimeException('Unable to commit cleanup: ' . pg_last_error($conn));
    }
} catch (Throwable $error) {
    pg_query($conn, 'ROLLBACK');
    foreach ($quarantined as $originalPath => $quarantinePath) {
        if (is_file($quarantinePath) && !rename($quarantinePath, $originalPath)) {
            fwrite(STDERR, "Could not restore quarantined file: $originalPath\n");
        }
    }
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

$failedDeletes = [];
foreach ($quarantined as $quarantinePath) {
    if (is_file($quarantinePath) && !unlink($quarantinePath)) {
        $failedDeletes[] = $quarantinePath;
    }
}
if ($failedDeletes) {
    fwrite(STDERR, "Metadata was cleaned, but could not remove quarantined file(s):\n");
    foreach ($failedDeletes as $path) {
        fwrite(STDERR, "- $path\n");
    }
    exit(1);
}

echo 'Đã dọn ' . count($candidates) . " tệp.\n";
