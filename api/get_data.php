<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

try {
    $db = getDB();
    $action = $_GET['action'] ?? '';

    $tripTable = 'km_recorrido';
    if (!$db->query("SHOW TABLES LIKE 'km_recorrido'")->fetch()) {
        $tripTable = 'hoja_ruta';
    }
    $hasHojaRuta = (bool)$db->query("SHOW TABLES LIKE 'hoja_ruta'")->fetch();

    switch ($action) {
        case 'camion':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM camiones WHERE id_camion = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch() ?: []);
            break;

        case 'chofer':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM choferes WHERE id_chofer = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch() ?: []);
            break;

        case 'historial_camion':
            $id = (int)($_GET['id'] ?? 0);
            $asignaciones = $db->prepare("SELECT a.*, CONCAT(ch.nombre, ' ', ch.apellido) as chofer FROM asignaciones a JOIN choferes ch ON a.id_chofer = ch.id_chofer WHERE a.id_camion = ? ORDER BY a.fecha_desde DESC");
            $asignaciones->execute([$id]);
            $mantenimientos = $db->prepare("SELECT * FROM mantenimientos WHERE id_camion = ? ORDER BY fecha DESC LIMIT 20");
            $mantenimientos->execute([$id]);
            echo json_encode([
                'asignaciones' => $asignaciones->fetchAll(),
                'mantenimientos' => $mantenimientos->fetchAll(),
            ]);
            break;

        case 'combustible':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM combustible WHERE id_combustible = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch() ?: []);
            break;

        case 'chofer_asignacion':
            $idChofer = getChoferIdFromUser();
            if (!$idChofer) {
                $userId = getCurrentUserId();
                if ($userId) {
                    try {
                        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
                        $stmtCh->execute([$userId]);
                        $idChofer = $stmtCh->fetchColumn() ?: null;
                    } catch (Exception $e) {}
                }
            }
            if (!$idChofer) { echo json_encode(null); break; }
            $stmt = $db->prepare("SELECT c.*, a.fecha_desde FROM asignaciones a JOIN camiones c ON a.id_camion = c.id_camion WHERE a.id_chofer = ? AND a.activa = 1 LIMIT 1");
            $stmt->execute([$idChofer]);
            echo json_encode($stmt->fetch() ?: null);
            break;

        case 'chofer_stats':
            $idChofer = getChoferIdFromUser();
            $userId = getCurrentUserId();
            if (!$idChofer && $userId) {
                try {
                    $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
                    $stmtCh->execute([$userId]);
                    $idChofer = $stmtCh->fetchColumn() ?: null;
                } catch (Exception $e) {}
            }
            if (!$idChofer && $userId) {
                try {
                    $stmtVh = $db->prepare("SELECT a.id_chofer FROM vehiculos_usuarios vu JOIN asignaciones a ON vu.vehiculo_id = a.id_camion AND a.activa = 1 WHERE vu.usuario_id = ? LIMIT 1");
                    $stmtVh->execute([$userId]);
                    $idChofer = $stmtVh->fetchColumn() ?: null;
                } catch (Exception $e) {}
            }
            if (!$idChofer) { echo json_encode(null); break; }
            $mes = date('m');
            $anio = date('Y');
            $km = $db->prepare("SELECT COALESCE(SUM(km_recorridos),0) as total FROM {$tripTable} WHERE id_chofer = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?");
            $km->execute([$idChofer, $mes, $anio]);
            $comb = $db->prepare("SELECT COALESCE(SUM(litros),0) as litros, COALESCE(SUM(litros * precio_litro),0) as total FROM combustible WHERE id_chofer = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ?");
            $comb->execute([$idChofer, $mes, $anio]);
            echo json_encode(['km' => $km->fetch(), 'combustible' => $comb->fetch()]);
            break;

        case 'viaje':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM {$tripTable} WHERE id_hoja = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch() ?: []);
            break;

        case 'precargar_viaje':
            $id = (int)($_GET['id'] ?? 0);
            $stmtHr = $db->prepare("SELECT * FROM hoja_ruta WHERE id = ?");
            $stmtHr->execute([$id]);
            $hr = $stmtHr->fetch();
            if ($hr) {
                $idCamion = null;
                if (!empty($hr['patente'])) {
                    $stmtCam = $db->prepare("SELECT id_camion FROM camiones WHERE patente = ?");
                    $stmtCam->execute([$hr['patente']]);
                    $cam = $stmtCam->fetch();
                    $idCamion = $cam ? (int)$cam['id_camion'] : null;
                }
                $idChofer = null;
                if (!empty($hr['chofer'])) {
                    $partes = explode(' ', $hr['chofer'], 2);
                    $nombre = $partes[0];
                    $apellido = $partes[1] ?? '';
                    $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE nombre LIKE ? AND apellido LIKE ? LIMIT 1");
                    $stmtCh->execute(["%$nombre%", "%$apellido%"]);
                    $ch = $stmtCh->fetch();
                    if ($ch) {
                        $idChofer = (int)$ch['id_chofer'];
                    } else {
                        $stmtCh2 = $db->prepare("SELECT id_chofer FROM choferes WHERE CONCAT(nombre, ' ', apellido) LIKE ? OR CONCAT(apellido, ' ', nombre) LIKE ? LIMIT 1");
                        $stmtCh2->execute(["%{$hr['chofer']}%", "%{$hr['chofer']}%"]);
                        $ch2 = $stmtCh2->fetch();
                        $idChofer = $ch2 ? (int)$ch2['id_chofer'] : null;
                    }
                }
                echo json_encode([
                    'id_hoja' => $id,
                    'id_chofer' => $idChofer,
                    'id_camion' => $idCamion,
                    'fecha' => date('Y-m-d'),
                    'km_salida' => (float)($hr['km_inicial'] ?? 0),
                    'km_llegada' => '',
                    'origen' => $hr['salida'] ?? '',
                    'destino' => $hr['destino'] ?? '',
                ]);
            } else {
                $stmt = $db->prepare("SELECT * FROM {$tripTable} WHERE id_hoja = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                echo json_encode($row ?: []);
            }
            break;

        case 'chofer_mantenimientos':
            $id = getChoferIdFromUser();
            if (!$id) {
                $uid = getCurrentUserId();
                if ($uid) {
                    try {
                        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
                        $stmtCh->execute([$uid]);
                        $id = $stmtCh->fetchColumn() ?: null;
                    } catch (Exception $e) {}
                }
            }
            if (!$id) { echo json_encode([]); break; }
            $stmt = $db->prepare("
                SELECT m.*, c.patente FROM mantenimientos m
                JOIN camiones c ON m.id_camion = c.id_camion
                JOIN asignaciones a ON a.id_camion = c.id_camion
                WHERE a.id_chofer = ? AND a.activa = 1
                ORDER BY m.fecha DESC LIMIT 10
            ");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetchAll());
            break;

        case 'usuario':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT u.*, ur.id_rol FROM usuarios u LEFT JOIN usuario_rol ur ON u.id_usuario = ur.id_usuario WHERE u.id_usuario = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if ($user) {
                $user['id_rol'] = $user['id_rol'] ?? null;
            }
            echo json_encode($user ?: []);
            break;

        case 'rol':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM roles WHERE id_rol = ?");
            $stmt->execute([$id]);
            $rol = $stmt->fetch();
            $stmtPerms = $db->prepare("SELECT p.codigo FROM permisos p JOIN rol_permiso rp ON p.id_permiso = rp.id_permiso WHERE rp.id_rol = ?");
            $stmtPerms->execute([$id]);
            echo json_encode(['rol' => $rol ?: null, 'permisos' => array_column($stmtPerms->fetchAll(), 'codigo')]);
            break;

        case 'lista_roles':
            $stmt = $db->query("SELECT id_rol, nombre, descripcion FROM roles ORDER BY nombre");
            echo json_encode($stmt->fetchAll());
            break;

        default:
            echo json_encode(['error' => 'Accion no valida']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
