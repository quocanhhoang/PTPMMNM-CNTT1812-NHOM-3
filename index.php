<?php
require_once __DIR__ . '/lib.php';
app_session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $_SESSION['csrf'];
}
$defaultExpiryDate = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="vi" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#090d18">
    <title>FileShare — Không gian tệp của bạn</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
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
                    <div class="storage-heading"><span>Dung lượng</span><span>68%</span></div>
                    <div class="storage-bar"><span></span></div>
                    <p>6.8 GB <span>/ 10 GB đã dùng</span></p>
                    <button type="button" class="storage-upgrade">Nâng cấp gói <span aria-hidden="true">↗</span></button>
                </div>
                <button class="profile-card" id="profile-card" type="button" aria-label="Đăng xuất">
                    <span class="avatar">K</span>
                    <span class="profile-copy"><strong id="profile-name">Người dùng</strong><small id="profile-email">Đăng xuất</small></span>
                    <svg class="more-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
                </button>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumbs"><span>Không gian làm việc</span><span class="breadcrumb-divider">/</span><strong id="breadcrumb-current">Tổng quan</strong></div>
                <div class="topbar-actions">
                    <button class="icon-button theme-button" id="theme-toggle" type="button" aria-label="Chuyển sang chế độ tối" aria-pressed="false" title="Chuyển sang chế độ tối">
                        <svg class="theme-icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 15.5A8.5 8.5 0 0 1 8.5 3.5a8.5 8.5 0 1 0 12 12Z"/></svg>
                        <svg class="theme-icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                    </button>
                    <button class="icon-button search-button" id="global-search-toggle" type="button" aria-label="Tìm kiếm tệp" aria-expanded="false" aria-controls="global-search-panel">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4 4"/></svg>
                    </button>
                    <button class="icon-button notification-button" id="notification-toggle" type="button" aria-label="Thông báo mới" aria-expanded="false" aria-controls="notification-panel">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                        <i id="notification-indicator"></i>
                    </button>
                    <section class="topbar-popover search-popover" id="global-search-panel" aria-label="Tìm kiếm tệp" hidden>
                        <label class="global-search-field">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4 4"/></svg>
                            <input id="global-search-input" type="search" placeholder="Tìm tên tệp..." autocomplete="off">
                            <kbd>ESC</kbd>
                        </label>
                        <p class="popover-caption" id="search-result-caption">Tệp của bạn</p>
                        <div class="global-search-results" id="global-search-results" role="listbox" aria-label="Kết quả tìm kiếm"></div>
                    </section>
                    <section class="topbar-popover notification-popover" id="notification-panel" aria-label="Thông báo gần đây" hidden>
                        <div class="popover-heading"><div><strong>Thông báo</strong><span id="notification-count">Gần đây</span></div><button class="mark-read-button" id="mark-notifications-read" type="button">Đánh dấu đã đọc</button></div>
                        <div class="notification-list" id="notification-list"></div>
                    </section>
                    <span class="topbar-divider"></span>
                    <span class="avatar avatar-small">K</span>
                </div>
            </header>

            <div class="page-content">
                <section class="view-panel active" id="dashboard-view" aria-labelledby="welcome-title">
                    <div class="welcome-row">
                        <div>
                            <p class="eyebrow"><span class="status-dot"></span> KHÔNG GIAN CỦA BẠN</p>
                            <h1 id="welcome-title">Xin chào <span class="wave" aria-hidden="true">✦</span></h1>
                            <p class="welcome-subtitle">Sẵn sàng chia sẻ những tệp quan trọng của bạn?</p>
                        </div>
                        <button class="primary-button" type="button" data-open-upload>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg>
                            Tải tệp lên
                        </button>
                    </div>

                    <section class="future-hero" aria-label="Không gian lưu trữ cá nhân">
                        <div class="hero-copy">
                            <p class="hero-kicker"><span>PERSONAL CLOUD <i>／</i> 01</span><span class="hero-live"><i></i> HỆ THỐNG ĐANG HOẠT ĐỘNG</span></p>
                            <h2>Mọi thứ quan trọng,<br><span>ở đúng quỹ đạo.</span></h2>
                            <p class="hero-description">Không gian lưu trữ riêng tư, sẵn sàng cho mọi ý tưởng bạn muốn chia sẻ.</p>
                            <div class="hero-actions">
                                <button class="hero-upload" type="button" data-open-upload><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg>Thêm tệp mới <span>↗</span></button>
                                <div class="hero-capacity"><span>DUNG LƯỢNG ĐÃ DÙNG</span><strong>6.8 <small>GB / 10 GB</small></strong></div>
                            </div>
                        </div>
                        <div class="hero-visual" aria-hidden="true">
                            <div class="hero-orbit hero-orbit-outer"></div>
                            <div class="hero-orbit hero-orbit-inner"></div>
                            <div class="hero-planet"><span class="planet-glow"></span><svg viewBox="0 0 24 24"><path d="M13.5 3.5H7.75A2.75 2.75 0 0 0 5 6.25v11.5a2.75 2.75 0 0 0 2.75 2.75h8.5A2.75 2.75 0 0 0 19 17.75V9l-5.5-5.5Z"/><path d="M13 4v5h5M8.5 13h7M8.5 16.5h7"/></svg></div>
                            <span class="hero-node hero-node-one"></span><span class="hero-node hero-node-two"></span><span class="hero-node hero-node-three"></span>
                            <div class="hero-float hero-float-top"><span class="hero-float-icon">↗</span><span><small>ĐƯỢC CHIA SẺ</small><strong id="hero-links-count">0 liên kết</strong></span></div>
                            <div class="hero-float hero-float-bottom"><span class="hero-signal"><i></i><i></i><i></i></span><span><small>ĐỒNG BỘ HÓA</small><strong>Đang bảo mật</strong></span></div>
                            <span class="hero-coordinate">10°46' N&nbsp; 106°40' E</span>
                        </div>
                    </section>

                    <div class="stats-grid">
                        <article class="stat-card">
                            <div class="stat-top"><span>Tệp đã lưu</span><span class="stat-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20m6-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-7.5a4 4 0 0 1 0 7.75m2 3.75a3.5 3.5 0 0 1 2 3.5V20"/></svg></span></div>
                            <div class="stat-value" id="stat-files">0</div>
                            <div class="stat-foot">Trong không gian của bạn</div>
                        </article>
                        <article class="stat-card">
                            <div class="stat-top"><span>Liên kết còn hiệu lực</span><span class="stat-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 3.5H7.75A2.75 2.75 0 0 0 5 6.25v11.5a2.75 2.75 0 0 0 2.75 2.75h8.5A2.75 2.75 0 0 0 19 17.75V9l-5.5-5.5Z"/><path d="M13 4v5h5"/></svg></span></div>
                            <div class="stat-value" id="stat-links">0</div>
                            <div class="stat-foot">Tự hết hạn theo cài đặt</div>
                        </article>
                        <article class="stat-card">
                            <div class="stat-top"><span>Tổng lượt tải</span><span class="stat-icon orange"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12m0 0 4-4m-4 4-4-4"/><path d="M5 18v2h14v-2"/></svg></span></div>
                            <div class="stat-value" id="stat-downloads">0</div>
                            <div class="stat-foot">Đã ghi nhận trên máy chủ</div>
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
                                    <tbody id="recent-file-rows"></tbody>
                                </table>
                            </div>
                        </section>
                        <aside class="content-card activity-card">
                            <div class="section-heading"><div><h2>Hoạt động gần đây</h2><p>Cập nhật mới nhất</p></div><button class="more-button" type="button" aria-label="Xem thêm">···</button></div>
                            <div class="activity-list" id="dashboard-activity"></div>
                            <button class="activity-link" type="button" data-open-shared>Xem tất cả hoạt động <span aria-hidden="true">→</span></button>
                        </aside>
                    </div>

                    <section class="content-card users-card">
                        <div class="section-heading">
                        <div><h2>Lịch sử truy cập</h2><p>Hoạt động tải lên, cấp quyền, thu hồi và tải xuống. IP chưa được lưu trong cơ sở dữ liệu.</p></div>
                            <button class="secondary-button" id="export-dashboard-report" type="button">Xuất CSV</button>
                        </div>
                        <div class="file-table-wrap">
                            <table class="user-table">
                                <thead><tr><th>THỜI GIAN</th><th>SỰ KIỆN</th><th>TỆP</th><th>ĐỊA CHỈ IP</th><th>CHI TIẾT</th></tr></thead>
                                <tbody id="audit-rows"></tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <section class="view-panel" id="files-view" aria-labelledby="files-title">
                    <div class="page-heading"><div><p class="eyebrow">THƯ VIỆN CỦA BẠN</p><h1 id="files-title">Tệp của tôi</h1><p class="welcome-subtitle">Quản lý và chia sẻ tất cả tệp của bạn.</p></div><button class="primary-button" type="button" data-open-upload><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg>Tải tệp lên</button></div>
                    <section class="content-card full-files-card">
                        <div class="file-toolbar"><label class="search-field"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4 4"/></svg><input id="file-search" type="search" placeholder="Tìm kiếm tệp..." aria-label="Tìm kiếm tệp"></label><select class="filter-button" id="share-filter" aria-label="Lọc trạng thái chia sẻ"><option value="all">Tất cả trạng thái</option><option value="active">Đang chia sẻ</option><option value="private">Riêng tư</option><option value="expired">Hết hạn / đã thu hồi</option><option value="none">Chưa chia sẻ</option></select><select class="filter-button" id="folder-filter" aria-label="Lọc theo thư mục"><option value="all">Tất cả thư mục</option></select><button class="secondary-button" id="create-folder" type="button">+ Thư mục</button><button class="secondary-button" id="export-report" type="button">Xuất báo cáo</button></div>
                        <div class="file-table-wrap"><table class="file-table"><thead><tr><th>TÊN TỆP</th><th>NGÀY TẢI LÊN</th><th>QUYỀN TRUY CẬP</th><th>LƯỢT TẢI</th><th></th></tr></thead><tbody id="all-file-rows"></tbody></table></div>
                    </section>
                </section>

                <section class="view-panel" id="upload-view" aria-labelledby="upload-title">
                    <div class="page-heading"><div><p class="eyebrow">THƯ VIỆN CỦA BẠN</p><h1 id="upload-title">Tải tệp lên</h1><p class="welcome-subtitle">Thêm tệp mới và thiết lập quyền chia sẻ.</p></div></div>
                    <form class="upload-layout" id="upload-form" enctype="multipart/form-data">
                        <div class="upload-main">
                            <label class="dropzone" id="dropzone" for="file-input">
                                <input id="file-input" type="file" accept=".pdf,.txt,.csv,.png,.jpg,.jpeg,.gif,.webp" hidden>
                                <span class="upload-cloud"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M5 14v4.25A1.75 1.75 0 0 0 6.75 20h10.5A1.75 1.75 0 0 0 19 18.25V14"/></svg></span>
                                <strong>Kéo thả tệp vào đây</strong>
                                <span class="dropzone-subtitle">hoặc chọn tệp từ thiết bị của bạn</span>
                                <span class="secondary-button">Chọn tệp</span>
                                <span class="dropzone-hint">PDF, TXT, CSV, PNG, JPG, GIF, WEBP · Tối đa 100 MB</span>
                            </label>
                            <div class="selected-file" id="selected-file" hidden></div>
                            <div class="form-card">
                                <label class="field-label" for="folder-select">Thư mục lưu</label>
                                <select class="text-input" id="folder-select" name="folder_id" required></select>
                                <label class="field-label" for="file-name">Tên tệp</label>
                                <input class="text-input" id="file-name" name="fileName" type="text" value="" required>
                                <div class="field-label access-label">Quyền tải xuống</div>
                                <div class="radio-options">
                                    <label class="radio-card selected"><input type="radio" name="access" value="public" checked><span class="radio-indicator"></span><span><strong>Công khai</strong><small>Bất kỳ ai có liên kết đều có thể tải</small></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18m0-18 7 7m-7-7L5 10"/></svg></label>
                                    <label class="radio-card"><input type="radio" name="access" value="private"><span class="radio-indicator"></span><span><strong>Riêng tư</strong><small>Chỉ người được cấp quyền mới có thể tải</small></span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></label>
                                </div>
                            </div>
                        </div>
                        <aside class="upload-side">
                            <div class="form-card settings-card">
                                <h2>Cài đặt chia sẻ</h2>
                                <label class="field-label" for="download-limit">Giới hạn lượt tải (trống = không giới hạn)</label>
                                <div class="input-suffix"><input class="text-input" id="download-limit" name="max_downloads" type="number" min="1" max="1000000" placeholder="Không giới hạn"><span>lượt</span></div>
                                <label class="field-label expiry-label" for="expiry-days">Thời hạn liên kết</label>
                                <div class="input-suffix"><input class="text-input" id="expiry-days" name="expiry_days" type="number" min="1" max="3650" value="7" required><span>ngày</span></div>
                                <label class="field-label expiry-label" for="link-password">Mật khẩu liên kết (không bắt buộc)</label>
                                <input class="text-input" id="link-password" name="password" type="password" maxlength="200" autocomplete="new-password" placeholder="Tạo mật khẩu tải tệp">
                                <div class="settings-note"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg>Tải xuống cần đi qua máy chủ để kiểm tra quyền và thời hạn.</div>
                                <button class="primary-button save-button" type="submit">Lưu tệp <span aria-hidden="true">→</span></button>
                            </div>
                            <div class="privacy-note"><span class="privacy-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg></span><div><strong>Tệp của bạn luôn an toàn</strong><p>Chỉ những người bạn cho phép mới có thể truy cập tệp riêng tư.</p></div></div>
                        </aside>
                    </form>
                </section>

                <section class="view-panel" id="shared-view" aria-labelledby="shared-title">
                    <div class="page-heading"><div><p class="eyebrow">CHIA SẺ AN TOÀN</p><h1 id="shared-title">Liên kết chia sẻ</h1><p class="welcome-subtitle">Quản lý liên kết còn hiệu lực và lịch sử cấp quyền.</p></div><div class="heading-actions"><button class="primary-button" id="create-link" type="button">+ Tạo liên kết</button><button class="secondary-button back-files" type="button" data-open-files>← Tất cả tệp</button></div></div>
                    <section class="content-card full-files-card link-list-card"><div class="section-heading"><div><h2>Liên kết đã tạo</h2><p>Liên kết hết hạn hoặc thu hồi sẽ không thể tải tệp.</p></div><button class="secondary-button" id="export-audit" type="button">Xuất lịch sử truy cập</button></div><div class="file-table-wrap"><table class="file-table"><thead><tr><th>TỆP</th><th>TRẠNG THÁI</th><th>LƯỢT TẢI</th><th>HẾT HẠN</th><th>MẬT KHẨU</th><th></th></tr></thead><tbody id="link-rows"></tbody></table></div></section>
                    <div class="details-layout">
                        <section class="content-card details-card">
                            <div class="detail-file-heading"><span class="file-icon word" id="detail-file-icon"><span>F</span></span><div><h2 id="detail-file-name">Chưa chọn tệp</h2><p id="detail-file-meta">Chọn một tệp để xem chi tiết.</p></div><span class="access-badge private" id="detail-access"><i></i>Chưa chia sẻ</span></div>
                            <div class="detail-section"><h3>Liên kết chia sẻ</h3><p id="share-description">Chọn một liên kết bên dưới để xem chi tiết.</p><div class="share-link"><span id="share-url">Chưa chọn liên kết</span><button type="button" id="copy-link" class="copy-button"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>Sao chép</button></div></div>
                            <div class="detail-section permission-section"><h3>Lịch sử cấp quyền</h3><p>Những tài khoản được cấp quyền tải tệp này.</p><form class="permission-grant-form" id="permission-grant-form"><label class="field-label" for="permission-email">Email tài khoản</label><div class="permission-grant-controls"><input class="text-input" id="permission-email" name="email" type="email" maxlength="100" autocomplete="email" placeholder="user@example.com" required><button class="secondary-button" id="permission-grant-submit" type="submit" disabled>Cấp quyền tải</button></div></form><ul class="permission-history" id="permission-history" aria-live="polite"><li>Chọn tệp để xem lịch sử cấp quyền.</li></ul></div>
                        </section>
                        <aside class="content-card link-settings"><h2>Cài đặt liên kết</h2><div class="link-setting-row"><span class="stat-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.07 0l3-3A5 5 0 0 0 13 2.93l-1.72 1.72"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3A5 5 0 0 0 11 21.07l1.72-1.72"/></svg></span><div><strong>Liên kết đang hoạt động</strong><small>Chưa tạo liên kết</small></div><span class="toggle" aria-label="Đang tắt"><i></i></span></div><div class="link-setting-row"><span class="stat-icon orange"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12m0 0 4-4m-4 4-4-4"/><path d="M5 18v2h14v-2"/></svg></span><div><strong>Giới hạn lượt tải</strong><small>—</small></div></div><div class="link-setting-row"><span class="stat-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><div><strong>Ngày hết hạn</strong><small>—</small></div></div><button class="danger-button" type="button" id="revoke-link" disabled>Thu hồi liên kết</button></aside>
                    </div>
                </section>
                <footer class="page-footer"><span>© 2026 FileShare</span><span>Chia sẻ tệp dễ dàng và an toàn.</span><button class="text-button" id="cleanup-preview" type="button">Xem trước tệp hết hạn</button><a href="#" id="help-link">Trung tâm trợ giúp</a></footer>
            </div>
        </main>
    </div>
    <section class="auth-overlay" id="auth-overlay" aria-labelledby="auth-title">
        <form class="auth-card" id="auth-form">
            <span class="brand-mark auth-mark" aria-hidden="true">F</span>
            <p class="eyebrow">FILESHARE · KHÔNG GIAN RIÊNG</p>
            <h1 id="auth-title">Đăng nhập</h1>
            <p class="welcome-subtitle" id="auth-description">Đăng nhập để quản lý tệp và liên kết chia sẻ của bạn.</p>
            <label class="field-label" for="auth-name" id="auth-name-label" hidden>Họ và tên</label>
            <input class="text-input" id="auth-name" name="name" autocomplete="name" maxlength="80" hidden>
            <label class="field-label" for="auth-email">Email</label>
            <input class="text-input" id="auth-email" name="email" type="email" autocomplete="email" required maxlength="100">
            <label class="field-label" for="auth-password">Mật khẩu</label>
            <input class="text-input" id="auth-password" name="password" type="password" autocomplete="current-password" required minlength="10" maxlength="200">
            <p class="auth-error" id="auth-error" role="alert"></p>
            <button class="primary-button save-button" id="auth-submit" type="submit">Đăng nhập</button>
            <button class="text-button auth-switch" id="auth-switch" type="button">Chưa có tài khoản? Đăng ký</button>
            <small class="auth-help">Tài khoản mới được cấp thư mục mặc định và 10 GB dung lượng.</small>
        </form>
    </section>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
    <script src="assets/js/script.js"></script>
</body>
</html>