(() => {
    const panels = [...document.querySelectorAll(".view-panel")];
    const navLinks = [...document.querySelectorAll(".nav-link[data-view]")];
    const breadcrumb = document.getElementById("breadcrumb-current");
    const allFileRows = document.getElementById("all-file-rows");
    const recentFileRows = document.getElementById("recent-file-rows");
    const toast = document.getElementById("toast");
    const fileInput = document.getElementById("file-input");
    const fileNameInput = document.getElementById("file-name");
    const selectedFile = document.getElementById("selected-file");
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    let toastTimer;
    let selectedUpload = null;
    let selectedFileId = null;
    let files = [];
    let storageAccount = null;

    const showToast = (message) => {
        toast.textContent = message;
        toast.classList.add("visible");
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove("visible"), 3200);
    };

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
    };

    const loadAccount = async () => {
        try {
            const { account } = await apiRequest("api/account.php");
            renderQuota(account);
        } catch (error) {
            showToast(error.message);
        }
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
            dashboard: "Tổng quan",
            files: "Tệp của tôi",
            upload: "Tải tệp lên",
            shared: "Chi tiết tệp",
            folders: "Thư mục",
        }[viewName] || "Tổng quan";
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
        row.dataset.fileId = String(file.id);
        row.draggable = true;
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
        const menu = document.createElement("button");
        menu.className = "row-menu";
        menu.type = "button";
        menu.setAttribute("aria-label", `Tùy chọn ${file.file_name}`);
        menu.textContent = "···";
        menuCell.append(menu);

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
            meta.textContent = `${file.download_count} / ${file.download_limit ?? "∞"} lượt · Hết hạn: ${expiry}${file.has_link_password ? " · Có mật khẩu" : ""}`;
            item.append(anchor, meta);
            activeList.append(item);
        });
    };

    const setSelectedFile = (file) => {
        const extension = file.name.includes(".") ? file.name.split(".").pop().toLowerCase() : "";
        const allowedExtensions = fileInput.accept.split(",").map((item) => item.trim().replace(/^\./, "").toLowerCase());
        if (!allowedExtensions.includes(extension) || file.size < 1 || file.size > 100 * 1024 * 1024) {
            selectedUpload = null;
            selectedFile.hidden = true;
            showToast("Loại tệp không được hỗ trợ hoặc kích thước không nằm trong giới hạn 1 byte–100 MB.");
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
            user.append(avatar, copy, check);
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

    const openFileDetails = async (event) => {
        if (event.target.closest(".row-menu")) {
            showToast("Các tùy chọn tệp sẽ hiển thị tại đây.");
            return;
        }
        const row = event.target.closest(".file-row");
        if (!row) return;

        const file = files.find((item) => item.id === Number(row.dataset.fileId));
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
        document.getElementById("detail-link-password").value = "";
        document.getElementById("clear-link-password").checked = false;
        document.getElementById("create-link").disabled = !isPublic;
        document.getElementById("create-link").title = isPublic ? "Lưu cài đặt và tạo mã liên kết mới" : "Chỉ tệp công khai mới có thể tạo liên kết tải";
        renderPermissions([]);
        document.getElementById("permission-history").hidden = true;
        document.getElementById("permission-history-toggle").textContent = "Xem lịch sử cấp quyền";
        activateView("shared");

        try {
            const { permissions } = await apiRequest(`api/permissions.php?file_id=${file.id}`);
            if (selectedFileId === file.id) renderPermissions(permissions);
        } catch (error) {
            showToast(error.message);
        }
    };

    document.querySelectorAll(".nav-link[data-view]").forEach((link) => {
        link.addEventListener("click", () => activateView(link.dataset.view));
    });
    document.querySelectorAll("[data-open-upload]").forEach((button) => {
        button.addEventListener("click", () => activateView("upload"));
    });
    document.querySelectorAll("[data-open-files]").forEach((button) => {
        button.addEventListener("click", () => activateView("files"));
    });
    recentFileRows.addEventListener("click", openFileDetails);
    allFileRows.addEventListener("click", openFileDetails);
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
        if (file.size < 1 || file.size > 100 * 1024 * 1024) {
            selectedUpload = null;
            selectedFile.hidden = true;
            fileInput.value = "";
            showToast("Kích thước tệp phải từ 1 byte đến 100 MB.");
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
        if (!acceptedExtensions.includes(displayExtension) || selectedUpload.size > 100 * 1024 * 1024) {
            showToast("Tên tệp phải có định dạng được hỗ trợ và kích thước không vượt quá 100 MB.");
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
        data.append("link_password", document.getElementById("upload-link-password").value);
        data.append("folder_id", document.getElementById("upload-folder").value);
        submitButton.disabled = true;

        try {
            const { file } = await apiRequest("api/upload.php", { method: "POST", body: data });
            files.unshift(file);
            renderFiles();
            if (storageAccount) renderQuota({ ...storageAccount, storage_used_bytes: storageAccount.storage_used_bytes + file.file_size });
            form.reset();
            selectedUpload = null;
            selectedFile.hidden = true;
            document.querySelectorAll(".radio-card").forEach((card) => {
                card.classList.toggle("selected", card.querySelector('input[name="access"]').checked);
            });
            activateView("files");
            showToast("Đã lưu tệp vào cơ sở dữ liệu.");
            document.dispatchEvent(new CustomEvent("folders:refresh"));
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
            showToast("Đã lưu quyền truy cập.");
        } catch (error) {
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
            showToast("Đã thu hồi liên kết chia sẻ.");
        } catch (error) {
            showToast(error.message);
        }
    });

    document.getElementById("create-link").addEventListener("click", async () => {
        if (!selectedFileId) return;
        const button = document.getElementById("create-link");
        button.disabled = true;
        try {
            const { file } = await apiRequest("api/create_link.php", {
                method: "POST",
                body: {
                    file_id: selectedFileId,
                    expiry: document.getElementById("detail-link-expiry").value,
                    download_limit: document.getElementById("detail-link-limit").value,
                    password: document.getElementById("detail-link-password").value,
                    clear_password: document.getElementById("clear-link-password").checked,
                },
            });
            const currentFile = files.find((item) => item.id === selectedFileId);
            Object.assign(currentFile, file);
            const shareUrl = new URL(`download.php?code=${encodeURIComponent(file.share_code)}`, window.location.href).href;
            document.getElementById("share-url").textContent = shareUrl;
            document.getElementById("share-description").textContent = file.has_link_password
                ? "Liên kết mới yêu cầu mật khẩu để tải tệp."
                : "Liên kết mới dùng cài đặt thời hạn và giới hạn tải đã chọn.";
            document.getElementById("link-status-toggle").classList.add("on");
            document.getElementById("link-status-label").textContent = "Liên kết đang hoạt động";
            document.getElementById("link-download-limit").textContent = `0 / ${file.download_limit ?? "Không giới hạn"} lượt tải`;
            document.getElementById("link-expiry").textContent = file.expires_at
                ? new Intl.DateTimeFormat("vi-VN").format(new Date(file.expires_at))
                : "Không thời hạn";
            document.getElementById("detail-link-password").value = "";
            document.getElementById("clear-link-password").checked = false;
            document.getElementById("revoke-link").hidden = false;
            renderFiles();
            showToast("Đã lưu cài đặt và tạo mã liên kết mới.");
        } catch (error) {
            showToast(error.message);
        } finally {
            button.disabled = false;
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
        if (!selectedFileId || !window.confirm("Bạn có chắc muốn xóa tệp này? Thao tác này không thể hoàn tác.")) return;
        try {
            await apiRequest("api/delete_file.php", { method: "POST", body: { file_id: selectedFileId } });
            files = files.filter((file) => file.id !== selectedFileId);
            selectedFileId = null;
            renderFiles();
            activateView("files");
            document.dispatchEvent(new CustomEvent("folders:refresh"));
            await loadAccount();
            showToast("Đã xóa tệp.");
        } catch (error) {
            showToast(error.message);
        }
    });

    document.getElementById("logout-button").addEventListener("click", async () => {
        try {
            await apiRequest("api/logout.php", { method: "POST", body: {} });
            window.location.href = "login.php";
        } catch (error) {
            showToast(error.message);
        }
    });

    document.getElementById("help-link").addEventListener("click", (event) => {
        event.preventDefault();
        showToast("Trung tâm trợ giúp sẽ sớm có mặt.");
    });

    document.querySelectorAll(".search-button, .notification-button, .storage-upgrade, .more-button, .profile-card, .activity-link").forEach((button) => {
        button.addEventListener("click", () => showToast("Tính năng này sẽ sớm có mặt."));
    });

    loadFiles();
    loadAccount();
})();
