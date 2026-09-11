<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Dashboard Ejecutivo';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();



$mes = date('m');
$anio = date('Y');

// KPI Data
$totalCamiones = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(estado='activo'), 0) as activos FROM camiones")->fetch();
$totalChoferes = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(estado='activo'), 0) as activos FROM choferes")->fetch();

$kmMes = $db->prepare("SELECT COALESCE(SUM(km_recorridos),0) as total FROM km_recorrido WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$kmMes->execute([$mes, $anio]);
$kmData = $kmMes->fetch();

$litrosMes = $db->prepare("SELECT COALESCE(SUM(litros),0) as total FROM combustible WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$litrosMes->execute([$mes, $anio]);
$litrosData = $litrosMes->fetch();

$gastoCombustible = $db->prepare("SELECT COALESCE(SUM(litros * precio_litro),0) as total FROM combustible WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$gastoCombustible->execute([$mes, $anio]);
$gastoCombData = $gastoCombustible->fetch();

$gastoMantenimiento = $db->prepare("SELECT COALESCE(SUM(costo),0) as total FROM mantenimientos WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$gastoMantenimiento->execute([$mes, $anio]);
$gastoMantData = $gastoMantenimiento->fetch();

// Alertas (Auto-generar antes de consultar)
require_once __DIR__ . '/../includes/alertas_helper.php';
generarAlertasAutomaticas($db);
$alertas = $db->query("SELECT a.*, c.patente FROM alertas a LEFT JOIN camiones c ON a.id_referencia = c.id_camion WHERE resuelta = 0 AND severidad IN ('rojo','amarillo') ORDER BY FIELD(severidad,'rojo','amarillo'), fecha_creacion DESC LIMIT 10")->fetchAll();

// Gasto por camion (mes actual) con KM
$gastoCamion = $db->prepare("
    SELECT c.patente, c.marca,
        COALESCE((SELECT SUM(co2.litros * co2.precio_litro) FROM combustible co2 WHERE co2.id_camion = c.id_camion AND MONTH(co2.fecha)=? AND YEAR(co2.fecha)=?),0) as total,
        COALESCE((SELECT SUM(hr.km_recorridos) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion AND MONTH(hr.fecha)=? AND YEAR(hr.fecha)=?),0) as km
    FROM camiones c
    ORDER BY total DESC LIMIT 5
");
$gastoCamion->execute([$mes, $anio, $mes, $anio]);
$gastoCamiones = $gastoCamion->fetchAll();
$maxGasto = $gastoCamiones ? max(array_column($gastoCamiones, 'total')) : 1;

// Combustible por mes (ultimos 6 meses)
$combustibleMeses = $db->query("
    SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(litros) as litros, SUM(litros * precio_litro) as total, COUNT(*) as cargas
    FROM combustible
    WHERE fecha >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY mes ORDER BY mes ASC
")->fetchAll();

// Detalle de combustible por camion en los ultimos 6 meses
$combDetalleMes = $db->query("
    SELECT DATE_FORMAT(co.fecha, '%Y-%m') as mes, c.id_camion, c.patente, c.marca, c.modelo,
           SUM(co.litros) as litros, SUM(co.litros * co.precio_litro) as total, COUNT(*) as cargas
    FROM combustible co
    JOIN camiones c ON co.id_camion = c.id_camion
    WHERE co.fecha >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY mes, c.id_camion
    ORDER BY mes ASC, litros DESC
")->fetchAll();

// Kilometros por camion (mes actual)
$kmCamion = $db->prepare("SELECT c.patente, COALESCE(SUM(h.km_recorridos),0) as total FROM camiones c LEFT JOIN km_recorrido h ON c.id_camion = h.id_camion AND MONTH(h.fecha)=? AND YEAR(h.fecha)=? GROUP BY c.id_camion ORDER BY total DESC LIMIT 5");
$kmCamion->execute([$mes, $anio]);
$kmCamiones = $kmCamion->fetchAll();

// Consumo Mensual L/100 Km por camion
$rendimiento = $db->prepare("
    SELECT c.id_camion, c.patente, c.marca, c.modelo,
        COALESCE((SELECT SUM(km_recorridos) FROM km_recorrido WHERE id_camion = c.id_camion AND MONTH(fecha)=? AND YEAR(fecha)=?), 0) as km,
        COALESCE((SELECT COUNT(*) FROM km_recorrido WHERE id_camion = c.id_camion AND MONTH(fecha)=? AND YEAR(fecha)=?), 0) as viajes,
        COALESCE((SELECT SUM(litros) FROM combustible WHERE id_camion = c.id_camion AND MONTH(fecha)=? AND YEAR(fecha)=?), 0) as litros,
        COALESCE((SELECT SUM(litros * precio_litro) FROM combustible WHERE id_camion = c.id_camion AND MONTH(fecha)=? AND YEAR(fecha)=?), 0) as gasto,
        COALESCE((SELECT COUNT(*) FROM combustible WHERE id_camion = c.id_camion AND MONTH(fecha)=? AND YEAR(fecha)=?), 0) as cargas
    FROM camiones c
    HAVING km > 0 AND litros > 0
    ORDER BY ((litros * 100) / km) ASC LIMIT 5
");
$rendimiento->execute([$mes, $anio, $mes, $anio, $mes, $anio, $mes, $anio, $mes, $anio]);
$rendimientos = $rendimiento->fetchAll();

// Consumo Anual / Histórico L/100 Km por camion
$rendimientoAnual = $db->prepare("
    SELECT c.id_camion, c.patente, c.marca, c.modelo,
        COALESCE((SELECT SUM(km_recorridos) FROM km_recorrido WHERE id_camion = c.id_camion AND YEAR(fecha)=?), 0) as km,
        COALESCE((SELECT COUNT(*) FROM km_recorrido WHERE id_camion = c.id_camion AND YEAR(fecha)=?), 0) as viajes,
        COALESCE((SELECT SUM(litros) FROM combustible WHERE id_camion = c.id_camion AND YEAR(fecha)=?), 0) as litros,
        COALESCE((SELECT SUM(litros * precio_litro) FROM combustible WHERE id_camion = c.id_camion AND YEAR(fecha)=?), 0) as gasto,
        COALESCE((SELECT COUNT(*) FROM combustible WHERE id_camion = c.id_camion AND YEAR(fecha)=?), 0) as cargas
    FROM camiones c
    HAVING km > 0 AND litros > 0
    ORDER BY ((litros * 100) / km) ASC LIMIT 5
");
$rendimientoAnual->execute([$anio, $anio, $anio, $anio, $anio]);
$rendimientosAnual = $rendimientoAnual->fetchAll();

if (empty($rendimientosAnual)) {
    $rendimientoAnualGral = $db->query("
        SELECT c.id_camion, c.patente, c.marca, c.modelo,
            COALESCE((SELECT SUM(km_recorridos) FROM km_recorrido WHERE id_camion = c.id_camion), 0) as km,
            COALESCE((SELECT COUNT(*) FROM km_recorrido WHERE id_camion = c.id_camion), 0) as viajes,
            COALESCE((SELECT SUM(litros) FROM combustible WHERE id_camion = c.id_camion), 0) as litros,
            COALESCE((SELECT SUM(litros * precio_litro) FROM combustible WHERE id_camion = c.id_camion), 0) as gasto,
            COALESCE((SELECT COUNT(*) FROM combustible WHERE id_camion = c.id_camion), 0) as cargas
        FROM camiones c
        HAVING km > 0 AND litros > 0
        ORDER BY ((litros * 100) / km) ASC LIMIT 5
    ");
    $rendimientosAnual = $rendimientoAnualGral->fetchAll();
}

// Historial mensual por camión para el detalle anual
$rendAnualMensual = [];
if (!empty($rendimientosAnual)) {
    $camionIds = array_column($rendimientosAnual, 'id_camion');
    $inClause = implode(',', array_map('intval', $camionIds));
    if ($inClause) {
        $rendAnualMensualRaw = $db->query("
            SELECT co.id_camion, DATE_FORMAT(co.fecha, '%Y-%m') as mes,
                   SUM(co.litros) as litros,
                   SUM(co.litros * co.precio_litro) as gasto,
                   COUNT(*) as cargas
            FROM combustible co
            WHERE co.id_camion IN ($inClause) AND YEAR(co.fecha) = $anio
            GROUP BY co.id_camion, mes
            ORDER BY mes ASC
        ")->fetchAll();
        
        $kmAnualMensualRaw = $db->query("
            SELECT id_camion, DATE_FORMAT(fecha, '%Y-%m') as mes,
                   SUM(km_recorridos) as km, COUNT(*) as viajes
            FROM km_recorrido
            WHERE id_camion IN ($inClause) AND YEAR(fecha) = $anio
            GROUP BY id_camion, mes
            ORDER BY mes ASC
        ")->fetchAll();
        
        foreach ($rendAnualMensualRaw as $r) {
            $rendAnualMensual[$r['id_camion']][$r['mes']]['litros'] = (float)$r['litros'];
            $rendAnualMensual[$r['id_camion']][$r['mes']]['gasto'] = (float)$r['gasto'];
            $rendAnualMensual[$r['id_camion']][$r['mes']]['cargas'] = (int)$r['cargas'];
        }
        foreach ($kmAnualMensualRaw as $r) {
            $rendAnualMensual[$r['id_camion']][$r['mes']]['km'] = (float)$r['km'];
            $rendAnualMensual[$r['id_camion']][$r['mes']]['viajes'] = (int)$r['viajes'];
        }
    }
}

// VTV proximas a vencer (3 meses = 90 dias) - Combina camiones.vtv y tabla vtv
$vtvAlertas = $db->query("
    SELECT c.id_camion, c.patente, c.marca, c.modelo,
           COALESCE(c.vtv, (SELECT MAX(v.fecha_vencimiento) FROM vtv v WHERE v.id_camion = c.id_camion)) as vtv,
           DATEDIFF(COALESCE(c.vtv, (SELECT MAX(v.fecha_vencimiento) FROM vtv v WHERE v.id_camion = c.id_camion)), CURDATE()) as dias_restantes
    FROM camiones c
    HAVING vtv IS NOT NULL AND vtv <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY vtv ASC
")->fetchAll();

// KM por chofer (mes actual) separando viajes como chofer y como ayudante
$kmChoferList = [];
$kmSt = $db->prepare("SELECT ch.id_chofer, ch.nombre, ch.apellido, COALESCE(km.km,0) as km FROM choferes ch LEFT JOIN (SELECT id_chofer, SUM(km_recorridos) as km FROM km_recorrido WHERE MONTH(fecha)=? AND YEAR(fecha)=? GROUP BY id_chofer) km ON ch.id_chofer = km.id_chofer HAVING km > 0 ORDER BY km DESC");
$kmSt->execute([$mes, $anio]);
foreach ($kmSt->fetchAll() as $r) {
    $kmChoferList[$r['id_chofer']] = ['id_chofer' => $r['id_chofer'], 'nombre' => $r['nombre'], 'apellido' => $r['apellido'], 'km_chofer' => $r['km'], 'km_ayudante' => 0];
}
try {
    $kmSt2 = $db->prepare("SELECT ch.id_chofer, ch.nombre, ch.apellido, COALESCE(km.km,0) as km FROM choferes ch JOIN (SELECT ayudante_id, SUM(km_recorridos) as km FROM km_recorrido WHERE ayudante_id IS NOT NULL AND MONTH(fecha)=? AND YEAR(fecha)=? GROUP BY ayudante_id) km ON ch.id_chofer = km.ayudante_id");
    $kmSt2->execute([$mes, $anio]);
    foreach ($kmSt2->fetchAll() as $r) {
        $id = $r['id_chofer'];
        if (isset($kmChoferList[$id])) {
            $kmChoferList[$id]['km_ayudante'] = $r['km'];
        } else {
            $kmChoferList[$id] = ['id_chofer' => $id, 'nombre' => $r['nombre'], 'apellido' => $r['apellido'], 'km_chofer' => 0, 'km_ayudante' => $r['km']];
        }
    }
} catch (Exception $e) {}
$kmChoferList = array_filter($kmChoferList, function($v) { return $v['km_chofer'] > 0; });
uasort($kmChoferList, function($a, $b) { return ($b['km_chofer'] + $b['km_ayudante']) - ($a['km_chofer'] + $a['km_ayudante']); });

// Litros por chofer (mes actual)
try {
    $litrosChofer = $db->prepare("
        SELECT ch.id_chofer, ch.nombre, ch.apellido, COALESCE(co.litros,0) as total_litros
        FROM choferes ch
        LEFT JOIN (SELECT id_chofer, SUM(litros) as litros FROM combustible WHERE MONTH(fecha)=? AND YEAR(fecha)=? GROUP BY id_chofer) co ON ch.id_chofer = co.id_chofer
        HAVING total_litros > 0
        ORDER BY total_litros DESC
    ");
    $litrosChofer->execute([$mes, $anio]);
    $litrosChoferList = $litrosChofer->fetchAll();
} catch (Exception $e) {
    $litrosChoferList = [];
}

// Proximo mantenimiento (camiones que ya pasaron o estan cerca del km/hs configurado)
$mantenimientoAlertas = $db->query("
    SELECT c.id_camion, c.patente, c.marca, c.modelo, c.por_hora,
           COALESCE(c.horas_actuales, 0) as horas_actuales,
           GREATEST(COALESCE(c.kilometraje_actual,0), COALESCE((SELECT MAX(hr.km_llegada) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion),0)) as km_actual,
           c.proximo_mantenimiento_km, c.proximo_mantenimiento_hs
    FROM camiones c
    WHERE (c.proximo_mantenimiento_km IS NOT NULL AND GREATEST(COALESCE(c.kilometraje_actual,0), COALESCE((SELECT MAX(hr.km_llegada) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion),0)) >= (c.proximo_mantenimiento_km - 5000))
       OR (c.proximo_mantenimiento_hs IS NOT NULL AND COALESCE(c.horas_actuales, 0) >= (c.proximo_mantenimiento_hs - 50))
    ORDER BY (CASE WHEN c.por_hora = 1 AND c.proximo_mantenimiento_hs IS NOT NULL THEN (c.proximo_mantenimiento_hs - COALESCE(c.horas_actuales, 0)) WHEN c.proximo_mantenimiento_km IS NOT NULL THEN (c.proximo_mantenimiento_km - GREATEST(COALESCE(c.kilometraje_actual,0), COALESCE((SELECT MAX(hr.km_llegada) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion),0))) ELSE 0 END) ASC
")->fetchAll();

// Matafuegos proximos a vencer (3 meses = 90 dias)
$matafuegosAlertas = [];
if (hasPermission('matafuegos_ver')) {
    try {
        $matafuegosAlertas = $db->query("
            SELECT m.*, c.patente, c.marca, c.modelo, DATEDIFF(m.vencimiento, CURDATE()) as dias_restantes 
            FROM matafuegos m 
            LEFT JOIN camiones c ON m.id_camion = c.id_camion 
            WHERE m.vencimiento IS NOT NULL AND m.vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) 
            ORDER BY m.vencimiento ASC
        ")->fetchAll();
    } catch (Exception $e) {}
}
// Agregar created_at a km_recorrido si no existe (referencia de hora de salida)
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Exception $e) {}

// Choferes en viaje (planillas abiertas = en curso)
$choferesEnViaje = $db->query("
    SELECT h.id_hoja, h.fecha, h.origen, h.destino, h.km_salida,
           h.created_at as hora_registro,
           ch.nombre, ch.apellido,
           ay.nombre as ayudante_nombre, ay.apellido as ayudante_apellido,
           c.patente, c.marca, c.modelo,
           cx.patente as cachape_patente
    FROM km_recorrido h
    JOIN choferes ch ON h.id_chofer = ch.id_chofer
    JOIN camiones c ON h.id_camion = c.id_camion
    LEFT JOIN choferes ay ON h.ayudante_id = ay.id_chofer
    LEFT JOIN camiones cx ON h.cachape_id = cx.id_camion
    WHERE h.estado = 'abierto'
    ORDER BY h.fecha DESC, h.id_hoja DESC
")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<!-- KPI Cards Grid -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
<?php if (hasPermission('vehiculos_ver')): ?>
<div class="stat-card bg-surface-container-lowest border border-outline-variant rounded-xl p-5 flex flex-col justify-between min-h-[130px]">
<div class="flex items-center gap-3 text-secondary mb-3">
<div class="w-10 h-10 rounded-lg bg-primary/5 flex items-center justify-center">
<span class="material-symbols-outlined text-primary">local_shipping</span>
</div>
<span class="font-label-caps text-label-caps uppercase text-on-surface-variant">Total Vehiculos</span>
</div>
<div class="font-headline-lg text-headline-lg text-primary"><?= number_format($totalCamiones['total']) ?></div>
<div class="text-[11px] text-on-surface-variant font-medium mt-1.5">OPERATIVOS: <span class="text-green-600 font-bold"><?= number_format($totalCamiones['activos']) ?></span></div>
</div>
<?php endif; ?>
<?php if (hasPermission('usuarios_ver')): ?>
<div class="stat-card bg-surface-container-lowest border border-outline-variant rounded-xl p-5 flex flex-col justify-between min-h-[130px]">
<div class="flex items-center gap-3 text-secondary mb-3">
<div class="w-10 h-10 rounded-lg bg-primary/5 flex items-center justify-center">
<span class="material-symbols-outlined text-primary">person</span>
</div>
<span class="font-label-caps text-label-caps uppercase text-on-surface-variant">Total Choferes</span>
</div>
<div class="font-headline-lg text-headline-lg text-primary"><?= number_format($totalChoferes['total']) ?></div>
<div class="text-[11px] text-on-surface-variant font-medium mt-1.5">ACTIVOS: <span class="text-green-600 font-bold"><?= number_format($totalChoferes['activos']) ?></span></div>
</div>
<?php endif; ?>
<?php if (hasPermission('kilometraje_ver')): ?>
<div class="stat-card bg-surface-container-lowest border border-outline-variant rounded-xl p-5 flex flex-col justify-between min-h-[130px] cursor-pointer" onclick="openKmModal()">
<div class="flex items-center gap-3 text-secondary mb-3">
<div class="w-10 h-10 rounded-lg bg-primary/5 flex items-center justify-center">
<span class="material-symbols-outlined text-primary">route</span>
</div>
<span class="font-label-caps text-label-caps uppercase text-on-surface-variant">KM Recorridos Mes</span>
</div>
<div class="font-headline-lg text-headline-lg text-primary"><?= number_format($kmData['total'], 0) ?> <span class="text-body-md text-on-surface-variant">km</span></div>
<div class="text-[11px] text-green-600 font-bold mt-1.5">MES ACTUAL</div>
</div>
<?php endif; ?>
<?php if (hasPermission('combustible_ver')): ?>
<div class="stat-card bg-surface-container-lowest border border-outline-variant rounded-xl p-5 flex flex-col justify-between min-h-[130px] cursor-pointer" onclick="openCombustibleModal()">
<div class="flex items-center gap-3 text-secondary mb-3">
<div class="w-10 h-10 rounded-lg bg-primary/5 flex items-center justify-center">
<span class="material-symbols-outlined text-primary">payments</span>
</div>
<span class="font-label-caps text-label-caps uppercase text-on-surface-variant">Gasto Combustible</span>
</div>
            <div class="font-headline-lg text-headline-lg text-primary overflow-x-auto whitespace-nowrap"><?= esAdminPleno() ? '$' . number_format($gastoCombData['total'], 2) : '-' ?></div>
<div class="text-[11px] text-on-surface-variant font-medium mt-1.5">LITROS: <span class="text-green-600 font-bold"><?= number_format($litrosData['total'], 2) ?></span></div>
</div>
<?php endif; ?>
</section>

<!-- Bento Layout -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

<?php if (hasRole('Administrador') || hasRole('Bascula') || hasRole('Báscula') || hasRole('DIRECTOR') || hasRole('Director') || hasRole('Inspector') || hasRole('Supervisor')): ?>
<!-- Choferes en Viaje (full width) -->
<section class="lg:col-span-12 bg-surface-container-lowest border border-outline-variant rounded-xl p-6 card-modern">
<div class="flex items-center justify-between mb-5">
  <div class="flex items-center gap-3">
    <div class="relative flex h-3 w-3">
      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
      <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
    </div>
    <h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">Choferes en Viaje</h2>
    <?php if (!empty($choferesEnViaje)): ?>
    <span class="bg-green-100 text-green-700 text-[11px] font-bold px-2 py-0.5 rounded-full border border-green-200"><?= count($choferesEnViaje) ?> ACTIVO<?= count($choferesEnViaje) > 1 ? 'S' : '' ?></span>
    <?php endif; ?>
  </div>
  <a href="<?= BASE_URL ?>/admin/viajes.php" class="text-primary font-bold text-[12px] hover:underline">Ver Viajes</a>
</div>
<?php if (empty($choferesEnViaje)): ?>
<div class="flex flex-col items-center justify-center py-8 text-on-surface-variant gap-2">
  <span class="material-symbols-outlined text-4xl text-outline">local_shipping</span>
  <p class="text-sm">No hay choferes en viaje en este momento</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
<?php foreach ($choferesEnViaje as $v): ?>
<div class="group relative bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 rounded-xl p-4 hover:shadow-md transition-all duration-200 hover:border-green-400">
  <!-- Pulse indicator -->
  <div class="absolute top-3 right-3 flex h-2.5 w-2.5">
    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
  </div>
  <!-- Chofer -->
  <div class="flex items-center gap-2 mb-3">
    <div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
      <span class="material-symbols-outlined text-primary text-[18px]">person</span>
    </div>
    <div class="min-w-0">
      <p class="font-bold text-[13px] text-on-surface uppercase truncate"><?= htmlspecialchars($v['apellido'] . ', ' . $v['nombre']) ?></p>
      <?php if (!empty($v['ayudante_nombre'])): ?>
      <p class="text-[10px] text-on-surface-variant truncate">+ <?= htmlspecialchars($v['ayudante_apellido'] . ' ' . $v['ayudante_nombre']) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <!-- Camion -->
  <div class="flex items-center gap-1.5 mb-3">
    <span class="material-symbols-outlined text-[16px] text-primary">local_shipping</span>
    <span class="font-bold text-[13px] text-primary"><?= htmlspecialchars($v['patente']) ?></span>
    <?php if (!empty($v['cachape_patente'])): ?>
    <span class="text-[10px] text-on-surface-variant">+<?= htmlspecialchars($v['cachape_patente']) ?></span>
    <?php endif; ?>
    <span class="text-[10px] text-on-surface-variant truncate ml-1"><?= htmlspecialchars($v['marca'] . ' ' . $v['modelo']) ?></span>
  </div>
  <!-- Ruta -->
  <?php if (!empty($v['origen']) || !empty($v['destino'])): ?>
  <div class="flex items-center gap-1 text-[11px] text-on-surface-variant mb-2">
    <span class="material-symbols-outlined text-[13px]">route</span>
    <span class="truncate font-medium"><?= htmlspecialchars(($v['origen'] ?: '?') . ' → ' . ($v['destino'] ?: '?')) ?></span>
  </div>
  <?php endif; ?>
  <!-- Fecha y hora de salida -->
  <div class="flex items-center justify-between text-[10px] text-on-surface-variant border-t border-green-200 pt-2 mt-2">
    <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">calendar_today</span><?= date('d/m/Y', strtotime($v['fecha'])) ?></span>
    <?php if (!empty($v['hora_registro']) && $v['hora_registro'] !== '0000-00-00 00:00:00'): ?>
    <span class="flex items-center gap-1 text-green-700 font-semibold"><span class="material-symbols-outlined text-[12px]">schedule</span>Salida: <?= date('H:i', strtotime($v['hora_registro'])) ?></span>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if (hasPermission('combustible_ver')): ?>
<!-- Charts Row (Combustible, L/100 Km Mensual, L/100 Km Anual) - Ubicado debajo de Choferes en Viaje -->
<div class="lg:col-span-12 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <!-- Combustible por mes -->
  <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 overflow-x-auto card-modern flex flex-col justify-between group">
    <div>
      <div class="flex items-center justify-between mb-4">
        <div>
          <h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">Combustible</h2>
          <span class="text-[10px] text-on-surface-variant flex items-center gap-1 font-medium mt-0.5"><span class="material-symbols-outlined text-[13px] text-primary">touch_app</span> Clic en una barra para ver detalle</span>
        </div>
        <span class="text-[10px] px-2 py-0.5 rounded-full bg-surface-container-high text-on-surface-variant font-bold uppercase self-start">6 Meses</span>
      </div>
    </div>
    <div class="relative cursor-pointer">
      <canvas id="chartCombustible" height="200"></canvas>
    </div>
  </div>

  <!-- Consumo Mensual L/100 Km -->
  <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 overflow-x-auto card-modern flex flex-col justify-between group">
    <div>
      <div class="flex items-center justify-between mb-4">
        <div>
          <h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">L/100 Km Mensual</h2>
          <span class="text-[10px] text-on-surface-variant flex items-center gap-1 font-medium mt-0.5"><span class="material-symbols-outlined text-[13px] text-blue-600">touch_app</span> Clic en un camión para ver detalle</span>
        </div>
        <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-bold uppercase self-start">Mes <?= date('m/Y') ?></span>
      </div>
    </div>
    <div class="relative cursor-pointer">
      <canvas id="chartRendimiento" height="200"></canvas>
    </div>
  </div>

  <!-- Consumo Anual L/100 Km -->
  <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 overflow-x-auto card-modern flex flex-col justify-between group">
    <div>
      <div class="flex items-center justify-between mb-4">
        <div>
          <h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">L/100 Km Anual</h2>
          <span class="text-[10px] text-on-surface-variant flex items-center gap-1 font-medium mt-0.5"><span class="material-symbols-outlined text-[13px] text-emerald-600">touch_app</span> Clic en un camión para ver histórico</span>
        </div>
        <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-bold uppercase self-start">Histórico <?= $anio ?></span>
      </div>
    </div>
    <div class="relative cursor-pointer">
      <canvas id="chartRendimientoAnual" height="200"></canvas>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Alertas -->
<section class="lg:col-span-7 bg-surface-container-lowest border border-outline-variant rounded-xl p-6 card-modern">
<div class="flex items-center justify-between mb-6">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">Alertas de Flota</h2>
<a href="<?= BASE_URL ?>/admin/alertas.php" class="text-primary font-bold text-[12px] hover:underline">Ver Todas</a>
</div>
<div class="space-y-3">
<?php if (empty($alertas)): ?>
<p class="text-on-surface-variant text-center py-8">No hay alertas activas</p>
<?php else: ?>
<?php foreach ($alertas as $alerta):
$severidadConfig = [
'rojo' => ['bg' => 'bg-red-50', 'border' => 'border-red-600', 'icon' => 'warning', 'text' => 'bg-red-600', 'label' => 'CRITICO', 'labelText' => 'text-red-700', 'title' => 'text-red-900', 'desc' => 'text-red-800'],
'amarillo' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-500', 'icon' => 'oil_barrel', 'text' => 'bg-amber-500', 'label' => 'ADVERTENCIA', 'labelText' => 'text-amber-700', 'title' => 'text-amber-900', 'desc' => 'text-amber-800'],
'verde' => ['bg' => 'bg-green-50', 'border' => 'border-green-600', 'icon' => 'check_circle', 'text' => 'bg-green-600', 'label' => 'OK', 'labelText' => 'text-green-700', 'title' => 'text-green-900', 'desc' => 'text-green-800'],
];
$cfg = $severidadConfig[$alerta['severidad']] ?? $severidadConfig['verde'];
$patente = $alerta['patente'] ?? '';
?>
<div class="flex items-center gap-4 p-3 <?= $cfg['bg'] ?> border-l-4 <?= $cfg['border'] ?>">
<div class="w-10 h-10 rounded-full <?= $cfg['text'] ?> flex items-center justify-center text-white shrink-0">
<span class="material-symbols-outlined"><?= $cfg['icon'] ?></span>
</div>
<div class="flex-1">
<div class="flex justify-between items-start gap-2">
<p class="font-body-md font-bold <?= $cfg['title'] ?> uppercase break-words flex-1"><?= htmlspecialchars($patente ? "$patente - " : "") ?><?= htmlspecialchars($alerta['mensaje']) ?></p>
<span class="text-[10px] font-bold <?= $cfg['labelText'] ?> shrink-0"><?= $cfg['label'] ?></span>
</div>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</section>

<?php if (hasPermission('combustible_ver')): ?>
<!-- Gasto por camion -->
<section class="lg:col-span-5 bg-surface-container-lowest border border-outline-variant rounded-xl p-6 flex flex-col card-modern">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider mb-6">Gasto por Camion (Mes)</h2>
<div class="flex-1 flex flex-col justify-start gap-2">
<div class="space-y-4">
<?php foreach ($gastoCamiones as $gc):
    $width = $maxGasto > 0 ? ($gc['total'] / $maxGasto * 100) : 0;
?>
<div class="space-y-1">
<div class="flex justify-between text-[11px] font-bold text-secondary uppercase gap-2">
<span class="truncate"><?= htmlspecialchars($gc['patente']) ?> (<?= htmlspecialchars($gc['marca']) ?>)</span>
<span class="shrink-0"><?= esAdminPleno() ? '$' . number_format($gc['total'], 2) : '-' ?></span>
</div>
<div class="w-full bg-surface-container-high h-4">
<div class="bg-primary h-full transition-all duration-1000" style="width: <?= $width ?>%"></div>
</div>
<div class="flex justify-between text-[10px] text-on-surface-variant">
<span>KM: <?= number_format($gc['km'], 0) ?> km</span>
<span><?= $gc['km'] > 0 ? (esAdminPleno() ? '$' . number_format($gc['total'] / $gc['km'], 2) . '/km' : '-') : '' ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
</section>
<?php endif; ?>
</div>

<?php if ((hasPermission('vehiculos_ver') || hasPermission('alertas_ver') || hasRole('inspector') || hasRole('Inspector')) && !empty($vtvAlertas)): ?>
<!-- VTV Proximas a Vencer (Acordeon) -->
<section class="mt-6">
<details class="group bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern transition-all">
<summary class="p-5 flex items-center justify-between cursor-pointer list-none select-none hover:bg-surface-container-high/30 transition-colors">
<div class="flex items-center gap-3">
  <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
    <span class="material-symbols-outlined text-red-600">assignment</span>
  </div>
  <div>
    <div class="flex items-center gap-2">
      <h3 class="font-headline-sm text-headline-sm text-primary uppercase font-bold">VTV Próximas a Vencer (3 Meses)</h3>
      <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700 border border-red-200"><?= count($vtvAlertas) ?> <?= count($vtvAlertas) === 1 ? 'vehículo' : 'vehículos' ?></span>
    </div>
    <p class="text-xs text-on-surface-variant font-medium mt-0.5">Clic para expandir / contraer el listado</p>
  </div>
</div>
<div class="flex items-center gap-3">
  <span class="text-xs font-bold text-primary group-open:hidden">Ver <?= count($vtvAlertas) ?></span>
  <span class="material-symbols-outlined text-on-surface-variant group-open:rotate-180 transition-transform duration-200">expand_more</span>
</div>
</summary>
<div class="divide-y divide-outline-variant border-t border-outline-variant bg-surface-container-low/30">
<?php foreach ($vtvAlertas as $v):
$dias = (int)$v['dias_restantes'];
if ($dias <= 0) { $c = 'red'; $label = 'VENCIDA'; }
elseif ($dias <= 30) { $c = 'red'; $label = "$dias dias"; }
elseif ($dias <= 60) { $c = 'yellow'; $label = "$dias dias"; }
else { $c = 'yellow'; $label = "$dias dias"; }
?>
<div class="p-4 flex items-center gap-4 hover:bg-surface-container-high/20 transition-colors">
<div class="w-10 h-10 rounded-full bg-<?= $c ?>-100 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-<?= $c ?>-600">calendar_today</span>
</div>
<div class="flex-1">
<p class="font-bold"><?= htmlspecialchars($v['patente']) ?> - <?= htmlspecialchars($v['marca'] . ' ' . $v['modelo']) ?></p>
<p class="text-sm text-on-surface-variant">Vence: <?= date('d/m/Y', strtotime($v['vtv'])) ?></p>
</div>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase bg-<?= $c ?>-100 text-<?= $c ?>-800 border border-<?= $c ?>-200"><?= $label ?></span>
</div>
<?php endforeach; ?>
</div>
</details>
</section>
<?php endif; ?>

<?php if (hasPermission('mantenimiento_ver') && !empty($mantenimientoAlertas)): ?>
<!-- Proximo Mantenimiento (Acordeon) -->
<section class="mt-6">
<details class="group bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern transition-all">
<summary class="p-5 flex items-center justify-between cursor-pointer list-none select-none hover:bg-surface-container-high/30 transition-colors">
<div class="flex items-center gap-3">
  <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center shrink-0">
    <span class="material-symbols-outlined text-amber-600">build</span>
  </div>
  <div>
    <div class="flex items-center gap-2">
      <h3 class="font-headline-sm text-headline-sm text-primary uppercase font-bold">Próximo Mantenimiento</h3>
      <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200"><?= count($mantenimientoAlertas) ?> <?= count($mantenimientoAlertas) === 1 ? 'pendiente' : 'pendientes' ?></span>
    </div>
    <p class="text-xs text-on-surface-variant font-medium mt-0.5">Clic para expandir / contraer el listado</p>
  </div>
</div>
<div class="flex items-center gap-3">
  <span class="text-xs font-bold text-primary group-open:hidden">Ver <?= count($mantenimientoAlertas) ?></span>
  <span class="material-symbols-outlined text-on-surface-variant group-open:rotate-180 transition-transform duration-200">expand_more</span>
</div>
</summary>
<div class="divide-y divide-outline-variant border-t border-outline-variant bg-surface-container-low/30">
<?php foreach ($mantenimientoAlertas as $m):
$isPorHora = (bool)$m['por_hora'];
$kmActual = (float)$m['km_actual'];
$hsActual = (float)$m['horas_actuales'];
$proxKm = (float)$m['proximo_mantenimiento_km'];
$proxHs = (float)$m['proximo_mantenimiento_hs'];

if ($isPorHora && $proxHs > 0) {
    $hsDiff = $proxHs - $hsActual;
    if ($hsDiff <= 0) { $c = 'red'; $label = 'VENCIDO'; }
    elseif ($hsDiff <= 20) { $c = 'red'; $label = "FALTAN " . number_format($hsDiff, 1) . " HS"; }
    elseif ($hsDiff <= 50) { $c = 'yellow'; $label = "FALTAN " . number_format($hsDiff, 1) . " HS"; }
    else { $c = 'green'; $label = "FALTAN " . number_format($hsDiff, 1) . " HS"; }
    $subTexto = "Horas Actuales: " . number_format($hsActual, 1) . " HS | Prox: " . number_format($proxHs, 1) . " HS";
} else {
    $kmDiff = $proxKm > 0 ? $proxKm - $kmActual : null;
    if ($kmDiff !== null && $kmDiff <= 0) { $c = 'red'; $label = 'VENCIDO'; }
    elseif ($kmDiff !== null && $kmDiff <= 1000) { $c = 'red'; $label = "FALTAN $kmDiff KM"; }
    elseif ($kmDiff !== null && $kmDiff <= 5000) { $c = 'yellow'; $label = "FALTAN $kmDiff KM"; }
    elseif ($kmDiff !== null) { $c = 'green'; $label = "FALTAN $kmDiff KM"; }
    elseif ($proxHs > 0) { $c = 'yellow'; $label = number_format($proxHs, 0) . ' HS'; }
    else { $c = 'gray'; $label = '-'; }
    $subTexto = "KM Actual: " . number_format($kmActual, 0) . ($proxKm ? ' | Prox: ' . number_format($proxKm, 0) . ' KM' : '') . ($proxHs ? ' | ' . number_format($proxHs, 0) . ' HS' : '');
}
?>
<div class="p-4 flex items-center gap-4 hover:bg-surface-container-high/20 transition-colors">
<div class="w-10 h-10 rounded-full bg-<?= $c ?>-100 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-<?= $c ?>-600"><?= $isPorHora ? 'precision_manufacturing' : 'build' ?></span>
</div>
<div class="flex-1">
<p class="font-bold"><?= htmlspecialchars($m['patente']) ?> - <?= htmlspecialchars($m['marca'] . ' ' . $m['modelo']) ?><?= $isPorHora ? ' <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-bold uppercase ml-1">Máquina</span>' : '' ?></p>
<p class="text-sm text-on-surface-variant"><?= $subTexto ?></p>
</div>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase bg-<?= $c ?>-100 text-<?= $c ?>-800 border border-<?= $c ?>-200"><?= $label ?></span>
</div>
<?php endforeach; ?>
</div>
</details>
</section>
<?php endif; ?>

<?php if ((hasPermission('matafuegos_ver') || hasPermission('alertas_ver') || hasRole('inspector') || hasRole('Inspector')) && !empty($matafuegosAlertas)): ?>
<!-- Matafuegos Proximos a Vencer (3 Meses) (Acordeon) -->
<section class="mt-6">
<details class="group bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern transition-all">
<summary class="p-5 flex items-center justify-between cursor-pointer list-none select-none hover:bg-surface-container-high/30 transition-colors">
<div class="flex items-center gap-3">
  <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
    <span class="material-symbols-outlined text-red-600">fire_extinguisher</span>
  </div>
  <div>
    <div class="flex items-center gap-2">
      <h3 class="font-headline-sm text-headline-sm text-primary uppercase font-bold">Matafuegos Próximos a Vencer (3 Meses)</h3>
      <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700 border border-red-200"><?= count($matafuegosAlertas) ?> <?= count($matafuegosAlertas) === 1 ? 'matafuego' : 'matafuegos' ?></span>
    </div>
    <p class="text-xs text-on-surface-variant font-medium mt-0.5">Clic para expandir / contraer el listado</p>
  </div>
</div>
<div class="flex items-center gap-3">
  <a href="<?= BASE_URL ?>/admin/matafuegos.php" onclick="event.stopPropagation()" class="text-primary font-bold text-xs hover:underline flex items-center gap-1">
    Gestionar <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
  </a>
  <span class="material-symbols-outlined text-on-surface-variant group-open:rotate-180 transition-transform duration-200">expand_more</span>
</div>
</summary>
<div class="divide-y divide-outline-variant border-t border-outline-variant bg-surface-container-low/30">
<?php foreach ($matafuegosAlertas as $mf):
$dias = (int)$mf['dias_restantes'];
if ($dias < 0) { $c = 'red'; $label = 'VENCIDO (' . abs($dias) . ' días)'; }
elseif ($dias <= 30) { $c = 'red'; $label = "$dias días"; }
else { $c = 'amber'; $label = "$dias días"; }
$lugar = $mf['patente'] ? htmlspecialchars($mf['patente'] . ' - ' . $mf['marca'] . ' ' . $mf['modelo']) : htmlspecialchars('Sector: ' . $mf['sector']);
?>
<div class="p-4 flex items-center gap-4 hover:bg-surface-container-high/20 transition-colors">
<div class="w-10 h-10 rounded-full bg-<?= $c === 'amber' ? 'amber' : 'red' ?>-100 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-<?= $c === 'amber' ? 'amber' : 'red' ?>-600">fire_extinguisher</span>
</div>
<div class="flex-1">
<div class="flex items-center gap-2">
<p class="font-bold">Matafuego N° <?= htmlspecialchars($mf['numero']) ?></p>
<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-100 text-blue-700 border border-blue-200">Clase <?= htmlspecialchars($mf['clase']) ?></span>
</div>
<p class="text-sm text-on-surface-variant font-medium mt-0.5"><?= $lugar ?></p>
<p class="text-xs text-on-surface-variant">Vence: <strong class="text-primary"><?= date('d/m/Y', strtotime($mf['vencimiento'])) ?></strong><?= $mf['recarga'] ? ' | Última recarga: ' . date('d/m/Y', strtotime($mf['recarga'])) : '' ?></p>
</div>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase bg-<?= $c === 'amber' ? 'amber' : 'red' ?>-100 text-<?= $c === 'amber' ? 'amber' : 'red' ?>-800 border border-<?= $c === 'amber' ? 'amber' : 'red' ?>-200"><?= $label ?></span>
</div>
<?php endforeach; ?>
</div>
</details>
</section>
<?php endif; ?>
<section class="mt-8">
<div class="bg-surface-container-lowest border border-outline-variant overflow-hidden">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Resumen del Mes</h3>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-outline-variant">
<?php if (hasPermission('combustible_ver')): ?>
<div class="p-6">
<h4 class="font-body-md font-bold text-primary uppercase mb-2">Combustible</h4>
<p class="text-on-surface-variant mb-4">Litros consumidos: <strong><?= number_format($litrosData['total'], 2) ?> L</strong></p>
<p class="text-on-surface-variant">Gasto total: <strong><?= esAdminPleno() ? '$' . number_format($gastoCombData['total'], 2) : '-' ?></strong></p>
</div>
<?php endif; ?>
<?php if (hasPermission('mantenimiento_ver')): ?>
<div class="p-6">
<h4 class="font-body-md font-bold text-primary uppercase mb-2">Mantenimiento</h4>
<p class="text-on-surface-variant mb-4">Gasto del mes: <strong><?= esAdminPleno() ? '$' . number_format($gastoMantData['total'], 2) : '-' ?></strong></p>
</div>
<?php endif; ?>
<?php if (hasPermission('kilometraje_ver')): ?>
<div class="p-6">
<h4 class="font-body-md font-bold text-primary uppercase mb-2">Kilometraje</h4>
<p class="text-on-surface-variant mb-4">KM recorridos: <strong><?= number_format($kmData['total'], 0) ?> km</strong></p>
</div>
<?php endif; ?>
</div>
</div>
</section>
<!-- Modal Combustible por Chofer -->
<div id="modalCombustible" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">Litros por Chofer (Mes Actual)</h3>
<button onclick="closeCombustibleModal()"><span class="material-symbols-outlined">close</span></button>
</div>
<div class="p-6">
<?php if (empty($litrosChoferList)): ?>
<p class="text-on-surface-variant text-center py-8">No hay registros de combustible este mes</p>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($litrosChoferList as $lc):
$litrosPorcentaje = $litrosData['total'] > 0 ? round($lc['total_litros'] / $litrosData['total'] * 100, 1) : 0;
?>
<div class="flex items-center gap-4 p-3 bg-surface-container-high/30 rounded-lg">
<div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-primary">local_gas_station</span>
</div>
<div class="flex-1 min-w-0">
<p class="font-bold text-sm truncate"><?= htmlspecialchars($lc['apellido'] . ', ' . $lc['nombre']) ?></p>
<div class="w-full bg-surface-container-high h-2 rounded-full mt-1.5 overflow-hidden">
<div class="bg-primary h-full rounded-full transition-all" style="width: <?= $litrosPorcentaje ?>%"></div>
</div>
</div>
<div class="text-right shrink-0">
<p class="font-bold text-sm"><?= number_format($lc['total_litros'], 2) ?> L</p>
<p class="text-[10px] text-on-surface-variant"><?= $litrosPorcentaje ?>%</p>
</div>
</div>
<?php endforeach; ?>
</div>
<div class="mt-4 pt-4 border-t border-outline-variant flex justify-between text-sm">
<span class="font-bold text-on-surface-variant">TOTAL</span>
<span class="font-bold"><?= number_format($litrosData['total'], 2) ?> L</span>
</div>
<?php endif; ?>
</div>
</div>
</div>

<!-- Modal KM por Chofer -->
<div id="modalKm" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">KM por Chofer (Mes Actual)</h3>
<button onclick="closeKmModal()"><span class="material-symbols-outlined">close</span></button>
</div>
<div class="p-6">
<?php if (empty($kmChoferList)): ?>
<p class="text-on-surface-variant text-center py-8">No hay registros de KM este mes</p>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($kmChoferList as $kc):
$totalKm = $kc['km_chofer'] + $kc['km_ayudante'];
$kmPorcentaje = $kmData['total'] > 0 ? round($totalKm / $kmData['total'] * 100, 1) : 0;
?>
<div class="flex items-center gap-4 p-3 bg-surface-container-high/30 rounded-lg">
<div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-primary">person</span>
</div>
<div class="flex-1 min-w-0">
<p class="font-bold text-sm truncate"><?= htmlspecialchars($kc['apellido'] . ', ' . $kc['nombre']) ?></p>
<div class="flex gap-2 text-[10px] text-on-surface-variant mt-1">
<span><?= number_format($kc['km_chofer'], 0) ?> km <span class="text-primary">(Chofer)</span></span>
<?php if ($kc['km_ayudante'] > 0): ?>
<span class="text-primary/60">|</span>
<span><?= number_format($kc['km_ayudante'], 0) ?> km <span class="text-primary">(Ayudante)</span></span>
<?php endif; ?>
</div>
<div class="w-full bg-surface-container-high h-2 rounded-full mt-1.5 overflow-hidden">
<div class="bg-primary h-full rounded-full transition-all" style="width: <?= $kmPorcentaje ?>%"></div>
</div>
</div>
<div class="text-right shrink-0">
<p class="font-bold text-sm"><?= number_format($totalKm, 0) ?> km</p>
<p class="text-[10px] text-on-surface-variant"><?= $kmPorcentaje ?>%</p>
</div>
</div>
<?php endforeach; ?>
</div>
<div class="mt-4 pt-4 border-t border-outline-variant flex justify-between text-sm">
<span class="font-bold text-on-surface-variant">TOTAL</span>
<span class="font-bold"><?= number_format($kmData['total'], 0) ?> km</span>
</div>
<?php endif; ?>
</div>
</div>
</div>

<!-- Modal Detalle Interactivo de Gráficos (Velas) -->
<div id="modalDetalleVela" class="fixed inset-0 bg-black/60 backdrop-blur-xs z-50 hidden flex items-center justify-center p-3 sm:p-4 transition-opacity">
<div class="bg-surface-container-lowest border border-outline-variant rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
  <!-- Header -->
  <div class="p-5 sm:p-6 border-b border-outline-variant flex justify-between items-center bg-surface-container-low/50">
    <div class="flex items-center gap-3">
      <div id="modalVelaIconBg" class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
        <span id="modalVelaIcon" class="material-symbols-outlined text-primary text-2xl">insights</span>
      </div>
      <div>
        <h3 id="modalVelaTitulo" class="font-headline-sm text-lg sm:text-xl text-primary font-black uppercase tracking-wide">Detalle</h3>
        <p id="modalVelaSubtitulo" class="text-xs text-on-surface-variant font-medium">Información detallada</p>
      </div>
    </div>
    <button type="button" onclick="cerrarModalVela()" class="w-9 h-9 rounded-full bg-surface-container hover:bg-surface-container-high flex items-center justify-center text-on-surface-variant hover:text-primary transition-colors">
      <span class="material-symbols-outlined text-xl">close</span>
    </button>
  </div>

  <!-- Body / Content -->
  <div id="modalVelaContenido" class="p-5 sm:p-6 overflow-y-auto flex-1 space-y-6">
    <!-- Dynamic Javascript Content -->
  </div>

  <!-- Footer -->
  <div class="p-4 bg-surface-container-low/60 border-t border-outline-variant flex justify-between items-center text-xs">
    <span class="text-on-surface-variant font-medium">Transporte FASA &bull; Sistema Integral</span>
    <button type="button" onclick="cerrarModalVela()" class="px-4 py-2 rounded-lg bg-surface-container-high hover:bg-surface-container text-primary font-bold transition-colors">
      Cerrar
    </button>
  </div>
</div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const combustibleMesesData = <?= json_encode($combustibleMeses) ?>;
const combDetalleMesData = <?= json_encode($combDetalleMes) ?>;
const rendimientosData = <?= json_encode($rendimientos) ?>;
const rendimientosAnualData = <?= json_encode($rendimientosAnual) ?>;
const rendAnualMensualData = <?= json_encode($rendAnualMensual) ?>;
const esAdminPlenoUser = <?= json_encode(esAdminPleno()) ?>;
const baseUrlApp = <?= json_encode(BASE_URL) ?>;

<?php if (hasPermission('combustible_ver')): ?>
// 1. Gráfico de Combustible (6 Meses)
const combustibleData = {
  labels: [<?php foreach ($combustibleMeses as $c): ?>'<?= $c['mes'] ?>',<?php endforeach; ?>],
  datasets: [{
    label: 'Litros',
    data: [<?php foreach ($combustibleMeses as $c): ?><?= (float)$c['litros'] ?>,<?php endforeach; ?>],
    backgroundColor: '#091426',
    hoverBackgroundColor: '#1e3a8a',
    borderColor: '#091426',
    borderWidth: 2,
    borderRadius: 6,
    tension: 0.3
  }]
};

const chartCombustibleEl = document.getElementById('chartCombustible');
if (chartCombustibleEl) {
  new Chart(chartCombustibleEl, {
    type: 'bar',
    data: combustibleData,
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            afterLabel: () => '👉 Haz clic para ver el desglose por camión'
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Litros', font: { weight: 'bold' } }
        }
      },
      onClick: (evt, elements) => {
        if (elements && elements.length > 0) {
          mostrarDetalleCombustibleMes(elements[0].index);
        }
      },
      onHover: (evt, chartElement) => {
        evt.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
      }
    }
  });
}

// 2. Gráfico Rendimiento L/100 Km Mensual
const rendimientoData = {
  labels: [<?php foreach ($rendimientos as $r): ?>'<?= $r['patente'] ?>',<?php endforeach; ?>],
  datasets: [{
    label: 'L/100 Km (Mensual)',
    data: [<?php foreach ($rendimientos as $r): $km = (float)$r['km']; $litros = (float)$r['litros']; $rl = $km > 0 ? (($litros * 100) / $km) : 0; ?><?= number_format($rl, 2, '.', '') ?>,<?php endforeach; ?>],
    backgroundColor: ['#091426', '#1e3a8a', '#2563eb', '#3b82f6', '#60a5fa'],
    hoverBackgroundColor: ['#1e293b', '#1d4ed8', '#3b82f6', '#60a5fa', '#93c5fd'],
    borderRadius: 6,
    borderWidth: 0
  }]
};

const chartRendimientoEl = document.getElementById('chartRendimiento');
if (chartRendimientoEl) {
  new Chart(chartRendimientoEl, {
    type: 'bar',
    data: rendimientoData,
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            afterLabel: () => '👉 Haz clic para ver la ficha y estadísticas'
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          title: { display: true, text: 'L/100 Km', font: { weight: 'bold' } }
        }
      },
      onClick: (evt, elements) => {
        if (elements && elements.length > 0) {
          mostrarDetalleRendimientoMes(elements[0].index);
        }
      },
      onHover: (evt, chartElement) => {
        evt.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
      }
    }
  });
}

// 3. Gráfico Rendimiento L/100 Km Anual
const rendimientoAnualData = {
  labels: [<?php foreach ($rendimientosAnual as $r): ?>'<?= $r['patente'] ?>',<?php endforeach; ?>],
  datasets: [{
    label: 'L/100 Km (Anual)',
    data: [<?php foreach ($rendimientosAnual as $r): $km = (float)$r['km']; $litros = (float)$r['litros']; $rl = $km > 0 ? (($litros * 100) / $km) : 0; ?><?= number_format($rl, 2, '.', '') ?>,<?php endforeach; ?>],
    backgroundColor: ['#064e3b', '#047857', '#059669', '#10b981', '#34d399'],
    hoverBackgroundColor: ['#022c22', '#065f46', '#047857', '#059669', '#10b981'],
    borderRadius: 6,
    borderWidth: 0
  }]
};

const chartRendimientoAnualEl = document.getElementById('chartRendimientoAnual');
if (chartRendimientoAnualEl) {
  new Chart(chartRendimientoAnualEl, {
    type: 'bar',
    data: rendimientoAnualData,
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            afterLabel: () => '👉 Haz clic para ver el desglose mensual anual'
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          title: { display: true, text: 'L/100 Km', font: { weight: 'bold' } }
        }
      },
      onClick: (evt, elements) => {
        if (elements && elements.length > 0) {
          mostrarDetalleRendimientoAnual(elements[0].index);
        }
      },
      onHover: (evt, chartElement) => {
        evt.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
      }
    }
  });
}
<?php endif; ?>

// Helpers de formateo
function formatearNumero(num, decimales = 2) {
  const n = parseFloat(num) || 0;
  return n.toLocaleString('es-AR', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
}

function formatearMesTexto(mesStr) {
  if (!mesStr) return '';
  const partes = mesStr.split('-');
  if (partes.length < 2) return mesStr;
  const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  const mIndex = parseInt(partes[1], 10) - 1;
  const mesNombre = meses[mIndex] || partes[1];
  return `${mesNombre} ${partes[0]}`;
}

// Abrir / Cerrar Modal Genérico
function abrirModalVela(titulo, subtitulo, icon, iconBgClass, iconTextClass, htmlContent) {
  const modal = document.getElementById('modalDetalleVela');
  document.getElementById('modalVelaTitulo').textContent = titulo;
  document.getElementById('modalVelaSubtitulo').textContent = subtitulo;
  
  const iconBg = document.getElementById('modalVelaIconBg');
  const iconEl = document.getElementById('modalVelaIcon');
  iconBg.className = `w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${iconBgClass}`;
  iconEl.className = `material-symbols-outlined text-2xl ${iconTextClass}`;
  iconEl.textContent = icon;

  document.getElementById('modalVelaContenido').innerHTML = htmlContent;
  modal.classList.remove('hidden');
}

function cerrarModalVela() {
  const modal = document.getElementById('modalDetalleVela');
  if (modal) modal.classList.add('hidden');
}

document.getElementById('modalDetalleVela')?.addEventListener('click', function(e) {
  if (e.target === this) cerrarModalVela();
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    cerrarModalVela();
    closeCombustibleModal();
    closeKmModal();
  }
});

// 1. Mostrar Detalle de Combustible por Mes
function mostrarDetalleCombustibleMes(index) {
  const item = combustibleMesesData[index];
  if (!item) return;

  const mesFormateado = formatearMesTexto(item.mes);
  const camionesDelMes = combDetalleMesData.filter(c => c.mes === item.mes);
  const totalLitros = parseFloat(item.litros) || 0;
  const totalGasto = parseFloat(item.total) || 0;
  const totalCargas = parseInt(item.cargas, 10) || 0;
  const promedioPorCarga = totalCargas > 0 ? (totalLitros / totalCargas) : 0;

  let camionesHtml = '';
  if (camionesDelMes.length === 0) {
    camionesHtml = '<p class="text-on-surface-variant text-center py-6 text-sm">No se encontraron camiones registrados para este período.</p>';
  } else {
    camionesHtml = `
      <div class="space-y-3">
        ${camionesDelMes.map(c => {
          const l = parseFloat(c.litros) || 0;
          const pct = totalLitros > 0 ? Math.round((l / totalLitros) * 100) : 0;
          const g = parseFloat(c.total) || 0;
          const cargas = parseInt(c.cargas, 10) || 0;
          return `
            <div class="p-3.5 bg-surface-container-high/40 hover:bg-surface-container-high/70 border border-outline-variant/60 rounded-xl transition-colors">
              <div class="flex items-center justify-between gap-2 mb-1.5">
                <div class="flex items-center gap-2">
                  <span class="px-2 py-0.5 rounded bg-primary/10 text-primary font-bold text-xs tracking-wider">${c.patente}</span>
                  <span class="text-xs font-semibold text-on-surface">${c.marca || ''} ${c.modelo || ''}</span>
                </div>
                <div class="text-right">
                  <span class="font-bold text-sm text-primary">${formatearNumero(l, 2)} L</span>
                  <span class="text-[11px] text-on-surface-variant font-semibold ml-1">(${pct}%)</span>
                </div>
              </div>
              <div class="w-full bg-surface-container-high h-2 rounded-full overflow-hidden mb-2">
                <div class="bg-primary h-full rounded-full transition-all duration-500" style="width: ${pct}%"></div>
              </div>
              <div class="flex items-center justify-between text-[11px] text-on-surface-variant">
                <span>${cargas} ${cargas === 1 ? 'carga registrada' : 'cargas registradas'}</span>
                <span>${esAdminPlenoUser ? '$' + formatearNumero(g, 2) : ''}</span>
              </div>
            </div>
          `;
        }).join('')}
      </div>
    `;
  }

  const html = `
    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Total Litros</p>
        <p class="text-lg sm:text-xl font-black text-primary mt-0.5">${formatearNumero(totalLitros, 2)} <span class="text-xs font-bold text-on-surface-variant">L</span></p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Gasto Total</p>
        <p class="text-lg sm:text-xl font-black text-primary mt-0.5">${esAdminPlenoUser ? '$' + formatearNumero(totalGasto, 0) : '-'}</p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Total Cargas</p>
        <p class="text-lg sm:text-xl font-black text-primary mt-0.5">${totalCargas}</p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Promedio / Carga</p>
        <p class="text-lg sm:text-xl font-black text-primary mt-0.5">${formatearNumero(promedioPorCarga, 1)} <span class="text-xs font-bold text-on-surface-variant">L</span></p>
      </div>
    </div>

    <!-- Breakdown List -->
    <div>
      <div class="flex items-center justify-between mb-3">
        <h4 class="font-bold text-xs uppercase tracking-wider text-primary flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">local_shipping</span>
          Desglose por Camión (${camionesDelMes.length})
        </h4>
        <span class="text-[11px] text-on-surface-variant">Ordenado por consumo</span>
      </div>
      ${camionesHtml}
    </div>

    <!-- Actions -->
    <div class="pt-2 flex flex-wrap gap-2 justify-end">
      <a href="${baseUrlApp}/admin/combustible.php" class="px-3.5 py-2 rounded-lg bg-primary text-white text-xs font-bold hover:bg-primary/90 flex items-center gap-1.5 transition-colors">
        <span class="material-symbols-outlined text-sm">local_gas_station</span> Ver Módulo de Combustible
      </a>
    </div>
  `;

  abrirModalVela(
    `Combustible: ${mesFormateado}`,
    `Período mensual ${item.mes}`,
    'local_gas_station',
    'bg-primary/10',
    'text-primary',
    html
  );
}

// 2. Mostrar Detalle de Rendimiento Mensual por Camión
function mostrarDetalleRendimientoMes(index) {
  const r = rendimientosData[index];
  if (!r) return;

  const km = parseFloat(r.km) || 0;
  const litros = parseFloat(r.litros) || 0;
  const gasto = parseFloat(r.gasto) || 0;
  const viajes = parseInt(r.viajes, 10) || 0;
  const cargas = parseInt(r.cargas, 10) || 0;
  const l100 = km > 0 ? ((litros * 100) / km) : 0;
  const kmPorLitro = litros > 0 ? (km / litros) : 0;
  const costoKm = km > 0 ? (gasto / km) : 0;

  let badgeColor = 'bg-blue-100 text-blue-800 border-blue-200';
  let badgeTexto = 'Rendimiento Óptimo';
  if (l100 <= 25) {
    badgeColor = 'bg-emerald-100 text-emerald-800 border-emerald-200';
    badgeTexto = 'Excelente Eficiencia';
  } else if (l100 > 50) {
    badgeColor = 'bg-amber-100 text-amber-800 border-amber-200';
    badgeTexto = 'Consumo Elevado';
  }

  const html = `
    <!-- Header Truck Card -->
    <div class="p-4 bg-gradient-to-r from-blue-900/10 via-blue-800/5 to-transparent border border-blue-500/20 rounded-2xl flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-xl bg-blue-600/10 text-blue-600 flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-2xl">local_shipping</span>
        </div>
        <div>
          <div class="flex items-center gap-2">
            <h4 class="font-black text-xl text-primary tracking-wide">${r.patente}</h4>
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border ${badgeColor}">${badgeTexto}</span>
          </div>
          <p class="text-xs text-on-surface-variant font-medium mt-0.5">${r.marca || ''} ${r.modelo || ''}</p>
        </div>
      </div>
      <div class="text-right">
        <p class="text-xs uppercase font-bold text-on-surface-variant">Consumo Mes Actual</p>
        <p class="text-2xl font-black text-blue-600">${formatearNumero(l100, 2)} <span class="text-xs font-bold text-on-surface-variant">L/100km</span></p>
      </div>
    </div>

    <!-- KPIs Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">KM Recorridos</p>
        <p class="text-lg font-black text-primary mt-0.5">${formatearNumero(km, 0)} <span class="text-xs font-bold text-on-surface-variant">km</span></p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Litros Consumidos</p>
        <p class="text-lg font-black text-primary mt-0.5">${formatearNumero(litros, 2)} <span class="text-xs font-bold text-on-surface-variant">L</span></p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Km / Litro</p>
        <p class="text-lg font-black text-primary mt-0.5">${formatearNumero(kmPorLitro, 2)} <span class="text-xs font-bold text-on-surface-variant">km/L</span></p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Gasto Combustible</p>
        <p class="text-lg font-black text-primary mt-0.5">${esAdminPlenoUser ? '$' + formatearNumero(gasto, 2) : '-'}</p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Costo Estimado / KM</p>
        <p class="text-lg font-black text-primary mt-0.5">${esAdminPlenoUser && costoKm > 0 ? '$' + formatearNumero(costoKm, 2) + '/km' : '-'}</p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Registros del Mes</p>
        <p class="text-lg font-black text-primary mt-0.5">${viajes} <span class="text-xs text-on-surface-variant font-normal">viajes</span> &bull; ${cargas} <span class="text-xs text-on-surface-variant font-normal">cargas</span></p>
      </div>
    </div>

    <!-- Action Links -->
    <div class="pt-2 flex flex-wrap gap-2 justify-end">
      <a href="${baseUrlApp}/admin/camiones_ver.php?id=${r.id_camion}" class="px-3.5 py-2 rounded-lg bg-surface-container-high hover:bg-surface-container text-primary text-xs font-bold flex items-center gap-1.5 transition-colors">
        <span class="material-symbols-outlined text-sm">visibility</span> Ver Ficha del Camión
      </a>
      <a href="${baseUrlApp}/admin/combustible.php" class="px-3.5 py-2 rounded-lg bg-primary text-white text-xs font-bold hover:bg-primary/90 flex items-center gap-1.5 transition-colors">
        <span class="material-symbols-outlined text-sm">local_gas_station</span> Historial Cargas
      </a>
    </div>
  `;

  abrirModalVela(
    `Rendimiento Mensual: ${r.patente}`,
    `Análisis del mes en curso (${r.marca || ''} ${r.modelo || ''})`,
    'speed',
    'bg-blue-50 text-blue-600',
    'text-blue-600',
    html
  );
}

// 3. Mostrar Detalle de Rendimiento Anual / Histórico por Camión
function mostrarDetalleRendimientoAnual(index) {
  const r = rendimientosAnualData[index];
  if (!r) return;

  const km = parseFloat(r.km) || 0;
  const litros = parseFloat(r.litros) || 0;
  const gasto = parseFloat(r.gasto) || 0;
  const viajes = parseInt(r.viajes, 10) || 0;
  const cargas = parseInt(r.cargas, 10) || 0;
  const l100 = km > 0 ? ((litros * 100) / km) : 0;
  const kmPorLitro = litros > 0 ? (km / litros) : 0;
  const costoKm = km > 0 ? (gasto / km) : 0;

  // Breakdown mensual del camión en el año
  const historialMeses = rendAnualMensualData[r.id_camion] || {};
  const mesesKeys = Object.keys(historialMeses).sort();

  let tablaMesesHtml = '';
  if (mesesKeys.length === 0) {
    tablaMesesHtml = '<p class="text-on-surface-variant text-center py-4 text-xs">No hay meses con registros combinados para este camión en el período.</p>';
  } else {
    tablaMesesHtml = `
      <div class="overflow-x-auto border border-outline-variant/70 rounded-xl">
        <table class="w-full text-left text-xs">
          <thead class="bg-surface-container-high/60 text-on-surface-variant uppercase font-bold text-[10px]">
            <tr>
              <th class="p-2.5">Mes</th>
              <th class="p-2.5 text-right">KM</th>
              <th class="p-2.5 text-right">Litros</th>
              <th class="p-2.5 text-right font-black text-emerald-800">L/100 Km</th>
              <th class="p-2.5 text-right">${esAdminPlenoUser ? 'Gasto' : ''}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/50">
            ${mesesKeys.map(m => {
              const d = historialMeses[m];
              const dKm = d.km || 0;
              const dL = d.litros || 0;
              const dG = d.gasto || 0;
              const dL100 = dKm > 0 ? ((dL * 100) / dKm) : 0;
              return `
                <tr class="hover:bg-surface-container-high/30 transition-colors">
                  <td class="p-2.5 font-bold">${formatearMesTexto(m)}</td>
                  <td class="p-2.5 text-right">${formatearNumero(dKm, 0)} km</td>
                  <td class="p-2.5 text-right">${formatearNumero(dL, 1)} L</td>
                  <td class="p-2.5 text-right font-bold text-emerald-700">${dL100 > 0 ? formatearNumero(dL100, 2) : '-'}</td>
                  <td class="p-2.5 text-right">${esAdminPlenoUser && dG > 0 ? '$' + formatearNumero(dG, 0) : '-'}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
  }

  const html = `
    <!-- Header Truck Card -->
    <div class="p-4 bg-gradient-to-r from-emerald-900/10 via-emerald-800/5 to-transparent border border-emerald-500/20 rounded-2xl flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-xl bg-emerald-600/10 text-emerald-600 flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-2xl">history</span>
        </div>
        <div>
          <div class="flex items-center gap-2">
            <h4 class="font-black text-xl text-primary tracking-wide">${r.patente}</h4>
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border bg-emerald-100 text-emerald-800 border-emerald-200">Histórico Anual</span>
          </div>
          <p class="text-xs text-on-surface-variant font-medium mt-0.5">${r.marca || ''} ${r.modelo || ''}</p>
        </div>
      </div>
      <div class="text-right">
        <p class="text-xs uppercase font-bold text-on-surface-variant">Promedio Anual</p>
        <p class="text-2xl font-black text-emerald-600">${formatearNumero(l100, 2)} <span class="text-xs font-bold text-on-surface-variant">L/100km</span></p>
      </div>
    </div>

    <!-- KPIs Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Total KM Anual</p>
        <p class="text-lg font-black text-primary mt-0.5">${formatearNumero(km, 0)} <span class="text-xs font-bold text-on-surface-variant">km</span></p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Total Litros</p>
        <p class="text-lg font-black text-primary mt-0.5">${formatearNumero(litros, 0)} <span class="text-xs font-bold text-on-surface-variant">L</span></p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Gasto Anual</p>
        <p class="text-lg font-black text-primary mt-0.5">${esAdminPlenoUser ? '$' + formatearNumero(gasto, 0) : '-'}</p>
      </div>
      <div class="p-3.5 bg-surface-container-low border border-outline-variant rounded-xl">
        <p class="text-[11px] font-bold uppercase text-on-surface-variant">Costo Promedio / KM</p>
        <p class="text-lg font-black text-primary mt-0.5">${esAdminPlenoUser && costoKm > 0 ? '$' + formatearNumero(costoKm, 2) : '-'}</p>
      </div>
    </div>

    <!-- Month by Month Table -->
    <div>
      <div class="flex items-center justify-between mb-2">
        <h4 class="font-bold text-xs uppercase tracking-wider text-primary flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">calendar_month</span>
          Desglose Mes a Mes del Año
        </h4>
        <span class="text-[11px] text-on-surface-variant">${mesesKeys.length} meses registrados</span>
      </div>
      ${tablaMesesHtml}
    </div>

    <!-- Action Links -->
    <div class="pt-2 flex flex-wrap gap-2 justify-end">
      <a href="${baseUrlApp}/admin/camiones_ver.php?id=${r.id_camion}" class="px-3.5 py-2 rounded-lg bg-surface-container-high hover:bg-surface-container text-primary text-xs font-bold flex items-center gap-1.5 transition-colors">
        <span class="material-symbols-outlined text-sm">visibility</span> Ver Ficha del Camión
      </a>
      <a href="${baseUrlApp}/admin/reportes.php" class="px-3.5 py-2 rounded-lg bg-emerald-700 text-white text-xs font-bold hover:bg-emerald-800 flex items-center gap-1.5 transition-colors">
        <span class="material-symbols-outlined text-sm">bar_chart</span> Reporte de Rendimiento
      </a>
    </div>
  `;

  abrirModalVela(
    `Rendimiento Anual: ${r.patente}`,
    `Histórico Anual ${<?= json_encode($anio) ?>} (${r.marca || ''} ${r.modelo || ''})`,
    'analytics',
    'bg-emerald-50 text-emerald-600',
    'text-emerald-600',
    html
  );
}

function openKmModal() { document.getElementById('modalKm').classList.remove('hidden'); }
function closeKmModal() { document.getElementById('modalKm').classList.add('hidden'); }
document.getElementById('modalKm')?.addEventListener('click', function(e) { if (e.target === this) closeKmModal(); });
function openCombustibleModal() { document.getElementById('modalCombustible').classList.remove('hidden'); }
function closeCombustibleModal() { document.getElementById('modalCombustible').classList.add('hidden'); }
document.getElementById('modalCombustible')?.addEventListener('click', function(e) { if (e.target === this) closeCombustibleModal(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
