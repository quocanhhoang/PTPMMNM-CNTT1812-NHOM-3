(() => {
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const panels = $$(".view-panel");
    const navLinks = $$(".nav-link[data-view]");
    const allRows = $("#all-file-rows");
    const recentRows = $("#recent-file-rows");
    const toast = $("#toast");
    const authOverlay = $("#auth-overlay");
    const authForm = $("#auth-form");
    const authError = $("#auth-error");
    const fileInput = $("#file-input");
    const fileNameInput = $("#file-name");
    const selectedFile = $("#selected-file");
    const themeToggle = $("#theme-toggle");
    const themeColor = $('meta[name="theme-color"]');
    const searchToggle = $("#global-search-toggle");
    const searchPanel = $("#global-search-panel");
    const searchInput = $("#global-search-input");
    const searchResults = $("#global-search-results");
    const searchCaption = $("#search-result-caption");
    const notificationToggle = $("#notification-toggle");
    const notificationPanel = $("#notification-panel");
    const notificationList = $("#notification-list");
    const notificationCount = $("#notification-count");
    const notificationIndicator = $("#notification-indicator");
    const profileMenuToggle = $("#profile-menu-toggle");
    const profileMenu = $("#profile-menu");
    const notifications = [];
    let toastTimer;
    let csrfToken = "";
    let authMode = "login";
    let selectedUpload = null;
    let storageUsedBytes = 0;
    let storageQuotaBytes = 0;
    let defaultUploadAccess = "public";
    let activeFileId = null;
    let activeLinkId = null;
    let files = [];
    let folders = [];
    let links = [];
    let audit = [];
    const notifiedMissingFileIds = new Set();

    const showToast = (message) => {
        toast.textContent = message;
        toast.classList.add("visible");
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove("visible"), 3200);
    };

    const requestPath = async (path, { method = "GET", body, formData = false } = {}) => {
        const headers = {};
        if (method !== "GET") {
            headers["X-CSRF-Token"] = csrfToken;
            if (body && !formData) headers["Content-Type"] = "application/json";
        }
        const response = await fetch(path, {
            method,
            credentials: "same-origin",
            headers,
            body: body ? (formData ? body : JSON.stringify(body)) : undefined,
        });
        if (!response.ok) {
            let message = "Yêu cầu không thành công.";
            const result = await response.json().catch(() => null);
            if (result?.error) message = result.error;
            else if (response.status === 401) message = "Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.";
            if (response.status === 401 && !path.includes("action=login") && !path.includes("action=register")) {
                authOverlay.hidden = false;
                const sessionResponse = await fetch("api.php?action=session", { credentials: "same-origin" });
                if (sessionResponse.ok) {
                    const sessionData = await sessionResponse.json();
                    csrfToken = sessionData.csrf;
                }
            }
            throw new Error(message);
        }
        return response;
    };
    const request = (action, options) => requestPath(`api.php?action=${encodeURIComponent(action)}`, options);

    const applyTheme = (theme) => {
        const isDark = theme === "dark";
        document.documentElement.dataset.theme = theme;
        $("#setting-theme").value = theme;
        themeToggle.setAttribute("aria-pressed", String(isDark));
        themeToggle.setAttribute("aria-label", `Chuyển sang chế độ ${isDark ? "sáng" : "tối"}`);
        themeToggle.title = `Chuyển sang chế độ ${isDark ? "sáng" : "tối"}`;
        themeColor.content = isDark ? "#090d18" : "#f5f7fb";
    };

    let savedTheme = "dark";
    try {
        const storedTheme = localStorage.getItem("fileshare-theme");
        if (storedTheme === "dark" || storedTheme === "light") savedTheme = storedTheme;
        const storedAccess = localStorage.getItem("fileshare-default-access");
        if (storedAccess === "public" || storedAccess === "private") defaultUploadAccess = storedAccess;
    } catch (error) {
        showToast("Không thể đọc tùy chọn đã lưu trên trình duyệt này.");
    }
    $("#setting-default-access").value = defaultUploadAccess;
    applyTheme(savedTheme);
    const savePreference = (key, value) => {
        try {
            localStorage.setItem(key, value);
            return true;
        } catch (error) {
            showToast("Không thể lưu cài đặt trên trình duyệt này.");
            return false;
        }
    };
    themeToggle.addEventListener("click", () => {
        const theme = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
        applyTheme(theme);
        savePreference("fileshare-theme", theme);
    });
    $("#setting-theme").addEventListener("change", (event) => {
        const theme = event.currentTarget.value;
        applyTheme(theme);
        savePreference("fileshare-theme", theme);
    });

    const formatSize = (bytes) => {
        if (!Number.isFinite(bytes) || bytes <= 0) return "0 KB";
        if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const formatDate = (value) => {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? "—" : new Intl.DateTimeFormat("vi-VN").format(date);
    };

    const getIconType = (name) => {
        const extension = name.includes(".") ? name.split(".").pop().toLowerCase() : "";
        if (["doc", "docx"].includes(extension)) return ["word", "W"];
        if (extension === "pdf") return ["pdf", "P"];
        if (["xls", "xlsx", "csv"].includes(extension)) return ["excel", "X"];
        return ["word", extension.slice(0, 1).toUpperCase() || "F"];
    };

    const fileLinks = (fileId) => links.filter((link) => link.file_id === fileId);
    const activeLink = (fileId) => fileLinks(fileId)
        .filter((link) => link.status === "active" || link.status === "private")
        .sort((a, b) => b.created_at.localeCompare(a.created_at))[0];

    const activateView = (viewName) => {
        const target = document.getElementById(`${viewName}-view`);
        if (!target) return;
        panels.forEach((panel) => panel.classList.toggle("active", panel === target));
        navLinks.forEach((link) => link.classList.toggle("active", link.dataset.view === viewName));
        $("#breadcrumb-current").textContent = {
            dashboard: "Tổng quan",
            files: "Tệp của tôi",
            upload: "Tải tệp lên",
            shared: "Liên kết chia sẻ",
            upgrade: "Nâng cấp gói",
            personal: "Thông tin cá nhân",
            settings: "Cài đặt người dùng",
        }[viewName] || "Tổng quan";
        profileMenu.hidden = true;
        profileMenuToggle.setAttribute("aria-expanded", "false");
        window.scrollTo({ top: 0, behavior: "smooth" });
    };

    const makeFileRow = (file) => {
        const row = document.createElement("tr");
        const publicLink = activeLink(file.id);
        const access = publicLink?.access === "public" ? "public" : "private";
        const shareStatus = !file.share_code
            ? "none"
            : file.link_status === "active"
                ? "active"
                : file.link_status === "private"
                    ? "private"
                    : "expired";
        const [iconType, iconLetter] = getIconType(file.name);
        row.className = "file-row";
        row.dataset.fileId = file.id;
        row.dataset.filename = file.name;
        row.dataset.folderId = file.folder_id;
        row.dataset.access = access;
        row.dataset.shared = shareStatus;
        if (publicLink) row.dataset.shareLink = publicLink.url;
        row.innerHTML = `<td><span class="file-name"><span class="file-icon ${iconType}"><span></span></span><span><strong></strong><small></small></span></span></td><td></td><td><span class="access-badge ${access}"><i></i></span></td><td></td><td><button class="row-menu" type="button" aria-label="Xóa tệp" title="Xóa tệp">×</button></td>`;
        $(".file-icon span", row).textContent = iconLetter;
        $(".file-name strong", row).textContent = file.name;
        $(".file-name small", row).textContent = file.missing ? "Tệp bị thiếu trên ổ đĩa" : `${formatSize(file.size)} · ${file.folder_name}`;
        row.cells[1].textContent = formatDate(file.created_at);
        $(".access-badge", row).lastChild.textContent = file.missing
            ? "Thiếu tệp"
            : shareStatus === "active"
                ? "Đang chia sẻ"
                : shareStatus === "private"
                    ? "Riêng tư"
                    : shareStatus === "expired"
                        ? "Hết hạn / đã thu hồi"
                        : "Chưa chia sẻ";
        row.cells[3].textContent = `${file.downloads} lượt`;
        if (file.missing) row.classList.add("file-missing");
        return row;
    };

    const applyFileFilters = () => {
        const query = $("#file-search").value.trim().toLocaleLowerCase("vi");
        const shareFilter = $("#share-filter").value;
        const folderFilter = $("#folder-filter").value;
        allRows.querySelector("[data-empty]")?.remove();
        let visibleCount = 0;
        $$(".file-row", allRows).forEach((row) => {
            const file = files.find((entry) => entry.id === row.dataset.fileId);
            const statusMatches = shareFilter === "all"
                || (shareFilter === "active" && row.dataset.shared === "active")
                || (shareFilter === "private" && row.dataset.shared === "private")
                || (shareFilter === "expired" && row.dataset.shared === "expired")
                || (shareFilter === "none" && row.dataset.shared === "none");
            row.hidden = !`${row.dataset.filename} ${file?.folder_name || ""}`.toLocaleLowerCase("vi").includes(query)
                || (folderFilter !== "all" && row.dataset.folderId !== folderFilter)
                || !statusMatches;
            if (!row.hidden) visibleCount++;
        });
        if (visibleCount === 0) {
            const row = document.createElement("tr");
            row.dataset.empty = "true";
            const cell = document.createElement("td");
            cell.colSpan = 5;
            cell.textContent = files.length ? "Không tìm thấy tệp phù hợp." : "Bạn chưa tải tệp nào lên.";
            row.append(cell);
            allRows.append(row);
        }
    };

    const renderFiles = () => {
        recentRows.replaceChildren();
        allRows.replaceChildren();
        files.slice(0, 8).forEach((file) => recentRows.append(makeFileRow(file)));
        files.forEach((file) => allRows.append(makeFileRow(file)));
        $("#nav-file-count").textContent = String(files.length);
        applyFileFilters();
    };

    const renderFolders = () => {
        const filter = $("#folder-filter");
        const uploadFolder = $("#folder-select");
        filter.replaceChildren(new Option("Tất cả thư mục", "all"));
        uploadFolder.replaceChildren();
        if (folders.length === 0) {
            uploadFolder.add(new Option("Chưa có thư mục — hãy tạo thư mục trước", ""));
            uploadFolder.disabled = true;
            $("#upload-form button[type='submit']").disabled = true;
            return;
        }
        uploadFolder.disabled = false;
        $("#upload-form button[type='submit']").disabled = false;
        folders.forEach((folder) => {
            filter.add(new Option(folder.name, folder.id));
            uploadFolder.add(new Option(folder.name, folder.id));
        });
    };

    const linkStatusLabel = (status) => ({
        active: "Còn hiệu lực",
        expired: "Hết hạn",
        revoked: "Đã thu hồi",
        limit_reached: "Hết lượt tải",
        private: "Riêng tư · cần quyền",
    })[status] || "Không khả dụng";

    const renderLinks = () => {
        const linkRows = $("#link-rows");
        linkRows.replaceChildren();
        links.filter((link) => link.status === "active" || link.status === "private")
            .sort((a, b) => b.created_at.localeCompare(a.created_at))
            .forEach((link) => {
            const file = files.find((entry) => entry.id === link.file_id);
            const row = document.createElement("tr");
            row.dataset.linkId = link.id;
            row.innerHTML = `<td><strong class="link-file-name"></strong><a class="link-open" target="_blank" rel="noopener noreferrer"></a></td><td><span class="access-badge ${link.access === "public" ? "public" : "private"}"><i></i></span></td><td></td><td></td><td></td><td><button class="row-menu copy-link-row" type="button">Sao chép</button><button class="row-menu revoke-link-row" type="button">Thu hồi</button></td>`;
            $(".link-file-name", row).textContent = file?.name || "Tệp đã xóa";
            const openLink = $(".link-open", row);
            openLink.href = link.url;
            openLink.textContent = link.url;
            $(".access-badge", row).lastChild.textContent = link.access === "private"
                ? "Riêng tư · cần quyền"
                : linkStatusLabel(link.status);
            row.cells[2].textContent = link.max_downloads === null ? `${link.downloads} lượt · không giới hạn` : `${link.downloads} / ${link.max_downloads}`;
            row.cells[3].textContent = formatDate(link.expires_at);
            row.cells[4].textContent = link.password_protected ? "Có" : "Không";
            linkRows.append(row);
        });
        if (linkRows.childElementCount === 0) {
            const row = document.createElement("tr");
            const cell = document.createElement("td");
            cell.colSpan = 6;
            cell.textContent = "Bạn chưa có liên kết nào còn hiệu lực.";
            row.append(cell);
            linkRows.append(row);
        }
    };

    const updateStorage = (used, quota) => {
        const percentage = quota ? Math.min(100, Math.round(used / quota * 100)) : 0;
        $(".storage-heading span:last-child").textContent = `${percentage}%`;
        $(".storage-bar span").style.width = `${percentage}%`;
        $(".storage-card p").innerHTML = "";
        $(".storage-card p").append(document.createTextNode(`${(used / 1024 ** 3).toFixed(2)} GB `));
        const limit = document.createElement("span");
        limit.textContent = `/ ${(quota / 1024 ** 3).toFixed(0)} GB đã dùng`;
        $(".storage-card p").append(limit);
        $(".hero-capacity strong").innerHTML = "";
        $(".hero-capacity strong").append(document.createTextNode(`${(used / 1024 ** 3).toFixed(2)} `));
        const capacity = document.createElement("small");
        capacity.textContent = `GB / ${(quota / 1024 ** 3).toFixed(0)} GB`;
        $(".hero-capacity strong").append(capacity);
        $("#personal-storage-used").textContent = `${(used / 1024 ** 3).toFixed(2)} GB`;
        $("#personal-storage-quota").textContent = `${(quota / 1024 ** 3).toFixed(quota % 1024 ** 3 ? 2 : 0)} GB`;
    };

    const updatePlanOptions = () => {
        const quotaGiB = storageQuotaBytes / 1024 ** 3;
        $("#current-plan-label").textContent = `${quotaGiB.toFixed(quotaGiB % 1 ? 2 : 0)} GB`;
        $$("#storage-plans .plan-card").forEach((card) => {
            const planGiB = Number(card.dataset.planGb);
            const button = $(".plan-action", card);
            const status = $(".plan-status", card);
            const isCurrent = storageQuotaBytes === planGiB * 1024 ** 3;
            const isSmaller = planGiB * 1024 ** 3 < storageQuotaBytes;
            button.disabled = isCurrent || isSmaller;
            button.textContent = isCurrent
                ? "Gói hiện tại"
                : isSmaller
                    ? "Thấp hơn gói hiện tại"
                    : `Nâng cấp lên ${planGiB} GB`;
            status.hidden = !isCurrent;
            status.textContent = isCurrent ? "Đang sử dụng" : "";
            card.classList.toggle("plan-card-current", isCurrent);
        });
    };

    const buildNotifications = () => {
        notifications.splice(0, notifications.length);
        const auditRows = $("#audit-rows");
        auditRows.replaceChildren();
        const activityList = $("#dashboard-activity");
        activityList.replaceChildren();
        const eventLabels = {
            download: "Tải xuống",
            file_missing: "Tệp bị thiếu trên ổ đĩa",
            link_created: "Cấp liên kết",
            link_revoked: "Thu hồi liên kết",
            file_uploaded: "Tải tệp lên",
            upload: "Tải tệp lên",
            permission_granted: "Cấp quyền",
            file_deleted: "Xóa tệp",
            file_cleanup: "Dọn tệp hết hạn",
        };
        audit.slice(0, 12).forEach((event, index) => {
            const file = files.find((entry) => entry.id === event.file_id);
            const eventName = eventLabels[event.event] || event.event;
            const fileName = file?.name || event.file_name || event.details?.name || "—";
            const row = document.createElement("tr");
            [formatDate(event.created_at), eventName, fileName, event.ip || "Không ghi nhận", Object.keys(event.details || {}).length ? JSON.stringify(event.details) : "—"].forEach((value) => {
                const cell = document.createElement("td");
                cell.textContent = value;
                row.append(cell);
            });
            auditRows.append(row);
            if (index < 5) {
                const activity = document.createElement("div");
                activity.className = "activity-item";
                activity.innerHTML = '<span class="activity-avatar activity-purple">FS</span><div><p><strong></strong></p><span></span></div>';
                $("strong", activity).textContent = eventName;
                $("span:last-child", activity).textContent = `${fileName} · ${formatDate(event.created_at)}`;
                activityList.append(activity);
            }
        });
        audit.slice(0, 12).forEach((event, index) => {
            if (!["download", "file_missing", "link_created", "link_revoked", "file_uploaded", "upload", "permission_granted"].includes(event.event)) return;
            const file = files.find((entry) => entry.id === event.file_id);
            const labels = {
                download: "Có lượt tải tệp",
                file_missing: "Tệp bị thiếu trên ổ đĩa",
                link_created: "Đã tạo liên kết chia sẻ",
                link_revoked: "Đã thu hồi liên kết",
                file_uploaded: "Đã tải tệp lên",
                upload: "Đã tải tệp lên",
                permission_granted: "Đã cấp quyền tải",
            };
            notifications.push({
                title: labels[event.event],
                detail: `${file?.name || event.details?.name || "Tệp"} · ${formatDate(event.created_at)}`,
                filename: file?.name || "",
                unread: index < 2,
            });
        });
        renderNotifications();
    };

    const loadData = async () => {
        const [databaseResponse, folderResponse] = await Promise.all([
            requestPath("api/files.php"),
            requestPath("api/folders.php"),
        ]);
        const databaseFiles = await databaseResponse.json();
        const folderData = await folderResponse.json();
        const auditResponse = await requestPath("api/audit.php");
        const auditData = await auditResponse.json();
        folders = folderData.folders;
        audit = auditData.events;
        files = databaseFiles.files.map((file) => ({
            id: String(file.id),
            name: file.file_name,
            size: file.file_size,
            mime: file.file_type,
            created_at: file.created_at,
            downloads: file.download_count,
            folder_id: file.folder_id,
            folder_name: file.folder_name,
            missing: file.missing,
            share_code: file.share_code,
            access_type: file.access_type,
            link_status: file.link_status,
        }));
        const newlyMissingFiles = files.filter((file) => file.missing && !notifiedMissingFileIds.has(file.id));
        files.forEach((file) => {
            if (file.missing) notifiedMissingFileIds.add(file.id);
            else notifiedMissingFileIds.delete(file.id);
        });
        if (newlyMissingFiles.length) {
            const names = newlyMissingFiles.slice(0, 3).map((file) => `“${file.name}”`).join(", ");
            const additionalCount = newlyMissingFiles.length - 3;
            showToast(`Không tìm thấy ${names}${additionalCount > 0 ? ` và ${additionalCount} tệp khác` : ""} trên ổ đĩa. Liên kết tải sẽ không hoạt động.`);
        }
        links = databaseFiles.files
            .filter((file) => file.share_code)
            .map((file) => ({
                id: String(file.id),
                file_id: String(file.id),
                url: file.download_url,
                access: file.access_type,
                max_downloads: file.download_limit,
                downloads: file.download_count,
                expires_at: file.expires_at,
                status: file.link_status,
                password_protected: file.password_protected,
                created_at: file.created_at,
            }));
        renderFolders();
        renderFiles();
        renderLinks();
        const used = databaseFiles.files.reduce((total, file) => total + (file.missing ? 0 : file.file_size), 0);
        storageUsedBytes = used;
        storageQuotaBytes = databaseFiles.quota;
        const availableLinks = links.filter((link) => link.status === "active" || link.status === "private").length;
        updateStorage(used, databaseFiles.quota);
        updatePlanOptions();
        $("#personal-file-count").textContent = String(files.length);
        $("#stat-files").textContent = String(files.length);
        $("#stat-links").textContent = String(availableLinks);
        $("#stat-downloads").textContent = String(files.reduce((total, file) => total + file.downloads, 0));
        $("#hero-links-count").textContent = `${availableLinks} liên kết`;
        buildNotifications();
    };

    const renderNotifications = () => {
        notificationList.replaceChildren();
        const unreadCount = notifications.filter((item) => item.unread).length;
        notificationCount.textContent = unreadCount ? `${unreadCount} thông báo mới` : "Đã cập nhật";
        notificationIndicator.hidden = unreadCount === 0;
        notificationToggle.setAttribute("aria-label", unreadCount ? `${unreadCount} thông báo chưa đọc` : "Không có thông báo mới");
        notifications.forEach((notification) => {
            const item = document.createElement("button");
            item.className = "notification-item";
            item.type = "button";
            item.dataset.filename = notification.filename;
            item.classList.toggle("unread", notification.unread);
            const status = document.createElement("span");
            status.className = "notification-status";
            status.setAttribute("aria-hidden", "true");
            const copy = document.createElement("span");
            copy.className = "notification-copy";
            const title = document.createElement("strong");
            title.textContent = notification.title;
            const detail = document.createElement("small");
            detail.textContent = notification.detail;
            copy.append(title, detail);
            item.append(status, copy);
            notificationList.append(item);
        });
    };

    const closeHeaderPanels = () => {
        searchPanel.hidden = true;
        notificationPanel.hidden = true;
        searchToggle.setAttribute("aria-expanded", "false");
        notificationToggle.setAttribute("aria-expanded", "false");
    };

    const renderSearchResults = () => {
        const query = searchInput.value.trim().toLocaleLowerCase("vi");
        const matches = files.filter((file) => `${file.name} ${file.folder_name}`.toLocaleLowerCase("vi").includes(query));
        searchResults.replaceChildren();
        searchCaption.textContent = query ? `${matches.length} kết quả` : "Tệp của bạn";
        if (!matches.length) {
            const empty = document.createElement("p");
            empty.className = "popover-empty";
            empty.textContent = "Không tìm thấy tệp phù hợp.";
            searchResults.append(empty);
            return;
        }
        matches.slice(0, 8).forEach((file) => {
            const [iconType, iconLetter] = getIconType(file.name);
            const result = document.createElement("button");
            result.className = "global-search-result";
            result.type = "button";
            result.setAttribute("role", "option");
            result.dataset.fileId = file.id;
            result.innerHTML = `<span class="file-icon ${iconType}"><span></span></span><span class="global-search-result-copy"><strong></strong><small></small></span>`;
            $(".file-icon span", result).textContent = iconLetter;
            $(".global-search-result-copy strong", result).textContent = file.name;
            $(".global-search-result-copy small", result).textContent = `${file.folder_name} · ${formatSize(file.size)}`;
            searchResults.append(result);
        });
    };

    const showAuth = (mode = "login") => {
        authMode = mode;
        const isRegister = mode === "register";
        $("#auth-title").textContent = isRegister ? "Tạo tài khoản" : "Đăng nhập";
        $("#auth-description").textContent = isRegister
            ? "Tạo không gian riêng để lưu trữ và chia sẻ tệp."
            : "Đăng nhập để quản lý tệp và liên kết chia sẻ của bạn.";
        $("#auth-name-label").hidden = !isRegister;
        $("#auth-name").hidden = !isRegister;
        $("#auth-name").required = isRegister;
        $("#auth-password").autocomplete = isRegister ? "new-password" : "current-password";
        $("#auth-password").minLength = isRegister ? 10 : 1;
        $("#auth-submit").textContent = isRegister ? "Tạo tài khoản" : "Đăng nhập";
        $("#auth-switch").textContent = isRegister ? "Đã có tài khoản? Đăng nhập" : "Chưa có tài khoản? Đăng ký";
        authError.textContent = "";
        authOverlay.hidden = false;
        $("#auth-email").focus();
    };

    const updateProfile = (user) => {
        $("#profile-name").textContent = user.name;
        $("#profile-email").textContent = user.email;
        $(".avatar", $("#profile-card")).textContent = [...user.name][0].toLocaleUpperCase("vi");
        $("#personal-avatar").textContent = [...user.name][0].toLocaleUpperCase("vi");
        $("#personal-name").textContent = user.name;
        $("#personal-email").textContent = user.email;
        $("#welcome-title").firstChild.textContent = `Xin chào, ${user.name} `;
    };

    const loadPermissionHistory = async (fileId) => {
        const history = $("#permission-history");
        history.replaceChildren();
        const loading = document.createElement("li");
        loading.textContent = "Đang tải lịch sử cấp quyền…";
        history.append(loading);
        try {
            const response = await requestPath(`api/permissions.php?file_id=${encodeURIComponent(fileId)}`);
            const data = await response.json();
            if (activeFileId !== fileId) return;
            history.replaceChildren();
            if (data.permissions.length === 0) {
                const empty = document.createElement("li");
                empty.textContent = "Chưa có người nào được cấp quyền tải tệp này.";
                history.append(empty);
                return;
            }
            data.permissions.forEach((permission) => {
                const item = document.createElement("li");
                const recipient = document.createElement("strong");
                recipient.textContent = permission.full_name;
                const details = document.createElement("small");
                details.textContent = `${permission.email} · Cấp lúc ${formatDate(permission.granted_at)}${permission.granted_by ? ` · Bởi ${permission.granted_by}` : ""}`;
                item.append(recipient, details);
                history.append(item);
            });
        } catch (error) {
            if (activeFileId !== fileId) return;
            history.replaceChildren();
            const failure = document.createElement("li");
            failure.textContent = error.message;
            history.append(failure);
        }
    };

    const openFileDetails = (fileId) => {
        const file = files.find((entry) => entry.id === fileId);
        if (!file) return;
        activeFileId = file.id;
        const [iconType, iconLetter] = getIconType(file.name);
        const share = activeLink(file.id);
        const selectedLink = share || fileLinks(file.id).find((item) => item.status === "active" || item.status === "private") || null;
        activeLinkId = selectedLink?.id || null;
        $("#detail-file-name").textContent = file.name;
        $("#detail-file-meta").textContent = `Tải lên ngày ${formatDate(file.created_at)} · ${formatSize(file.size)} · ${file.folder_name}`;
        $("#detail-file-icon").className = `file-icon ${iconType}`;
        $("#detail-file-icon").innerHTML = `<span>${iconLetter}</span>`;
        const badge = $("#detail-access");
        badge.className = `access-badge ${selectedLink?.access === "public" ? "public" : "private"}`;
        badge.innerHTML = `<i></i>${selectedLink ? (selectedLink.access === "public" ? "Công khai" : "Riêng tư") : "Chưa chia sẻ"}`;
        $("#share-url").textContent = selectedLink?.url || "Chưa có liên kết còn hiệu lực";
        $("#permission-grant-submit").disabled = false;
        $("#share-description").textContent = selectedLink
            ? `${selectedLink.access === "private" ? "Chỉ tài khoản được cấp quyền mới tải được. " : ""}${selectedLink.downloads} lượt tải · Hết hạn ${formatDate(selectedLink.expires_at)}${selectedLink.password_protected ? " · Có mật khẩu" : ""}`
            : "Tạo liên kết mới để bắt đầu chia sẻ tệp.";
        $(".link-setting-row:nth-of-type(1) small").textContent = selectedLink ? linkStatusLabel(selectedLink.status) : "Chưa tạo liên kết";
        $(".link-setting-row:nth-of-type(2) small").textContent = selectedLink
            ? `${selectedLink.downloads} / ${selectedLink.max_downloads ?? "∞"} lượt tải`
            : "Chưa giới hạn";
        $(".link-setting-row:nth-of-type(3) small").textContent = selectedLink ? formatDate(selectedLink.expires_at) : "—";
        $("#revoke-link").disabled = !selectedLink || !["active", "private"].includes(selectedLink.status);
        loadPermissionHistory(file.id);
        activateView("shared");
    };

    const renderAllFiles = () => {
        allRows.replaceChildren(...files.map(makeFileRow));
        applyFileFilters();
    };

    navLinks.forEach((link) => link.addEventListener("click", () => activateView(link.dataset.view)));
    profileMenuToggle.addEventListener("click", () => {
        const isOpen = profileMenu.hidden;
        profileMenu.hidden = !isOpen;
        profileMenuToggle.setAttribute("aria-expanded", String(isOpen));
        if (isOpen) $('[role="menuitem"]', profileMenu).focus();
    });
    profileMenu.addEventListener("click", (event) => {
        const action = event.target.closest("[data-profile-view]");
        if (action) activateView(action.dataset.profileView);
    });
    $$("[data-profile-view]").forEach((action) => {
        if (profileMenu.contains(action)) return;
        action.addEventListener("click", () => activateView(action.dataset.profileView));
    });
    $(".storage-upgrade").addEventListener("click", () => activateView("upgrade"));
    $("#back-from-upgrade").addEventListener("click", () => activateView("dashboard"));
    $("#storage-plans").addEventListener("click", async (event) => {
        const button = event.target.closest(".plan-action");
        if (!button || button.disabled) return;
        const planGb = Number(button.dataset.planGb);
        $$("#storage-plans .plan-action").forEach((planButton) => {
            planButton.disabled = true;
        });
        try {
            const response = await requestPath("api/upgrade.php", {
                method: "POST",
                body: { storage_gb: planGb },
            });
            const result = await response.json();
            await loadData();
            showToast(result.message);
        } catch (error) {
            updatePlanOptions();
            showToast(error.message);
        }
    });
    $$("[data-open-upload]").forEach((button) => button.addEventListener("click", () => activateView("upload")));
    $$("[data-open-files]").forEach((button) => button.addEventListener("click", () => activateView("files")));
    $$("[data-open-shared]").forEach((button) => button.addEventListener("click", () => activateView("shared")));

    searchToggle.addEventListener("click", () => {
        const shouldOpen = searchPanel.hidden;
        closeHeaderPanels();
        if (shouldOpen) {
            searchPanel.hidden = false;
            searchToggle.setAttribute("aria-expanded", "true");
            renderSearchResults();
            searchInput.focus();
        }
    });
    searchInput.addEventListener("input", renderSearchResults);
    searchResults.addEventListener("click", (event) => {
        const result = event.target.closest(".global-search-result");
        if (!result) return;
        closeHeaderPanels();
        openFileDetails(result.dataset.fileId);
    });
    notificationToggle.addEventListener("click", () => {
        const shouldOpen = notificationPanel.hidden;
        closeHeaderPanels();
        if (shouldOpen) {
            notificationPanel.hidden = false;
            notificationToggle.setAttribute("aria-expanded", "true");
        }
    });
    $("#mark-notifications-read").addEventListener("click", () => {
        notifications.forEach((item) => { item.unread = false; });
        renderNotifications();
    });
    notificationList.addEventListener("click", (event) => {
        const item = event.target.closest(".notification-item");
        if (!item) return;
        const notification = notifications.find((entry) => entry.filename === item.dataset.filename);
        if (notification) notification.unread = false;
        renderNotifications();
        closeHeaderPanels();
        const file = files.find((entry) => entry.name === item.dataset.filename);
        if (file) openFileDetails(file.id);
        else activateView("files");
    });
    document.addEventListener("pointerdown", (event) => {
        if (!event.target.closest(".topbar-actions")) closeHeaderPanels();
    });
    document.addEventListener("keydown", (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
            event.preventDefault();
            closeHeaderPanels();
            searchPanel.hidden = false;
            searchToggle.setAttribute("aria-expanded", "true");
            renderSearchResults();
            searchInput.focus();
        } else if (event.key === "Escape") {
            closeHeaderPanels();
        }
    });

    const handleFileRowClick = (event) => {
        const row = event.target.closest(".file-row");
        if (!row) return;
        if (event.target.closest(".row-menu")) {
            event.stopPropagation();
            if (!window.confirm(`Xóa tệp "${row.dataset.filename}" và thu hồi các liên kết?`)) return;
            request("file-delete", { method: "POST", body: { file_id: row.dataset.fileId } })
                .then(loadData)
                .then(() => showToast("Đã xóa tệp và thu hồi các liên kết."))
                .catch((error) => showToast(error.message));
            return;
        }
        openFileDetails(row.dataset.fileId);
    };
    recentRows.addEventListener("click", handleFileRowClick);
    allRows.addEventListener("click", handleFileRowClick);

    $("#file-search").addEventListener("input", applyFileFilters);
    $("#share-filter").addEventListener("change", applyFileFilters);
    $("#folder-filter").addEventListener("change", applyFileFilters);
    $("#create-folder").addEventListener("click", async () => {
        const name = window.prompt("Tên thư mục mới:");
        if (name === null) return;
        try {
            const response = await requestPath("api/folders.php", { method: "POST", body: { name } });
            const data = await response.json();
            folders.push(data.folder);
            renderFolders();
            $("#folder-select").value = data.folder.id;
            showToast("Đã tạo thư mục.");
        } catch (error) {
            showToast(error.message);
        }
    });

    const exportReport = async () => {
        try {
            const response = await requestPath("api/audit.php?format=csv");
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = "bao-cao-truy-cap.csv";
            anchor.click();
            URL.revokeObjectURL(url);
            showToast("Đã xuất báo cáo truy cập.");
        } catch (error) {
            showToast(error.message);
        }
    };
    $("#export-report").addEventListener("click", exportReport);
    $("#export-audit").addEventListener("click", exportReport);
    $("#export-dashboard-report").addEventListener("click", exportReport);
    $("#cleanup-preview").addEventListener("click", async () => {
        try {
            const response = await requestPath("api/cleanup-preview.php");
            const data = await response.json();
            const preview = data.files.map((file) => `${file.name} (${formatSize(file.size)})`).join("\n");
            window.alert(data.files.length
                ? `Xem trước: ${data.files.length} tệp không còn liên kết hiệu lực sẽ được dọn bằng lệnh máy chủ:\n\n${preview}\n\nChưa có tệp nào bị xóa.`
                : "Không có tệp nào đủ điều kiện dọn dẹp. Chưa có tệp nào bị xóa.");
        } catch (error) {
            showToast(error.message);
        }
    });

    const setSelectedFile = (file) => {
        const extension = file.name.includes(".") ? file.name.split(".").pop().toLowerCase() : "";
        const allowedExtensions = fileInput.accept.split(",").map((item) => item.trim().replace(/^\./, "").toLowerCase());
        if (!allowedExtensions.includes(extension) || file.size < 1 || file.size > 100 * 1024 * 1024) {
            selectedUpload = null;
            selectedFile.hidden = true;
            showToast("Loại tệp không được hỗ trợ hoặc kích thước không nằm trong giới hạn 1 byte–100 MB.");
            return false;
        }
        if (storageQuotaBytes > 0 && storageUsedBytes + file.size > storageQuotaBytes) {
            selectedUpload = null;
            selectedFile.hidden = true;
            showToast("Tệp vượt quá dung lượng còn trống trong tài khoản.");
            return false;
        }
        selectedUpload = file;
        fileNameInput.value = file.name;
        const [iconType, iconLetter] = getIconType(file.name);
        selectedFile.innerHTML = `<span class="file-icon ${iconType}"><span></span></span><span><strong></strong><small></small></span>`;
        $(".file-icon span", selectedFile).textContent = iconLetter;
        $("strong", selectedFile).textContent = file.name;
        $("small", selectedFile).textContent = formatSize(file.size);
        selectedFile.hidden = false;
        return true;
    };
    fileInput.addEventListener("change", () => {
        const [file] = fileInput.files;
        if (!file) return;
        if (!setSelectedFile(file)) fileInput.value = "";
    });
    const dropzone = $("#dropzone");
    ["dragenter", "dragover"].forEach((name) => dropzone.addEventListener(name, (event) => {
        event.preventDefault();
        dropzone.classList.add("dragover");
    }));
    ["dragleave", "drop"].forEach((name) => dropzone.addEventListener(name, (event) => {
        event.preventDefault();
        dropzone.classList.remove("dragover");
    }));
    dropzone.addEventListener("drop", (event) => {
        event.preventDefault();
        const [file] = event.dataTransfer.files;
        if (file) setSelectedFile(file);
    });
    const applyUploadAccess = (access) => {
        $$('input[name="access"]').forEach((radio) => {
            radio.checked = radio.value === access;
            $$(".radio-card").forEach((card) => card.classList.toggle("selected", card.contains(radio) && radio.checked));
        });
    };
    applyUploadAccess(defaultUploadAccess);
    $$('input[name="access"]').forEach((radio) => radio.addEventListener("change", () => {
        $$(".radio-card").forEach((card) => card.classList.toggle("selected", card.contains(radio) && radio.checked));
    }));
    $("#setting-default-access").addEventListener("change", (event) => {
        defaultUploadAccess = event.currentTarget.value;
        savePreference("fileshare-default-access", defaultUploadAccess);
        applyUploadAccess(defaultUploadAccess);
    });
    $("#upload-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const uploadForm = event.currentTarget;
        if (!selectedUpload) {
            showToast("Vui lòng chọn tệp trước khi tải lên.");
            fileInput.focus();
            return;
        }
        const form = new FormData();
        form.append("file", selectedUpload);
        form.append("fileName", fileNameInput.value.trim());
        form.append("folder_id", $("#folder-select").value);
        form.append("access", $('input[name="access"]:checked').value);
        form.append("limit", $("#download-limit").value);
        form.append("expiry_days", $("#expiry-days").value);
        form.append("password", $("#link-password").value);
        try {
            const response = await requestPath("api/upload.php", { method: "POST", body: form, formData: true });
            const data = await response.json();
            await loadData();
            selectedUpload = null;
            uploadForm.reset();
            applyUploadAccess(defaultUploadAccess);
            selectedFile.hidden = true;
            showToast(`Đã tải "${data.file.file_name}" và tạo liên kết chia sẻ.`);
            activateView("files");
        } catch (error) {
            showToast(error.message);
        }
    });

    $("#copy-link").addEventListener("click", async () => {
        const link = $("#share-url").textContent;
        if (!link.startsWith("http")) {
            showToast("Chưa có liên kết để sao chép.");
            return;
        }
        try {
            await navigator.clipboard.writeText(link);
            showToast("Đã sao chép liên kết chia sẻ.");
        } catch {
            showToast("Không thể sao chép tự động. Hãy chọn và sao chép liên kết.");
        }
    });

    $("#revoke-link").addEventListener("click", async () => {
        if (!activeLinkId) return;
        try {
            await requestPath("api/revoke.php", { method: "POST", body: { file_id: activeLinkId } });
            await loadData();
            openFileDetails(activeFileId);
            showToast("Đã thu hồi liên kết chia sẻ.");
        } catch (error) {
            showToast(error.message);
        }
    });
    $("#permission-grant-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!activeFileId) {
            showToast("Chọn một tệp trước khi cấp quyền.");
            return;
        }
        const fileId = activeFileId;
        const submitButton = $("#permission-grant-submit");
        submitButton.disabled = true;
        try {
            await requestPath("api/permissions.php", {
                method: "POST",
                body: { file_id: fileId, email: $("#permission-email").value.trim() },
            });
            $("#permission-email").value = "";
            await loadPermissionHistory(fileId);
            showToast("Đã cấp quyền tải tệp.");
        } catch (error) {
            showToast(error.message);
        } finally {
            submitButton.disabled = false;
        }
    });
    $("#create-link").addEventListener("click", async () => {
        if (!activeFileId) {
            showToast("Chọn một tệp trước khi tạo liên kết.");
            activateView("files");
            return;
        }
        const expiryDays = window.prompt("Liên kết có hiệu lực trong bao nhiêu ngày? (1–3650)", "7");
        if (expiryDays === null) return;
        const maxDownloads = window.prompt("Giới hạn lượt tải (để trống nếu không giới hạn):", "");
        if (maxDownloads === null) return;
        const password = window.prompt("Mật khẩu liên kết (để trống nếu không cần):", "");
        if (password === null) return;
        try {
            await requestPath("api/link.php", {
                method: "POST",
                body: {
                    file_id: activeFileId,
                    expiry_days: expiryDays,
                    max_downloads: maxDownloads,
                    password,
                },
            });
            await loadData();
            openFileDetails(activeFileId);
            showToast("Đã tạo liên kết chia sẻ.");
        } catch (error) {
            showToast(error.message);
        }
    });
    $("#link-rows").addEventListener("click", async (event) => {
        const row = event.target.closest("tr[data-link-id]");
        if (!row) return;
        const link = links.find((entry) => entry.id === row.dataset.linkId);
        if (!link) return;
        if (event.target.closest(".copy-link-row")) {
            try {
                await navigator.clipboard.writeText(link.url);
                showToast("Đã sao chép liên kết chia sẻ.");
            } catch {
                showToast("Không thể sao chép tự động. Hãy mở liên kết rồi sao chép địa chỉ.");
            }
            return;
        }
        if (event.target.closest(".revoke-link-row")) {
            try {
                await requestPath("api/revoke.php", { method: "POST", body: { file_id: link.file_id } });
                await loadData();
                showToast("Đã thu hồi liên kết.");
            } catch (error) {
                showToast(error.message);
            }
            return;
        }
        openFileDetails(link.file_id);
    });

    $("#auth-switch").addEventListener("click", () => showAuth(authMode === "login" ? "register" : "login"));
    authForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        authError.textContent = "";
        const body = {
            email: $("#auth-email").value.trim(),
            password: $("#auth-password").value,
        };
        if (authMode === "register") body.name = $("#auth-name").value.trim();
        try {
            const response = await request(authMode, { method: "POST", body });
            const data = await response.json();
            csrfToken = data.csrf;
            updateProfile(data.user);
            authOverlay.hidden = true;
            await loadData();
        } catch (error) {
            authError.textContent = error.message;
            if (authOverlay.hidden) showToast(error.message);
        }
    });
    $("#logout-button").addEventListener("click", async () => {
        try {
            const response = await request("logout", { method: "POST", body: {} });
            const data = await response.json();
            csrfToken = data.csrf;
            files = [];
            folders = [];
            links = [];
            allRows.replaceChildren();
            recentRows.replaceChildren();
            profileMenu.hidden = true;
            profileMenuToggle.setAttribute("aria-expanded", "false");
            showAuth("login");
        } catch (error) {
            showToast(error.message);
        }
    });

    document.addEventListener("click", (event) => {
        if (!event.target.closest(".profile-area")) {
            profileMenu.hidden = true;
            profileMenuToggle.setAttribute("aria-expanded", "false");
        }
        const helpLink = event.target.closest("#help-link");
        if (helpLink) {
            event.preventDefault();
            showToast("Trung tâm trợ giúp sẽ sớm có mặt.");
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !profileMenu.hidden) {
            profileMenu.hidden = true;
            profileMenuToggle.setAttribute("aria-expanded", "false");
            profileMenuToggle.focus();
        }
    });

    const start = async () => {
        try {
            const response = await request("session");
            const data = await response.json();
            csrfToken = data.csrf;
            if (!data.user) {
                showAuth("login");
                return;
            }
            updateProfile(data.user);
            authOverlay.hidden = true;
            await loadData();
        } catch (error) {
            const message = `Không thể tải dữ liệu: ${error.message}`;
            authError.textContent = message;
            showToast(message);
        }
    };
    start();
})();
