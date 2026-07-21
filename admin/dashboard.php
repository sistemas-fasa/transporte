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
    SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(litros) as litros, SUM(litros * precio_litro) as total
    FROM combustible
    WHERE fecha >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY mes ORDER BY mes ASC
")->fetchAll();

// Kilometros por camion (mes actual)
$kmCamion = $db->prepare("SELECT c.patente, COALESCE(SUM(h.km_recorridos),0) as total FROM camiones c LEFT JOIN km_recorrido h ON c.id_camion = h.id_camion AND MONTH(h.fecha)=? AND YEAR(h.fecha)=? GROUP BY c.id_camion ORDER BY total DESC LIMIT 5");
$kmCamion->execute([$mes, $anio]);
$kmCamiones = $kmCamion->fetchAll();

// Rendimiento km/l por camion
$rendimiento = $db->prepare("
    SELECT c.patente,
        COALESCE((SELECT SUM(km_recorridos) FROM km_recorrido WHERE id_camion = c.id_camion AND MONTH(fecha)=? AND YEAR(fecha)=?), 0) as km,
        COALESCE((SELECT SUM(litros) FROM combustible WHERE id_camion = c.id_camion AND MONTH(fecha)=? AND YEAR(fecha)=?), 0) as litros
    FROM camiones c
    HAVING litros > 0
    ORDER BY (km/litros) DESC LIMIT 5
");
$rendimiento->execute([$mes, $anio, $mes, $anio]);
$rendimientos = $rendimiento->fetchAll();

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
    SELECT c.id_camion, c.patente, c.marca, c.modelo,
           GREATEST(COALESCE(c.kilometraje_actual,0), COALESCE((SELECT MAX(hr.km_llegada) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion),0)) as km_actual,
           c.proximo_mantenimiento_km, c.proximo_mantenimiento_hs
    FROM camiones c
    WHERE (c.proximo_mantenimiento_km IS NOT NULL AND GREATEST(COALESCE(c.kilometraje_actual,0), COALESCE((SELECT MAX(hr.km_llegada) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion),0)) >= (c.proximo_mantenimiento_km - 5000))
       OR (c.proximo_mantenimiento_hs IS NOT NULL)
    ORDER BY (CASE WHEN c.proximo_mantenimiento_km IS NOT NULL THEN (c.proximo_mantenimiento_km - GREATEST(COALESCE(c.kilometraje_actual,0), COALESCE((SELECT MAX(hr.km_llegada) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion),0))) ELSE 0 END) ASC
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

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
<?php if (hasPermission('combustible_ver')): ?>
<!-- Combustible por mes -->
<div class="bg-surface-container-lowest border border-outline-variant p-6 overflow-x-auto">
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 overflow-x-auto card-modern">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider mb-4">Combustible por Mes</h2>
<canvas id="chartCombustible" height="200"></canvas>
</div>
<!-- Rendimiento km/l -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6 overflow-x-auto card-modern">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider mb-4">Rendimiento km/l por Camion</h2>
<canvas id="chartRendimiento" height="200"></canvas>
</div>
</div>
<?php endif; ?>

<?php if ((hasPermission('vehiculos_ver') || hasPermission('alertas_ver') || hasRole('inspector') || hasRole('Inspector')) && !empty($vtvAlertas)): ?>
<!-- VTV Proximas a Vencer -->
<section class="mt-8">
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern">
<div class="p-6 border-b border-outline-variant flex items-center gap-2">
<span class="material-symbols-outlined text-red-600">assignment</span>
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">VTV Próximas a Vencer (3 Meses)</h3>
</div>
<div class="divide-y divide-outline-variant">
<?php foreach ($vtvAlertas as $v):
$dias = (int)$v['dias_restantes'];
if ($dias <= 0) { $c = 'red'; $label = 'VENCIDA'; }
elseif ($dias <= 30) { $c = 'red'; $label = "$dias dias"; }
elseif ($dias <= 60) { $c = 'yellow'; $label = "$dias dias"; }
else { $c = 'yellow'; $label = "$dias dias"; }
?>
<div class="p-4 flex items-center gap-4">
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
</div>
</section>
<?php endif; ?>

<?php if (hasPermission('mantenimiento_ver') && !empty($mantenimientoAlertas)): ?>
<!-- Proximo Mantenimiento -->
<section class="mt-8">
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern">
<div class="p-6 border-b border-outline-variant flex items-center gap-2">
<span class="material-symbols-outlined text-amber-600">build</span>
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Proximo Mantenimiento</h3>
</div>
<div class="divide-y divide-outline-variant">
<?php foreach ($mantenimientoAlertas as $m):
$kmActual = (float)$m['km_actual'];
$proxKm = (float)$m['proximo_mantenimiento_km'];
$proxHs = (float)$m['proximo_mantenimiento_hs'];
$kmDiff = $proxKm > 0 ? $proxKm - $kmActual : null;
if ($kmDiff !== null && $kmDiff <= 0) { $c = 'red'; $label = 'VENCIDO'; }
elseif ($kmDiff !== null && $kmDiff <= 1000) { $c = 'red'; $label = "FALTAN $kmDiff KM"; }
elseif ($kmDiff !== null && $kmDiff <= 5000) { $c = 'yellow'; $label = "FALTAN $kmDiff KM"; }
elseif ($kmDiff !== null) { $c = 'green'; $label = "FALTAN $kmDiff KM"; }
elseif ($proxHs > 0) { $c = 'yellow'; $label = number_format($proxHs, 0) . ' HS'; }
else { $c = 'gray'; $label = '-'; }
?>
<div class="p-4 flex items-center gap-4">
<div class="w-10 h-10 rounded-full bg-<?= $c ?>-100 flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-<?= $c ?>-600">build</span>
</div>
<div class="flex-1">
<p class="font-bold"><?= htmlspecialchars($m['patente']) ?> - <?= htmlspecialchars($m['marca'] . ' ' . $m['modelo']) ?></p>
<p class="text-sm text-on-surface-variant">KM Actual: <?= number_format($kmActual, 0) ?><?= $proxKm ? ' | Prox: ' . number_format($proxKm, 0) . ' KM' : '' ?><?= $proxHs ? ' | ' . number_format($proxHs, 0) . ' HS' : '' ?></p>
</div>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase bg-<?= $c ?>-100 text-<?= $c ?>-800 border border-<?= $c ?>-200"><?= $label ?></span>
</div>
<?php endforeach; ?>
</div>
</div>
</section>
<?php endif; ?>

<?php if ((hasPermission('matafuegos_ver') || hasPermission('alertas_ver') || hasRole('inspector') || hasRole('Inspector')) && !empty($matafuegosAlertas)): ?>
<!-- Matafuegos Proximos a Vencer (3 Meses) -->
<section class="mt-8">
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern">
<div class="p-6 border-b border-outline-variant flex items-center justify-between">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-red-600">fire_extinguisher</span>
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Matafuegos Próximos a Vencer (3 Meses)</h3>
</div>
<a href="<?= BASE_URL ?>/admin/matafuegos.php" class="text-primary font-bold text-xs hover:underline flex items-center gap-1">
Gestionar <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
</a>
</div>
<div class="divide-y divide-outline-variant">
<?php foreach ($matafuegosAlertas as $mf):
$dias = (int)$mf['dias_restantes'];
if ($dias < 0) { $c = 'red'; $label = 'VENCIDO (' . abs($dias) . ' días)'; }
elseif ($dias <= 30) { $c = 'red'; $label = "$dias días"; }
else { $c = 'amber'; $label = "$dias días"; }
$lugar = $mf['patente'] ? htmlspecialchars($mf['patente'] . ' - ' . $mf['marca'] . ' ' . $mf['modelo']) : htmlspecialchars('Sector: ' . $mf['sector']);
?>
<div class="p-4 flex items-center gap-4 hover:bg-surface-container/50 transition-colors">
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
</div>
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

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php if (hasPermission('combustible_ver')): ?>
const combustibleData = {
labels: [<?php foreach ($combustibleMeses as $c): ?>'<?= $c['mes'] ?>',<?php endforeach; ?>],
datasets: [{
label: 'Litros',
data: [<?php foreach ($combustibleMeses as $c): ?><?= $c['litros'] ?>,<?php endforeach; ?>],
backgroundColor: '#091426',
borderColor: '#091426',
borderWidth: 2,
tension: 0.3
}]
};
new Chart(document.getElementById('chartCombustible'), {
type: 'bar',
data: combustibleData,
options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, title: { display: true, text: 'Litros', font: { weight: 'bold' } } } } }
});

