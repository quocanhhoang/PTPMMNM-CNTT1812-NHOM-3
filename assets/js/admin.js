(() => {
    try {
        const preferences = JSON.parse(localStorage.getItem("fileshare-user-preferences") || "{}");
        document.body.dataset.theme = preferences.theme === "dark" ? "dark" : "light";
    } catch (error) {
        console.error("Unable to load FileShare appearance preference.", error);
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const toast = document.getElementById("admin-toast");
    const userSearch = document.getElementById("admin-user-search");
    const userResults = document.getElementById("admin-user-results");
    let toastTimer;
    let userSearchTimer;
    let userSearchSequence = 0;

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
        permission_revoked: "đã thu hồi quyền",
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

    const formatOnlineDuration = (seconds) => {
        const totalMinutes = Math.floor(seconds / 60);
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        return `${hours} giờ ${minutes} phút`;
    };

    const renderLeaderboard = (elementId, users, metric) => {
        const container = document.getElementById(elementId);
        container.replaceChildren();
        if (users.length === 0) {
            container.textContent = "Chưa có dữ liệu.";
            return;
        }

        const chart = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        chart.setAttribute("viewBox", "0 0 600 170");
        chart.setAttribute("role", "img");
        chart.setAttribute("aria-label", "Biểu đồ miền top 10");
        const max = Math.max(1, ...users.map((user) => user.value));
        const coordinates = users.map((user, index) => {
            const x = users.length === 1 ? 300 : 20 + index * 560 / (users.length - 1);
            return [x, 140 - user.value / max * 115];
        });
        const area = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
        area.setAttribute("points", `20,145 ${coordinates.map(([x, y]) => `${x},${y}`).join(" ")} 580,145`);
        area.setAttribute("class", "leaderboard-area-fill");
        chart.append(area);
        coordinates.forEach(([x, y], index) => {
            const point = document.createElementNS("http://www.w3.org/2000/svg", "circle");
            point.setAttribute("cx", String(x));
            point.setAttribute("cy", String(y));
            point.setAttribute("r", "4");
            point.setAttribute("class", "leaderboard-area-point");
            const title = document.createElementNS("http://www.w3.org/2000/svg", "title");
            title.textContent = `${users[index].full_name}: ${metric.format(users[index].value)}`;
            point.append(title);
            chart.append(point);
        });
        const list = document.createElement("ol");
        list.className = "leaderboard-list";
        users.forEach((user) => {
            const item = document.createElement("li");
            const name = document.createElement("span");
            name.textContent = user.full_name;
            name.title = user.email;
            const value = document.createElement("strong");
            value.textContent = metric.format(user.value);
            item.append(name, value);
            list.append(item);
        });
        container.append(chart, list);
    };

    const renderLeaderboards = (leaderboards) => {
        renderLeaderboard("leaderboard-coins", leaderboards.coins, {
            format: (value) => `${value.toLocaleString("vi-VN")} xu`,
        });
        renderLeaderboard("leaderboard-missions", leaderboards.missions, {
            format: (value) => `${value.toLocaleString("vi-VN")} nhiệm vụ`,
        });
        renderLeaderboard("leaderboard-online", leaderboards.online, {
            format: formatOnlineDuration,
        });

        const usersById = new Map();
        Object.values(leaderboards).flat().forEach((user) => usersById.set(user.user_id, user));
        const select = document.getElementById("admin-gift-user");
        select.replaceChildren(new Option("Chọn người nhận", ""));
        [...usersById.values()].sort((first, second) => first.full_name.localeCompare(second.full_name, "vi")).forEach((user) => {
            select.add(new Option(`${user.full_name} (${user.email})`, String(user.user_id)));
        });
    };

    const renderUsers = (users) => {
        userResults.replaceChildren();
        if (users.length === 0) {
            const empty = document.createElement("p");
            empty.className = "activity-empty";
            empty.textContent = "Không tìm thấy người dùng phù hợp.";
            userResults.append(empty);
            return;
        }
        users.forEach((user) => {
            const item = document.createElement("article");
            item.className = "admin-user-result";
            const name = document.createElement("strong");
            name.textContent = user.full_name;
            const email = document.createElement("span");
            email.textContent = user.email;
            const role = document.createElement("small");
            role.textContent = user.role === "admin" ? "Quản trị viên" : "Người dùng";
            item.append(name, email, role);
            userResults.append(item);
        });
    };

    const searchUsers = async () => {
        const query = userSearch.value.trim();
        const sequence = ++userSearchSequence;
        if (!query) {
            userResults.replaceChildren();
            const prompt = document.createElement("p");
            prompt.className = "activity-empty";
            prompt.textContent = "Nhập tên để tra cứu người dùng.";
            userResults.append(prompt);
            return;
        }
        userResults.textContent = "Đang tìm kiếm...";
        try {
            const response = await fetch(`api/admin_users.php?q=${encodeURIComponent(query)}`, {
                headers: { Accept: "application/json" },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || "Không thể tra cứu người dùng.");
            if (sequence === userSearchSequence) renderUsers(data.users);
        } catch (error) {
            if (sequence !== userSearchSequence) return;
            userResults.textContent = error.message;
        }
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
            renderLeaderboards(data.leaderboards);
        } catch (error) {
            showToast(error.message);
        }
    };

    userSearch.addEventListener("input", () => {
        window.clearTimeout(userSearchTimer);
        userSearchTimer = window.setTimeout(searchUsers, 250);
    });

    document.getElementById("admin-gift-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const response = await fetch("api/admin_gift_coins.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrfToken,
                },
                body: JSON.stringify({
                    user_id: Number(document.getElementById("admin-gift-user").value),
                    coins: Number(document.getElementById("admin-gift-coins").value),
                }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || "Không thể tặng xu.");
            form.reset();
            showToast(`Đã tặng ${data.coins.toLocaleString("vi-VN")} xu cho ${data.recipient}.`);
            await loadDashboard();
        } catch (error) {
            showToast(error.message);
        } finally {
            button.disabled = false;
        }
    });

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
