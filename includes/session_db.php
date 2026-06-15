<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

!defined('DB_HOST') && define('DB_HOST', 'localhost');
!defined('DB_NAME') && define('DB_NAME', 'c0860365_sistema');
!defined('DB_USER') && define('DB_USER', 'c0860365_sistema');
!defined('DB_PASS') && define('DB_PASS', '96gasasoBA');
!defined('BASE_URL') && define('BASE_URL', '/sistema');

// Custom session usando cookies + DB
function dbSessionStart() {
    if (!isset($_COOKIE['SISTEMA_TOKEN'])) {
        return false;
    }
    $token = $_COOKIE['SISTEMA_TOKEN'];
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmt = $pdo->prepare("SELECT u.id_usuario, u.rol, u.username, u.id_chofer, u.nombre, u.apellido
            FROM usuarios u JOIN sesiones s ON u.id_usuario = s.id_usuario
            WHERE s.token = ? AND s.expira > NOW() AND u.activo = 1 LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) {
            $pdo->prepare("UPDATE sesiones SET ultimo_acceso = NOW() WHERE token = ?")->execute([$token]);
            $_SESSION['user_id'] = (int)$user['id_usuario'];
            $_SESSION['user_rol'] = $user['rol'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['id_chofer'] = $user['id_chofer'];
            $_SESSION['user_nombre'] = $user['nombre'] ?: $user['username'];

            // Cargar roles y permisos (con fallback si las tablas no existen)
            $_SESSION['user_roles'] = [];
            $_SESSION['user_permissions'] = [];
            try {
                $stmtRoles = $pdo->prepare("SELECT r.id_rol, r.nombre FROM usuario_rol ur JOIN roles r ON ur.id_rol = r.id_rol WHERE ur.id_usuario = ?");
                $stmtRoles->execute([$user['id_usuario']]);
                $_SESSION['user_roles'] = $stmtRoles->fetchAll();

                if (!empty($_SESSION['user_roles'])) {
                    $roleIds = array_column($_SESSION['user_roles'], 'id_rol');
                    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                    $stmtPerms = $pdo->prepare("SELECT DISTINCT p.codigo FROM permisos p JOIN rol_permiso rp ON p.id_permiso = rp.id_permiso WHERE rp.id_rol IN ($placeholders)");
                    $stmtPerms->execute($roleIds);
                    $_SESSION['user_permissions'] = array_column($stmtPerms->fetchAll(), 'codigo');
                }
            } catch (Exception $e) {
                // Tablas de permisos aun no creadas - continuar sin permisos granulares
            }

            return true;
        }
    } catch (Exception $e) {}
    return false;
}

function dbSessionLogin($id_usuario, $rol, $username, $id_chofer = null) {
    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+7 days'));
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS sesiones (
            id_sesion INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expira DATETIME NOT NULL,
            ultimo_acceso DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
            INDEX (token),
            INDEX (expira)
        ) ENGINE=InnoDB");
        $pdo->prepare("INSERT INTO sesiones (id_usuario, token, expira) VALUES (?, ?, ?)")->execute([$id_usuario, $token, $expira]);
        setcookie('SISTEMA_TOKEN', $token, time() + 86400 * 7, '/', '', false, true);

        // Obtener datos actualizados del usuario
        $stmt = $pdo->prepare("SELECT u.* FROM usuarios u WHERE u.id_usuario = ?");
        $stmt->execute([$id_usuario]);
        $user = $stmt->fetch();

        $_SESSION['user_id'] = (int)$id_usuario;
        $_SESSION['user_rol'] = $rol;
        $_SESSION['username'] = $username;
        $_SESSION['id_chofer'] = $id_chofer;
        $_SESSION['user_nombre'] = ($user['nombre'] ?? '') ?: $username;

        // Cargar roles y permisos (con fallback si las tablas no existen)
        $_SESSION['user_roles'] = [];
        $_SESSION['user_permissions'] = [];
        try {
            $stmtRoles = $pdo->prepare("SELECT r.id_rol, r.nombre FROM usuario_rol ur JOIN roles r ON ur.id_rol = r.id_rol WHERE ur.id_usuario = ?");
            $stmtRoles->execute([$id_usuario]);
            $_SESSION['user_roles'] = $stmtRoles->fetchAll();

            if (!empty($_SESSION['user_roles'])) {
                $roleIds = array_column($_SESSION['user_roles'], 'id_rol');
                $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                $stmtPerms = $pdo->prepare("SELECT DISTINCT p.codigo FROM permisos p JOIN rol_permiso rp ON p.id_permiso = rp.id_permiso WHERE rp.id_rol IN ($placeholders)");
                $stmtPerms->execute($roleIds);
                $_SESSION['user_permissions'] = array_column($stmtPerms->fetchAll(), 'codigo');
            }
        } catch (Exception $e) {
            // Tablas de permisos aun no creadas - continuar sin permisos granulares
        }
    } catch (Exception $e) {
        error_log("Error en dbSessionLogin: " . $e->getMessage());
    }
}

function dbSessionLogout() {
    if (isset($_COOKIE['SISTEMA_TOKEN'])) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo->prepare("DELETE FROM sesiones WHERE token = ?")->execute([$_COOKIE['SISTEMA_TOKEN']]);
        } catch (Exception $e) {}
        setcookie('SISTEMA_TOKEN', '', time() - 3600, '/', '', false, true);
    }
    $_SESSION = [];
}
