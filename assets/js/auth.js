(() => {
    const form = document.getElementById("auth-form");
    const switchButton = document.getElementById("auth-switch");
    const nameInput = document.getElementById("auth-name");
    const nameLabel = document.getElementById("name-label");
    const passwordInput = document.getElementById("auth-password");
    const error = document.getElementById("auth-error");
    let registering = false;

    switchButton.addEventListener("click", () => {
        registering = !registering;
        nameInput.hidden = !registering;
        nameLabel.hidden = !registering;
        nameInput.required = registering;
        passwordInput.autocomplete = registering ? "new-password" : "current-password";
        document.getElementById("auth-title").textContent = registering ? "Tạo tài khoản" : "Đăng nhập";
        document.getElementById("auth-subtitle").textContent = registering
            ? "Tài khoản mới được cấp 10 GB dung lượng lưu trữ."
            : "Đăng nhập để quản lý tệp và thư mục của bạn.";
        document.getElementById("auth-submit").textContent = registering ? "Tạo tài khoản" : "Đăng nhập";
        document.getElementById("auth-switch-copy").textContent = registering ? "Đã có tài khoản?" : "Chưa có tài khoản?";
        switchButton.textContent = registering ? "Đăng nhập" : "Tạo tài khoản";
        error.textContent = "";
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        error.textContent = "";
        const data = new FormData(form);
        const button = document.getElementById("auth-submit");
        button.disabled = true;
        try {
            const response = await fetch("api/auth.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    action: registering ? "register" : "login",
                    full_name: data.get("full_name"),
                    email: data.get("email"),
                    password: data.get("password"),
                }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || "Không thể xác thực tài khoản.");
            window.location.href = "index.php";
        } catch (requestError) {
            error.textContent = requestError.message;
        } finally {
            button.disabled = false;
        }
    });
})();
