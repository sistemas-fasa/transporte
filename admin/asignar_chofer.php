<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $id_chofer = (int)($_POST['id_chofer'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);

    if ($id_chofer && $id_camion) {
        // Desactivar asignaciones activas del mismo camion
        $db->prepare("UPDATE asignaciones SET activa = 0, fecha_hasta = CURDATE() WHERE id_camion = ? AND activa = 1")->execute([$id_camion]);

        // Nueva asignacion
        $stmt = $db->prepare("INSERT INTO asignaciones (id_chofer, id_camion, fecha_desde, activa) VALUES (?, ?, CURDATE(), 1)");
        $stmt->execute([$id_chofer, $id_camion]);

        registrarAuditoria(getCurrentUserId(), 'asignar', 'asignaciones', $db->lastInsertId(), "Asigno chofer $id_chofer a camion $id_camion");
    }
}

header('Location: ' . BASE_URL . '/admin/camiones.php');
exit;
