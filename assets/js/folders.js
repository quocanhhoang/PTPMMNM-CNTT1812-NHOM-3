(() => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const list = document.getElementById("folder-list");
    const uploadSelect = document.getElementById("upload-folder");
    const moveSelect = document.getElementById("move-folder");
    const fileFilter = document.getElementById("file-folder-filter");
    const fileFolderBrowser = document.getElementById("file-folder-browser");
    let activeFileFolderId = null;
    let draggedFile = null;
    let draggedFolder = null;
    let foldersCache = [];

    const moveFile = async (fileId, folderId) => {
        const response = await fetch("api/move_file.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
            body: JSON.stringify({ file_id: Number(fileId), folder_id: folderId }),
        });
        const moved = await response.json();
        if (!response.ok) throw new Error(moved.error || "Không thể chuyển tệp.");
        document.dispatchEvent(new CustomEvent("folders:refresh"));
        document.dispatchEvent(new CustomEvent("files:refresh"));
    };

    const moveFolder = async (folderId, parentId) => {
        await request({ action: "move", folder_id: folderId, parent_id: parentId });
        await refresh();
        document.dispatchEvent(new CustomEvent("files:refresh"));
    };

    const isFolderDescendant = (folderId, ancestorId) => {
        let current = foldersCache.find((folder) => folder.id === folderId);
        while (current?.parent_id != null) {
            if (current.parent_id === ancestorId) return true;
            current = foldersCache.find((folder) => folder.id === current.parent_id);
        }
        return false;
    };

    const request = async (body) => {
        const response = await fetch("api/folders.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
            body: JSON.stringify(body),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Không thể quản lý thư mục.");
        return result;
    };

    const refresh = async () => {
        try {
            const response = await fetch("api/folders.php");
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || "Không thể tải thư mục.");
            foldersCache = result.folders;
            const options = (select, placeholder) => {
                if (!select) return;
                const current = select.value;
                select.replaceChildren(new Option(placeholder, ""));
                result.folders.forEach((folder) => select.add(new Option(folder.name, String(folder.id))));
                if ([...select.options].some((option) => option.value === current)) select.value = current;
            };
            options(uploadSelect, "Không có thư mục");
            options(moveSelect, "Không có thư mục");
            if (moveSelect && activeFileFolderId !== null) moveSelect.value = String(activeFileFolderId);
            if (fileFilter) {
                const currentFilter = fileFilter.value;
                fileFilter.replaceChildren(new Option("Tất cả thư mục", "all"), new Option("Không có thư mục", "root"));
                result.folders.forEach((folder) => fileFilter.add(new Option(folder.name, String(folder.id))));
                if ([...fileFilter.options].some((option) => option.value === currentFilter)) fileFilter.value = currentFilter;
            }
            list.replaceChildren();
            if (result.folders.length === 0) {
                const empty = document.createElement("p");
                empty.className = "folder-empty";
                empty.textContent = "Bạn chưa tạo thư mục nào.";
                list.append(empty);
            } else {
                renderFolderBranch(null, list);
            }
            renderFileFolderBrowser();
            window.fileShareFolders = result.folders;
            document.dispatchEvent(new CustomEvent("folders:loaded", { detail: result.folders }));
        } catch (error) {
            if (list) list.textContent = error.message;
        }
    };

    const renderFolderBranch = (parentId, container) => {
        foldersCache.filter((folder) => folder.parent_id === parentId).forEach((folder) => {
                const item = document.createElement("article");
                item.className = "folder-item";
                item.dataset.folderId = String(folder.id);
                item.dataset.parentId = folder.parent_id === null ? "root" : String(folder.parent_id);
                const copy = document.createElement("div");
                copy.className = "folder-copy";
                const toggle = document.createElement("button");
                toggle.type = "button";
                toggle.className = "folder-toggle";
                toggle.textContent = folder.folder_count > 0 ? "▸" : "·";
                toggle.setAttribute("aria-label", `Mở thư mục con của ${folder.name}`);
                const name = document.createElement("strong");
                name.textContent = folder.name;
                name.className = "folder-open";
                name.tabIndex = 0;
                name.setAttribute("role", "button");
                name.setAttribute("aria-expanded", "false");
                name.draggable = true;
                name.addEventListener("dragstart", (event) => {
                    draggedFolder = folder;
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = "move";
                        event.dataTransfer.setData("text/plain", `folder:${folder.id}`);
                    }
                });
                name.addEventListener("dragend", () => { draggedFolder = null; });
                const count = document.createElement("small");
                count.textContent = `${folder.file_count} tệp · ${folder.folder_count} thư mục con`;
                const label = document.createElement("div");
                label.className = "folder-label";
                label.append(name, count);
                copy.append(toggle, label);
                const openFolder = async () => {
                    const existing = item.querySelector(":scope > .folder-contents");
                    if (existing) {
                        existing.remove();
                        name.setAttribute("aria-expanded", "false");
                        toggle.textContent = folder.folder_count > 0 ? "▸" : "·";
                        return;
                    }
                    try {
                        const response = await fetch(`api/files.php?folder_id=${encodeURIComponent(folder.id)}`);
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.error || "Không thể tải tệp trong thư mục.");
                        const contents = document.createElement("div");
                        contents.className = "folder-contents";
                        renderFolderBranch(folder.id, contents);
                        if (data.files.length === 0 && folder.folder_count === 0) {
                            const empty = document.createElement("p");
                            empty.className = "folder-empty";
                            empty.textContent = "Thư mục này chưa có tệp hoặc thư mục con.";
                            contents.append(empty);
                        }
                        if (data.files.length > 0) {
                            data.files.forEach((file) => {
                                const fileRow = document.createElement("div");
                                fileRow.className = "folder-file-row";
                                fileRow.draggable = true;
                                fileRow.addEventListener("dragstart", (event) => {
                                    draggedFile = file;
                                    if (event.dataTransfer) {
                                        event.dataTransfer.effectAllowed = "move";
                                        event.dataTransfer.setData("text/plain", `file:${file.id}`);
                                    }
                                });
                                fileRow.addEventListener("dragend", () => { draggedFile = null; });
                                const fileName = document.createElement("span");
                                fileName.textContent = file.file_name;
                                const fileSize = document.createElement("small");
                                fileSize.textContent = `${(file.file_size / (1024 * 1024)).toFixed(1)} MB`;
                                fileRow.append(fileName, fileSize);
                                contents.append(fileRow);
                            });
                        }
                        item.append(contents);
                        name.setAttribute("aria-expanded", "true");
                        toggle.textContent = "▾";
                    } catch (error) { window.alert(error.message); }
                };
                name.addEventListener("click", openFolder);
                toggle.addEventListener("click", openFolder);
                name.addEventListener("keydown", (event) => {
                    if (event.key === "Enter" || event.key === " ") { event.preventDefault(); openFolder(); }
                });
                item.addEventListener("dragover", (event) => {
                    const validFile = draggedFile && String(draggedFile.folder_id) !== String(folder.id);
                    const validFolder = draggedFolder && draggedFolder.id !== folder.id && !isFolderDescendant(folder.id, draggedFolder.id);
                    if (validFile || validFolder) {
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
                    if (draggedFolder && draggedFolder.id !== folder.id && !isFolderDescendant(folder.id, draggedFolder.id)) {
                        const accepted = window.confirm(`Bạn có chấp nhận chuyển thư mục con “${draggedFolder.name}” vào thư mục “${folder.name}” không?`);
                        if (!accepted) return;
                        try { await moveFolder(draggedFolder.id, folder.id); }
                        catch (error) { window.alert(error.message); }
                        finally { draggedFolder = null; }
                    } else if (draggedFile && String(draggedFile.folder_id) !== String(folder.id)) {
                        const accepted = window.confirm(`Bạn có chấp nhận chuyển tệp “${draggedFile.file_name}” sang thư mục “${folder.name}” không?`);
                        if (!accepted) return;
                        try { await moveFile(draggedFile.id, folder.id); }
                        catch (error) { window.alert(error.message); }
                        finally { draggedFile = null; }
                    }
                });
                const remove = document.createElement("button");
                remove.type = "button";
                remove.className = "danger-button folder-delete";
                remove.textContent = "Xóa thư mục";
                remove.addEventListener("click", async () => {
                    try {
                        await request({ action: "delete", folder_id: folder.id });
                        await refresh();
                        document.dispatchEvent(new CustomEvent("folders:changed"));
                    } catch (error) {
                        window.alert(error.message);
                    }
                });
                const actions = document.createElement("div");
                actions.className = "folder-actions";
                const addChild = document.createElement("button");
                addChild.type = "button";
                addChild.className = "secondary-button folder-add-files";
                addChild.textContent = "Tạo thư mục con";
                addChild.addEventListener("click", async () => {
                    const childName = window.prompt(`Tên thư mục con trong “${folder.name}”:`);
                    if (childName === null || !childName.trim()) return;
                    try {
                        await request({ action: "create", name: childName.trim(), parent_id: folder.id });
                        await refresh();
                        document.dispatchEvent(new CustomEvent("folders:changed"));
                    } catch (error) { window.alert(error.message); }
                });
                const addFiles = document.createElement("button");
                addFiles.type = "button";
                addFiles.className = "secondary-button folder-add-files";
                addFiles.textContent = "Thêm tệp đã tải lên";
                addFiles.addEventListener("click", async () => {
                    if (item.querySelector(".folder-file-picker")) return;
                    try {
                        const response = await fetch("api/files.php");
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.error || "Không thể tải danh sách tệp.");
                        const available = data.files.filter((file) => String(file.folder_id) !== String(folder.id));
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
                                const selectedFile = available.find((file) => String(file.id) === select.value);
                                if (!window.confirm(`Bạn có chấp nhận chuyển tệp “${selectedFile.file_name}” sang thư mục “${folder.name}” không?`)) {
                                    confirm.disabled = false;
                                    return;
                                }
                                await moveFile(select.value, folder.id);
                                picker.remove();
                            } catch (error) {
                                window.alert(error.message);
                                confirm.disabled = false;
                            }
                        });
                        picker.append(select, confirm, cancel);
                        item.append(picker);
                        select.focus();
                    } catch (error) {
                        window.alert(error.message);
                    }
                });
                actions.append(addChild, addFiles, remove);
                item.append(copy, actions);
                container.append(item);
        });
    };

    const renderFileFolderBrowser = () => {
        if (!fileFolderBrowser) return;
        fileFolderBrowser.replaceChildren();
        const title = document.createElement("h2");
        title.textContent = "Thư mục của tôi · kéo tệp vào thư mục để chuyển";
        fileFolderBrowser.append(title);
        const rootDrop = document.createElement("div");
        rootDrop.className = "file-folder-root";
        rootDrop.textContent = "Không có thư mục · thả vào đây để chuyển ra ngoài";
        rootDrop.addEventListener("dragover", (event) => {
            if ((draggedFile && draggedFile.folder_id !== null) || (draggedFolder && draggedFolder.parent_id !== null)) event.preventDefault();
        });
        rootDrop.addEventListener("drop", async (event) => {
            event.preventDefault();
            if (draggedFile && draggedFile.folder_id !== null && window.confirm(`Bạn có chấp nhận chuyển tệp “${draggedFile.file_name}” ra ngoài thư mục không?`)) {
                try { await moveFile(draggedFile.id, null); } catch (error) { window.alert(error.message); }
            } else if (draggedFolder && draggedFolder.parent_id !== null && window.confirm(`Bạn có chấp nhận chuyển thư mục “${draggedFolder.name}” ra ngoài thư mục không?`)) {
                try { await moveFolder(draggedFolder.id, null); } catch (error) { window.alert(error.message); }
            }
        });
        fileFolderBrowser.append(rootDrop);
        const addBranch = (parentId, depth = 0) => {
            foldersCache.filter((folder) => folder.parent_id === parentId).forEach((folder) => {
                const row = document.createElement("div");
                row.className = "file-folder-chip";
                row.style.marginLeft = `${depth * 18}px`;
                row.textContent = `📁 ${folder.name} · ${folder.file_count} tệp`;
                row.addEventListener("dragover", (event) => {
                    const validFolder = draggedFolder && draggedFolder.id !== folder.id && !isFolderDescendant(folder.id, draggedFolder.id);
                    const validFile = draggedFile && String(draggedFile.folder_id) !== String(folder.id);
                    if (validFolder || validFile) { event.preventDefault(); row.classList.add("folder-drop-target"); }
                });
                row.addEventListener("dragleave", () => row.classList.remove("folder-drop-target"));
                row.addEventListener("drop", async (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    row.classList.remove("folder-drop-target");
                    if (draggedFolder && draggedFolder.id !== folder.id && !isFolderDescendant(folder.id, draggedFolder.id)) {
                        if (!window.confirm(`Bạn có chấp nhận chuyển thư mục “${draggedFolder.name}” vào thư mục “${folder.name}” không?`)) return;
                        try { await moveFolder(draggedFolder.id, folder.id); } catch (error) { window.alert(error.message); }
                    } else if (draggedFile && String(draggedFile.folder_id) !== String(folder.id)) {
                        if (!window.confirm(`Bạn có chấp nhận chuyển tệp “${draggedFile.file_name}” sang thư mục “${folder.name}” không?`)) return;
                        try { await moveFile(draggedFile.id, folder.id); } catch (error) { window.alert(error.message); }
                    }
                });
                fileFolderBrowser.append(row);
                addBranch(folder.id, depth + 1);
            });
        };
        addBranch(null);
    };

    document.getElementById("create-folder-form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const input = document.getElementById("folder-name");
        try {
            await request({ action: "create", name: input.value.trim() });
            input.value = "";
            await refresh();
            document.dispatchEvent(new CustomEvent("folders:changed"));
        } catch (error) {
            window.alert(error.message);
        }
    });

    document.addEventListener("folders:refresh", refresh);
    document.addEventListener("files:rendered", (event) => {
        document.querySelectorAll(".file-row[data-file-id]").forEach((row) => {
            const file = event.detail?.find((entry) => String(entry.id) === row.dataset.fileId);
            if (!file) return;
            row.draggable = true;
            row.addEventListener("dragstart", () => { draggedFile = file; });
            row.addEventListener("dragend", () => { draggedFile = null; });
        });
    });
    document.addEventListener("file:detail-opened", (event) => {
        activeFileFolderId = event.detail.folder_id;
        if (moveSelect) moveSelect.value = activeFileFolderId === null ? "" : String(activeFileFolderId);
    });
    refresh();
})();
