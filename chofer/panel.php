<?php
require_once __DIR__ . '/../includes/auth.php';
requireChofer();
require_once __DIR__ . '/../includes/alertas_helper.php';
$db = getDB();
$esMantenimiento = esMantenimiento();

// Handler: actualizar estado de pedido (solo rol mantenimiento)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esMantenimiento) {
    $action = $_POST['action'] ?? '';
    if ($action === 'pedido_update') {
        $id_pedido = (int)($_POST['id_pedido'] ?? 0);
        $estado = $_POST['estado'] ?? 'pendiente';
        $respuesta = trim($_POST['respuesta'] ?? '');
        $repuestos_check = $_POST['repuestos_check'] ?? [];
        $tareas_check = $_POST['tareas_check'] ?? [];
        if ($id_pedido) {
            $pedidoInfo = $db->query("SELECT id_camion FROM pedidos_mantenimiento WHERE id_pedido = $id_pedido")->fetch();
            $id_camion = $pedidoInfo['id_camion'] ?? 0;
            
            $repuestosNombres = [];
            if (!empty($repuestos_check)) {
                $placeholders = str_repeat('?,', count($repuestos_check) - 1) . '?';
                $r = $db->prepare("SELECT nombre FROM camion_repuestos WHERE id_repuesto IN ($placeholders)");
                $r->execute($repuestos_check);
                $repuestosNombres = $r->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
            
            $tareasMarcadas = array_filter($tareas_check);
            foreach ($repuestosNombres as $nombre) {
                $tareasMarcadas[] = "Repuesto: $nombre";
            }
            $tareasMarcadas = implode("\n", $tareasMarcadas);
            
            try {
                $db->prepare("UPDATE pedidos_mantenimiento SET estado = ?, tareas = ?, respuesta = ? WHERE id_pedido = ?")->execute([$estado, $tareasMarcadas ?: null, $respuesta ?: null, $id_pedido]);
                registrarAuditoria($userId, 'update', 'pedidos_mantenimiento', $id_pedido, "Actualizo pedido mantenimiento: estado=$estado");
                header('Location: ' . $_SERVER['PHP_SELF'] . '?ok=pedido_actualizado');
                exit;
            } catch (Exception $e) {}
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$pageTitle = 'Panel del Chofer';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_chofer.php';

$userId = getCurrentUserId();
$idChofer = getChoferIdFromUser();

$mensaje = $_GET['ok'] ?? '';

generarAlertasAutomaticas($db);

// ---- Vista Mantenimiento: todos los vehiculos y pedidos ----
$allCamiones = [];
if ($esMantenimiento) {
    $allCamiones = $db->query("SELECT c.*, e.nombre as empresa_nombre FROM camiones c LEFT JOIN empresas e ON c.empresa_id = e.id_empresa WHERE c.estado='activo' ORDER BY c.patente")->fetchAll();
    $pedidos = $db->query("SELECT p.*, c.patente, c.marca, c.modelo, u.username as creador FROM pedidos_mantenimiento p JOIN camiones c ON p.id_camion = c.id_camion LEFT JOIN usuarios u ON p.id_usuario_crea = u.id_usuario WHERE p.estado IN ('pendiente','en_proceso') ORDER BY p.prioridad, p.created_at DESC LIMIT 50")->fetchAll();
    $repuestosPorCamion = [];
    $tareasPorCamion = [];
    foreach ($db->query("SELECT id_camion, id_repuesto, nombre, codigo, cantidad, costo_unitario FROM camion_repuestos")->fetchAll() as $r) {
        $repuestosPorCamion[$r['id_camion']][] = $r;
    }
    foreach ($db->query("SELECT id_camion, id_tarea, nombre FROM camion_tareas")->fetchAll() as $t) {
        $tareasPorCamion[$t['id_camion']][] = $t;
    }
    $prioridadLabels = ['normal'=>'Normal','urgente'=>'Urgente','critica'=>'Critica','sinprioridad'=>'Normal'];
    $prioridadColors = ['normal'=>'bg-blue-100 text-blue-800','urgente'=>'bg-amber-100 text-amber-800','critica'=>'bg-red-100 text-red-800'];
    $alertas = $db->query("SELECT a.*, c.patente FROM alertas a LEFT JOIN camiones c ON a.id_referencia = c.id_camion WHERE resuelta = 0 AND severidad IN ('rojo','amarillo') ORDER BY FIELD(severidad,'rojo','amarillo'), a.fecha_creacion DESC LIMIT 15")->fetchAll();
    $ultimosMants = $db->query("SELECT m.*, c.patente, c.marca, c.modelo FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion ORDER BY m.fecha DESC LIMIT 10")->fetchAll();
}

// Si el usuario no tiene id_chofer vinculado, buscarlo en choferes por usuario_id
if (!$idChofer && $userId) {
    try {
        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
        $stmtCh->execute([$userId]);
        $idChofer = $stmtCh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}
// Si aun no hay id_chofer, buscarlo via vehiculos asignados
if (!$idChofer && $userId) {
    try {
        $stmtVh = $db->prepare("SELECT a.id_chofer FROM vehiculos_usuarios vu JOIN asignaciones a ON vu.vehiculo_id = a.id_camion AND a.activa = 1 WHERE vu.usuario_id = ? LIMIT 1");
        $stmtVh->execute([$userId]);
        $idChofer = $stmtVh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Verificar si la columna usuario_id existe en km_recorrido
$hasUsuarioId = false;
try {
    $hasUsuarioId = (bool)$db->query("SHOW COLUMNS FROM km_recorrido LIKE 'usuario_id'")->fetch();
} catch (Exception $e) {}

// Obtener vehículos asignados (de ambas fuentes)
$vehiculos = [];
$vistos = [];
if ($userId) {
    try {
        $stmt = $db->prepare("SELECT c.* FROM vehiculos_usuarios vu JOIN camiones c ON vu.vehiculo_id = c.id_camion WHERE vu.usuario_id = ?");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $vistos[$row['id_camion']] = true;
            $vehiculos[] = $row;
        }
    } catch (Exception $e) {}
}
if ($idChofer) {
    try {
        $stmt2 = $db->prepare("SELECT c.* FROM asignaciones a JOIN camiones c ON a.id_camion = c.id_camion WHERE a.id_chofer = ? AND a.activa = 1");
        $stmt2->execute([$idChofer]);
        foreach ($stmt2->fetchAll() as $row) {
            if (!isset($vistos[$row['id_camion']])) {
                $vistos[$row['id_camion']] = true;
                $vehiculos[] = $row;
            }
        }
    } catch (Exception $e) {}
}

$camionPrincipal = $vehiculos[0] ?? null;

// Ultimo km_llegada por camion desde viajes
$ultimoKmViaje = [];
if (!empty($vehiculos)) {
    $ids = array_column($vehiculos, 'id_camion');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmtKm = $db->prepare("SELECT r.id_camion, r.km_llegada FROM km_recorrido r WHERE r.id_hoja = (SELECT MAX(r2.id_hoja) FROM km_recorrido r2 WHERE r2.id_camion = r.id_camion AND r2.id_camion IN ($placeholders))");
        $stmtKm->execute($ids);
        while ($row = $stmtKm->fetch()) { $ultimoKmViaje[$row['id_camion']] = $row['km_llegada']; }
    } catch (Exception $e) {}
}

// Monthly stats
$mes = date('m');
$anio = date('Y');

// KM recorridos (solo autorizados)
$kmSql = "SELECT COALESCE(SUM(km_recorridos),0) as total FROM km_recorrido WHERE MONTH(fecha) = ? AND YEAR(fecha) = ? AND estado = 'aprobado'";
$kmParams = [$mes, $anio];
if ($idChofer) { $kmSql .= " AND id_chofer = ?"; $kmParams[] = $idChofer; }
elseif ($hasUsuarioId) { $kmSql .= " AND usuario_id = ?"; $kmParams[] = $userId; }
else { $kmSql .= " AND 1=0"; }
$kmMes = $db->prepare($kmSql);
$kmMes->execute($kmParams);
$kmData = $kmMes->fetch();

// Combustible
$combSql = "SELECT COALESCE(SUM(litros),0) as litros, COALESCE(SUM(litros * precio_litro),0) as total FROM combustible WHERE MONTH(fecha) = ? AND YEAR(fecha) = ?";
$combParams = [$mes, $anio];
if ($idChofer) { $combSql .= " AND id_chofer = ?"; $combParams[] = $idChofer; }
elseif ($userId) { $combSql .= " AND id_usuario_registra = ?"; $combParams[] = $userId; }
else { $combSql .= " AND 1=0"; }
$combMes = $db->prepare($combSql);
$combMes->execute($combParams);
$combData = $combMes->fetch();

// Previous month comparison
$mesAnt = $mes == 1 ? 12 : $mes - 1;
$anioAnt = $mes == 1 ? $anio - 1 : $anio;
$kmAntParams = [$mesAnt, $anioAnt];
$kmAntSql = "SELECT COALESCE(SUM(km_recorridos),0) as total FROM km_recorrido WHERE MONTH(fecha) = ? AND YEAR(fecha) = ? AND estado = 'aprobado'";
if ($idChofer) { $kmAntSql .= " AND id_chofer = ?"; $kmAntParams[] = $idChofer; }
elseif ($hasUsuarioId) { $kmAntSql .= " AND usuario_id = ?"; $kmAntParams[] = $userId; }
else { $kmAntSql .= " AND 1=0"; }
$kmAnt = $db->prepare($kmAntSql);
$kmAnt->execute($kmAntParams);
$kmAntData = $kmAnt->fetch();
$eficiencia = $kmAntData['total'] > 0 ? (($kmData['total'] - $kmAntData['total']) / $kmAntData['total'] * 100) : 0;

// Rendimiento km/l
$rendimiento = $combData['litros'] > 0 ? ($kmData['total'] / $combData['litros']) : 0;

// Ultimas cargas de combustible
$ultimasCargas = [];
try {
    $cargasSql = "SELECT co.fecha, co.litros, co.precio_litro, co.kilometraje_al_cargar, co.horas_al_cargar, c.patente FROM combustible co JOIN camiones c ON co.id_camion = c.id_camion WHERE ";
    $cargasParams = [];
    if ($idChofer) { $cargasSql .= "co.id_chofer = ?"; $cargasParams[] = $idChofer; }
    elseif ($userId) { $cargasSql .= "co.id_usuario_registra = ?"; $cargasParams[] = $userId; }
    else { $cargasSql .= "1=0"; }
    $cargasSql .= " ORDER BY co.fecha DESC LIMIT 5";
    $stmtCargas = $db->prepare($cargasSql);
    $stmtCargas->execute($cargasParams);
    $ultimasCargas = $stmtCargas->fetchAll();
} catch (Exception $e) {}

// KM sin autorizar (estado = 'cerrado', no aprobado)
$kmSinAutSql = "SELECT COALESCE(SUM(km_recorridos),0) as total FROM km_recorrido WHERE MONTH(fecha) = ? AND YEAR(fecha) = ? AND estado = 'cerrado'";
$kmSinAutParams = [$mes, $anio];
if ($idChofer) { $kmSinAutSql .= " AND id_chofer = ?"; $kmSinAutParams[] = $idChofer; }
elseif ($hasUsuarioId) { $kmSinAutSql .= " AND usuario_id = ?"; $kmSinAutParams[] = $userId; }
else { $kmSinAutSql .= " AND 1=0"; }
$kmSinAut = $db->prepare($kmSinAutSql);
$kmSinAut->execute($kmSinAutParams);
$kmSinAutData = $kmSinAut->fetch();

// Proximos mantenimientos de sus vehiculos
$mants = [];
if (!empty($vehiculos)) {
    $ids = array_column($vehiculos, 'id_camion');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $mants = $db->prepare("SELECT m.*, c.patente FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion WHERE c.id_camion IN ($placeholders) ORDER BY m.fecha DESC LIMIT 5");
    $mants->execute($ids);
    $mants = $mants->fetchAll();
}

// Mantenimientos pendientes
$mantsPendientes = [];
if (!empty($vehiculos)) {
    $ids = array_column($vehiculos, 'id_camion');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtPend = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.kilometraje_actual, c.proximo_mantenimiento_km FROM camiones c WHERE c.id_camion IN ($placeholders) AND c.proximo_mantenimiento_km IS NOT NULL AND c.kilometraje_actual >= (c.proximo_mantenimiento_km - 1000)");
    $stmtPend->execute($ids);
    $mantsPendientes = $stmtPend->fetchAll();
}
if ($esMantenimiento && empty($vehiculos)) {
    $stmtPend2 = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.kilometraje_actual, c.proximo_mantenimiento_km FROM camiones c WHERE c.estado='activo' AND c.proximo_mantenimiento_km IS NOT NULL AND c.kilometraje_actual >= (c.proximo_mantenimiento_km - 1000)");
    $stmtPend2->execute();
    $mantsPendientes = $stmtPend2->fetchAll();
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-5xl mx-auto">
<?php if ($mensaje === 'pedido_actualizado'): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">Pedido actualizado</div>
<?php $mensaje = ''; ?>
<?php endif; ?>
<div class="space-y-6">
<!-- Welcome Header -->
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
<div>
<p class="text-secondary font-label-caps tracking-widest mb-1">BIENVENIDO DE NUEVO</p>
<h2 class="font-headline-lg text-headline-lg text-primary">Hola, <?= htmlspecialchars(getCurrentUserName()) ?></h2>
</div>
<div class="bg-surface-container-low px-4 py-2 rounded-xl flex items-center gap-3 border border-outline-variant">
<span class="relative flex h-3 w-3">
<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
<span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
</span>
<span class="font-label-caps text-on-surface-variant">Turno Activo</span>
</div>
</div>

<?php if ($esMantenimiento): ?>
<!-- ===================== PANEL MANTENIMIENTO ===================== -->
<div class="space-y-6">
<h3 class="font-headline-sm text-headline-sm text-primary">Panel de Mantenimiento</h3>

<!-- Tarjetas KPI -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
<span class="font-label-caps text-on-surface-variant uppercase text-[10px]">PEDIDOS</span>
<div class="font-data-mono text-2xl font-bold text-primary"><?= count(array_filter($pedidos, fn($p)=>$p['estado']=='pendiente')) ?> pendientes</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
<span class="font-label-caps text-on-surface-variant uppercase text-[10px]">VEHICULOS</span>
<div class="font-data-mono text-2xl font-bold text-primary"><?= count($allCamiones) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
<span class="font-label-caps text-on-surface-variant uppercase text-[10px]">ALERTAS</span>
<div class="font-data-mono text-2xl font-bold text-amber-600"><?= count($alertas) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center">
<span class="font-label-caps text-on-surface-variant uppercase text-[10px]">PROX. SERVICIO</span>
<div class="font-data-mono text-2xl font-bold text-red-600"><?= count($mantsPendientes) ?></div>
</div>
</div>

<!-- Pedidos recibidos -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
<h4 class="font-headline-sm text-headline-sm text-primary flex items-center gap-2"><span class="material-symbols-outlined text-blue-600">local_offer</span> Pedidos de Mantenimiento Recibidos</h4>
<span class="font-label-caps text-xs text-outline"><?= count($pedidos) ?> PENDIENTES</span>
</div>
<?php if (empty($pedidos)): ?>
<p class="p-6 text-on-surface-variant text-center">No tenés pedidos de mantenimiento pendientes.</p>
<?php else: ?>
<div class="divide-y divide-outline-variant">
<?php foreach ($pedidos as $p): ?>
<div class="px-6 py-4 flex flex-col gap-2">
<div class="flex justify-between items-start">
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-primary">local_shipping</span>
<div>
<p class="font-bold"><?= htmlspecialchars($p['patente']) ?> - <?= htmlspecialchars($p['marca'] . ' ' . $p['modelo']) ?></p>
<p class="font-medium"><?= htmlspecialchars($p['titulo']) ?></p>
</div>
</div>
<span class="px-2 py-1 rounded text-[10px] font-bold uppercase <?= $prioridadColors[$p['prioridad']] ?? 'bg-gray-100 text-gray-800' ?>"><?= $prioridadLabels[$p['prioridad']] ?? $p['prioridad'] ?></span>
</div>
<p class="text-sm text-on-surface-variant"><?= nl2br(htmlspecialchars($p['descripcion'])) ?></p>
<?php
$tareasMarcadas = !empty($p['tareas']) ? explode("\n", $p['tareas']) : [];
$repuestosCamion = $repuestosPorCamion[$p['id_camion']] ?? [];
$tareasCamion = $tareasPorCamion[$p['id_camion']] ?? [];
?>
<?php if (!empty($repuestosCamion)): ?>
<div class="mt-2">
<label class="text-[10px] font-bold text-on-surface-variant uppercase">Repuestos asociados:</label>
<div class="flex flex-wrap gap-2 mt-1">
<?php foreach ($repuestosCamion as $rep): ?>
<label class="flex items-center gap-1 text-xs text-on-surface-variant">
<input type="checkbox" name="repuestos_check[]" value="<?= $rep['id_repuesto'] ?>" data-nombre="<?= htmlspecialchars($rep['nombre']) ?>" <?= in_array($rep['id_repuesto'], explode(',', $p['tareas_rep'] ?? '')) ? 'checked' : '' ?>/>
<span><?= htmlspecialchars($rep['nombre']) ?></span>
</label>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
<?php if (!empty($tareasCamion)): ?>
<div class="mt-2">
<label class="text-[10px] font-bold text-on-surface-variant uppercase">Tareas del vehículo:</label>
<div class="flex flex-wrap gap-2 mt-1">
<?php foreach ($tareasCamion as $tar): ?>
<label class="flex items-center gap-1 text-xs text-on-surface-variant">
<input type="checkbox" name="tareas_check[]" value="<?= $tar['id_tarea'] ?>" data-nombre="<?= htmlspecialchars($tar['nombre']) ?>" <?= in_array($tar['nombre'], $tareasMarcadas) ? 'checked' : '' ?>/>
<span><?= htmlspecialchars($tar['nombre']) ?></span>
</label>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
<?php if (!empty($p['tareas'])): ?>
<div class="mt-2">
<label class="text-[10px] font-bold text-on-surface-variant uppercase">Tareas realizadas:</label>
<ul class="list-disc list-inside text-sm text-on-surface-variant mt-1">
<?php foreach (explode("\n", $p['tareas']) as $tarea): ?>
<li><?= htmlspecialchars($tarea) ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>
<?php if (!empty($p['respuesta'])): ?>
<div class="mt-2">
<label class="text-[10px] font-bold text-on-surface-variant uppercase">Respuesta:</label>
<div class="text-sm text-on-surface-variant mt-1"><?= nl2br(htmlspecialchars($p['respuesta'])) ?></div>
</div>
<?php endif; ?>
<?php if ($esMantenimiento): ?>
<div class="mt-2">
<label class="text-[10px] font-bold text-on-surface-variant uppercase">Agregar respuesta:</label>
<textarea name="respuesta" id="resp_<?= $p['id_pedido'] ?>" rows="2" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm" placeholder="Escriba su respuesta..."><?= htmlspecialchars($p['respuesta'] ?? '') ?></textarea>
<button onclick="guardarRespuesta(<?= $p['id_pedido'] ?>)" class="mt-1 text-xs font-bold text-blue-600 hover:opacity-80">Guardar respuesta</button>
</div>
<?php endif; ?>
<div class="flex justify-between items-center mt-2">
<span class="text-[10px] text-outline">Por: <?= htmlspecialchars($p['creador'] ?? 'admin') ?> | <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></span>
<div class="flex items-center gap-2">
<select name="estado" onchange="actualizarEstado(<?= $p['id_pedido'] ?>, this.value)" class="border border-outline-variant rounded p-1 text-xs bg-surface-container-high">
<option value="pendiente" <?= $p['estado']=='pendiente'?'selected':'' ?>>Pendiente</option>
<option value="en_proceso" <?= $p['estado']=='en_proceso'?'selected':'' ?>>En proceso</option>
<option value="completado" <?= $p['estado']=='completado'?'selected':'' ?>>Completado</option>
</select>
<button onclick="abrirEditarPedido(<?= $p['id_pedido'] ?>)" class="text-xs font-bold text-blue-600 hover:opacity-80">Marcar</button>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<!-- Alertas -->
<?php if (!empty($alertas)): ?>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
<h4 class="font-headline-sm text-headline-sm text-primary flex items-center gap-2"><span class="material-symbols-outlined text-amber-600">notifications_active</span> Alertas Activas</h4>
</div>
<div class="divide-y divide-outline-variant">
<?php foreach ($alertas as $a): ?>
<div class="px-6 py-3 flex items-start gap-3">
<span class="material-symbols-outlined <?= $a['severidad']==='rojo'?'text-red-600':($a['severidad']==='amarillo'?'text-amber-600':'text-green-600') ?> mt-0.5">warning</span>
<div class="flex-1">
<p class="font-medium"><?= htmlspecialchars($a['mensaje']) ?></p>
<p class="text-[10px] text-outline"><?= $a['patente'] ?? '' ?> · <?= date('d/m/Y', strtotime($a['fecha_creacion'])) ?></p>
</div>
<span class="text-[10px] font-bold uppercase border border-outline px-2 py-1 rounded"><?= $a['severidad'] ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<!-- Programacion de mantenimiento de todos los vehiculos -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
<h4 class="font-headline-sm text-headline-sm text-primary flex items-center gap-2"><span class="material-symbols-outlined">event</span> Programacion de Proxs. Servicios</h4>
</div>
<div class="overflow-x-auto">
<table class="w-full text-sm">
<thead class="bg-surface-container-high/50">
<tr class="text-left font-label-caps text-[10px] text-on-surface-variant">
<th class="px-4 py-2">CAMION</th>
<th class="px-4 py-2 text-right">KM ACTUAL</th>
<th class="px-4 py-2 text-right">PROX. KM</th>
<th class="px-4 py-2 text-right">FALTANTE</th>
<th class="px-4 py-2 text-left">ULTIMO MANT.</th>
<th class="px-4 py-2 text-center">ESTADO</th>
</tr>
</thead>
<tbody>
<?php foreach ($allCamiones as $c):
$falt = ($c['proximo_mantenimiento_km'] ?? null) ? ((float)$c['proximo_mantenimiento_km'] - (float)$c['kilometraje_actual']) : null;
$est = 'al_dia';
if ($falt === null) { $est = 'sin_datos'; }
elseif ($falt <= 0) { $est = 'vencido'; }
elseif ($falt <= 5000) { $est = 'proximo'; }
$ultMant = isset($ultimosMants) ? array_filter($ultimosMants, fn($m)=>$m['id_camion']==$c['id_camion']) : [];
$ultMantFecha = $ultMant ? date('d/m/Y', strtotime(reset($ultMant)['fecha'])) : '-';
?>
<tr class="border-t border-outline-variant hover:bg-surface-container transition-colors">
<td class="px-4 py-3 font-bold"><?= htmlspecialchars($c['patente']) ?> - <?= htmlspecialchars($c['marca'] . ' ' . $c['modelo']) ?></td>
<td class="px-4 py-3 text-right font-data-mono"><?= number_format((float)$c['kilometraje_actual'], 0) ?></td>
<td class="px-4 py-3 text-right font-data-mono"><?= ($c['proximo_mantenimiento_km'] ?? null) ? number_format((float)$c['proximo_mantenimiento_km'], 0) : '-' ?></td>
<td class="px-4 py-3 text-right font-bold <?= $est==='vencido'?'text-red-600':($est==='proximo'?'text-amber-600':'text-green-600') ?>"><?= $falt === null ? '-' : ($falt > 0 ? number_format($falt, 0) : 'VENCIDO') ?></td>
<td class="px-4 py-3 font-data-mono text-xs"><?= $ultMantFecha ?></td>
<td class="px-4 py-3 text-center">
<span class="px-2 py-1 rounded text-[10px] font-bold uppercase <?= ['al_dia'=>'bg-green-50 text-green-700','proximo'=>'bg-amber-50 text-amber-700','vencido'=>'bg-red-50 text-red-700','sin_datos'=>'bg-gray-50 text-gray-600'][$est] ?>"><?= ['al_dia'=>'Al dia','proximo'=>'Proximo','vencido'=>'Vencido','sin_datos'=>'Sin datos'][$est] ?></span>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- Historial reciente -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
<h4 class="font-headline-sm text-headline-sm text-primary flex items-center gap-2"><span class="material-symbols-outlined text-sm">history</span> Historial Recentes</h4>
</div>
<?php if (empty($ultimosMants)): ?>
<p class="p-6 text-center text-on-surface-variant">Sin historial.</p>
<?php else: ?>
<div class="divide-y divide-outline-variant">
<?php foreach ($ultimosMants as $m): ?>
<div class="px-6 py-3 flex justify-between items-center">
<div>
<span class="font-bold"><?= htmlspecialchars($m['patente']) ?></span>
<span class="text-[10px] text-on-surface-variant font-data-mono">(<?= date('d/m/Y', strtotime($m['fecha'])) ?>)</span>
</div>
<div class="text-right">
<span class="text-xs text-on-surface-variant"> <?= number_format((float)$m['kilometraje'], 0)?> KM</span>
<span class="text-red-600 font-bold"> $<?= number_format((float)$m['costo'], 2) ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<?php endif; ?>

<!-- Assigned Trucks + Metrics -->
<?php if (!$esMantenimiento): ?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
<div class="md:col-span-2 relative overflow-hidden rounded-xl bg-primary-container text-on-primary p-6 flex flex-col justify-between min-h-[200px]">
<div class="z-10">
<span class="font-label-caps bg-on-primary-container/20 px-3 py-1 rounded-full text-xs">VEHICULOS ASIGNADOS</span>
<h3 class="font-headline-md text-headline-md mt-4 mb-4">
<?php if (count($vehiculos) > 0): ?>
<?= count($vehiculos) === 1 ? '1 vehiculo asignado' : count($vehiculos) . ' vehiculos asignados' ?>
<?php else: ?>
Sin vehiculos asignados
<?php endif; ?>
</h3>

<?php if (count($vehiculos) > 0): ?>
<div class="flex flex-col gap-3 mt-2">
<?php foreach ($vehiculos as $v): ?>
<div class="flex justify-between items-center border-b border-on-primary-container/20 pb-3 last:border-0 last:pb-0">
    <div class="flex flex-col">
        <span class="font-bold text-lg"><?= htmlspecialchars($v['marca'] . ' ' . $v['patente']) ?></span>
        <span class="text-on-primary-container text-xs mt-1">Estado: <?= ucfirst($v['estado']) ?> | Asignado: <?= date('d/m/Y', strtotime($v['fecha_asignacion'])) ?></span>
    </div>
    <div class="flex flex-col items-end">
        <span class="text-on-primary-container text-[10px] uppercase font-bold">Kilometraje</span>
        <span class="font-data-mono text-body-md"><?= number_format($ultimoKmViaje[$v['id_camion']] ?? $v['kilometraje_actual'], 0) ?> KM</span>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-on-primary-container mt-1 opacity-80">Contacte al administrador para recibir asignacion</p>
<?php endif; ?>
</div>
<div class="absolute right-[-40px] bottom-[-20px] opacity-10 pointer-events-none">
<span class="material-symbols-outlined text-[200px]">local_shipping</span>
</div>
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 flex flex-col justify-between">
<h4 class="font-label-caps text-on-surface-variant border-b border-outline-variant pb-2 mb-4">MIS METRICAS DEL MES</h4>

<!-- KM Counter -->
<div class="bg-surface-container-high/40 rounded-xl p-4 mb-4 text-center border border-outline-variant/50">
<span class="font-label-caps text-outline text-[10px] tracking-widest uppercase">Kilometros Recorridos</span>
<div class="flex items-baseline justify-center gap-1 mt-2">
<span class="font-data-mono text-5xl font-bold text-primary tabular-nums"><?= number_format($kmData['total'], 0) ?></span>
<span class="text-on-surface-variant font-bold text-sm">KM</span>
</div>
<div class="flex items-center justify-center gap-3 mt-2">
<div class="flex items-center gap-1 text-xs <?= $eficiencia >= 0 ? 'text-green-600' : 'text-red-600' ?>">
<span class="material-symbols-outlined text-sm"><?= $eficiencia >= 0 ? 'trending_up' : 'trending_down' ?></span>
<span><?= ($eficiencia >= 0 ? '+' : '') . number_format($eficiencia, 1) ?>% vs mes anterior</span>
</div>
</div>
</div>

<!-- Fuel Counter + Details -->
<div class="bg-secondary-container/40 rounded-xl p-4 text-center border border-secondary-container/60">
<span class="font-label-caps text-outline text-[10px] tracking-widest uppercase">Combustible</span>
<div class="flex items-baseline justify-center gap-1 mt-2">
<span class="font-data-mono text-3xl font-bold text-primary tabular-nums"><?= number_format($combData['litros'], 1) ?></span>
<span class="text-on-surface-variant font-bold text-xs">LTS</span>
</div>
</div>

<!-- Efficiency -->
<div class="mt-3 bg-primary-fixed/30 rounded-xl p-3 flex items-center justify-between border border-primary-fixed/50">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-lg">speed</span>
<span class="font-label-caps text-on-surface-variant text-[10px]">Rendimiento</span>
</div>
<span class="font-data-mono text-lg font-bold text-primary"><?= number_format($rendimiento, 1) ?> <span class="text-xs font-normal text-on-surface-variant">km/l</span></span>
</div>
</div>
</div>

<!-- KM sin autorizar -->
<?php if ($kmSinAutData['total'] > 0): ?>
<div class="bg-amber-50 border border-amber-300 rounded-xl p-5 flex items-start gap-4">
    <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-amber-600">hourglass_empty</span>
    </div>
    <div class="flex-1">
        <h4 class="font-bold text-amber-900">KM Pendientes de Autorizacion</h4>
        <p class="text-amber-700 text-sm mt-1">Tenes <span class="font-bold text-lg"><?= number_format($kmSinAutData['total'], 0) ?> KM</span> en viajes cerrados que aun no fueron autorizados por el administrador.</p>
    </div>
</div>
<?php endif; ?>

<!-- Lista de vehículos asignados -->
<?php if (count($vehiculos) > 1): ?>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Mis Vehiculos</h3>
<span class="font-label-caps text-xs text-outline"><?= count($vehiculos) ?> ASIGNADOS</span>
</div>
<table class="w-full text-left">
<thead class="bg-surface-container-high/30">
<tr>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant">PATENTE</th>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant">MARCA / MODELO</th>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM</th>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ASIGNADO</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php foreach ($vehiculos as $v): ?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-6 py-4 font-bold"><?= htmlspecialchars($v['patente']) ?></td>
<td class="px-6 py-4"><?= htmlspecialchars($v['marca'] . ' ' . $v['modelo']) ?></td>
<td class="px-6 py-4 text-right font-data-mono"><?= number_format($ultimoKmViaje[$v['id_camion']] ?? $v['kilometraje_actual'], 0) ?> KM</td>
<td class="px-6 py-4 text-center">
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase <?= $v['estado'] === 'activo' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= $v['estado'] ?></span>
</td>
<td class="px-6 py-4 text-center text-sm"><?= date('d/m/Y', strtotime($v['fecha_asignacion'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<!-- Ultimas Cargas -->
<?php if (!empty($ultimasCargas)): ?>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern">
<div class="px-6 py-4 border-b border-outline-variant flex items-center justify-between">
<h3 class="font-headline-sm text-headline-sm text-primary flex items-center gap-2">
<span class="material-symbols-outlined text-lg">local_gas_station</span> Ultimas Cargas
</h3>
</div>
<div class="divide-y divide-outline-variant">
<?php foreach ($ultimasCargas as $carga): ?>
<div class="px-6 py-4 flex items-center justify-between hover:bg-surface-container-low transition-colors">
<div class="flex items-center gap-3">
<div class="w-10 h-10 rounded-full bg-primary/5 flex items-center justify-center">
<span class="material-symbols-outlined text-primary">local_gas_station</span>
</div>
<div>
<p class="font-bold text-primary text-sm"><?= htmlspecialchars($carga['patente']) ?></p>
<p class="text-xs text-on-surface-variant"><?= date('d/m/Y H:i', strtotime($carga['fecha'])) ?></p>
</div>
</div>
<div class="text-right">
<p class="font-data-mono text-primary font-bold"><?= number_format($carga['litros'], 4) ?> L</p>
<p class="text-xs text-on-surface-variant">$<?= number_format($carga['precio_litro'], 4) ?>/L<?php
    $det = [];
    if (!empty($carga['kilometraje_al_cargar']) && $carga['kilometraje_al_cargar'] > 0) $det[] = number_format($carga['kilometraje_al_cargar'], 0) . ' KM';
    if (!empty($carga['horas_al_cargar']) && $carga['horas_al_cargar'] > 0) $det[] = number_format($carga['horas_al_cargar'], 1) . ' HS';
    if (!empty($det)) echo ' | ' . implode(' / ', $det);
?></p>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="space-y-4">
<h3 class="font-label-caps text-on-surface-variant px-1">ACCIONES RAPIDAS</h3>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
<a href="<?= BASE_URL ?>/chofer/checklist.php" class="card-modern flex flex-col items-center justify-center gap-4 p-7 bg-primary text-on-primary border border-white/10 rounded-2xl hover:bg-primary/90 transition-all duration-300 group cursor-pointer active:scale-95 shadow-md">
<div class="bg-white/15 w-14 h-14 rounded-2xl flex items-center justify-center group-hover:bg-white/25 transition-all">
<span class="material-symbols-outlined text-3xl">fact_check</span>
</div>
<span class="font-headline-sm text-headline-sm text-center">Checklist Máquinas</span>
</a>
<?php if (hasPermission('combustible_cargar')): ?>
<a href="<?= BASE_URL ?>/chofer/cargar_combustible.php" class="card-modern flex flex-col items-center justify-center gap-4 p-7 bg-primary text-on-primary border border-white/10 rounded-2xl hover:bg-primary/90 transition-all duration-300 group cursor-pointer active:scale-95 shadow-md">
<div class="bg-white/15 w-14 h-14 rounded-2xl flex items-center justify-center group-hover:bg-white/25 transition-all">
<span class="material-symbols-outlined text-3xl">local_gas_station</span>
</div>
<span class="font-headline-sm text-headline-sm text-center">Cargar Combustible</span>
</a>
<?php endif; ?>
<?php if (hasPermission('mantenimiento_crear')): ?>
<a href="<?= BASE_URL ?>/chofer/registrar_mantenimiento.php" class="card-modern flex flex-col items-center justify-center gap-4 p-7 bg-primary text-on-primary border border-white/10 rounded-2xl hover:bg-primary/90 transition-all duration-300 group cursor-pointer active:scale-95 shadow-md">
<div class="bg-white/15 w-14 h-14 rounded-2xl flex items-center justify-center group-hover:bg-white/25 transition-all">
<span class="material-symbols-outlined text-3xl">build</span>
</div>
<span class="font-headline-sm text-headline-sm text-center">Registrar Mantenimiento</span>
</a>
<?php endif; ?>
<?php if (hasPermission('kilometraje_cargar')): ?>
<a href="<?= BASE_URL ?>/chofer/viajes.php" class="card-modern flex flex-col items-center justify-center gap-4 p-7 bg-primary text-on-primary border border-white/10 rounded-2xl hover:bg-primary/90 transition-all duration-300 group cursor-pointer active:scale-95 shadow-md">
<div class="bg-white/15 w-14 h-14 rounded-2xl flex items-center justify-center group-hover:bg-white/25 transition-all">
<span class="material-symbols-outlined text-3xl">map</span>
</div>
<span class="font-headline-sm text-headline-sm text-center">Ver Mis Viajes</span>
</a>
<?php endif; ?>
</div>
</div>

<!-- Mantenimientos Pendientes -->
<?php if (!empty($mantsPendientes)): ?>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary flex items-center gap-2">
<span class="material-symbols-outlined text-amber-600">warning</span>
Mantenimientos Pendientes
</h3>
</div>
<div class="divide-y divide-outline-variant">
<?php foreach ($mantsPendientes as $m): ?>
<div class="px-6 py-4 flex items-center gap-4">
<div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-amber-600">build</span>
</div>
<div class="flex-1">
<p class="font-bold"><?= htmlspecialchars($m['patente']) ?> - <?= htmlspecialchars($m['marca']) ?></p>
<p class="text-sm text-on-surface-variant">KM Actual: <?= number_format($ultimoKmViaje[$m['id_camion']] ?? $m['kilometraje_actual'], 0) ?> | Prox. Servicio: <?= number_format($m['proximo_mantenimiento_km'], 0) ?> KM</p>
</div>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase bg-red-100 text-red-800 border border-red-200">PENDIENTE</span>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<!-- Maintenance History -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Historial de Mantenimiento</h3>
<span class="font-label-caps text-xs text-outline">VISTA PREVENTIVA</span>
</div>
<div class="p-0">
<table class="w-full text-left">
<thead class="bg-surface-container-high/30">
<tr>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant">VEHICULO</th>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant">TIPO DE SERVICIO</th>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant">FECHA</th>
<th class="px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">COSTO</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($mants)): ?>
<tr><td colspan="4" class="px-6 py-8 text-center text-on-surface-variant">No hay mantenimientos registrados</td></tr>
<?php else: ?>
<?php foreach ($mants as $m): ?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-6 py-4 font-bold"><?= htmlspecialchars($m['patente']) ?></td>
<td class="px-6 py-4 flex items-center gap-2">
<span class="material-symbols-outlined <?= $m['costo'] > 500 ? 'text-error' : 'text-green-600' ?> text-sm"><?= $m['tipo'] === 'cambio_aceite' ? 'oil_barrel' : 'build' ?></span>
<span class="font-medium"><?= ucfirst(str_replace('_', ' ', $m['tipo'])) ?></span>
</td>
<td class="px-6 py-4 font-data-mono"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
<td class="px-6 py-4 text-right font-data-mono">$<?= number_format($m['costo'], 2) ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
  </tbody>
  </table>
 </div>
 </div>
<?php endif; ?>
</div>
</main>

<?php if ($esMantenimiento): ?>
<div id="modalDetallePedido" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)cerrarDetallePedido()">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Detalle de Pedido</h3>
<button onclick="cerrarDetallePedido()"><span class="material-symbols-outlined">close</span></button>
</div>
<form id="formDetallePedido" method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="pedido_update"/>
<input type="hidden" name="id_pedido" id="det_id_pedido"/>

<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Estado</label>
<select name="estado" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="pendiente">Pendiente</option>
<option value="en_proceso">En proceso</option>
<option value="completado">Completado</option>
</select>
</div>

<div id="det_repuestos" class="hidden">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Repuestos a usar:</label>
<div class="grid grid-cols-2 gap-2 mt-1 space-y-0" id="listaRepuestosChk"></div>
</div>

<div id="det_tareas" class="hidden">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tareas realizadas:</label>
<div class="grid grid-cols-2 gap-2 mt-1 space-y-0" id="listaTareasChk"></div>
</div>

<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Respuesta</label>
<textarea name="respuesta" id="det_respuesta" rows="3" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Detalles de lo realizado..."></textarea>
</div>

<div class="flex gap-3 pt-4">
<button type="button" onclick="cerrarDetallePedido()" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded-lg font-bold hover:bg-blue-700">Guardar cambios</button>
</div>
</form>
</div>
</div>
<?php endif; ?>

<script>
<?php if ($esMantenimiento): ?>
function abrirEditarPedido(id) {
    const rows = <?= json_encode(array_column($pedidos, null, 'id_pedido')) ?>;
    const repuestos = <?= json_encode($repuestosPorCamion) ?>;
    const tareas = <?= json_encode($tareasPorCamion) ?>;
    const p = rows[id];
    if (!p) return;

    document.getElementById('det_id_pedido').value = p.id_pedido;
    document.getElementById('det_respuesta').value = p.respuesta || '';
    document.querySelector('#formDetallePedido select[name="estado"]').value = p.estado;

    const tareasMarcadas = p.tareas ? p.tareas.split("\n") : [];
    let htmlRep = '';
    const camRep = repuestos[p.id_camion] || [];
    if (camRep.length > 0) {
        document.getElementById('det_repuestos').classList.remove('hidden');
        htmlRep = camRep.map(r => {
            const checked = (p.tareas_rep || '').includes(String(r.id_repuesto)) ? 'checked' : '';
            return `<label class="flex items-center gap-1 text-xs"><input type="checkbox" name="repuestos_check[]" value="${r.id_repuesto}" ${checked}/> ${r.nombre}</label>`;
        }).join('');
    } else {
        document.getElementById('det_repuestos').classList.add('hidden');
    }
    document.getElementById('listaRepuestosChk').innerHTML = htmlRep;

    let htmlTar = '';
    const camTar = tareas[p.id_camion] || [];
    if (camTar.length > 0) {
        document.getElementById('det_tareas').classList.remove('hidden');
        htmlTar = camTar.map(t => {
            const checked = tareasMarcadas.includes(t.nombre) ? 'checked' : '';
            return `<label class="flex items-center gap-1 text-xs"><input type="checkbox" name="tareas_check[]" value="${t.nombre}" ${checked}/> ${t.nombre}</label>`;
        }).join('');
    } else {
        document.getElementById('det_tareas').classList.add('hidden');
    }
    document.getElementById('listaTareasChk').innerHTML = htmlTar;

    document.getElementById('modalDetallePedido').classList.remove('hidden');
}
function cerrarDetallePedido() {
    document.getElementById('modalDetallePedido').classList.add('hidden');
}
function actualizarEstado(id, estado) {
    const formData = new FormData();
    formData.append('action', 'pedido_update');
    formData.append('id_pedido', id);
    formData.append('estado', estado);
    fetch('', {method:'POST', body: formData})
        .then(() => location.reload())
        .catch(() => {});
}
function guardarRespuesta(id) {
    const resp = document.getElementById('resp_' + id).value;
    const formData = new FormData();
    formData.append('action', 'pedido_update');
    formData.append('id_pedido', id);
    formData.append('estado', 'en_proceso');
    formData.append('respuesta', resp);
    fetch('', {method:'POST', body: formData})
        .then(() => location.reload())
        .catch(() => {});
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
