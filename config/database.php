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

function recalcularCombustibleCamion($id_camion): void {
    try {
        $db = getDB();
        
        // Check if the truck is configured to register by hour
        $stmtCam = $db->prepare("SELECT por_hora FROM camiones WHERE id_camion = ?");
        $stmtCam->execute([$id_camion]);
        $cam = $stmtCam->fetch();
        if (!$cam) return;
        $isPorHora = (int)$cam['por_hora'];

        // Fetch all fuel loads for the vehicle ordered chronologically
        $stmt = $db->prepare("SELECT * FROM combustible WHERE id_camion = ? ORDER BY fecha ASC, id_combustible ASC");
        $stmt->execute([$id_camion]);
        $cargas = $stmt->fetchAll();

        $prevCarga = null;
        foreach ($cargas as $idx => $carga) {
            $id_combustible = (int)$carga['id_combustible'];
            $litros = (float)$carga['litros'];
            $precio_litro = (float)$carga['precio_litro'];
            $total_actual = $litros * $precio_litro;
            
            $km_actual = $carga['kilometraje_al_cargar'] !== null ? (float)$carga['kilometraje_al_cargar'] : null;
            $horas_actual = $carga['horas_al_cargar'] !== null ? (float)$carga['horas_al_cargar'] : null;

            // Initialize all computed fields as null
            $km_recorridos = null;
            $km_por_litro = null;
            $litros_cada_100km = null;
            $costo_por_km = null;

            $hs_recorridas = null;
            $litros_por_hora = null;
            $costo_por_hora = null;

            $error_consumo = null;

            if ($idx === 0) {
                $error_consumo = "Sin datos suficientes";
            } else {
                $km_anterior = $prevCarga['kilometraje_al_cargar'] !== null ? (float)$prevCarga['kilometraje_al_cargar'] : null;
                $horas_anterior = $prevCarga['horas_al_cargar'] !== null ? (float)$prevCarga['horas_al_cargar'] : null;

                // 1. Distance calculations
                if ($km_actual !== null && $km_actual > 0) {
                    if ($km_anterior !== null && $km_anterior > 0) {
                        if ($km_actual <= $km_anterior) {
                            $error_consumo = "El kilometraje actual ($km_actual) es menor o igual al anterior ($km_anterior)";
                        } else {
                            $km_recorridos = $km_actual - $km_anterior;
                            if ($litros > 0) {
                                $km_por_litro = round($km_recorridos / $litros, 2);
                                $litros_cada_100km = round(($litros * 100) / $km_recorridos, 2);
                                $costo_por_km = round($total_actual / $km_recorridos, 2);
                            }
                        }
                    } else {
                        $error_consumo = "Sin datos suficientes (carga anterior no tiene kilometraje)";
                    }
                }

                // 2. Hour calculations
                if ($isPorHora && $horas_actual !== null && $horas_actual > 0) {
                    if ($horas_anterior !== null && $horas_anterior > 0) {
                        if ($horas_actual <= $horas_anterior) {
                            $error_horas = "Las horas actuales ($horas_actual) son menores o iguales a las anteriores ($horas_anterior)";
                            $error_consumo = $error_consumo ? $error_consumo . " | " . $error_horas : $error_horas;
                        } else {
                            $hs_recorridas = $horas_actual - $horas_anterior;
                            if ($litros > 0) {
                                $litros_por_hora = round($hs_recorridas / $litros, 2);
                                $costo_por_hora = round($total_actual / $hs_recorridas, 2);
                            }
                        }
                    } else {
                        $error_horas = "Sin datos suficientes (carga anterior no tiene horas)";
                        $error_consumo = $error_consumo ? $error_consumo . " | " . $error_horas : $error_horas;
                    }
                }
            }

            // Update calculations in database
            $upd = $db->prepare("UPDATE combustible SET 
                km_recorridos = ?, 
                km_por_litro = ?, 
                litros_cada_100km = ?, 
                costo_por_km = ?, 
                hs_recorridas = ?, 
                litros_por_hora = ?, 
                costo_por_hora = ?, 
                error_consumo = ? 
                WHERE id_combustible = ?");
            $upd->execute([
                $km_recorridos,
                $km_por_litro,
                $litros_cada_100km,
                $costo_por_km,
                $hs_recorridas,
                $litros_por_hora,
                $costo_por_hora,
                $error_consumo,
                $id_combustible
            ]);

            // For the next iteration, this is the previous load
            $prevCarga = $carga;
        }
    } catch (Exception $e) {
        // Log or handle error if needed
    }
}
