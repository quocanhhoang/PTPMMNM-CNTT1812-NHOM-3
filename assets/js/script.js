(() => {
    const panels = [...document.querySelectorAll(".view-panel")];
    const navLinks = [...document.querySelectorAll(".nav-link[data-view]")];
    const breadcrumb = document.getElementById("breadcrumb-current");
    const allFileRows = document.getElementById("all-file-rows");
    const recentFileRows = document.getElementById("recent-file-rows");
    const toast = document.getElementById("toast");
    const notificationButton = document.querySelector(".notification-button");
    const notificationDropdown = document.getElementById("notification-dropdown");
    const notificationList = document.getElementById("notification-list");
    const searchToggle = document.getElementById("global-search-toggle");
    const searchPanel = document.getElementById("global-search-panel");
    const searchInput = document.getElementById("global-search-input");
    const searchResults = document.getElementById("global-search-results");
    const fileInput = document.getElementById("file-input");
    const fileNameInput = document.getElementById("file-name");
    const selectedFile = document.getElementById("selected-file");
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const maxUploadBytes = Number(document.querySelector('meta[name="max-upload-bytes"]').content);
    let toastTimer;
    let selectedUpload = null;
    let selectedFileId = null;
    let files = [];
    let storageAccount = null;
    const preferenceKey = "fileshare-user-preferences";
    let userPreferences = { theme: "light", defaultAccess: "public" };

    const showToast = (message) => {
        toast.textContent = message;
        toast.classList.add("visible");
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove("visible"), 3200);
    };

    try {
        const savedPreferences = JSON.parse(localStorage.getItem(preferenceKey) || "{}");
        userPreferences = {
            theme: savedPreferences.theme === "dark" ? "dark" : "light",
            defaultAccess: savedPreferences.defaultAccess === "private" ? "private" : "public",
        };
    } catch {
        showToast("Không thể đọc cài đặt đã lưu trên trình duyệt.");
    }

    const persistPreferences = () => {
        try {
            localStorage.setItem(preferenceKey, JSON.stringify(userPreferences));
            return true;
        } catch {
            showToast("Không thể lưu cài đặt trên trình duyệt này.");
            return false;
        }
    };

    const formatOnlineTime = (seconds) => {
        const totalMinutes = Math.floor(seconds / 60);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        return hours > 0 ? `${hours} giờ ${minutes} phút` : `${totalMinutes} phút`;
    };

    const renderRewardTasks = (tasks) => {
        const grid = document.getElementById("reward-task-grid");
        grid.replaceChildren();
        tasks.forEach((task) => {
            const card = document.createElement("article");
            card.className = `reward-task-card${task.claimed ? " reward-task-complete" : ""}`;
            card.id = `reward-task-${task.id}`;
            const heading = document.createElement("div");
            heading.className = "reward-task-heading";
            const title = document.createElement("strong");
            title.textContent = task.title;
            const period = document.createElement("small");
            period.textContent = task.period;
            heading.append(title, period);
            const progressText = document.createElement("div");
            progressText.className = "reward-task-progress";
            const progressLabel = document.createElement("span");
            progressLabel.className = "reward-task-progress-value";
            const isOnline = task.id.startsWith("online_");
            progressLabel.textContent = isOnline
                ? `${formatOnlineTime(task.progress)} / ${task.id.slice(7)} phút`
                : `${task.progress} / ${task.goal} lần`;
            const reward = document.createElement("strong");
            reward.textContent = task.claimed ? "Đã nhận" : `+${task.coins} xu`;
            progressText.append(progressLabel, reward);
            const progress = document.createElement("div");
            progress.className = "reward-progress-track";
            const fill = document.createElement("span");
            fill.style.width = `${Math.min(100, Math.round(task.progress * 100 / task.goal))}%`;
            progress.append(fill);
            card.append(heading, progressText, progress);
            grid.append(card);
        });
    };

    const refreshRewards = async () => {
        const rewards = await apiRequest("api/rewards.php");
        document.getElementById("header-coin-balance").textContent = rewards.coins.toLocaleString("vi-VN");
        document.getElementById("rewards-coin-balance").textContent = rewards.coins.toLocaleString("vi-VN");
        document.getElementById("online-time-today").textContent = formatOnlineTime(rewards.online_seconds_today);
        document.getElementById("reward-storage-status").textContent = rewards.active_boost_bytes > 0
            ? `Đang có thêm ${formatStorage(rewards.active_boost_bytes)} dung lượng thưởng; gói gần hết hạn vào ${new Intl.DateTimeFormat("vi-VN", { dateStyle: "medium" }).format(new Date(rewards.next_boost_expiry))}.`
            : "Chưa có gói dung lượng thưởng nào đang hoạt động.";
        renderRewardTasks(rewards.tasks);
        document.querySelectorAll(".reward-redeem-button").forEach((button) => {
            const price = Number(button.dataset.coinPrice);
            button.disabled = rewards.coins < price;
        });
        return rewards;
    };

    const updateOnlineTaskProgress = (seconds) => {
        document.getElementById("online-time-today").textContent = formatOnlineTime(seconds);
        document.querySelectorAll(".reward-task-card[id^='reward-task-online_']").forEach((card) => {
            const minutes = Number(card.id.split("_").pop());
            const goal = minutes * 60;
            const value = Math.min(goal, seconds);
            const progressLabel = card.querySelector(".reward-task-progress-value");
            if (progressLabel) progressLabel.textContent = `${formatOnlineTime(value)} / ${minutes} phút`;
            const fill = card.querySelector(".reward-progress-track span");
            if (fill) fill.style.width = `${Math.min(100, Math.round(value * 100 / goal))}%`;
        });
    };

    let onlinePingErrorShown = false;
    const pingOnline = async () => {
        try {
            const previousSeconds = Number(document.getElementById("online-time-today").dataset.seconds || 0);
            const { online_seconds_today: seconds } = await apiRequest("api/online_ping.php", { method: "POST", body: {} });
            onlinePingErrorShown = false;
            document.getElementById("online-time-today").dataset.seconds = String(seconds);
            updateOnlineTaskProgress(seconds);
            if (seconds < previousSeconds || Math.floor(seconds / 900) > Math.floor(previousSeconds / 900)) {
                await refreshRewards();
            }
        } catch (error) {
            if (!onlinePingErrorShown) showToast(error.message);
            onlinePingErrorShown = true;
        }
    };

    const rewardStore = [
        ["week_1", 500], ["week_2", 950], ["month_1", 2500], ["month_3", 4850],
        ["month_6", 7620], ["month_9", 10300], ["year_1", 13870],
    ];
    rewardStore.forEach(([planId, coins]) => {
        const button = document.querySelector(`[data-plan-id="${planId}"]`);
        button.dataset.coinPrice = String(coins);
        button.addEventListener("click", async () => {
            if (!window.confirm(`Đổi gói này với ${coins.toLocaleString("vi-VN")} xu?`)) return;
            button.disabled = true;
            try {
                const { message } = await apiRequest("api/redeem_storage.php", {
                    method: "POST",
                    body: { plan_id: planId },
                });
                await Promise.all([refreshRewards(), loadAccount()]);
                showToast(message);
            } catch (error) {
                showToast(error.message);
                button.disabled = false;
            }
        });
    });

    const applyTheme = () => {
        document.body.dataset.theme = userPreferences.theme;
        document.getElementById("setting-theme").value = userPreferences.theme;
    };

    const applyDefaultAccess = () => {
        document.querySelectorAll('input[name="access"]').forEach((radio) => {
            radio.checked = radio.value === userPreferences.defaultAccess;
            radio.closest(".radio-card").classList.toggle("selected", radio.checked);
        });
        document.getElementById("setting-default-access").value = userPreferences.defaultAccess;
    };

    applyTheme();
    applyDefaultAccess();

    const apiRequest = async (url, { method = "GET", body } = {}) => {
        const headers = { "X-CSRF-Token": csrfToken };
        let requestBody = body;
        if (body && !(body instanceof FormData)) {
            headers["Content-Type"] = "application/json";
            requestBody = JSON.stringify(body);
        }

        const response = await fetch(url, { method, headers, body: requestBody });
        const result = await response.json();
        if (!response.ok) {
            throw new Error(result.error || "Yêu cầu không thành công.");
        }
        return result;
    };

    const formatSize = (bytes) => {
        if (!Number.isFinite(bytes) || bytes < 0) return "Kích thước không xác định";
        if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const formatStorage = (bytes) => {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
    };

    const renderQuota = (account) => {
        storageAccount = account;
        const used = account.storage_used_bytes;
        const quota = account.storage_quota_bytes;
        const percent = quota > 0 ? Math.min(100, Math.round(used * 100 / quota)) : 0;
        document.getElementById("storage-used").textContent = formatStorage(used);
        document.getElementById("storage-quota").textContent = formatStorage(quota);
        document.getElementById("storage-percent").textContent = `${percent}%`;
        document.getElementById("storage-bar-fill").style.width = `${percent}%`;
        document.getElementById("stat-file-count").textContent = String(account.file_count);
        document.getElementById("stat-downloads-today").textContent = String(account.downloads_today);
        document.getElementById("stat-storage-used").textContent = formatStorage(used);
        document.getElementById("personal-storage-used").textContent = formatStorage(used);
        document.getElementById("personal-storage-quota").textContent = formatStorage(quota);
        document.getElementById("personal-file-count").textContent = String(account.file_count);
        document.getElementById("current-plan-label").textContent = formatStorage(quota);
        updatePlanOptions();
    };

    const loadAccount = async () => {
        try {
            const { account } = await apiRequest("api/account.php");
            document.getElementById("personal-name").textContent = account.full_name;
            document.getElementById("personal-email").textContent = account.email;
            document.getElementById("personal-avatar").textContent = [...account.full_name][0] || "U";
            renderQuota(account);
        } catch (error) {
            showToast(error.message);
        }
    };

    const updatePlanOptions = () => {
        if (!storageAccount) {
            document.querySelectorAll(".plan-action").forEach((button) => { button.disabled = false; });
            return;
        }
        const quota = storageAccount.storage_quota_bytes;
        const currentGb = quota / (1024 ** 3);
        document.querySelectorAll(".plan-card").forEach((card) => {
            const planGb = Number(card.dataset.planGb);
            const button = card.querySelector(".plan-action");
            const status = card.querySelector(".plan-status");
            const isCurrent = planGb === currentGb;
            const isLower = planGb < currentGb;
            button.disabled = isCurrent || isLower;
            status.hidden = !isCurrent && !isLower;
            status.textContent = isCurrent ? "Gói hiện tại" : "Thấp hơn hạn mức hiện tại";
            card.classList.toggle("plan-card-current", isCurrent);
        });
    };

    const folderNameForFile = (file) => {
        const folders = window.fileShareFolders || [];
        return folders.find((folder) => folder.id === file.folder_id)?.name || "";
    };

    const renderSearchResults = () => {
        const query = searchInput.value.trim().toLocaleLowerCase("vi");
        searchResults.replaceChildren();
        const matches = files.filter((file) => (
            `${file.file_name} ${folderNameForFile(file)}`.toLocaleLowerCase("vi").includes(query)
        )).slice(0, 20);
        document.getElementById("search-result-caption").textContent = query
            ? `${matches.length} kết quả`
            : "Tệp gần đây";
        if (matches.length === 0) {
            const empty = document.createElement("p");
            empty.className = "popover-empty";
            empty.textContent = query ? "Không tìm thấy tệp phù hợp." : "Bạn chưa có tệp nào.";
            searchResults.append(empty);
            return;
        }
        matches.forEach((file) => {
            const result = document.createElement("button");
            result.className = "global-search-result";
            result.type = "button";
            result.dataset.fileId = String(file.id);
            const name = document.createElement("strong");
            name.textContent = file.file_name;
            const folder = document.createElement("small");
            folder.textContent = folderNameForFile(file) || "Không có thư mục";
            result.append(name, folder);
            searchResults.append(result);
        });
    };

    const getIconType = (name) => {
        const extension = name.includes(".") ? name.split(".").pop().toLowerCase() : "";
        if (["doc", "docx"].includes(extension)) return ["word", "W"];
        if (extension === "pdf") return ["pdf", "P"];
        if (["xls", "xlsx", "csv"].includes(extension)) return ["excel", "X"];
        return ["word", "F"];
    };

    const activateView = (viewName) => {
        const target = document.getElementById(`${viewName}-view`);
        if (!target) return;
        panels.forEach((panel) => panel.classList.toggle("active", panel === target));
        navLinks.forEach((link) => link.classList.toggle("active", link.dataset.view === viewName));
        breadcrumb.textContent = {
            dashboard: "Chung",
            files: "Tệp của tôi",
            upload: "Tải tệp lên",
            shared: "Chi tiết tệp",
            folders: "Thư mục",
            rewards: "Nhiệm vụ & xu",
            personal: "Thông tin cá nhân",
            settings: "Cài đặt người dùng",
            upgrade: "Nâng cấp dung lượng",
        }[viewName] || "Chung";
        window.scrollTo({ top: 0, behavior: "smooth" });
    };

    const makeCell = (text) => {
        const cell = document.createElement("td");
        cell.textContent = text;
        return cell;
    };

    const makeFileRow = (file) => {
        const row = document.createElement("tr");
        row.className = "file-row";
        row.draggable = true;
        row.dataset.fileId = String(file.id);
        row.dataset.filename = file.file_name;
        row.dataset.folderId = file.folder_id === null || file.folder_id === undefined ? "root" : String(file.folder_id);
        row.dataset.shareStatus = file.access_type;
        row.dataset.missing = file.is_missing ? "true" : "false";
        row.dataset.revoked = file.is_link_active ? "false" : "true";
        row.dataset.expired = file.expires_at && new Date(file.expires_at) < new Date() ? "true" : "false";
        row.tabIndex = 0;

        const [iconType, iconLetter] = getIconType(file.file_name);
        const nameCell = document.createElement("td");
        const fileName = document.createElement("span");
        fileName.className = "file-name";
        const icon = document.createElement("span");
        icon.className = `file-icon ${iconType}`;
        const letter = document.createElement("span");
        letter.textContent = iconLetter;
        icon.append(letter);
        const copy = document.createElement("span");
        const name = document.createElement("strong");
        name.textContent = file.file_name;
        const size = document.createElement("small");
        size.textContent = formatSize(file.file_size);
        copy.append(name, size);
        fileName.append(icon, copy);
        nameCell.append(fileName);

        const access = document.createElement("span");
        access.className = `access-badge ${file.access_type === "public" ? "public" : "private"}`;
        const dot = document.createElement("i");
        access.append(dot, document.createTextNode(file.is_missing ? "Thiếu tệp" : file.access_type === "public" ? "Công khai" : "Riêng tư"));
        if (file.is_missing) access.classList.add("missing");

        const menuCell = document.createElement("td");
        const menuWrap = document.createElement("div");
        menuWrap.className = "file-action-menu-wrap";
        const menu = document.createElement("button");
        menu.className = "row-menu";
        menu.type = "button";
        menu.setAttribute("aria-label", `Tùy chọn ${file.file_name}`);
        menu.setAttribute("aria-expanded", "false");
        menu.textContent = "···";
        const actions = document.createElement("div");
        actions.className = "file-action-menu";
        actions.hidden = true;
        const share = document.createElement("button");
        share.type = "button";
        share.dataset.fileAction = "share";
        share.textContent = "Chia sẻ";
        const remove = document.createElement("button");
        remove.type = "button";
        remove.dataset.fileAction = "delete";
        remove.className = "file-action-delete";
        remove.textContent = "Xóa";
        actions.append(share, remove);
        menuWrap.append(menu, actions);
        menuCell.append(menuWrap);

        const accessCell = document.createElement("td");
        accessCell.append(access);
        row.append(
            nameCell,
            makeCell(new Intl.DateTimeFormat("vi-VN").format(new Date(file.created_at))),
            accessCell,
            makeCell(`${file.download_count} lượt`),
            menuCell
        );
        return row;
    };

    const renderSharedFiles = (sharedFiles) => {
        const rows = document.getElementById("shared-file-rows");
        const empty = document.getElementById("shared-files-empty");
        rows.replaceChildren();
        empty.hidden = sharedFiles.length > 0;
        if (sharedFiles.length === 0) return;

        sharedFiles.forEach((file) => {
            const row = document.createElement("tr");
            const name = makeCell(file.file_name);
            name.className = "shared-file-name";
            const sharedWith = [
                file.recipient_names,
                file.is_link_active ? "Liên kết công khai" : "",
            ].filter(Boolean).join(" · ") || "Không có người nhận";
            const sharedAt = makeCell(new Intl.DateTimeFormat("vi-VN").format(new Date(file.shared_at)));
            const downloads = makeCell(`${file.download_count} lượt`);
            const action = document.createElement("td");
            if (file.is_missing) {
                action.textContent = "Tệp không khả dụng";
            } else if (file.is_link_active) {
                const copy = document.createElement("button");
                copy.className = "secondary-button shared-download";
                copy.type = "button";
                copy.dataset.copyShareLink = file.share_code;
                copy.textContent = "Sao chép liên kết";
                action.append(copy);
            } else {
                action.textContent = "Đã cấp quyền trực tiếp";
            }
            row.append(name, makeCell(sharedWith), sharedAt, downloads, action);
            rows.append(row);
        });
    };

    const loadSharedFiles = async () => {
        const rows = document.getElementById("shared-file-rows");
        const empty = document.getElementById("shared-files-empty");
        rows.replaceChildren();
        empty.hidden = true;
        try {
            const { files: sharedFiles } = await apiRequest("api/shared_files.php");
            renderSharedFiles(sharedFiles);
            return true;
        } catch (error) {
            rows.replaceChildren();
            empty.hidden = false;
            empty.textContent = error.message;
            return false;
        }
    };

    const renderRows = (target, list) => {
        target.replaceChildren();
        if (list.length === 0) {
            const row = document.createElement("tr");
            const cell = makeCell("Chưa có tệp nào được lưu.");
            cell.colSpan = 5;
            row.append(cell);
            target.append(row);
            return;
        }
        list.forEach((file) => target.append(makeFileRow(file)));
    };

    const renderFiles = () => {
        renderRows(recentFileRows, files.slice(0, 3));
        renderRows(allFileRows, files);
        document.dispatchEvent(new CustomEvent("files:rendered", { detail: files }));
        document.getElementById("nav-file-count").textContent = String(files.length);
        document.getElementById("stat-file-count").textContent = String(files.length);
        const activeList = document.getElementById("active-links-list");
        const now = Date.now();
        const activeLinks = files.filter((file) => file.access_type === "public" && file.is_link_active && !file.is_missing
            && (!file.expires_at || new Date(file.expires_at).getTime() >= now)
            && (file.download_limit === null || file.download_count < file.download_limit));
        activeList.replaceChildren();
        if (activeLinks.length === 0) {
            const empty = document.createElement("li");
            empty.className = "active-links-empty";
            empty.textContent = "Hiện chưa có liên kết nào còn hiệu lực.";
            activeList.append(empty);
        }
        activeLinks.forEach((file) => {
            const item = document.createElement("li");
            const anchor = document.createElement("a");
            anchor.href = new URL(`download.php?code=${encodeURIComponent(file.share_code)}`, window.location.href).href;
            anchor.textContent = file.file_name;
            const meta = document.createElement("small");
            const expiry = file.expires_at ? new Intl.DateTimeFormat("vi-VN").format(new Date(file.expires_at)) : "Không hết hạn";
            meta.textContent = `${file.download_count} / ${file.download_limit ?? "∞"} lượt · Hết hạn: ${expiry}`;
            item.append(anchor, meta);
            activeList.append(item);
        });
    };

    const deleteFileRecord = async (fileId) => {
        const file = files.find((item) => item.id === fileId);
        if (!file || !window.confirm(`Bạn có chắc muốn xóa “${file.file_name}”? Thao tác này không thể hoàn tác.`)) return false;
        try {
            await apiRequest("api/delete_file.php", { method: "POST", body: { file_id: fileId } });
            files = files.filter((item) => item.id !== fileId);
            if (selectedFileId === fileId) selectedFileId = null;
            renderFiles();
            document.dispatchEvent(new CustomEvent("folders:refresh"));
            await loadAccount();
            loadPersonalActivity();
            loadNotifications().catch((error) => showToast(error.message));
            showToast(`Đã xóa “${file.file_name}”.`);
            return true;
        } catch (error) {
            showToast(error.message);
            return false;
        }
    };

    const setSelectedFile = (file) => {
        const extension = file.name.includes(".") ? file.name.split(".").pop().toLowerCase() : "";
        const allowedExtensions = fileInput.accept.split(",").map((item) => item.trim().replace(/^\./, "").toLowerCase());
        if (!allowedExtensions.includes(extension) || file.size < 1 || file.size > maxUploadBytes) {
            selectedUpload = null;
            selectedFile.hidden = true;
            showToast("Loại tệp không được hỗ trợ hoặc kích thước không nằm trong giới hạn 1 byte–1 GB.");
            return false;
        }
        if (storageAccount && storageAccount.storage_used_bytes + file.size > storageAccount.storage_quota_bytes) {
            showToast("Tệp vượt quá dung lượng còn trống trong tài khoản.");
            return false;
        }
        selectedUpload = file;
        fileNameInput.value = file.name;
        const [iconType, iconLetter] = getIconType(file.name);
        selectedFile.innerHTML = `<span class="file-icon ${iconType}"><span>${iconLetter}</span></span><span><strong></strong><small></small></span>`;
        selectedFile.querySelector("strong").textContent = file.name;
        selectedFile.querySelector("small").textContent = formatSize(file.size);
        selectedFile.hidden = false;
        return true;
    };

    const renderPermissions = (permissions) => {
        const permissionList = document.getElementById("permission-list");
        permissionList.replaceChildren();
        permissions.forEach((permission) => {
            const user = document.createElement("div");
            user.className = "permission-user";
            user.dataset.userId = String(permission.id);
            const avatar = document.createElement("span");
            avatar.className = "mini-avatar user-c";
            avatar.textContent = permission.email.slice(0, 2).toUpperCase();
            const copy = document.createElement("span");
            const name = document.createElement("strong");
            name.textContent = permission.full_name || permission.email.split("@")[0];
            const email = document.createElement("small");
            email.textContent = permission.email;
            copy.append(name, email);
            const check = document.createElement("span");
            check.className = "permission-check";
            check.setAttribute("aria-label", "Được cấp quyền");
            check.textContent = "✓";
            const revokeButton = document.createElement("button");
            revokeButton.className = "permission-remove-button";
            revokeButton.type = "button";
            revokeButton.dataset.revokePermission = "";
            revokeButton.setAttribute("aria-label", `Thu hồi quyền của ${permission.email}`);
            revokeButton.textContent = "Thu hồi";
            user.append(avatar, copy, check, revokeButton);
            permissionList.append(user);
        });
        document.getElementById("permission-count").textContent = `${permissions.length} người`;
    };

    const renderPermissionHistory = (history) => {
        const container = document.getElementById("permission-history");
        container.replaceChildren();
        if (history.length === 0) {
            const empty = document.createElement("p");
            empty.textContent = "Chưa có lịch sử cấp quyền cho tệp này.";
            container.append(empty);
        }
        history.forEach((entry) => {
            const item = document.createElement("div");
            item.className = "permission-history-item";
            const recipient = document.createElement("strong");
            recipient.textContent = entry.full_name || entry.email;
            const details = document.createElement("small");
            const date = new Intl.DateTimeFormat("vi-VN", { dateStyle: "short", timeStyle: "short" }).format(new Date(entry.created_at));
            details.textContent = `${entry.email} · Cấp ngày ${date}`;
            item.append(recipient, details);
            container.append(item);
        });
    };

    const openFileDetails = async (eventOrFileId) => {
        const row = typeof eventOrFileId === "number" ? null : eventOrFileId.target.closest(".file-row");
        const fileId = typeof eventOrFileId === "number" ? eventOrFileId : Number(row?.dataset.fileId);
        const file = files.find((item) => item.id === fileId);
        if (!file) return;
        if (file.is_missing) {
            showToast(`Không tìm thấy “${file.file_name}” trên ổ đĩa. Hãy kiểm tra thư mục lưu trữ hoặc tải tệp lên lại.`);
            return;
        }
        selectedFileId = file.id;
        document.dispatchEvent(new CustomEvent("file:detail-opened", { detail: file }));
        const [iconType, iconLetter] = getIconType(file.file_name);
        const isPublic = file.access_type === "public";
        const shareUrl = new URL(`download.php?code=${encodeURIComponent(file.share_code)}`, window.location.href).href;
        const isActive = file.is_link_active;
        document.getElementById("detail-file-name").textContent = file.file_name;
        document.getElementById("detail-file-meta").textContent =
            `Tải lên ngày ${new Intl.DateTimeFormat("vi-VN").format(new Date(file.created_at))} · ${formatSize(file.file_size)}`;
        document.getElementById("share-url").textContent = isActive ? shareUrl : "Liên kết này đã bị thu hồi";
        document.getElementById("share-description").textContent = !isActive
            ? "Liên kết chia sẻ đã bị thu hồi và không còn truy cập được."
            : isPublic
                ? "Ai có liên kết đều có thể tải tệp này."
                : "Tệp riêng tư không thể tải qua liên kết công khai.";
        const detailBadge = document.getElementById("detail-access");
        detailBadge.className = `access-badge ${isPublic ? "public" : "private"}`;
        detailBadge.replaceChildren(document.createElement("i"), document.createTextNode(isPublic ? "Công khai" : "Riêng tư"));
        const icon = document.getElementById("detail-file-icon");
        icon.className = `file-icon ${iconType}`;
        icon.innerHTML = `<span>${iconLetter}</span>`;
        document.getElementById("revoke-link").hidden = !isActive;
        document.getElementById("link-status-toggle").classList.toggle("on", isActive);
        document.getElementById("link-status-label").textContent = isActive ? "Liên kết đang hoạt động" : "Liên kết đã bị thu hồi";
        document.getElementById("link-download-limit").textContent = `${file.download_count} / ${file.download_limit ?? "Không giới hạn"} lượt tải`;
        document.getElementById("link-expiry").textContent = file.expires_at
            ? new Intl.DateTimeFormat("vi-VN").format(new Date(file.expires_at))
            : "Không thời hạn";
        document.getElementById("detail-link-limit").value = file.download_limit ?? "";
        document.getElementById("detail-link-expiry").value = file.expires_at ? file.expires_at.slice(0, 10) : "";
        document.getElementById("create-link").disabled = !isPublic;
        document.getElementById("create-link").title = isPublic ? "Lưu cài đặt và tạo mã liên kết mới" : "Chỉ tệp công khai mới có thể tạo liên kết tải";
        document.getElementById("share-file").disabled = !isPublic;
        document.getElementById("share-file").title = isPublic ? "Tạo liên kết và sao chép để chia sẻ" : "Chỉ tệp công khai mới có thể tạo liên kết tải";
        renderPermissions([]);
        document.getElementById("permission-history").hidden = true;
        document.getElementById("permission-history-toggle").textContent = "Xem lịch sử cấp quyền";
        document.getElementById("shared-files-panel").hidden = true;
        document.getElementById("shared-details-panel").hidden = false;
        activateView("shared");

        try {
            const { permissions } = await apiRequest(`api/permissions.php?file_id=${file.id}`);
            if (selectedFileId === file.id) renderPermissions(permissions);
        } catch (error) {
            showToast(error.message);
        }
    };

    document.querySelectorAll(".nav-link[data-view]").forEach((link) => {
        link.addEventListener("click", () => {
            const viewName = link.dataset.view;
            if (viewName === "shared") {
                document.getElementById("shared-files-panel").hidden = false;
                document.getElementById("shared-details-panel").hidden = true;
                loadSharedFiles();
            }
            activateView(viewName);
            if (viewName === "rewards") refreshRewards().catch((error) => showToast(error.message));
        });
    });
    document.querySelectorAll('[data-view="rewards"]:not(.nav-link)').forEach((button) => {
        button.addEventListener("click", () => {
            activateView("rewards");
            refreshRewards().catch((error) => showToast(error.message));
        });
    });
    document.querySelectorAll("[data-open-upload]").forEach((button) => {
        button.addEventListener("click", () => activateView("upload"));
    });
    document.querySelectorAll("[data-open-files]").forEach((button) => {
        button.addEventListener("click", () => activateView("files"));
    });
    const handleFileTableClick = async (event) => {
        const menuButton = event.target.closest(".row-menu");
        if (menuButton) {
            event.stopPropagation();
            const menu = menuButton.nextElementSibling;
            const opening = menu.hidden;
            document.querySelectorAll(".file-action-menu").forEach((item) => { item.hidden = true; });
            document.querySelectorAll(".row-menu").forEach((item) => item.setAttribute("aria-expanded", "false"));
            menu.hidden = !opening;
            menuButton.setAttribute("aria-expanded", String(opening));
            return;
        }
        const action = event.target.closest("[data-file-action]");
        if (action) {
            event.stopPropagation();
            const row = action.closest(".file-row");
            action.closest(".file-action-menu").hidden = true;
            action.closest(".file-action-menu-wrap").querySelector(".row-menu").setAttribute("aria-expanded", "false");
            if (action.dataset.fileAction === "delete") {
                await deleteFileRecord(fileId);
            } else if (action.dataset.fileAction === "share") {
                await openFileDetails({ target: row });
            }
            return;
        }
        document.querySelectorAll(".file-action-menu").forEach((item) => { item.hidden = true; });
        document.querySelectorAll(".row-menu").forEach((item) => item.setAttribute("aria-expanded", "false"));
        await openFileDetails(event);
    };
    recentFileRows.addEventListener("click", handleFileTableClick);
    allFileRows.addEventListener("click", handleFileTableClick);
    allFileRows.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") openFileDetails(event);
    });

    const loadFiles = async () => {
        renderRows(recentFileRows, []);
        renderRows(allFileRows, []);
        try {
            const result = await apiRequest("api/files.php");
            files = result.files;
            renderFiles();
        } catch (error) {
            renderRows(recentFileRows, []);
            renderRows(allFileRows, []);
            showToast(error.message);
        }
    };

    document.addEventListener("files:refresh", loadFiles);

    fileInput.addEventListener("change", () => {
        const [file] = fileInput.files;
        if (!file) return;
        const extension = file.name.includes(".") ? file.name.split(".").pop().toLowerCase() : "";
        const allowedExtensions = fileInput.accept.split(",").map((item) => item.trim().replace(/^\./, "").toLowerCase());
        if (!allowedExtensions.includes(extension)) {
            selectedUpload = null;
            selectedFile.hidden = true;
            fileInput.value = "";
            showToast("Định dạng tệp này không được hỗ trợ.");
            return;
        }
        if (file.size < 1 || file.size > maxUploadBytes) {
            selectedUpload = null;
            selectedFile.hidden = true;
            fileInput.value = "";
            showToast("Kích thước tệp phải từ 1 byte đến 1 GB.");
            return;
        }
        if (storageAccount && storageAccount.storage_used_bytes + file.size > storageAccount.storage_quota_bytes) {
            selectedUpload = null;
            selectedFile.hidden = true;
            fileInput.value = "";
            showToast("Tệp vượt quá dung lượng còn trống trong tài khoản.");
            return;
        }
        setSelectedFile(file);
    });

    const dropzone = document.getElementById("dropzone");
    ["dragenter", "dragover"].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add("dragover");
        });
    });
    ["dragleave", "drop"].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove("dragover");
        });
    });
    dropzone.addEventListener("drop", (event) => {
        const [file] = event.dataTransfer.files;
        if (file) setSelectedFile(file);
    });

    document.querySelectorAll('input[name="access"]').forEach((radio) => {
        radio.addEventListener("change", () => {
            document.querySelectorAll(".radio-card").forEach((card) => {
                card.classList.toggle("selected", card.contains(radio) && radio.checked);
            });
        });
    });

    document.getElementById("upload-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!selectedUpload) {
            showToast("Vui lòng chọn tệp cần tải lên.");
            fileInput.click();
            return;
        }
        if (!fileNameInput.value.trim()) {
            fileNameInput.focus();
            showToast("Vui lòng nhập tên tệp.");
            return;
        }
        const displayExtension = fileNameInput.value.trim().split(".").pop().toLowerCase();
        const acceptedExtensions = fileInput.accept.split(",").map((item) => item.trim().replace(/^\./, "").toLowerCase());
        if (!acceptedExtensions.includes(displayExtension) || selectedUpload.size > maxUploadBytes) {
            showToast("Tên tệp phải có định dạng được hỗ trợ và kích thước không vượt quá 1 GB.");
            return;
        }
        if (storageAccount && storageAccount.storage_used_bytes + selectedUpload.size > storageAccount.storage_quota_bytes) {
            showToast("Không đủ dung lượng tài khoản để lưu tệp này.");
            return;
        }

        const form = event.currentTarget;
        const submitButton = form.querySelector('button[type="submit"]');
        const data = new FormData();
        data.append("file", selectedUpload);
        data.append("fileName", fileNameInput.value.trim());
        data.append("access", document.querySelector('input[name="access"]:checked').value);
        data.append("limit", document.getElementById("download-limit").value);
        data.append("expiry", document.getElementById("expiry-date").value);
        data.append("folder_id", document.getElementById("upload-folder").value);
        submitButton.disabled = true;

        try {
            const { file } = await apiRequest("api/upload.php", { method: "POST", body: data });
            files.unshift(file);
            renderFiles();
            if (storageAccount) renderQuota({
                ...storageAccount,
                storage_used_bytes: storageAccount.storage_used_bytes + file.file_size,
                file_count: storageAccount.file_count + 1,
            });
            form.reset();
            applyDefaultAccess();
            selectedUpload = null;
            selectedFile.hidden = true;
            document.querySelectorAll(".radio-card").forEach((card) => {
                card.classList.toggle("selected", card.querySelector('input[name="access"]').checked);
            });
            activateView("files");
            showToast("Đã lưu tệp vào cơ sở dữ liệu.");
            document.dispatchEvent(new CustomEvent("folders:refresh"));
            loadPersonalActivity();
            refreshRewards().catch((error) => showToast(error.message));
            loadNotifications().catch((error) => showToast(error.message));
        } catch (error) {
            showToast(error.message);
        } finally {
            submitButton.disabled = false;
        }
    });

    document.getElementById("copy-link").addEventListener("click", async () => {
        const link = document.getElementById("share-url").textContent;
        if (!link.startsWith("http")) {
            showToast("Liên kết này không còn khả dụng.");
            return;
        }
        if (!navigator.clipboard?.writeText) {
            showToast("Trình duyệt không hỗ trợ sao chép tự động.");
            return;
        }
        try {
            await navigator.clipboard.writeText(link);
            showToast("Đã sao chép liên kết chia sẻ.");
        } catch {
            showToast("Không thể sao chép tự động. Hãy chọn và sao chép liên kết.");
        }
    });

    document.getElementById("add-user-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!selectedFileId) {
            showToast("Vui lòng chọn tệp trước.");
            return;
        }

        const emailInput = document.getElementById("user-email");
        try {
            await apiRequest("api/permissions.php", {
                method: "POST",
                body: { file_id: selectedFileId, email: emailInput.value.trim() },
            });
            emailInput.value = "";
            const { permissions } = await apiRequest(`api/permissions.php?file_id=${selectedFileId}`);
            renderPermissions(permissions);
            const listUpdated = await loadSharedFiles();
            if (!listUpdated) {
                showToast("Đã cấp quyền nhưng không thể cập nhật danh sách chia sẻ.");
                return;
            }
            showToast("Đã lưu quyền truy cập.");
        } catch (error) {
            showToast(error.message);
        }
    });

    document.getElementById("permission-list").addEventListener("click", async (event) => {
        const button = event.target.closest("[data-revoke-permission]");
        if (!button || !selectedFileId) return;

        const user = button.closest(".permission-user");
        const permissionUserId = Number(user?.dataset.userId);
        const permissionEmail = user?.querySelector("small")?.textContent ?? "người dùng này";
        const file = files.find((item) => item.id === selectedFileId);
        if (!Number.isInteger(permissionUserId) || permissionUserId < 1) {
            showToast("Không xác định được người dùng cần thu hồi quyền.");
            return;
        }
        const publicLinkWarning = file?.access_type === "public"
            ? "\n\nTệp đang công khai; người này vẫn có thể tải qua liên kết công khai."
            : "";
        if (!window.confirm(`Bạn có chắc muốn thu hồi quyền tải của ${permissionEmail}?${publicLinkWarning}`)) return;

        button.disabled = true;
        try {
            await apiRequest("api/revoke_permission.php", {
                method: "POST",
                body: { file_id: selectedFileId, user_id: permissionUserId },
            });
            user.remove();
            const permissionCount = document.querySelectorAll("#permission-list .permission-user").length;
            document.getElementById("permission-count").textContent = `${permissionCount} người`;
            const listUpdated = await loadSharedFiles();
            if (!listUpdated) {
                showToast("Đã thu hồi quyền nhưng không thể cập nhật danh sách chia sẻ.");
                return;
            }
            showToast("Đã thu hồi quyền tải của người dùng.");
        } catch (error) {
            button.disabled = false;
            showToast(error.message);
        }
    });

    document.getElementById("revoke-link").addEventListener("click", async () => {
        if (!selectedFileId) {
            showToast("Vui lòng chọn tệp trước.");
            return;
        }
        try {
            await apiRequest("api/revoke.php", {
                method: "POST",
                body: { file_id: selectedFileId },
            });
            const file = files.find((item) => item.id === selectedFileId);
            file.is_link_active = false;
            renderFiles();
            document.getElementById("share-url").textContent = "Liên kết này đã bị thu hồi";
            document.getElementById("share-description").textContent = "Liên kết chia sẻ đã bị thu hồi và không còn truy cập được.";
            document.getElementById("link-status-toggle").classList.remove("on");
            document.getElementById("link-status-label").textContent = "Liên kết đã bị thu hồi";
            document.getElementById("revoke-link").hidden = true;
            await loadSharedFiles();
            showToast("Đã thu hồi liên kết chia sẻ.");
        } catch (error) {
            showToast(error.message);
        }
    });

    const createShareLink = async (copyLink) => {
        if (!selectedFileId) return;
        const buttons = [
            document.getElementById("create-link"),
            document.getElementById("share-file"),
        ];
        buttons.forEach((button) => { button.disabled = true; });
        try {
            const { file } = await apiRequest("api/create_link.php", {
                method: "POST",
                body: {
                    file_id: selectedFileId,
                    expiry: document.getElementById("detail-link-expiry").value,
                    download_limit: document.getElementById("detail-link-limit").value,
                },
            });
            const currentFile = files.find((item) => item.id === selectedFileId);
            Object.assign(currentFile, file);
            const shareUrl = new URL(`download.php?code=${encodeURIComponent(file.share_code)}`, window.location.href).href;
            document.getElementById("share-url").textContent = shareUrl;
            document.getElementById("share-description").textContent = "Liên kết mới dùng cài đặt thời hạn và giới hạn tải đã chọn.";
            document.getElementById("link-status-toggle").classList.add("on");
            document.getElementById("link-status-label").textContent = "Liên kết đang hoạt động";
            document.getElementById("link-download-limit").textContent = `0 / ${file.download_limit ?? "Không giới hạn"} lượt tải`;
            document.getElementById("link-expiry").textContent = file.expires_at
                ? new Intl.DateTimeFormat("vi-VN").format(new Date(file.expires_at))
                : "Không thời hạn";
            document.getElementById("revoke-link").hidden = false;
            renderFiles();
            const listUpdated = await loadSharedFiles();
            if (!listUpdated) {
                showToast("Đã tạo liên kết nhưng không thể cập nhật danh sách chia sẻ.");
                return;
            }
            refreshRewards().catch((error) => showToast(error.message));
            if (copyLink) {
                if (!navigator.clipboard?.writeText) {
                    showToast("Đã tạo liên kết, nhưng trình duyệt không hỗ trợ sao chép tự động.");
                    return;
                }
                try {
                    await navigator.clipboard.writeText(shareUrl);
                    showToast("Đã tạo liên kết và sao chép để chia sẻ.");
                } catch {
                    showToast("Đã tạo liên kết nhưng không thể sao chép tự động. Hãy sao chép liên kết ở khung bên trái.");
                }
            } else {
                showToast("Đã lưu cài đặt, tạo mã mới và cập nhật danh sách chia sẻ.");
            }
        } catch (error) {
            showToast(error.message);
        } finally {
            buttons.forEach((button) => { button.disabled = false; });
        }
    };

    document.getElementById("create-link").addEventListener("click", () => createShareLink(false));
    document.getElementById("share-file").addEventListener("click", () => createShareLink(true));

    document.getElementById("shared-file-rows").addEventListener("click", async (event) => {
        const copyButton = event.target.closest("[data-copy-share-link]");
        if (!copyButton) return;
        if (!navigator.clipboard?.writeText) {
            showToast("Trình duyệt không hỗ trợ sao chép tự động.");
            return;
        }
        try {
            const shareUrl = new URL(
                `download.php?code=${encodeURIComponent(copyButton.dataset.copyShareLink)}`,
                window.location.href
            ).href;
            await navigator.clipboard.writeText(shareUrl);
            showToast("Đã sao chép liên kết chia sẻ.");
        } catch {
            showToast("Không thể sao chép liên kết tự động.");
        }
    });

    document.getElementById("permission-history-toggle").addEventListener("click", async (event) => {
        if (!selectedFileId) return;
        const container = document.getElementById("permission-history");
        const opening = container.hidden;
        container.hidden = !opening;
        event.currentTarget.textContent = opening ? "Ẩn lịch sử cấp quyền" : "Xem lịch sử cấp quyền";
        if (!opening) return;
        try {
            const { history } = await apiRequest(`api/permission_history.php?file_id=${selectedFileId}`);
            if (!container.hidden) renderPermissionHistory(history);
        } catch (error) {
            showToast(error.message);
        }
    });

    document.getElementById("move-file-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!selectedFileId) return;
        const folderSelect = document.getElementById("move-folder");
        try {
            await apiRequest("api/move_file.php", {
                method: "POST",
                body: { file_id: selectedFileId, folder_id: folderSelect.value || null },
            });
            const file = files.find((item) => item.id === selectedFileId);
            if (file) file.folder_id = folderSelect.value ? Number(folderSelect.value) : null;
            document.dispatchEvent(new CustomEvent("file:detail-opened", { detail: file }));
            document.dispatchEvent(new CustomEvent("folders:refresh"));
            showToast("Đã chuyển tệp sang thư mục đã chọn.");
        } catch (error) {
            showToast(error.message);
        }
    });

    document.addEventListener("file:detail-opened", (event) => {
        const select = document.getElementById("move-folder");
        select.value = event.detail.folder_id === null ? "" : String(event.detail.folder_id);
    });

    document.getElementById("delete-file").addEventListener("click", async () => {
        if (!selectedFileId) return;
        if (await deleteFileRecord(selectedFileId)) activateView("files");
    });

    document.getElementById("logout-button").addEventListener("click", async () => {
        try {
            await apiRequest("api/logout.php", { method: "POST", body: {} });
            window.location.href = "login.php";
        } catch (error) {
            showToast(error.message);
        }
    });

    document.addEventListener("click", (event) => {
        if (event.target.closest(".file-action-menu-wrap")) return;
        document.querySelectorAll(".file-action-menu").forEach((item) => { item.hidden = true; });
        document.querySelectorAll(".row-menu").forEach((item) => item.setAttribute("aria-expanded", "false"));
    });

    document.getElementById("help-link").addEventListener("click", (event) => {
        event.preventDefault();
        showToast("Trung tâm trợ giúp sẽ sớm có mặt.");
    });

    const closeSearch = () => {
        searchPanel.hidden = true;
        searchToggle.setAttribute("aria-expanded", "false");
    };

    searchToggle.addEventListener("click", () => {
        const opening = searchPanel.hidden;
        searchPanel.hidden = !opening;
        searchToggle.setAttribute("aria-expanded", String(opening));
        if (opening) {
            renderSearchResults();
            searchInput.focus();
        }
    });
    searchInput.addEventListener("input", renderSearchResults);
    searchResults.addEventListener("click", async (event) => {
        const result = event.target.closest(".global-search-result");
        if (!result) return;
        closeSearch();
        await openFileDetails(Number(result.dataset.fileId));
    });
    document.addEventListener("click", (event) => {
        if (!event.target.closest("#global-search-panel, #global-search-toggle")) closeSearch();
    });
    document.addEventListener("keydown", (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
            event.preventDefault();
            searchPanel.hidden = false;
            searchToggle.setAttribute("aria-expanded", "true");
            renderSearchResults();
            searchInput.focus();
        } else if (event.key === "Escape" && !searchPanel.hidden) {
            closeSearch();
            searchToggle.focus();
        }
    });

    document.querySelector(".storage-upgrade").addEventListener("click", () => activateView("upgrade"));
    document.querySelector(".profile-card").addEventListener("click", () => activateView("personal"));
    document.querySelectorAll("[data-open-personal]").forEach((button) => {
        button.addEventListener("click", () => activateView("personal"));
    });
    document.querySelectorAll("[data-open-settings]").forEach((button) => {
        button.addEventListener("click", () => activateView("settings"));
    });
    document.querySelectorAll("[data-open-upgrade]").forEach((button) => {
        button.addEventListener("click", () => activateView("upgrade"));
    });

    document.getElementById("setting-theme").addEventListener("change", (event) => {
        userPreferences.theme = event.currentTarget.value === "dark" ? "dark" : "light";
        applyTheme();
        persistPreferences();
    });
    document.getElementById("setting-default-access").addEventListener("change", (event) => {
        userPreferences.defaultAccess = event.currentTarget.value === "private" ? "private" : "public";
        applyDefaultAccess();
        persistPreferences();
    });

    document.getElementById("storage-plans").addEventListener("click", async (event) => {
        const button = event.target.closest(".plan-action");
        if (!button || button.disabled) return;
        document.querySelectorAll(".plan-action").forEach((planButton) => { planButton.disabled = true; });
        try {
            const { quota, message } = await apiRequest("api/upgrade.php", {
                method: "POST",
                body: { storage_gb: Number(button.dataset.planGb) },
            });
            if (storageAccount) {
                renderQuota({ ...storageAccount, storage_quota_bytes: quota });
            }
            await loadAccount();
            showToast(message);
        } catch (error) {
            updatePlanOptions();
            showToast(error.message);
        }
    });

    document.getElementById("cleanup-preview").addEventListener("click", async () => {
        try {
            const { files: cleanupFiles } = await apiRequest("api/cleanup_preview.php");
            if (cleanupFiles.length === 0) {
                window.alert("Không có tệp nào cần dọn. Chưa có tệp nào bị xóa.");
                return;
            }
            const preview = cleanupFiles.slice(0, 30).map((file) => {
                const reasons = [];
                if (!file.is_link_active) reasons.push("đã thu hồi");
                if (file.expires_at && new Date(file.expires_at) <= new Date()) reasons.push("hết hạn");
                if (file.download_limit !== null && file.download_count >= file.download_limit) reasons.push("hết lượt tải");
                return `${file.file_name} (${formatSize(file.file_size)}) — ${reasons.join(", ")}`;
            }).join("\n");
            const remainingCount = cleanupFiles.length - 30;
            const remaining = remainingCount > 0 ? `\n... và ${remainingCount} tệp khác (xem danh sách đầy đủ trong terminal).` : "";
            window.alert(
                `Có ${cleanupFiles.length} tệp có liên kết hết hạn, bị thu hồi hoặc hết lượt tải:\n\n${preview}${remaining}\n\nChưa có tệp nào bị xóa. Để dọn, chạy php bin/cleanup_expired.php rồi rà soát và xác nhận trong terminal.`
            );
        } catch (error) {
            showToast(error.message);
        }
    });

    const formatRelativeTime = (dateValue) => {
        const minutes = Math.max(0, Math.floor((Date.now() - new Date(dateValue).getTime()) / 60000));
        if (minutes < 1) return "Vừa xong";
        if (minutes < 60) return `${minutes} phút trước`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours} giờ trước`;
        return `${Math.floor(hours / 24)} ngày trước`;
    };

    const loadPersonalActivity = async () => {
        const list = document.getElementById("personal-activity-list");
        try {
            const { uploads } = await apiRequest("api/recent_uploads.php");
            list.replaceChildren();
            if (uploads.length === 0) {
                const empty = document.createElement("p");
                empty.className = "activity-empty";
                empty.textContent = "Bạn chưa tải tệp nào lên.";
                list.append(empty);
                return;
            }
            uploads.forEach((upload) => {
                const item = document.createElement("div");
                item.className = "activity-item";
                const avatar = document.createElement("span");
                avatar.className = "activity-avatar activity-blue";
                avatar.textContent = "Bạn";
                const details = document.createElement("div");
                const title = document.createElement("p");
                const strong = document.createElement("strong");
                strong.textContent = "Bạn";
                title.append(strong, document.createTextNode(" đã tải tệp lên"));
                const meta = document.createElement("span");
                meta.textContent = `${upload.file_name} · ${formatRelativeTime(upload.created_at)}`;
                details.append(title, meta);
                item.append(avatar, details);
                list.append(item);
            });
        } catch (error) {
            list.replaceChildren();
            showToast(error.message);
        }
    };

    const setUnreadCount = (count) => {
        const dot = notificationButton.querySelector("i");
        dot.hidden = count === 0;
        const countLabel = document.getElementById("notification-unread-count");
        countLabel.textContent = count > 0 ? `${count} chưa đọc` : "";
        notificationButton.setAttribute("aria-label", count > 0 ? `Thông báo, ${count} chưa đọc` : "Thông báo");
    };

    const renderNotifications = (notifications) => {
        notificationList.replaceChildren();
        if (notifications.length === 0) {
            const empty = document.createElement("p");
            empty.className = "notification-empty";
            empty.textContent = "Bạn chưa có thông báo nào.";
            notificationList.append(empty);
            return;
        }
        notifications.forEach((notification) => {
            const item = document.createElement("article");
            item.className = `notification-item${notification.is_read ? "" : " unread"}`;
            const title = document.createElement("strong");
            title.textContent = notification.title;
            const message = document.createElement("p");
            message.textContent = notification.message;
            const time = document.createElement("small");
            time.textContent = formatRelativeTime(notification.created_at);
            item.append(title, message, time);
            notificationList.append(item);
        });
    };

    const loadNotifications = async (markRead = false) => {
        const { notifications, unread_count: unreadCount } = await apiRequest("api/notifications.php");
        renderNotifications(notifications);
        setUnreadCount(unreadCount);
        if (markRead && unreadCount > 0) {
            await apiRequest("api/notifications.php", { method: "POST", body: { action: "mark_read" } });
            setUnreadCount(0);
            notificationList.querySelectorAll(".notification-item").forEach((item) => item.classList.remove("unread"));
        }
    };

    notificationButton.addEventListener("click", async () => {
        const opening = notificationDropdown.hidden;
        notificationDropdown.hidden = !opening;
        notificationButton.setAttribute("aria-expanded", String(opening));
        if (!opening) return;
        try {
            await loadNotifications(true);
        } catch (error) {
            showToast(error.message);
        }
    });
    document.addEventListener("click", (event) => {
        if (!event.target.closest(".notification-menu")) {
            notificationDropdown.hidden = true;
            notificationButton.setAttribute("aria-expanded", "false");
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !notificationDropdown.hidden) {
            notificationDropdown.hidden = true;
            notificationButton.setAttribute("aria-expanded", "false");
            notificationButton.focus();
        }
    });

    loadFiles();
    loadAccount();
    refreshRewards().then((rewards) => {
        document.getElementById("online-time-today").dataset.seconds = String(rewards.online_seconds_today);
    }).catch((error) => showToast(error.message));
    window.setInterval(pingOnline, 30000);
    pingOnline();
    loadPersonalActivity();
    loadNotifications().catch((error) => showToast(error.message));
})();
