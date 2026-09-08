<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $id_chofer = isset($_POST['id_chofer']) ? (int)$_POST['id_chofer'] : null;
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (($action === 'desasignar' || $id_chofer === 0) && $id_camion) {
        if ($id_chofer > 0) {
            // Desactivar asignacion de un chofer especifico
            $stmt = $db->prepare("UPDATE asignaciones SET activa = 0, fecha_hasta = CURDATE() WHERE id_camion = ? AND id_chofer = ? AND activa = 1");
            $stmt->execute([$id_camion, $id_chofer]);
            registrarAuditoria(getCurrentUserId(), 'desasignar', 'asignaciones', $id_camion, "Desasigno chofer $id_chofer de camion $id_camion");
        } else {
            // Desactivar TODAS las asignaciones activas del mismo camion
            $stmt = $db->prepare("UPDATE asignaciones SET activa = 0, fecha_hasta = CURDATE() WHERE id_camion = ? AND activa = 1");
            $stmt->execute([$id_camion]);
            registrarAuditoria(getCurrentUserId(), 'desasignar', 'asignaciones', $id_camion, "Desasigno TODOS los choferes de camion $id_camion");
        }
    } elseif ($id_chofer && $id_camion) {
        // Verificamos que no este ya asignado para no duplicar
        $check = $db->prepare("SELECT COUNT(*) FROM asignaciones WHERE id_chofer = ? AND id_camion = ? AND activa = 1");
        $check->execute([$id_chofer, $id_camion]);
        
        if ($check->fetchColumn() == 0) {
            // Nueva asignacion (permitimos multiples choferes por camion y multiples camiones por chofer)
            $stmt = $db->prepare("INSERT INTO asignaciones (id_chofer, id_camion, fecha_desde, activa) VALUES (?, ?, CURDATE(), 1)");
            $stmt->execute([$id_chofer, $id_camion]);
            registrarAuditoria(getCurrentUserId(), 'asignar', 'asignaciones', $db->lastInsertId(), "Asigno chofer $id_chofer a camion $id_camion");
        }
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/admin/camiones.php');
header('Location: ' . $redirect);
exit;

