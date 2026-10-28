<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$currentUserName = (string) ($_SESSION['user_name'] ?? 'Tài khoản');
preg_match('/^./u', $currentUserName, $initialMatch);
$userInitial = $initialMatch[0] ?? 'U';
require_once __DIR__ . '/config/app.php';
$allowedExtensions = implode(',', FILESHARE_ALLOWED_EXTENSIONS);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$defaultExpiryDate = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f7fb">
    <title>FileShare — Không gian tệp của bạn</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="#" aria-label="FileShare trang chủ">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M13.5 3.5H7.75A2.75 2.75 0 0 0 5 6.25v11.5a2.75 2.75 0 0 0 2.75 2.75h8.5A2.75 2.75 0 0 0 19 17.75V9l-5.5-5.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M13 4v5h5M8.5 13h7M8.5 16.5h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span>File<span class="brand-accent">Share</span></span>
            </a>

            <div class="workspace-label">KHÔNG GIAN LÀM VIỆC</div>
            <nav class="main-nav" aria-label="Điều hướng chính">
                <button class="nav-link active" type="button" data-view="dashboard">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.8"/></svg>
                    <span>Tổng quan</span>
                </button>
                <button class="nav-link" type="button" data-view="files">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.25A2.25 2.25 0 0 1 6.25 5h4l2 2h5.5A2.25 2.25 0 0 1 20 9.25v8.5A2.25 2.25 0 0 1 17.75 20h-11A2.75 2.75 0 0 1 4 17.25v-10Z"/><path d="M4.5 10h15" /></svg>
                    <span>Tệp của tôi</span>
                    <span class="nav-count" id="nav-file-count">0</span>
                </button>
                <button class="nav-link" type="button" data-view="folders">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h4l2 2h6A2.5 2.5 0 0 1 20.5 9.5v8A2.5 2.5 0 0 1 18 20H6a2.5 2.5 0 0 1-2.5-2.5v-10Z"/><path d="M4 10h16"/></svg>
                    <span>Thư mục</span>
                </button>
                <button class="nav-link" type="button" data-view="upload">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg>
                    <span>Tải tệp lên</span>
                </button>
                <button class="nav-link" type="button" data-view="shared">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.25 10.85 7.5-4.7m-7.5 7 7.5 4.7"/></svg>
                    <span>Được chia sẻ</span>
                </button>
            </nav>

            <div class="sidebar-bottom">
                <div class="storage-card">
                    <div class="storage-heading"><span>Dung lượng</span><span id="storage-percent">0%</span></div>
                    <div class="storage-bar"><span id="storage-bar-fill"></span></div>
                    <p><span id="storage-used">0 B</span> <span>/ <span id="storage-quota">1 GB</span> đã dùng</span></p>
                    <button type="button" class="storage-upgrade">Nâng cấp gói <span aria-hidden="true">↗</span></button>
                </div>
                <button class="profile-card" type="button" aria-label="Tài khoản <?= htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="avatar"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="profile-copy"><strong><?= htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8') ?></strong><small>Tài khoản của bạn</small></span>
                    <svg class="more-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
                </button>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumbs"><span>Không gian làm việc</span><span class="breadcrumb-divider">/</span><strong id="breadcrumb-current">Tổng quan</strong></div>
                <div class="topbar-actions">
                    <button class="icon-button search-button" type="button" aria-label="Tìm kiếm">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4 4"/></svg>
                    </button>
                    <button class="icon-button notification-button" type="button" aria-label="Thông báo">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                        <i></i>
                    </button>
                    <span class="topbar-divider"></span>
                    <span class="avatar avatar-small"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></span>
                    <button class="logout-button" type="button" id="logout-button">Đăng xuất</button>
                </div>
            </header>

            <div class="page-content">
                <section class="view-panel active" id="dashboard-view" aria-labelledby="welcome-title">
                    <div class="welcome-row">
                        <div>
                            <p class="eyebrow"><span class="status-dot"></span> THỨ BẢY, 17 THÁNG 10, 2026</p>
                            <h1 id="welcome-title">Xin chào, <?= htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8') ?> <span class="wave" aria-hidden="true">✦</span></h1>
                            <p class="welcome-subtitle">Sẵn sàng chia sẻ những tệp quan trọng của bạn?</p>
                        </div>
                        <button class="primary-button" type="button" data-open-upload>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg>
                            Tải tệp lên
                        </button>
                    </div>

                    <div class="stats-grid">
                        <article class="stat-card">
                            <div class="stat-top"><span>Tổng người dùng</span><span class="stat-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20m6-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-7.5a4 4 0 0 1 0 7.75m2 3.75a3.5 3.5 0 0 1 2 3.5V20"/></svg></span></div>
                            <div class="stat-value">15</div>
                            <div class="stat-foot"><span class="trend">↑ 12%</span> so với tháng trước</div>
                        </article>
                        <article class="stat-card">
                            <div class="stat-top"><span>Tổng số tệp</span><span class="stat-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 3.5H7.75A2.75 2.75 0 0 0 5 6.25v11.5a2.75 2.75 0 0 0 2.75 2.75h8.5A2.75 2.75 0 0 0 19 17.75V9l-5.5-5.5Z"/><path d="M13 4v5h5"/></svg></span></div>
                            <div class="stat-value" id="stat-file-count">0</div>
                            <div class="stat-foot"><span class="trend">↑ 8%</span> so với tháng trước</div>
                        </article>
                        <article class="stat-card">
                            <div class="stat-top"><span>Lượt tải hôm nay</span><span class="stat-icon orange"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12m0 0 4-4m-4 4-4-4"/><path d="M5 18v2h14v-2"/></svg></span></div>
                            <div class="stat-value">35</div>
                            <div class="stat-foot"><span class="trend">↑ 5%</span> so với hôm qua</div>
                        </article>
                    </div>

                    <div class="dashboard-grid">
                        <section class="content-card recent-card">
                            <div class="section-heading">
                                <div><h2>Tệp gần đây</h2><p>Tất cả tệp bạn đã tải lên</p></div>
                                <button class="text-button" type="button" data-open-files>Xem tất cả <span aria-hidden="true">→</span></button>
                            </div>
                            <div class="file-table-wrap">
                                <table class="file-table">
                                    <thead><tr><th>TÊN TỆP</th><th>NGÀY TẢI LÊN</th><th>QUYỀN TRUY CẬP</th><th>LƯỢT TẢI</th><th></th></tr></thead>
                                    <tbody id="recent-file-rows">
                                        <tr class="file-row" data-filename="BaoCao.docx" data-share-link="https://fileshare.vn/f/abc123"><td><span class="file-name"><span class="file-icon word"><span>W</span></span><span><strong>BaoCao.docx</strong><small>2.4 MB</small></span></span></td><td>15/10/2026</td><td><span class="access-badge public"><i></i>Công khai</span></td><td>24 lượt</td><td><button class="row-menu" type="button" aria-label="Tùy chọn BaoCao.docx">···</button></td></tr>
                                        <tr class="file-row" data-filename="DeTai.pdf" data-share-link="https://fileshare.vn/f/def456"><td><span class="file-name"><span class="file-icon pdf"><span>P</span></span><span><strong>DeTai.pdf</strong><small>1.8 MB</small></span></span></td><td>12/10/2026</td><td><span class="access-badge private"><i></i>Riêng tư</span></td><td>8 lượt</td><td><button class="row-menu" type="button" aria-label="Tùy chọn DeTai.pdf">···</button></td></tr>
                                        <tr class="file-row" data-filename="KeHoach.xlsx" data-share-link="https://fileshare.vn/f/ghi789"><td><span class="file-name"><span class="file-icon excel"><span>X</span></span><span><strong>KeHoach.xlsx</strong><small>856 KB</small></span></span></td><td>10/10/2026</td><td><span class="access-badge public"><i></i>Công khai</span></td><td>16 lượt</td><td><button class="row-menu" type="button" aria-label="Tùy chọn KeHoach.xlsx">···</button></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <aside class="content-card activity-card">
                            <div class="section-heading"><div><h2>Hoạt động gần đây</h2><p>Cập nhật mới nhất</p></div><button class="more-button" type="button" aria-label="Xem thêm">···</button></div>
                            <div class="activity-list">
                                <div class="activity-item"><span class="activity-avatar activity-blue">NA</span><div><p><strong>Nguyễn An</strong> đã tải tệp</p><span>BaoCao.docx · 12 phút trước</span></div></div>
                                <div class="activity-item"><span class="activity-avatar activity-orange">ML</span><div><p><strong>Minh Long</strong> đã xem tệp</p><span>DeTai.pdf · 1 giờ trước</span></div></div>
                                <div class="activity-item"><span class="activity-avatar activity-green">TH</span><div><p><strong>Thu Hà</strong> được cấp quyền</p><span>KeHoach.xlsx · 3 giờ trước</span></div></div>
                                <div class="activity-item"><span class="activity-avatar activity-purple">BT</span><div><p><strong>Bạn</strong> đã tải tệp mới</p><span>BaoCao.docx · Hôm qua</span></div></div>
                            </div>
                            <button class="activity-link" type="button">Xem tất cả hoạt động <span aria-hidden="true">→</span></button>
                        </aside>
                    </div>

                    <section class="content-card users-card">
                        <div class="section-heading">
                            <div><h2>Thống kê người dùng</h2><p>Tổng quan hoạt động chia sẻ tệp</p></div>
                            <button class="filter-button" type="button">Tháng này <span aria-hidden="true">⌄</span></button>
                        </div>
                        <div class="file-table-wrap">
                            <table class="user-table">
                                <thead><tr><th>NGƯỜI DÙNG</th><th>EMAIL</th><th>SỐ TỆP</th><th>SỐ LƯỢT TẢI</th><th>TRẠNG THÁI</th></tr></thead>
                                <tbody>
                                    <tr><td><span class="user-name"><span class="mini-avatar user-a">NA</span><strong>Nguyễn Văn An</strong></span></td><td>nguyenvana@gmail.com</td><td>18 tệp</td><td>142 lượt</td><td><span class="member-status">Hoạt động</span></td></tr>
                                    <tr><td><span class="user-name"><span class="mini-avatar user-b">ML</span><strong>Minh Long</strong></span></td><td>minhlong@gmail.com</td><td>12 tệp</td><td>96 lượt</td><td><span class="member-status">Hoạt động</span></td></tr>
                                    <tr><td><span class="user-name"><span class="mini-avatar user-c">TH</span><strong>Thu Hà</strong></span></td><td>thuha@gmail.com</td><td>9 tệp</td><td>74 lượt</td><td><span class="member-status">Hoạt động</span></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <section class="view-panel" id="files-view" aria-labelledby="files-title">
                    <div class="page-heading"><div><p class="eyebrow">THƯ VIỆN CỦA BẠN</p><h1 id="files-title">Tệp của tôi</h1><p class="welcome-subtitle">Quản lý và chia sẻ tất cả tệp của bạn.</p></div><button class="primary-button" type="button" data-open-upload><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg>Tải tệp lên</button></div>
                    <section class="content-card full-files-card">
                        <div class="file-toolbar"><label class="search-field"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4 4"/></svg><input id="file-search" type="search" placeholder="Tìm kiếm tệp..." aria-label="Tìm kiếm tệp"></label><label class="sr-only" for="file-folder-filter">Lọc thư mục</label><select class="filter-button" id="file-folder-filter" aria-label="Lọc thư mục"><option value="all">Tất cả thư mục</option></select><label class="sr-only" for="file-status-filter">Lọc trạng thái chia sẻ</label><select class="filter-button" id="file-status-filter" aria-label="Lọc trạng thái chia sẻ"><option value="all">Tất cả trạng thái</option><option value="public">Công khai</option><option value="private">Riêng tư</option><option value="revoked">Đã thu hồi</option><option value="expired">Đã hết hạn</option><option value="missing">Thiếu trên ổ đĩa</option></select><a class="filter-button report-button" href="api/access_report.php" download>Xuất báo cáo truy cập</a></div>
                        <section id="file-folder-browser" class="file-folder-browser" aria-label="Thư mục hiện có"></section>
                        <section class="active-links-section" aria-labelledby="active-links-title"><div><h2 id="active-links-title">Liên kết còn hiệu lực</h2><p>Liên kết công khai chưa hết hạn hoặc đạt giới hạn tải.</p></div><ul id="active-links-list" class="active-links-list"></ul></section>
                        <div class="file-table-wrap"><table class="file-table"><thead><tr><th>TÊN TỆP</th><th>NGÀY TẢI LÊN</th><th>QUYỀN TRUY CẬP</th><th>LƯỢT TẢI</th><th></th></tr></thead><tbody id="all-file-rows"></tbody></table></div>
                    </section>
                </section>

                <section class="view-panel" id="upload-view" aria-labelledby="upload-title">
                    <div class="page-heading"><div><p class="eyebrow">THƯ VIỆN CỦA BẠN</p><h1 id="upload-title">Tải tệp lên</h1><p class="welcome-subtitle">Thêm tệp mới và thiết lập quyền chia sẻ.</p></div></div>
                    <form class="upload-layout" id="upload-form" enctype="multipart/form-data">
                        <div class="upload-main">
                            <label class="dropzone" id="dropzone" for="file-input">
                                <input id="file-input" name="file" type="file" accept="<?= htmlspecialchars(implode(',', array_map(static fn (string $extension): string => '.' . $extension, FILESHARE_ALLOWED_EXTENSIONS)), ENT_QUOTES, 'UTF-8') ?>" hidden>
                                <span class="upload-cloud"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg></span>
                                <strong>Kéo thả tệp vào đây</strong>
                                <span class="dropzone-subtitle">hoặc chọn tệp từ thiết bị của bạn</span>
                                <span class="secondary-button">Chọn tệp</span>
                                <span class="dropzone-hint">Định dạng: <?= htmlspecialchars(strtoupper(implode(', ', FILESHARE_ALLOWED_EXTENSIONS)), ENT_QUOTES, 'UTF-8') ?> · Tối đa <?= (int) (FILESHARE_MAX_UPLOAD_BYTES / 1024 / 1024) ?> MB</span>
                            </label>
                            <div class="selected-file" id="selected-file" hidden></div>
                            <div class="form-card">
                                <label class="field-label" for="file-name">Tên tệp</label>
                                <input class="text-input" id="file-name" name="fileName" type="text" placeholder="Tên tệp hiển thị" required>
                                <div class="field-label access-label">Quyền tải xuống</div>
                                <label class="field-label" for="upload-folder">Lưu vào thư mục</label>
                                <select class="text-input" id="upload-folder" name="folder_id"><option value="">Không có thư mục</option></select>
                                <div class="radio-options">
                                    <label class="radio-card selected"><input type="radio" name="access" value="public" checked><span class="radio-indicator"></span><span><strong>Công khai</strong><small>Bất kỳ ai có liên kết đều có thể tải</small></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18m0-18 7 7m-7-7L5 10"/></svg></label>
                                    <label class="radio-card"><input type="radio" name="access" value="private"><span class="radio-indicator"></span><span><strong>Riêng tư</strong><small>Chỉ người được cấp quyền mới có thể tải</small></span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></label>
                                </div>
                            </div>
                        </div>
                        <aside class="upload-side">
                            <div class="form-card settings-card">
                                <h2>Cài đặt chia sẻ</h2>
                                <label class="field-label" for="download-limit">Giới hạn lượt tải</label>
                                <div class="input-suffix"><input class="text-input" id="download-limit" name="limit" type="number" min="1" value="10"><span>lượt</span></div>
                                <label class="field-label expiry-label" for="expiry-date">Ngày hết hạn</label>
                                <div class="date-input-wrap"><input class="text-input" id="expiry-date" name="expiry" type="date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($defaultExpiryDate, ENT_QUOTES, 'UTF-8') ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></div>
                                <label class="field-label" for="upload-link-password">Mật khẩu liên kết (tùy chọn)</label><input class="text-input" id="upload-link-password" name="link_password" type="password" minlength="8" maxlength="1024" autocomplete="new-password" placeholder="Tối thiểu 8 ký tự">
                                <div class="settings-note"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg>Liên kết sẽ tự động hết hạn sau ngày đã chọn.</div>
                                <button class="primary-button save-button" type="submit">Lưu tệp <span aria-hidden="true">→</span></button>
                            </div>
                            <div class="privacy-note"><span class="privacy-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg></span><div><strong>Tệp của bạn luôn an toàn</strong><p>Chỉ những người bạn cho phép mới có thể truy cập tệp riêng tư.</p></div></div>
                        </aside>
                    </form>
                </section>

                <section class="view-panel" id="folders-view" aria-labelledby="folders-title">
                    <div class="page-heading"><div><p class="eyebrow">SẮP XẾP TỆP</p><h1 id="folders-title">Thư mục</h1><p class="welcome-subtitle">Tạo và quản lý thư mục lưu trữ của bạn.</p></div></div>
                    <section class="content-card folder-manager-card">
                        <form id="create-folder-form" class="add-user-form"><label class="sr-only" for="folder-name">Tên thư mục</label><input id="folder-name" maxlength="255" placeholder="Tên thư mục mới..." required><button class="primary-button" type="submit">Tạo thư mục</button></form>
                        <div id="folder-list" class="folder-list" aria-live="polite"></div>
                    </section>
                </section>

                <section class="view-panel" id="shared-view" aria-labelledby="shared-title">
                    <div class="page-heading"><div><p class="eyebrow">CHIA SẺ AN TOÀN</p><h1 id="shared-title">Chi tiết tệp</h1><p class="welcome-subtitle">Quản lý liên kết và những người có quyền truy cập.</p></div><button class="secondary-button back-files" type="button" data-open-files>← Tất cả tệp</button></div>
                    <div class="details-layout">
                        <section class="content-card details-card">
                            <div class="detail-file-heading"><span class="file-icon word" id="detail-file-icon"><span>W</span></span><div><h2 id="detail-file-name">BaoCao.docx</h2><p id="detail-file-meta">Tải lên ngày 15/10/2026 · 2.4 MB</p></div><span class="access-badge public" id="detail-access"><i></i>Công khai</span></div>
                            <div class="detail-section"><h3>Liên kết chia sẻ</h3><p id="share-description">Ai có liên kết đều có thể xem và tải tệp này.</p><div class="share-link"><span id="share-url">https://fileshare.vn/f/abc123</span><button type="button" id="copy-link" class="copy-button"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>Sao chép</button></div></div>
                            <div class="detail-section permission-section"><div class="permission-heading"><div><h3>Người được cấp quyền</h3><p>Những người có quyền truy cập tệp này.</p></div><span class="permission-count" id="permission-count">0 người</span></div><div class="permission-list" id="permission-list"></div><button class="text-button permission-history-toggle" type="button" id="permission-history-toggle">Xem lịch sử cấp quyền</button><div class="permission-history" id="permission-history" hidden></div><form class="add-user-form" id="add-user-form"><label class="sr-only" for="user-email">Địa chỉ email</label><input id="user-email" type="email" placeholder="Nhập địa chỉ email..." required><button class="secondary-button" type="submit"><span aria-hidden="true">+</span> Thêm người dùng</button></form></div>
                            <div class="detail-section file-management-section"><h3>Quản lý tệp</h3><form id="move-file-form"><label class="sr-only" for="move-folder">Chuyển vào thư mục</label><select class="text-input" id="move-folder"><option value="">Không có thư mục</option></select><button class="secondary-button" type="submit">Chuyển thư mục</button></form><button class="danger-button" type="button" id="delete-file">Xóa tệp</button></div>
                        </section>
                        <aside class="content-card link-settings"><h2>Cài đặt liên kết</h2><div class="link-setting-row"><span class="stat-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.07 0l3-3A5 5 0 0 0 13 2.93l-1.72 1.72"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3A5 5 0 0 0 11 21.07l1.72-1.72"/></svg></span><div><strong>Liên kết chia sẻ</strong><small id="link-status-label">Liên kết đang hoạt động</small></div><span class="toggle on" id="link-status-toggle" aria-label="Đang bật"><i></i></span></div><div class="link-setting-row"><span class="stat-icon orange"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12m0 0 4-4m-4 4-4-4"/><path d="M5 18v2h14v-2"/></svg></span><div><strong>Giới hạn lượt tải</strong><small id="link-download-limit">—</small></div></div><div class="link-setting-row"><span class="stat-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><div><strong>Ngày hết hạn</strong><small id="link-expiry">—</small></div></div><label class="field-label" for="detail-link-limit">Giới hạn lượt tải (để trống = không giới hạn)</label><input class="text-input" id="detail-link-limit" type="number" min="1" placeholder="Không giới hạn"><label class="field-label" for="detail-link-expiry">Ngày hết hạn (để trống = không hết hạn)</label><input class="text-input" id="detail-link-expiry" type="date" min="<?= date('Y-m-d') ?>"><label class="field-label" for="detail-link-password">Mật khẩu mới (để trống = không đặt/không đổi)</label><input class="text-input" id="detail-link-password" type="password" minlength="8" maxlength="1024" autocomplete="new-password" placeholder="Tối thiểu 8 ký tự"><label class="link-password-clear"><input id="clear-link-password" type="checkbox"> Gỡ mật khẩu hiện tại</label><p class="settings-note">Lưu cài đặt sẽ tạo mã liên kết mới và đặt lại lượt tải về 0.</p><button class="primary-button create-link-button" type="button" id="create-link">Lưu cài đặt và tạo mã mới</button><button class="danger-button" type="button" id="revoke-link">Thu hồi liên kết</button></aside>
                    </div>
                </section>
                <footer class="page-footer"><span>© 2026 FileShare</span><span>Chia sẻ tệp dễ dàng và an toàn.</span><a href="#" id="help-link">Trung tâm trợ giúp</a></footer>
            </div>
        </main>
    </div>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
    <script src="assets/js/script.js"></script>
    <script src="assets/js/file-library.js"></script>
    <script src="assets/js/folders.js"></script>
</body>
</html>
