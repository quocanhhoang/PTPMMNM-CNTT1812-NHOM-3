(() => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const list = document.getElementById("folder-list");
    const folderParent = document.getElementById("folder-parent");
    const uploadSelect = document.getElementById("upload-folder");
    const moveSelect = document.getElementById("move-folder");
    const fileFilter = document.getElementById("file-folder-filter");
    const managerPanel = document.getElementById("folder-manager-panel");
    const contentPanel = document.getElementById("folder-content-panel");
    const contentRows = document.getElementById("folder-file-rows");
    const folderBrowser = document.getElementById("file-folder-browser");
    const rootDrop = document.getElementById("folder-root-drop");
    let folders = [];
    let activeFileFolderId = null;
    let openedFolderId = null;
    let draggedFileId = null;
    let draggedFolderId = null;

    const request = async (url, options = {}) => {
        const headers = { ...(options.headers || {}) };
        if (options.body) {
            headers["Content-Type"] = "application/json";
            headers["X-CSRF-Token"] = token;
            options.body = JSON.stringify(options.body);
        }
        const response = await fetch(url, { ...options, headers });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Không thể quản lý thư mục.");
        return result;
    };

    const post = (body) => request("api/folders.php", { method: "POST", body });

    const folderPath = (folder) => {
        const parent = folders.find((item) => item.id === folder.parent_id);
        return parent ? `${folderPath(parent)} / ${folder.name}` : folder.name;
    };

    const setOptions = (select, placeholder, options) => {
        if (!select) return;
        const current = select.value;
        select.replaceChildren(new Option(placeholder, ""));
        options.forEach(([label, value]) => select.add(new Option(label, value)));
        if ([...select.options].some((option) => option.value === current)) select.value = current;
    };

    const renderSelectors = () => {
        const byName = [...folders].sort((a, b) => folderPath(a).localeCompare(folderPath(b), "vi"));
        setOptions(folderParent, "Thư mục gốc", byName.map((folder) => [folderPath(folder), String(folder.id)]));
        setOptions(uploadSelect, "Không có thư mục", byName.map((folder) => [folderPath(folder), String(folder.id)]));
        setOptions(moveSelect, "Không có thư mục", byName.map((folder) => [folderPath(folder), String(folder.id)]));
        if (moveSelect && activeFileFolderId !== null) moveSelect.value = String(activeFileFolderId);
        if (fileFilter) {
            const current = fileFilter.value;
            fileFilter.replaceChildren(new Option("Tất cả thư mục", "all"), new Option("Không có thư mục", "root"));
            byName.forEach((folder) => fileFilter.add(new Option(folderPath(folder), String(folder.id))));
            if ([...fileFilter.options].some((option) => option.value === current)) fileFilter.value = current;
        }
    };

    const showFolderError = (error) => window.alert(error.message);

    const refresh = async () => {
        try {
            const result = await request("api/folders.php");
            folders = result.folders;
            renderSelectors();
            renderTree();
            renderFolderBrowser(window.fileShareFiles || []);
            window.fileShareFolders = folders;
            document.dispatchEvent(new CustomEvent("folders:loaded", { detail: folders }));
            if (openedFolderId !== null) await openFolder(openedFolderId);
        } catch (error) {
            list.textContent = error.message;
        }
    };

    const moveFile = async (fileId, folderId) => {
        await request("api/move_file.php", {
            method: "POST",
            body: { file_id: fileId, folder_id: folderId },
        });
        document.dispatchEvent(new CustomEvent("files:refresh"));
        await refresh();
    };

    const renderFilePicker = async (folder, item) => {
        if (item.querySelector(".folder-file-picker")) return;
        try {
            const data = await request("api/files.php");
            const available = data.files.filter((file) => file.folder_id !== folder.id);
            if (available.length === 0) {
                window.alert("Không còn tệp nào khác để thêm vào thư mục này.");
                return;
            }
            const picker = document.createElement("div");
            picker.className = "folder-file-picker";
            const select = document.createElement("select");
            select.className = "text-input";
            select.setAttribute("aria-label", `Chọn tệp thêm vào ${folder.name}`);
            select.add(new Option("Chọn tệp đã tải lên...", ""));
            available.forEach((file) => select.add(new Option(file.file_name, String(file.id))));
            const confirm = document.createElement("button");
            confirm.type = "button";
            confirm.className = "primary-button";
            confirm.textContent = "Thêm vào thư mục";
            const cancel = document.createElement("button");
            cancel.type = "button";
            cancel.className = "secondary-button";
            cancel.textContent = "Hủy";
            cancel.addEventListener("click", () => picker.remove());
            confirm.addEventListener("click", async () => {
                if (!select.value) {
                    window.alert("Hãy chọn một tệp.");
                    return;
                }
                confirm.disabled = true;
                try {
                    await moveFile(Number(select.value), folder.id);
                    picker.remove();
                } catch (error) {
                    showFolderError(error);
                    confirm.disabled = false;
                }
            });
            picker.append(select, confirm, cancel);
            item.append(picker);
            select.focus();
        } catch (error) {
            showFolderError(error);
        }
    };

    const renderFolderNode = (folder, depth = 0) => {
        const item = document.createElement("article");
        item.className = "folder-item";
        item.dataset.folderId = String(folder.id);
        item.style.setProperty("--folder-depth", String(depth));

        const copy = document.createElement("div");
        copy.className = "folder-copy";
        const open = document.createElement("button");
        open.type = "button";
        open.className = "folder-open";
        open.textContent = folder.name;
        open.setAttribute("aria-label", `Mở thư mục ${folder.name}`);
        open.addEventListener("click", () => openFolder(folder.id));
        open.draggable = true;
        open.addEventListener("dragstart", (event) => {
            draggedFolderId = folder.id;
            event.dataTransfer?.setData("text/plain", `folder:${folder.id}`);
            if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
        });
        open.addEventListener("dragend", () => { draggedFolderId = null; });

        const details = document.createElement("span");
        details.className = "folder-label";
        const count = document.createElement("small");
        count.textContent = `${folder.file_count} tệp · ${folder.folder_count} thư mục con`;
        details.append(open, count);
        copy.append(details);

        const actions = document.createElement("div");
        actions.className = "folder-actions";
        const addChild = document.createElement("button");
        addChild.type = "button";
        addChild.className = "secondary-button folder-add-files";
        addChild.textContent = "Tạo thư mục con";
        addChild.addEventListener("click", () => {
            folderParent.value = String(folder.id);
            document.getElementById("folder-name").focus();
        });
        const addFiles = document.createElement("button");
        addFiles.type = "button";
        addFiles.className = "secondary-button folder-add-files";
        addFiles.textContent = "Thêm tệp";
        addFiles.addEventListener("click", () => renderFilePicker(folder, item));
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "danger-button folder-delete";
        remove.textContent = "Xóa";
        remove.addEventListener("click", async () => {
            try {
                await post({ action: "delete", folder_id: folder.id });
                if (openedFolderId === folder.id) closeFolder();
                await refresh();
                document.dispatchEvent(new CustomEvent("folders:changed"));
            } catch (error) {
                showFolderError(error);
            }
        });
        actions.append(addChild, addFiles, remove);
        item.append(copy, actions);

        item.addEventListener("dragover", (event) => {
            if (draggedFileId !== null || draggedFolderId !== null) {
                event.preventDefault();
                item.classList.add("folder-drop-target");
            }
        });
        item.addEventListener("dragleave", (event) => {
            if (!item.contains(event.relatedTarget)) item.classList.remove("folder-drop-target");
        });
        item.addEventListener("drop", async (event) => {
            event.preventDefault();
            item.classList.remove("folder-drop-target");
            try {
                if (draggedFileId !== null) {
                    await moveFile(draggedFileId, folder.id);
                } else if (draggedFolderId !== null) {
                    await post({ action: "move", folder_id: draggedFolderId, parent_id: folder.id });
                    draggedFolderId = null;
                    await refresh();
                }
            } catch (error) {
                showFolderError(error);
            }
        });

        const children = folders.filter((entry) => entry.parent_id === folder.id);
        if (children.length > 0) {
            const childList = document.createElement("div");
            childList.className = "folder-children";
            children.forEach((child) => childList.append(renderFolderNode(child, depth + 1)));
            item.append(childList);
        }
        return item;
    };

    const renderTree = () => {
        list.replaceChildren();
        if (folders.length === 0) {
            const empty = document.createElement("p");
            empty.className = "folder-empty";
            empty.textContent = "Bạn chưa tạo thư mục nào.";
            list.append(empty);
            return;
        }
        folders.filter((folder) => folder.parent_id === null).forEach((folder) => list.append(renderFolderNode(folder)));
    };

    const renderFolderBrowser = (files) => {
        if (!folderBrowser) return;
        folderBrowser.replaceChildren();
        const root = document.createElement("div");
        root.className = "file-folder-root";
        root.dataset.folderId = "root";
        root.textContent = "Thư mục gốc";
        folderBrowser.append(root);
        folders.forEach((folder) => {
            const chip = document.createElement("button");
            chip.type = "button";
            chip.className = "file-folder-chip";
            chip.dataset.folderId = String(folder.id);
            chip.textContent = folderPath(folder);
            chip.title = "Thả tệp vào thư mục này";
            folderBrowser.append(chip);
        });
        [root, ...folderBrowser.querySelectorAll(".file-folder-chip")].forEach((target) => {
            target.addEventListener("dragover", (event) => {
                if (draggedFileId !== null) {
                    event.preventDefault();
                    target.classList.add("folder-drop-target");
                }
            });
            target.addEventListener("dragleave", () => target.classList.remove("folder-drop-target"));
            target.addEventListener("drop", async (event) => {
                event.preventDefault();
                target.classList.remove("folder-drop-target");
                const folderId = target.dataset.folderId === "root" ? null : Number(target.dataset.folderId);
                try {
                    await moveFile(draggedFileId, folderId);
                } catch (error) {
                    showFolderError(error);
                }
            });
        });
        const fileRows = [...document.querySelectorAll("#all-file-rows .file-row")];
        fileRows.forEach((row) => {
            row.draggable = true;
            row.addEventListener("dragstart", (event) => {
                draggedFileId = Number(row.dataset.fileId);
                event.dataTransfer?.setData("text/plain", `file:${row.dataset.fileId}`);
                if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
            });
            row.addEventListener("dragend", () => { draggedFileId = null; });
        });
        if (files.length === 0) folderBrowser.hidden = true;
        else folderBrowser.hidden = false;
    };

    const renderOpenedFiles = (files) => {
        contentRows.replaceChildren();
        if (files.length === 0) {
            const row = document.createElement("tr");
            const cell = document.createElement("td");
            cell.colSpan = 5;
            cell.textContent = "Thư mục này chưa có tệp nào.";
            row.append(cell);
            contentRows.append(row);
            return;
        }
        files.forEach((file) => {
            const row = document.createElement("tr");
            row.className = "folder-file-row";
            row.dataset.fileId = String(file.id);
            row.tabIndex = 0;
            const nameCell = document.createElement("td");
            const name = document.createElement("strong");
            name.textContent = file.file_name;
            const size = document.createElement("small");
            const sizeBytes = file.file_size;
            size.textContent = sizeBytes < 1024 * 1024
                ? `${Math.max(1, Math.round(sizeBytes / 1024))} KB`
                : `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`;
            nameCell.append(name, document.createElement("br"), size);
            const date = document.createElement("td");
            date.textContent = new Intl.DateTimeFormat("vi-VN").format(new Date(file.created_at));
            const accessCell = document.createElement("td");
            const badge = document.createElement("span");
            badge.className = `access-badge ${file.access_type === "public" ? "public" : "private"}${file.is_missing ? " missing" : ""}`;
            badge.append(document.createElement("i"), document.createTextNode(file.is_missing ? "Thiếu tệp" : file.access_type === "public" ? "Công khai" : "Riêng tư"));
            accessCell.append(badge);
            const downloads = document.createElement("td");
            downloads.textContent = `${file.download_count} lượt`;
            const action = document.createElement("td");
            action.textContent = "›";
            row.append(nameCell, date, accessCell, downloads, action);
            contentRows.append(row);
        });
    };

    const openFolder = async (folderId) => {
        const folder = folders.find((item) => item.id === Number(folderId));
        if (!folder) return;
        openedFolderId = folder.id;
        managerPanel.hidden = true;
        contentPanel.hidden = false;
        document.getElementById("opened-folder-name").textContent = folder.name;
        document.getElementById("opened-folder-meta").textContent = `${folder.file_count} tệp · ${folder.folder_count} thư mục con`;
        const childrenContainer = document.getElementById("opened-folder-children");
        childrenContainer.replaceChildren();
        folders.filter((item) => item.parent_id === folder.id).forEach((child) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "folder-child-chip";
            button.textContent = `${child.name} · ${child.file_count} tệp`;
            button.addEventListener("click", () => openFolder(child.id));
            childrenContainer.append(button);
        });
        try {
            const result = await request(`api/files.php?folder_id=${encodeURIComponent(folder.id)}`);
            renderOpenedFiles(result.files);
        } catch (error) {
            contentRows.replaceChildren();
            const row = document.createElement("tr");
            const cell = document.createElement("td");
            cell.colSpan = 5;
            cell.textContent = error.message;
            row.append(cell);
            contentRows.append(row);
        }
    };

    const closeFolder = () => {
        openedFolderId = null;
        contentPanel.hidden = true;
        managerPanel.hidden = false;
    };

    const moveToRoot = async (event) => {
        event.preventDefault();
        rootDrop.classList.remove("folder-drop-target");
        try {
            if (draggedFileId !== null) {
                await moveFile(draggedFileId, null);
            } else if (draggedFolderId !== null) {
                await post({ action: "move", folder_id: draggedFolderId, parent_id: null });
                draggedFolderId = null;
                await refresh();
            }
        } catch (error) {
            showFolderError(error);
        }
    };

    rootDrop.addEventListener("dragover", (event) => {
        if (draggedFileId !== null || draggedFolderId !== null) {
            event.preventDefault();
            rootDrop.classList.add("folder-drop-target");
        }
    });
    rootDrop.addEventListener("dragleave", () => rootDrop.classList.remove("folder-drop-target"));
    rootDrop.addEventListener("drop", moveToRoot);

    document.getElementById("folder-back-button").addEventListener("click", closeFolder);
    document.getElementById("create-folder-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const input = document.getElementById("folder-name");
        try {
            await post({ action: "create", name: input.value.trim(), parent_id: folderParent.value || null });
            input.value = "";
            folderParent.value = "";
            await refresh();
            document.dispatchEvent(new CustomEvent("folders:changed"));
        } catch (error) {
            showFolderError(error);
        }
    });

    contentRows.addEventListener("click", (event) => {
        const row = event.target.closest("tr[data-file-id]");
        if (!row) return;
        const originalRow = document.querySelector(`#all-file-rows [data-file-id="${row.dataset.fileId}"]`);
        originalRow?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    });
    contentRows.addEventListener("keydown", (event) => {
        if (event.key !== "Enter" && event.key !== " ") return;
        const row = event.target.closest("tr[data-file-id]");
        if (!row) return;
        event.preventDefault();
        const originalRow = document.querySelector(`#all-file-rows [data-file-id="${row.dataset.fileId}"]`);
        originalRow?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    });

    document.addEventListener("files:rendered", (event) => {
        window.fileShareFiles = event.detail || [];
        renderFolderBrowser(window.fileShareFiles);
    });
    document.addEventListener("files:refresh", () => {
        if (openedFolderId !== null) openFolder(openedFolderId);
    });
    document.addEventListener("folders:refresh", refresh);
    document.addEventListener("file:detail-opened", (event) => {
        activeFileFolderId = event.detail.folder_id;
        if (moveSelect) moveSelect.value = activeFileFolderId === null ? "" : String(activeFileFolderId);
    });
    refresh();
})();
