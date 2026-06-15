<?php
!defined('DB_HOST') && define('DB_HOST', 'localhost');
!defined('DB_NAME') && define('DB_NAME', 'c0860365_sistema');
!defined('DB_USER') && define('DB_USER', 'c0860365_sistema');
!defined('DB_PASS') && define('DB_PASS', '96gasasoBA');

!defined('BASE_URL') && define('BASE_URL', '/sistema');
!defined('UPLOAD_DIR') && define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die("Error de conexion: " . $e->getMessage());
        }
    }
    return $pdo;
}

function registrarAuditoria(int $id_usuario, string $accion, ?string $tabla = null, ?int $id_registro = null, ?string $detalle = null): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $db->prepare("INSERT INTO auditoria (id_usuario, accion, tabla, id_registro, detalle, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_usuario, $accion, $tabla, $id_registro, $detalle, $ip]);
    } catch (Exception $e) {
        // Silently fail - auditoria is not critical
    }
}

// ---- Auditoría de accesos ----
function registrarAcceso(int $id_usuario, string $accion, ?string $modulo = null, ?int $id_registro = null, ?string $detalle = null): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $db->prepare("INSERT INTO auditoria_accesos (id_usuario, ip_address, accion, modulo, id_registro, detalle, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_usuario, $ip, $accion, $modulo, $id_registro, $detalle, $ua]);
    } catch (Exception $e) {}
}

// ---- Control de intentos fallidos ----
function registrarIntentoFallido(string $username): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE usuarios SET intentos_fallidos = COALESCE(intentos_fallidos, 0) + 1 WHERE username = ?");
        $stmt->execute([$username]);
    } catch (Exception $e) {}
}

function bloquearUsuario(string $username): void {
    try {
        $db = getDB();
        $bloqueo = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $db->prepare("UPDATE usuarios SET bloqueado_hasta = ? WHERE username = ?")->execute([$bloqueo, $username]);
    } catch (Exception $e) {}
}

function resetearIntentos(string $username): void {
    try {
        $db = getDB();
        $db->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE username = ?")->execute([$username]);
    } catch (Exception $e) {}
}

function usuarioBloqueado(string $username): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT bloqueado_hasta FROM usuarios WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row && $row['bloqueado_hasta']) {
            return strtotime($row['bloqueado_hasta']) > time();
        }
    } catch (Exception $e) {}
    return false;
}
