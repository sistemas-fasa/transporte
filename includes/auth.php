<?php
require_once __DIR__ . '/session_db.php';
require_once __DIR__ . '/../config/database.php';

if (!dbSessionStart()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ---- Funciones de autenticación existentes ----

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function isAdmin(): bool {
    return isLoggedIn() && $_SESSION['user_rol'] === 'admin';
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . '/chofer/panel.php');
        exit;
    }
}

function requireChofer(): void {
    requireLogin();
    if (isAdmin()) {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }
}

function requireChoferAccess(string $codigo): void {
    requireChofer();
    // Solo verificar permisos granulares si el sistema de roles esta configurado
    if (!empty($_SESSION['user_permissions'])) {
        if (!hasPermission($codigo)) {
            header('HTTP/1.0 403 Forbidden');
            header('Location: ' . BASE_URL . '/chofer/panel.php');
            exit;
        }
    }
}

function getCurrentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function getCurrentUserName(): string {
    return $_SESSION['user_nombre'] ?? $_SESSION['username'] ?? 'Usuario';
}

function getCurrentUserRol(): string {
    return $_SESSION['user_rol'] ?? '';
}

function getChoferIdFromUser(): ?int {
    return $_SESSION['id_chofer'] ?? null;
}

function logout(): void {
    dbSessionLogout();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ---- Nuevo sistema de permisos ----

function hasPermission(string $codigo): bool {
    if (!isLoggedIn()) return false;
    // Admin (rol antiguo) tiene todos los permisos
    if ($_SESSION['user_rol'] === 'admin') return true;
    return in_array($codigo, $_SESSION['user_permissions'] ?? []);
}

function requirePermission(string $codigo): void {
    requireLogin();
    if (!hasPermission($codigo)) {
        header('HTTP/1.0 403 Forbidden');
        if (isAdmin()) {
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
        } else {
            header('Location: ' . BASE_URL . '/chofer/panel.php');
        }
        exit;
    }
}

function hasRole(string $roleName): bool {
    if (!isLoggedIn()) return false;
    foreach ($_SESSION['user_roles'] ?? [] as $role) {
        if (strtolower($role['nombre']) === strtolower($roleName)) return true;
    }
    return false;
}

function getCurrentUserRoles(): array {
    return $_SESSION['user_roles'] ?? [];
}

// ---- CSRF Protection ----

function generarTokenCSRF(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificarTokenCSRF(?string $token): bool {
    if (empty($token)) return false;
    if (!isset($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCSRF(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!verificarTokenCSRF($token)) {
        die('Error de validación CSRF. Intente nuevamente.');
    }
}
