<?php
function generarAlertasAutomaticas(PDO $db): void {
    try {
        // Limpiar TODAS las alertas automáticas pendientes para regenerarlas frescas
        // (Las resueltas se conservan en el historial)
        $db->exec("DELETE FROM alertas WHERE resuelta = 0 AND tipo IN ('vencimiento_licencia','vencimiento_vtv','vencimiento_seguro')");

        // 1. Licencias proximas a vencer (90 dias de anticipacion)
        $licencias = $db->query(
            "SELECT c.id_chofer, c.nombre, c.apellido, c.vencimiento_licencia
             FROM choferes c
             WHERE c.estado = 'activo'
               AND c.vencimiento_licencia IS NOT NULL
               AND c.vencimiento_licencia <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
        )->fetchAll();
        foreach ($licencias as $l) {
            $diff = (strtotime($l['vencimiento_licencia']) - time()) / 86400;
            $severidad = $diff <= 0 ? 'rojo' : ($diff <= 15 ? 'rojo' : ($diff <= 60 ? 'amarillo' : 'verde'));
            $msg = "Licencia de {$l['nombre']} {$l['apellido']} vence el " . date('d/m/Y', strtotime($l['vencimiento_licencia']));
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_licencia', ?, ?, ?)")
               ->execute([$l['id_chofer'], $msg, $severidad]);
        }
    } catch (Exception $e) {
        // Error en licencias
    }

    try {
        // 2. VTV proximas a vencer - desde tabla vtv separada
        $vtvs = $db->query(
            "SELECT v.id_camion, v.fecha_vencimiento, c.patente
             FROM vtv v
             JOIN camiones c ON v.id_camion = c.id_camion
             WHERE v.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
             ORDER BY v.id_camion, v.fecha_vencimiento ASC"
        )->fetchAll();
        $procesadosVtv = []; // evitar duplicados por camion
        foreach ($vtvs as $v) {
            if (isset($procesadosVtv[$v['id_camion']])) continue;
            $procesadosVtv[$v['id_camion']] = true;
            $diff = (strtotime($v['fecha_vencimiento']) - time()) / 86400;
            $severidad = $diff <= 0 ? 'rojo' : ($diff <= 15 ? 'rojo' : ($diff <= 60 ? 'amarillo' : 'verde'));
            $msg = "VTV de camion {$v['patente']} vence el " . date('d/m/Y', strtotime($v['fecha_vencimiento']));
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_vtv', ?, ?, ?)")
               ->execute([$v['id_camion'], $msg, $severidad]);
        }
    } catch (Exception $e) {
        // Error en VTV tabla
    }

    try {
        // 3. VTV desde columna directa en camiones (para los que no tienen tabla vtv)
        $vtvsCamion = $db->query(
            "SELECT c.id_camion, c.patente, c.vtv
             FROM camiones c
             WHERE c.vtv IS NOT NULL
               AND c.vtv <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM vtv v
                   WHERE v.id_camion = c.id_camion
                     AND v.fecha_vencimiento = c.vtv
               )
               AND NOT EXISTS (
                   SELECT 1 FROM alertas a
                   WHERE a.tipo = 'vencimiento_vtv'
                     AND a.id_referencia = c.id_camion
                     AND a.resuelta = 0
               )"
        )->fetchAll();
        foreach ($vtvsCamion as $v) {
            $diff = (strtotime($v['vtv']) - time()) / 86400;
            $severidad = $diff <= 0 ? 'rojo' : ($diff <= 15 ? 'rojo' : ($diff <= 60 ? 'amarillo' : 'verde'));
            $msg = "VTV de camion {$v['patente']} vence el " . date('d/m/Y', strtotime($v['vtv']));
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_vtv', ?, ?, ?)")
               ->execute([$v['id_camion'], $msg, $severidad]);
        }
    } catch (Exception $e) {
        // Error en VTV columna camiones
    }

    try {
        // 4. Seguros proximos a vencer (90 dias de anticipacion)
        $seguros = $db->query(
            "SELECT s.id_camion, s.fecha_vencimiento, c.patente
             FROM seguros s
             JOIN camiones c ON s.id_camion = c.id_camion
             WHERE s.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
             ORDER BY s.id_camion, s.fecha_vencimiento ASC"
        )->fetchAll();
        $procesadosSeg = [];
        foreach ($seguros as $s) {
            if (isset($procesadosSeg[$s['id_camion']])) continue;
            $procesadosSeg[$s['id_camion']] = true;
            $diff = (strtotime($s['fecha_vencimiento']) - time()) / 86400;
            $severidad = $diff <= 0 ? 'rojo' : ($diff <= 15 ? 'rojo' : ($diff <= 60 ? 'amarillo' : 'verde'));
            $msg = "Seguro de camion {$s['patente']} vence el " . date('d/m/Y', strtotime($s['fecha_vencimiento']));
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_seguro', ?, ?, ?)")
               ->execute([$s['id_camion'], $msg, $severidad]);
        }
    } catch (Exception $e) {
        // Error en seguros
    }

    try {
        // 5. Proximo mantenimiento (solo si no hay alerta activa ya)
        $mants = $db->query(
            "SELECT m.id_camion, c.patente
             FROM mantenimientos m
             JOIN camiones c ON m.id_camion = c.id_camion
             WHERE m.proximo_mantenimiento_km IS NOT NULL
               AND c.kilometraje_actual >= (m.proximo_mantenimiento_km - 1000)
               AND (SELECT COUNT(*) FROM alertas WHERE tipo='cambio_aceite' AND id_referencia=c.id_camion AND resuelta=0) = 0"
        )->fetchAll();
        foreach ($mants as $m) {
            $msg = "Cambio de aceite proximo para {$m['patente']} - programar en los proximos 1000km";
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('cambio_aceite', ?, ?, 'amarillo')")
               ->execute([$m['id_camion'], $msg]);
        }
    } catch (Exception $e) {
        // Error en mantenimiento
    }
}
