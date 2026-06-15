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

$gastoCombustible = $db->prepare("SELECT COALESCE(SUM(importe_total),0) as total FROM combustible WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$gastoCombustible->execute([$mes, $anio]);
$gastoCombData = $gastoCombustible->fetch();

$gastoMantenimiento = $db->prepare("SELECT COALESCE(SUM(costo),0) as total FROM mantenimientos WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$gastoMantenimiento->execute([$mes, $anio]);
$gastoMantData = $gastoMantenimiento->fetch();

// Alertas
$alertas = $db->query("SELECT a.*, c.patente FROM alertas a LEFT JOIN camiones c ON a.id_referencia = c.id_camion WHERE resuelta = 0 ORDER BY FIELD(severidad,'rojo','amarillo','verde'), fecha_creacion DESC LIMIT 10")->fetchAll();

// Gasto por camion (mes actual) con KM
$gastoCamion = $db->prepare("
    SELECT c.patente, c.marca,
        COALESCE((SELECT SUM(co2.importe_total) FROM combustible co2 WHERE co2.id_camion = c.id_camion AND MONTH(co2.fecha)=? AND YEAR(co2.fecha)=?),0) as total,
        COALESCE((SELECT SUM(hr.km_recorridos) FROM km_recorrido hr WHERE hr.id_camion = c.id_camion AND MONTH(hr.fecha)=? AND YEAR(hr.fecha)=?),0) as km
    FROM camiones c
    ORDER BY total DESC LIMIT 5
");
$gastoCamion->execute([$mes, $anio, $mes, $anio]);
$gastoCamiones = $gastoCamion->fetchAll();
$maxGasto = $gastoCamiones ? max(array_column($gastoCamiones, 'total')) : 1;

// Combustible por mes (ultimos 6 meses)
$combustibleMeses = $db->query("
    SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(litros) as litros, SUM(importe_total) as total
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

// VTV proximas a vencer (3 meses)
$vtvAlertas = $db->query("SELECT id_camion, patente, marca, modelo, vtv, DATEDIFF(vtv, CURDATE()) as dias_restantes FROM camiones WHERE vtv IS NOT NULL AND vtv <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY vtv ASC")->fetchAll();

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
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<!-- KPI Cards Grid -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
<div class="bg-surface-container-lowest border border-outline-variant p-4 flex flex-col justify-between min-h-[120px]">
<div class="flex items-center gap-2 text-secondary mb-2">
<span class="material-symbols-outlined text-[20px]">local_shipping</span>
<span class="font-label-caps text-label-caps uppercase">Total Camiones</span>
</div>
<div class="font-headline-lg text-[18px] md:text-headline-lg text-primary overflow-x-auto"><?= number_format($totalCamiones['total']) ?></div>
<div class="text-[10px] text-on-surface-variant font-medium mt-1">OPERATIVOS: <?= number_format($totalCamiones['activos']) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4 flex flex-col justify-between min-h-[120px]">
<div class="flex items-center gap-2 text-secondary mb-2">
<span class="material-symbols-outlined text-[20px]">person</span>
<span class="font-label-caps text-label-caps uppercase">Total Choferes</span>
</div>
<div class="font-headline-lg text-[18px] md:text-headline-lg text-primary overflow-x-auto"><?= number_format($totalChoferes['total']) ?></div>
<div class="text-[10px] text-on-surface-variant font-medium mt-1">ACTIVOS: <?= number_format($totalChoferes['activos']) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4 flex flex-col justify-between min-h-[120px]">
<div class="flex items-center gap-2 text-secondary mb-2">
<span class="material-symbols-outlined text-[20px]">route</span>
<span class="font-label-caps text-label-caps uppercase">KM Recorridos Mes</span>
</div>
<div class="font-headline-lg text-[18px] md:text-headline-lg text-primary overflow-x-auto whitespace-nowrap"><?= number_format($kmData['total'], 0) ?> <span class="text-body-md">km</span></div>
<div class="text-[10px] text-green-600 font-medium mt-1">MES ACTUAL</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4 flex flex-col justify-between min-h-[120px]">
<div class="flex items-center gap-2 text-secondary mb-2">
<span class="material-symbols-outlined text-[20px]">payments</span>
<span class="font-label-caps text-label-caps uppercase">Gasto Combustible</span>
</div>
<div class="font-headline-lg text-[18px] md:text-headline-lg text-primary overflow-x-auto whitespace-nowrap">$<?= number_format($gastoCombData['total'], 2) ?></div>
<div class="text-[16px] md:text-[18px] font-bold text-green-600 mt-1">LITROS: <?= number_format($litrosData['total'], 2) ?></div>
</div>
</section>

<!-- Bento Layout -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
<!-- Alertas -->
<section class="lg:col-span-7 bg-surface-container-lowest border border-outline-variant p-6">
<div class="flex items-center justify-between mb-6">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider">Alertas de Flota</h2>
<a href="<?= BASE_URL ?>/admin/alertas.php" class="text-primary font-bold text-[12px] underline">Ver Todas</a>
</div>
<div class="space-y-3">
<?php if (empty($alertas)): ?>
<p class="text-on-surface-variant text-center py-8">No hay alertas activas</p>
<?php else: ?>
<?php foreach (array_slice($alertas, 0, 5) as $alerta):
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

<!-- Gasto por camion -->
<section class="lg:col-span-5 bg-surface-container-lowest border border-outline-variant p-6 flex flex-col">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider mb-6">Gasto por Camion (Mes) - Importe en $</h2>
<div class="flex-1 flex flex-col justify-end gap-2">
<div class="space-y-4">
<?php foreach ($gastoCamiones as $gc):
    $width = $maxGasto > 0 ? ($gc['total'] / $maxGasto * 100) : 0;
?>
<div class="space-y-1">
<div class="flex justify-between text-[11px] font-bold text-secondary uppercase gap-2">
<span class="truncate"><?= htmlspecialchars($gc['patente']) ?> (<?= htmlspecialchars($gc['marca']) ?>)</span>
<span class="shrink-0">$<?= number_format($gc['total'], 2) ?></span>
</div>
<div class="w-full bg-surface-container-high h-4">
<div class="bg-primary h-full transition-all duration-1000" style="width: <?= $width ?>%"></div>
</div>
<div class="flex justify-between text-[10px] text-on-surface-variant">
<span>KM: <?= number_format($gc['km'], 0) ?> km</span>
<span><?= $gc['km'] > 0 ? '$' . number_format($gc['total'] / $gc['km'], 2) . '/km' : '' ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
</section>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
<!-- Combustible por mes -->
<div class="bg-surface-container-lowest border border-outline-variant p-6 overflow-x-auto">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider mb-4">Combustible por Mes</h2>
<canvas id="chartCombustible" height="200"></canvas>
</div>
<!-- Rendimiento km/l -->
<div class="bg-surface-container-lowest border border-outline-variant p-6 overflow-x-auto">
<h2 class="font-headline-sm text-headline-sm text-primary uppercase tracking-wider mb-4">Rendimiento km/l por Camion</h2>
<canvas id="chartRendimiento" height="200"></canvas>
</div>
</div>

<!-- VTV Proximas a Vencer -->
<?php if (!empty($vtvAlertas)): ?>
<section class="mt-8">
<div class="bg-surface-container-lowest border border-outline-variant overflow-hidden">
<div class="p-6 border-b border-outline-variant flex items-center gap-2">
<span class="material-symbols-outlined text-red-600">assignment</span>
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">VTV Proximas a Vencer (3 Meses)</h3>
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

<!-- Proximo Mantenimiento -->
<?php if (!empty($mantenimientoAlertas)): ?>
<section class="mt-8">
<div class="bg-surface-container-lowest border border-outline-variant overflow-hidden">
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

<!-- Secondary Section -->
<section class="mt-8">
<div class="bg-surface-container-lowest border border-outline-variant overflow-hidden">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Resumen del Mes</h3>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-outline-variant">
<div class="p-6">
<h4 class="font-body-md font-bold text-primary uppercase mb-2">Combustible</h4>
<p class="text-on-surface-variant mb-4">Litros consumidos: <strong><?= number_format($litrosData['total'], 2) ?> L</strong></p>
<p class="text-on-surface-variant">Gasto total: <strong>$<?= number_format($gastoCombData['total'], 2) ?></strong></p>
</div>
<div class="p-6">
<h4 class="font-body-md font-bold text-primary uppercase mb-2">Mantenimiento</h4>
<p class="text-on-surface-variant mb-4">Gasto del mes: <strong>$<?= number_format($gastoMantData['total'], 2) ?></strong></p>
</div>
<div class="p-6">
<h4 class="font-body-md font-bold text-primary uppercase mb-2">Kilometraje</h4>
<p class="text-on-surface-variant mb-4">KM recorridos: <strong><?= number_format($kmData['total'], 0) ?> km</strong></p>
</div>
</div>
</div>
</section>
</main>

<script>
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