const rendimientoData = {
labels: [<?php foreach ($rendimientos as $r): ?>'<?= $r['patente'] ?>',<?php endforeach; ?>],
datasets: [{
label: 'km/l',
data: [<?php foreach ($rendimientos as $r): $km = $r['km']; $litros = $r['litros']; $rl = $litros > 0 ? ($km / $litros) : 0; ?><?= number_format($rl, 2) ?>,<?php endforeach; ?>],
backgroundColor: ['#091426', '#505f76', '#bcc7de', '#1e293b', '#54647a'],
borderWidth: 0
}]
};
new Chart(document.getElementById('chartRendimiento'), {
type: 'bar',
data: rendimientoData,
options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, title: { display: true, text: 'km/l', font: { weight: 'bold' } } } } }
});
<?php endif; ?>

function openKmModal() { document.getElementById('modalKm').classList.remove('hidden'); }
function closeKmModal() { document.getElementById('modalKm').classList.add('hidden'); }
document.getElementById('modalKm')?.addEventListener('click', function(e) { if (e.target === this) closeKmModal(); });
function openCombustibleModal() { document.getElementById('modalCombustible').classList.remove('hidden'); }
function closeCombustibleModal() { document.getElementById('modalCombustible').classList.add('hidden'); }
document.getElementById('modalCombustible')?.addEventListener('click', function(e) { if (e.target === this) closeCombustibleModal(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
