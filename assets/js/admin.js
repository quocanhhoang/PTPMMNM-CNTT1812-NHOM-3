(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const toast = document.getElementById("admin-toast");
    let toastTimer;

    const showToast = (message) => {
        toast.textContent = message;
        toast.classList.add("visible");
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove("visible"), 3200);
    };

    const formatBytes = (bytes) => {
        if (bytes < 1024) return `${bytes} B`;
        const units = ["KB", "MB", "GB", "TB"];
        let value = bytes / 1024;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit += 1;
        }
        return `${value.toFixed(1)} ${units[unit]}`;
    };

    const formatTime = (dateValue) => new Intl.DateTimeFormat("vi-VN", {
        dateStyle: "short",
        timeStyle: "short",
    }).format(new Date(dateValue));

    const actionLabels = {
        upload: "đã tải lên",
        download: "đã tải xuống",
        link_created: "đã tạo liên kết",
        link_revoked: "đã thu hồi liên kết",
        permission_granted: "đã cấp quyền",
    };

    const renderActivity = (activity) => {
        const list = document.getElementById("admin-activity-list");
        list.replaceChildren();
        if (activity.length === 0) {
            const empty = document.createElement("p");
            empty.className = "activity-empty";
            empty.textContent = "Chưa có hoạt động nào.";
            list.append(empty);
            return;
        }
        activity.forEach((entry) => {
            const item = document.createElement("article");
            item.className = "admin-activity-item";
            const description = document.createElement("p");
            const fileName = document.createElement("strong");
            fileName.textContent = entry.file_name;
            if (entry.action === "download") {
                description.append(document.createTextNode("Tệp "), fileName, document.createTextNode(" vừa có lượt tải"));
            } else {
                const owner = document.createElement("strong");
                owner.textContent = entry.full_name;
                description.append(owner, document.createTextNode(` ${actionLabels[entry.action] || entry.action} `), fileName);
            }
            const time = document.createElement("small");
            time.textContent = formatTime(entry.created_at);
            item.append(description, time);
            list.append(item);
        });
    };

    const renderTopFiles = (files) => {
        const body = document.getElementById("admin-top-files");
        body.replaceChildren();
        if (files.length === 0) {
            const row = document.createElement("tr");
            const cell = document.createElement("td");
            cell.colSpan = 3;
            cell.textContent = "Chưa có tệp nào.";
            row.append(cell);
            body.append(row);
            return;
        }
        files.forEach((file) => {
            const row = document.createElement("tr");
            [file.file_name, file.owner_name, `${file.download_count} lượt`].forEach((value) => {
                const cell = document.createElement("td");
                cell.textContent = value;
                row.append(cell);
            });
            body.append(row);
        });
    };

    const renderChart = (days) => {
        const chart = document.getElementById("download-chart");
        const max = Math.max(1, ...days.map((day) => day.download_count));
        chart.replaceChildren();
        days.forEach((day) => {
            const column = document.createElement("div");
            column.className = "chart-column";
            const value = document.createElement("span");
            value.className = "chart-value";
            value.textContent = String(day.download_count);
            const track = document.createElement("div");
            track.className = "chart-track";
            const bar = document.createElement("span");
            bar.style.height = `${Math.max(3, day.download_count / max * 100)}%`;
            track.append(bar);
            const label = document.createElement("small");
            label.textContent = new Intl.DateTimeFormat("vi-VN", { weekday: "short" }).format(new Date(`${day.day}T12:00:00`));
            column.append(value, track, label);
            chart.append(column);
        });
    };

    const loadDashboard = async () => {
        try {
            const response = await fetch("api/admin_dashboard.php", { headers: { Accept: "application/json" } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || "Không thể tải dashboard.");
            document.getElementById("admin-user-count").textContent = data.overview.user_count.toLocaleString("vi-VN");
            document.getElementById("admin-file-count").textContent = data.overview.file_count.toLocaleString("vi-VN");
            document.getElementById("admin-download-count").textContent = data.overview.download_count.toLocaleString("vi-VN");
            document.getElementById("admin-storage-bytes").textContent = formatBytes(data.overview.storage_bytes);
            renderActivity(data.recent_activity);
            renderTopFiles(data.top_files);
            renderChart(data.downloads_7_days);
        } catch (error) {
            showToast(error.message);
        }
    };

    document.getElementById("admin-logout").addEventListener("click", async () => {
        try {
            const response = await fetch("api/logout.php", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
                body: JSON.stringify({}),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || "Không thể đăng xuất.");
            window.location.href = "login.php";
        } catch (error) {
            showToast(error.message);
        }
    });

    loadDashboard();
})();
