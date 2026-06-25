<?php
require_once __DIR__ . '/session_db.php';
require_once __DIR__ . '/../config/database.php';

if (!dbSessionStart()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Auto-seed permisos faltantes (una vez por sesion)
if (!isset($_SESSION['_permisos_seeded'])) {
    try {
        $db = getDB();
        $faltantes = [
            ['combustible_importar', 'Importar combustible', 'Combustible'],
            ['viajes_importar', 'Importar viajes', 'Kilometraje'],
            ['choferes_ver', 'Ver choferes', 'Choferes'],
            ['choferes_crear', 'Crear choferes', 'Choferes'],
            ['choferes_editar', 'Editar choferes', 'Choferes'],
            ['choferes_eliminar', 'Eliminar choferes', 'Choferes'],
            ['alertas_ver', 'Ver alertas', 'Alertas'],
            ['empresas_ver', 'Ver empresas', 'Empresas'],
            ['empresas_crear', 'Crear empresas', 'Empresas'],
            ['empresas_editar', 'Editar empresas', 'Empresas'],
            ['empresas_eliminar', 'Eliminar empresas', 'Empresas'],
            ['matafuegos_ver', 'Ver matafuegos', 'Matafuegos'],
            ['matafuegos_crear', 'Crear matafuegos', 'Matafuegos'],
            ['matafuegos_editar', 'Editar matafuegos', 'Matafuegos'],
            ['matafuegos_eliminar', 'Eliminar matafuegos', 'Matafuegos'],
        ];
        $viewPermisos = ['choferes_ver','choferes_crear','choferes_editar','alertas_ver','empresas_ver','matafuegos_ver'];
        foreach ($faltantes as $p) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM permisos WHERE codigo = ?");
            $stmt->execute([$p[0]]);
            if (!$stmt->fetchColumn()) {
                $db->prepare("INSERT INTO permisos (codigo, nombre, modulo) VALUES (?, ?, ?)")->execute($p);
                $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) SELECT 1, id_permiso FROM permisos WHERE codigo = ?")->execute([$p[0]]);
                if (in_array($p[0], $viewPermisos)) {
                    $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) SELECT 2, id_permiso FROM permisos WHERE codigo = ?")->execute([$p[0]]);
                }
            }
        }
        $_SESSION['_permisos_seeded'] = true;
    } catch (Exception $e) {}
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

function _esRoleAdminCapable(array $role): bool {
    if (in_array((int)$role['id_rol'], [1,2,4])) return true;
    $name = strtolower($role['nombre'] ?? '');
    return in_array($name, ['administrador', 'supervisor', 'báscula', 'bascula', 'inspector']);
}

function _esRoleChofer(array $role): bool {
    if ((int)$role['id_rol'] === 3) return true;
    return strtolower($role['nombre'] ?? '') === 'chofer';
}

function _refreshUserPermissions(): void {
    if (empty($_SESSION['user_id'])) return;
    $lastRefresh = $_SESSION['_perm_version'] ?? 0;
    if (time() - $lastRefresh < 30) return;
    try {
        $db = getDB();
        $stmtRoles = $db->prepare("SELECT r.id_rol, r.nombre FROM usuario_rol ur JOIN roles r ON ur.id_rol = r.id_rol WHERE ur.id_usuario = ?");
        $stmtRoles->execute([$_SESSION['user_id']]);
        $_SESSION['user_roles'] = $stmtRoles->fetchAll();
        if (!empty($_SESSION['user_roles'])) {
            $roleIds = array_column($_SESSION['user_roles'], 'id_rol');
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $stmtPerms = $db->prepare("SELECT DISTINCT p.codigo FROM permisos p JOIN rol_permiso rp ON p.id_permiso = rp.id_permiso WHERE rp.id_rol IN ($placeholders)");
            $stmtPerms->execute($roleIds);
            $_SESSION['user_permissions'] = array_column($stmtPerms->fetchAll(), 'codigo');
        } else {
            $_SESSION['user_permissions'] = [];
        }
        $_SESSION['_perm_version'] = time();
    } catch (Exception $e) {}
}

function isAdmin(): bool {
    if (!isLoggedIn()) return false;
    if (!empty($_SESSION['id_chofer'])) return false;
    _refreshUserPermissions();
    foreach ($_SESSION['user_roles'] ?? [] as $role) {
        if (_esRoleAdminCapable($role)) return true;
    }
    return false;
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . getDefaultPage());
        exit;
    }
}

function requireChofer(): void {
    requireLogin();
    if (isAdmin()) {
        header('Location: ' . getDefaultPage());
        exit;
    }
}

function requireChoferAccess(string $codigo): void {
    requireChofer();
    if (!hasPermission($codigo)) {
        header('HTTP/1.0 403 Forbidden');
        header('Location: ' . BASE_URL . '/chofer/panel.php');
        exit;
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
    _refreshUserPermissions();
    return in_array($codigo, $_SESSION['user_permissions'] ?? []);
}

function requirePermission(string $codigo): void {
    requireLogin();
    if (!hasPermission($codigo)) {
        header('HTTP/1.0 403 Forbidden');
        header('Location: ' . getDefaultPage());
        exit;
    }
}

function hasRole(string $roleName): bool {
    if (!isLoggedIn()) return false;
    _refreshUserPermissions();
    foreach ($_SESSION['user_roles'] ?? [] as $role) {
        if (strtolower($role['nombre']) === strtolower($roleName)) return true;
    }
    return false;
}

function getCurrentUserRoles(): array {
    return $_SESSION['user_roles'] ?? [];
}

function esAdminPleno(): bool {
    if (!isLoggedIn()) return false;
    _refreshUserPermissions();
    foreach ($_SESSION['user_roles'] ?? [] as $role) {
        if ((int)$role['id_rol'] === 1) return true;
        if (strtolower($role['nombre'] ?? '') === 'administrador') return true;
    }
    return false;
}

function esChofer(): bool {
    if (!isLoggedIn()) return false;
    _refreshUserPermissions();
    foreach ($_SESSION['user_roles'] ?? [] as $role) {
        if (_esRoleChofer($role)) return true;
    }
    return false;
}

function getDefaultPage(): string {
    if (!isLoggedIn()) return BASE_URL . '/login.php';
    if (!empty($_SESSION['id_chofer'])) {
        return BASE_URL . '/chofer/panel.php';
    }
    _refreshUserPermissions();
    foreach ($_SESSION['user_roles'] ?? [] as $role) {
        if (_esRoleAdminCapable($role)) return BASE_URL . '/admin/dashboard.php';
    }
    foreach ($_SESSION['user_roles'] ?? [] as $role) {
        if (_esRoleChofer($role)) return BASE_URL . '/chofer/panel.php';
    }
    return BASE_URL . '/chofer/cargar_combustible.php';
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
