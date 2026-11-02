<?php
declare(strict_types=1);

session_start();
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false) {
    header('Location: login.php');
    exit;
}

try {
    require_once __DIR__ . '/config/database.php';
} catch (Throwable $error) {
    error_log('Administrator page initialization failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Dịch vụ hiện không khả dụng.');
}
$roleResult = pg_query_params($conn, 'SELECT role FROM users WHERE id = $1', [$userId]);
if (!$roleResult) {
    error_log('Failed to verify administrator page access: ' . pg_last_error($conn));
    http_response_code(500);
    exit('Không thể xác minh quyền quản trị.');
}
if (!($role = pg_fetch_assoc($roleResult)) || $role['role'] !== 'admin') {
    http_response_code(403);
    exit('Bạn không có quyền truy cập dashboard quản trị.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$adminNameText = (string) ($_SESSION['user_name'] ?? 'Quản trị viên');
preg_match('/^./u', $adminNameText, $initialMatch);
$adminInitial = htmlspecialchars($initialMatch[0] ?? 'A', ENT_QUOTES, 'UTF-8');
$adminName = htmlspecialchars($adminNameText, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard quản trị — FileShare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="admin-page">
    <div class="app-shell">
        <aside class="sidebar admin-sidebar">
            <a class="brand" href="index.php" aria-label="FileShare trang chủ">
                <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M13.5 3.5H7.75A2.75 2.75 0 0 0 5 6.25v11.5a2.75 2.75 0 0 0 2.75 2.75h8.5A2.75 2.75 0 0 0 19 17.75V9l-5.5-5.5Z"/><path d="M13 4v5h5"/></svg></span>
                <span>File<span class="brand-accent">Share</span></span>
            </a>
            <div class="workspace-label">QUẢN TRỊ HỆ THỐNG</div>
            <nav class="main-nav" aria-label="Điều hướng quản trị">
                <a class="nav-link" href="index.php"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1V10Z"/></svg><span>Trang chủ</span></a>
                <a class="nav-link active" href="admin.php"><svg viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.8"/></svg><span>Dashboard</span></a>
            </nav>
            <div class="sidebar-bottom">
                <div class="admin-profile">
                    <span class="avatar"><?= $adminInitial ?></span>
                    <span><?= $adminName ?><small>Quản trị viên</small></span>
                </div>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar"><div class="breadcrumbs"><a href="index.php">Trang chủ</a><span class="breadcrumb-divider">/</span><strong>Dashboard quản trị</strong></div><div class="topbar-actions"><span class="admin-name"><?= $adminName ?></span><button class="logout-button" type="button" id="admin-logout">Đăng xuất</button></div></header>
            <div class="page-content admin-content">
                <section aria-labelledby="admin-title">
                    <div class="page-heading"><div><p class="eyebrow">QUẢN LÝ HỆ THỐNG</p><h1 id="admin-title">Dashboard quản trị</h1><p class="welcome-subtitle">Theo dõi tình hình người dùng và hoạt động tải tệp.</p></div></div>
                    <div class="stats-grid admin-stats">
                        <article class="stat-card"><div class="stat-top"><span>Tổng người dùng</span><span class="stat-icon violet">U</span></div><div class="stat-value" id="admin-user-count">—</div></article>
                        <article class="stat-card"><div class="stat-top"><span>Tổng số tệp</span><span class="stat-icon blue">F</span></div><div class="stat-value" id="admin-file-count">—</div></article>
                        <article class="stat-card"><div class="stat-top"><span>Tổng lượt tải</span><span class="stat-icon orange">↓</span></div><div class="stat-value" id="admin-download-count">—</div></article>
                        <article class="stat-card"><div class="stat-top"><span>Dung lượng lưu trữ</span><span class="stat-icon violet">▤</span></div><div class="stat-value" id="admin-storage-bytes">—</div></article>
                    </div>
                    <div class="admin-dashboard-grid">
                        <section class="content-card admin-card">
                            <div class="section-heading"><div><h2>Hoạt động gần đây</h2><p>Hoạt động mới nhất trên hệ thống</p></div></div>
                            <div class="admin-activity-list" id="admin-activity-list" aria-live="polite"><p class="activity-empty">Đang tải...</p></div>
                        </section>
                        <section class="content-card admin-card">
                            <div class="section-heading"><div><h2>Lượt tải trong 7 ngày</h2><p>Thống kê theo ngày</p></div></div>
                            <div class="download-chart" id="download-chart" role="img" aria-label="Biểu đồ lượt tải trong 7 ngày gần đây"></div>
                        </section>
                    </div>
                    <section class="content-card admin-card admin-user-search-card">
                        <div class="section-heading"><div><h2>Tra cứu người dùng</h2><p>Tìm theo một phần tên người dùng.</p></div></div>
                        <label class="admin-user-search"><span class="sr-only">Tên người dùng</span><input id="admin-user-search" type="search" maxlength="200" placeholder="Ví dụ: Nguyễn Văn" autocomplete="off"></label>
                        <div class="admin-user-results" id="admin-user-results" aria-live="polite"><p class="activity-empty">Nhập tên để tra cứu người dùng.</p></div>
                    </section>
                    <section class="content-card admin-card admin-top-files">
                        <div class="section-heading"><div><h2>Top tệp được tải nhiều nhất</h2><p>5 tệp có tổng lượt tải cao nhất</p></div></div>
                        <div class="file-table-wrap"><table class="file-table admin-file-table"><thead><tr><th>TÊN TỆP</th><th>CHỦ SỞ HỮU</th><th>LƯỢT TẢI</th></tr></thead><tbody id="admin-top-files"><tr><td colspan="3">Đang tải...</td></tr></tbody></table></div>
                    </section>
                </section>
                <footer class="page-footer"><span>© 2026 FileShare</span><span>Dashboard quản trị</span></footer>
            </div>
        </main>
    </div>
    <div class="toast" id="admin-toast" role="status" aria-live="polite"></div>
    <script src="assets/js/admin.js"></script>
</body>
</html>
