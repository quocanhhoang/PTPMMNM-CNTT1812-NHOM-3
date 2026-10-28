(() => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const list = document.getElementById("folder-list");
    const uploadSelect = document.getElementById("upload-folder");
    const moveSelect = document.getElementById("move-folder");
    const fileFilter = document.getElementById("file-folder-filter");
    let activeFileFolderId = null;
    let openedFolderId = null;

    const moveFile = async (fileId, folderId) => {
        const response = await fetch("api/move_file.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
            body: JSON.stringify({ file_id: fileId, folder_id: folderId }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Không thể chuyển tệp.");
        await refresh();
        document.dispatchEvent(new CustomEvent("files:refresh"));
    };

    const enableFileDrop = (target, folderId) => {
        target.addEventListener("dragover", (event) => {
            if (event.dataTransfer.types.includes("application/x-fileshare-file")) {
                event.preventDefault();
                event.dataTransfer.dropEffect = "move";
                target.classList.add("folder-drop-target");
            }
        });
        target.addEventListener("dragleave", () => target.classList.remove("folder-drop-target"));
        target.addEventListener("drop", async (event) => {
            const fileId = Number(event.dataTransfer.getData("application/x-fileshare-file"));
            if (!fileId) return;
            event.preventDefault();
            target.classList.remove("folder-drop-target");
            try {
                await moveFile(fileId, folderId);
            } catch (error) {
                window.alert(error.message);
            }
        });
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
            }
            result.folders.forEach((folder) => {
                const item = document.createElement("article");
                item.className = "folder-item";
                enableFileDrop(item, folder.id);
                const copy = document.createElement("div");
                const name = document.createElement("strong");
                name.textContent = folder.name;
                name.className = "folder-open";
                name.tabIndex = 0;
                name.setAttribute("role", "button");
                name.setAttribute("aria-expanded", String(openedFolderId === folder.id));
                const toggleFolder = () => {
                    openedFolderId = openedFolderId === folder.id ? null : folder.id;
                    refresh();
                };
                name.addEventListener("click", toggleFolder);
                name.addEventListener("keydown", (event) => {
                    if (event.key === "Enter" || event.key === " ") {
                        event.preventDefault();
                        toggleFolder();
                    }
                });
                const count = document.createElement("small");
                count.textContent = `${folder.file_count} tệp`;
                copy.append(name, count);
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
                                const moveResponse = await fetch("api/move_file.php", {
                                    method: "POST",
                                    headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
                                    body: JSON.stringify({ file_id: Number(select.value), folder_id: folder.id }),
                                });
                                const moved = await moveResponse.json();
                                if (!moveResponse.ok) throw new Error(moved.error || "Không thể thêm tệp vào thư mục.");
                                document.dispatchEvent(new CustomEvent("folders:refresh"));
                                document.dispatchEvent(new CustomEvent("files:refresh"));
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
                actions.append(addFiles, remove);
                item.append(copy, actions);
                list.append(item);

                if (openedFolderId === folder.id) {
                    const contents = document.createElement("section");
                    contents.className = "folder-contained-files";
                    enableFileDrop(contents, folder.id);
                    const heading = document.createElement("h3");
                    heading.textContent = `Tệp trong “${folder.name}”`;
                    contents.append(heading);
                    const loading = document.createElement("p");
                    loading.className = "folder-empty";
                    loading.textContent = "Đang tải danh sách tệp...";
                    contents.append(loading);
                    list.append(contents);

                    fetch(`api/files.php?folder_id=${encodeURIComponent(folder.id)}`)
                        .then(async (response) => {
                            const data = await response.json();
                            if (!response.ok) throw new Error(data.error || "Không thể tải danh sách tệp.");
                            return data.files;
                        })
                        .then((files) => {
                            contents.replaceChildren(heading);
                            if (files.length === 0) {
                                const empty = document.createElement("p");
                                empty.className = "folder-empty";
                                empty.textContent = "Thư mục này chưa có tệp.";
                                contents.append(empty);
                                return;
                            }
                            const fileList = document.createElement("ul");
                            fileList.className = "folder-contained-file-list";
                            files.forEach((file) => {
                                const row = document.createElement("li");
                                row.draggable = true;
                                row.addEventListener("dragstart", (event) => {
                                    event.dataTransfer.setData("application/x-fileshare-file", String(file.id));
                                    event.dataTransfer.effectAllowed = "move";
                                });
                                const fileName = document.createElement("span");
                                fileName.textContent = file.file_name;
                                const fileSize = document.createElement("small");
                                fileSize.textContent = `${file.file_size} B`;
                                row.append(fileName, fileSize);
                                fileList.append(row);
                            });
                            contents.append(fileList);
                        })
                        .catch((error) => {
                            const message = document.createElement("p");
                            message.className = "folder-empty is-error";
                            message.textContent = error.message;
                            contents.replaceChildren(heading, message);
                        });
                }
            });
            window.fileShareFolders = result.folders;
            document.dispatchEvent(new CustomEvent("folders:loaded", { detail: result.folders }));
        } catch (error) {
            if (list) list.textContent = error.message;
        }
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
    document.addEventListener("file:detail-opened", (event) => {
        activeFileFolderId = event.detail.folder_id;
        if (moveSelect) moveSelect.value = activeFileFolderId === null ? "" : String(activeFileFolderId);
    });
    refresh();
})();
