<?php
declare(strict_types=1);
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <title>Đăng nhập — FileShare</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand auth-brand" href="login.php"><span class="brand-mark">F</span><span>File<span class="brand-accent">Share</span></span></a>
        <h1 id="auth-title">Đăng nhập</h1>
        <p id="auth-subtitle">Đăng nhập để quản lý tệp và thư mục của bạn.</p>
        <form id="auth-form">
            <label id="name-label" hidden for="auth-name">Họ và tên</label>
            <input id="auth-name" name="full_name" autocomplete="name" placeholder="Họ và tên" hidden>
            <label for="auth-email">Email</label>
            <input id="auth-email" name="email" type="email" autocomplete="email" required>
            <label for="auth-password">Mật khẩu</label>
            <input id="auth-password" name="password" type="password" autocomplete="current-password" minlength="10" required>
            <button class="primary-button" type="submit" id="auth-submit">Đăng nhập</button>
        </form>
        <p class="auth-switch"><span id="auth-switch-copy">Chưa có tài khoản?</span> <button type="button" id="auth-switch">Tạo tài khoản</button></p>
        <p class="auth-error" id="auth-error" role="alert"></p>
    </main>
    <script src="assets/js/auth.js"></script>
</body>
</html>
