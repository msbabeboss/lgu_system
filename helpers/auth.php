<?php
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect(BASE_URL . '/home.php');
    }
}

function requireRole(string $role): void {
    requireLogin();
    if ($_SESSION['user_role'] !== $role) {
        redirect(BASE_URL . '/home.php?error=unauthorized');
    }
}

function requireAnyRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles)) {
        redirect(BASE_URL . '/home.php?error=unauthorized');
    }
}

function isSuperAdmin(): bool {
    return isLoggedIn() && $_SESSION['user_role'] === 'super_admin';
}

function isBarangayAdmin(): bool {
    return isLoggedIn() && $_SESSION['user_role'] === 'barangay_admin';
}

function currentUser(): array {
    return [
        'id'          => $_SESSION['user_id'] ?? 0,
        'name'        => $_SESSION['user_name'] ?? '',
        'role'        => $_SESSION['user_role'] ?? '',
        'barangay_id' => $_SESSION['barangay_id'] ?? null,
        'email'       => $_SESSION['user_email'] ?? '',
        'photo'       => $_SESSION['user_photo'] ?? null,
    ];
}

function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_name']   = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_role']   = $user['role'];
    $_SESSION['barangay_id'] = $user['barangay_id'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['user_photo']  = $user['profile_photo'] ?? null;
    $_SESSION['must_change_password'] = (bool)$user['must_change_password'];
}

function logoutUser(): void {
    // Log logout time
    if (isset($_SESSION['login_log_id'])) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE login_logs SET logout_time = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['login_log_id']]);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
}
