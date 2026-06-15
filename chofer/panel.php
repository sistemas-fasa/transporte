<?php
require_once __DIR__ . '/../includes/auth.php';
requireChofer();
$pageTitle = 'Panel del Chofer';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_chofer.php';

$db = getDB();
$userId = getCurrentUserId();
$idChofer = getChoferIdFromUser();

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
if ($userId && $hasUsuarioId) { $kmSql .= " AND usuario_id = ?"; $kmParams[] = $userId; }
elseif ($idChofer) { $kmSql .= " AND id_chofer = ?"; $kmParams[] = $idChofer; }
$kmMes = $db->prepare($kmSql);
$kmMes->execute($kmParams);
$kmData = $kmMes->fetch();

// Combustible
$combSql = "SELECT COALESCE(SUM(litros),0) as litros, COALESCE(SUM(importe_total),0) as total FROM combustible WHERE MONTH(fecha) = ? AND YEAR(fecha) = ?";
$combParams = [$mes, $anio];
if ($userId) { $combSql .= " AND id_usuario_registra = ?"; $combParams[] = $userId; }
elseif ($idChofer) { $combSql .= " AND id_chofer = ?"; $combParams[] = $idChofer; }
$combMes = $db->prepare($combSql);
$combMes->execute($combParams);
$combData = $combMes->fetch();

// Previous month comparison
$mesAnt = $mes == 1 ? 12 : $mes - 1;
$anioAnt = $mes == 1 ? $anio - 1 : $anio;
$kmAntParams = [$mesAnt, $anioAnt];
$kmAntSql = "SELECT COALESCE(SUM(km_recorridos),0) as total FROM km_recorrido WHERE MONTH(fecha) = ? AND YEAR(fecha) = ? AND estado = 'aprobado'";
if ($userId && $hasUsuarioId) { $kmAntSql .= " AND usuario_id = ?"; $kmAntParams[] = $userId; }
elseif ($idChofer) { $kmAntSql .= " AND id_chofer = ?"; $kmAntParams[] = $idChofer; }
$kmAnt = $db->prepare($kmAntSql);
$kmAnt->execute($kmAntParams);
$kmAntData = $kmAnt->fetch();
$eficiencia = $kmAntData['total'] > 0 ? (($kmData['total'] - $kmAntData['total']) / $kmAntData['total'] * 100) : 0;

// Rendimiento km/l
$rendimiento = $combData['litros'] > 0 ? ($kmData['total'] / $combData['litros']) : 0;

// KM sin autorizar (estado = 'cerrado', no aprobado)
$kmSinAutSql = "SELECT COALESCE(SUM(km_recorridos),0) as total FROM km_recorrido WHERE MONTH(fecha) = ? AND YEAR(fecha) = ? AND estado = 'cerrado'";
$kmSinAutParams = [$mes, $anio];
if ($userId && $hasUsuarioId) { $kmSinAutSql .= " AND usuario_id = ?"; $kmSinAutParams[] = $userId; }
elseif ($idChofer) { $kmSinAutSql .= " AND id_chofer = ?"; $kmSinAutParams[] = $idChofer; }
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
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-5xl mx-auto">
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

<!-- Assigned Trucks + Metrics -->
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
<div class="grid grid-cols-2 gap-3">
<div class="bg-secondary-container/40 rounded-xl p-4 text-center border border-secondary-container/60">
<span class="font-label-caps text-outline text-[10px] tracking-widest uppercase">Combustible</span>
<div class="flex items-baseline justify-center gap-1 mt-2">
<span class="font-data-mono text-3xl font-bold text-primary tabular-nums"><?= number_format($combData['litros'], 1) ?></span>
<span class="text-on-surface-variant font-bold text-xs">LTS</span>
</div>
</div>
<div class="bg-tertiary-fixed/40 rounded-xl p-4 text-center border border-tertiary-fixed/60">
<span class="font-label-caps text-outline text-[10px] tracking-widest uppercase">Gasto</span>
<div class="flex items-baseline justify-center gap-1 mt-2">
<span class="font-data-mono text-3xl font-bold text-tertiary tabular-nums">$<?= number_format($combData['total'], 0) ?></span>
</div>
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

<!-- Quick Actions -->
<div class="space-y-4">
<h3 class="font-label-caps text-on-surface-variant px-1">ACCIONES RAPIDAS</h3>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
<a href="<?= BASE_URL ?>/chofer/cargar_combustible.php" class="flex flex-col items-center justify-center gap-4 p-8 bg-surface-container-lowest border border-outline-variant rounded-xl hover:bg-primary-container hover:text-on-primary transition-all duration-300 group cursor-pointer active:scale-95">
<div class="bg-secondary-fixed w-16 h-16 rounded-full flex items-center justify-center group-hover:bg-on-primary-container/20">
<span class="material-symbols-outlined text-4xl">local_gas_station</span>
</div>
<span class="font-headline-sm text-headline-sm">Cargar Combustible</span>
</a>
<a href="<?= BASE_URL ?>/chofer/registrar_mantenimiento.php" class="flex flex-col items-center justify-center gap-4 p-8 bg-surface-container-lowest border border-outline-variant rounded-xl hover:bg-primary-container hover:text-on-primary transition-all duration-300 group cursor-pointer active:scale-95">
<div class="bg-tertiary-fixed w-16 h-16 rounded-full flex items-center justify-center group-hover:bg-on-primary-container/20">
<span class="material-symbols-outlined text-4xl">build</span>
</div>
<span class="font-headline-sm text-headline-sm">Registrar Mantenimiento</span>
</a>
<a href="<?= BASE_URL ?>/chofer/viajes.php" class="flex flex-col items-center justify-center gap-4 p-8 bg-surface-container-lowest border border-outline-variant rounded-xl hover:bg-primary-container hover:text-on-primary transition-all duration-300 group cursor-pointer active:scale-95">
<div class="bg-primary-fixed w-16 h-16 rounded-full flex items-center justify-center group-hover:bg-on-primary-container/20">
<span class="material-symbols-outlined text-4xl">map</span>
</div>
<span class="font-headline-sm text-headline-sm">Ver Mis Viajes</span>
</a>
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
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
