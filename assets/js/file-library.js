(() => {
    const searchInput = document.getElementById("file-search");
    const statusFilter = document.getElementById("file-status-filter");
    const folderFilter = document.getElementById("file-folder-filter");
    const rows = document.getElementById("all-file-rows");
    if (!searchInput || !statusFilter || !rows) return;

    const applyFilters = () => {
        const query = searchInput.value.trim().toLocaleLowerCase("vi");
        const status = statusFilter.value;
        const folder = folderFilter.value;
        rows.querySelectorAll(".file-row").forEach((row) => {
            const matchesName = row.dataset.filename.toLocaleLowerCase("vi").includes(query);
            const matchesStatus = status === "all"
                || (status === "public" && row.dataset.shareStatus === "public")
                || (status === "private" && row.dataset.shareStatus === "private")
                || (status === "revoked" && row.dataset.revoked === "true")
                || (status === "expired" && row.dataset.expired === "true")
                || (status === "missing" && row.dataset.missing === "true");
            const matchesFolder = folder === "all" || row.dataset.folderId === folder;
            row.hidden = !matchesName || !matchesStatus || !matchesFolder;
        });
    };

    searchInput.addEventListener("input", applyFilters);
    statusFilter.addEventListener("change", applyFilters);
    folderFilter.addEventListener("change", applyFilters);
    document.addEventListener("files:rendered", applyFilters);
    document.addEventListener("folders:loaded", applyFilters);
})();
