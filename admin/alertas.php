<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('alertas_ver');
$pageTitle = 'Alertas';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();

// Generate alerts automatically
try {
    // Licencias proximas a vencer
    $licencias = $db->query("SELECT c.id_chofer, c.nombre, c.apellido, c.vencimiento_licencia FROM choferes c WHERE c.estado = 'activo' AND c.vencimiento_licencia IS NOT NULL AND c.vencimiento_licencia <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchAll();
    foreach ($licencias as $l) {
        $diff = (strtotime($l['vencimiento_licencia']) - time()) / 86400;
        $severidad = $diff <= 0 ? 'rojo' : ($diff <= 15 ? 'rojo' : 'amarillo');
        $msg = "Licencia de {$l['nombre']} {$l['apellido']} vence el " . date('d/m/Y', strtotime($l['vencimiento_licencia']));
        $check = $db->prepare("SELECT COUNT(*) FROM alertas WHERE tipo='vencimiento_licencia' AND id_referencia=? AND resuelta=0");
        $check->execute([$l['id_chofer']]);
        if ($check->fetchColumn() == 0) {
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_licencia', ?, ?, ?)")->execute([$l['id_chofer'], $msg, $severidad]);
        }
    }

    // VTV proximas a vencer (3 meses de anticipacion)
    $vtvs = $db->query("SELECT v.*, c.patente FROM vtv v JOIN camiones c ON v.id_camion = c.id_camion WHERE v.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)")->fetchAll();
    foreach ($vtvs as $v) {
        $diff = (strtotime($v['fecha_vencimiento']) - time()) / 86400;
        $severidad = $diff <= 0 ? 'rojo' : ($diff <= 15 ? 'rojo' : ($diff <= 60 ? 'amarillo' : 'verde'));
        $msg = "VTV de camion {$v['patente']} vence el " . date('d/m/Y', strtotime($v['fecha_vencimiento']));
        $check = $db->prepare("SELECT COUNT(*) FROM alertas WHERE tipo='vencimiento_vtv' AND id_referencia=? AND resuelta=0");
        $check->execute([$v['id_camion']]);
        if ($check->fetchColumn() == 0) {
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_vtv', ?, ?, ?)")->execute([$v['id_camion'], $msg, $severidad]);
        }
    }
    // VTV desde columna directa en camiones
    $vtvsCamion = $db->query("SELECT id_camion, patente, vtv FROM camiones WHERE vtv IS NOT NULL AND vtv <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND vtv NOT IN (SELECT fecha_vencimiento FROM vtv WHERE id_camion = camiones.id_camion)")->fetchAll();
    foreach ($vtvsCamion as $v) {
        $diff = (strtotime($v['vtv']) - time()) / 86400;
        $severidad = $diff <= 0 ? 'rojo' : ($diff <= 15 ? 'rojo' : ($diff <= 60 ? 'amarillo' : 'verde'));
        $msg = "VTV de camion {$v['patente']} vence el " . date('d/m/Y', strtotime($v['vtv']));
        $check = $db->prepare("SELECT COUNT(*) FROM alertas WHERE tipo='vencimiento_vtv' AND id_referencia=? AND resuelta=0");
        $check->execute([$v['id_camion']]);
        if ($check->fetchColumn() == 0) {
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_vtv', ?, ?, ?)")->execute([$v['id_camion'], $msg, $severidad]);
        }
    }

    // Seguros vencidos
    $seguros = $db->query("SELECT s.*, c.patente FROM seguros s JOIN camiones c ON s.id_camion = c.id_camion WHERE s.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchAll();
    foreach ($seguros as $s) {
        $diff = (strtotime($s['fecha_vencimiento']) - time()) / 86400;
        $severidad = $diff <= 0 ? 'rojo' : 'amarillo';
        $msg = "Seguro de camion {$s['patente']} vence el " . date('d/m/Y', strtotime($s['fecha_vencimiento']));
        $check = $db->prepare("SELECT COUNT(*) FROM alertas WHERE tipo='vencimiento_seguro' AND id_referencia=? AND resuelta=0");
        $check->execute([$s['id_camion']]);
        if ($check->fetchColumn() == 0) {
            $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('vencimiento_seguro', ?, ?, ?)")->execute([$s['id_camion'], $msg, $severidad]);
        }
    }

    // Proximo mantenimiento
    $mants = $db->query("SELECT m.*, c.patente FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion WHERE m.proximo_mantenimiento_km IS NOT NULL AND c.kilometraje_actual >= (m.proximo_mantenimiento_km - 1000) AND (SELECT COUNT(*) FROM alertas WHERE tipo='cambio_aceite' AND id_referencia=m.id_mantenimiento AND resuelta=0) = 0")->fetchAll();
    foreach ($mants as $m) {
        $msg = "Cambio de aceite proximo para {$m['patente']} - programar en los proximos 1000km";
        $db->prepare("INSERT INTO alertas (tipo, id_referencia, mensaje, severidad) VALUES ('cambio_aceite', ?, ?, 'amarillo')")->execute([$m['id_camion'], $msg]);
    }
} catch (Exception $e) {
    // Silently handle
}

// Resolve alerts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolver'])) {
    $id = (int)$_POST['id_alerta'];
    $db->prepare("UPDATE alertas SET resuelta = 1 WHERE id_alerta = ?")->execute([$id]);
    header('Location: ' . BASE_URL . '/admin/alertas.php');
    exit;
}

$alertas = $db->query("SELECT a.*, c.patente FROM alertas a LEFT JOIN camiones c ON a.id_referencia = c.id_camion WHERE resuelta = 0 ORDER BY FIELD(severidad,'rojo','amarillo','verde'), fecha_creacion DESC")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Centro de Alertas</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Monitoreo y notificaciones del sistema.</p>
</div>
</div>

<!-- Traffic Light Panel -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
<div class="bg-green-50 border border-green-200 rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-4xl text-green-600">check_circle</span>
<div class="font-headline-md text-headline-md text-green-800 mt-2"><?= count(array_filter($alertas, fn($a) => $a['severidad'] === 'verde')) ?></div>
<p class="font-label-caps text-label-caps text-green-700 uppercase">Estado OK</p>
</div>
<div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-4xl text-amber-500">warning</span>
<div class="font-headline-md text-headline-md text-amber-800 mt-2"><?= count(array_filter($alertas, fn($a) => $a['severidad'] === 'amarillo')) ?></div>
<p class="font-label-caps text-label-caps text-amber-700 uppercase">Advertencias</p>
</div>
<div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-4xl text-red-600">error</span>
<div class="font-headline-md text-headline-md text-red-800 mt-2"><?= count(array_filter($alertas, fn($a) => $a['severidad'] === 'rojo')) ?></div>
<p class="font-label-caps text-label-caps text-red-700 uppercase">Criticos</p>
</div>
</div>

<!-- Alertas List -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Alertas Activas</h3>
</div>
<div class="divide-y divide-outline-variant">
<?php if (empty($alertas)): ?>
<p class="p-6 text-on-surface-variant text-center">No hay alertas activas. Todo en orden.</p>
<?php else: ?>
<?php foreach ($alertas as $a):
$cfg = [
'rojo' => ['bg' => 'bg-red-50', 'border' => 'border-red-500', 'icon' => 'error', 'iconbg' => 'bg-red-600', 'label' => 'CRITICO', 'labeltext' => 'text-red-700'],
'amarillo' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-400', 'icon' => 'warning', 'iconbg' => 'bg-amber-500', 'label' => 'ADVERTENCIA', 'labeltext' => 'text-amber-700'],
'verde' => ['bg' => 'bg-green-50', 'border' => 'border-green-500', 'icon' => 'check_circle', 'iconbg' => 'bg-green-600', 'label' => 'OK', 'labeltext' => 'text-green-700'],
];
$c = $cfg[$a['severidad']] ?? $cfg['verde'];
?>
<div class="p-4 <?= $c['bg'] ?> border-l-4 <?= $c['border'] ?> flex items-start gap-4">
<div class="w-10 h-10 rounded-full <?= $c['iconbg'] ?> flex items-center justify-center text-white shrink-0">
<span class="material-symbols-outlined"><?= $c['icon'] ?></span>
</div>
<div class="flex-1">
<div class="flex justify-between items-start">
<div>
<p class="font-bold uppercase"><?= htmlspecialchars($a['mensaje']) ?></p>
<p class="text-xs text-on-surface-variant mt-1"><?= date('d/m/Y H:i', strtotime($a['fecha_creacion'])) ?></p>
</div>
<span class="text-[10px] font-bold <?= $c['labeltext'] ?>"><?= $c['label'] ?></span>
</div>
<form method="POST" class="mt-2">
<input type="hidden" name="id_alerta" value="<?= $a['id_alerta'] ?>"/>
<button type="submit" name="resolver" class="text-xs font-bold text-primary underline">Marcar como resuelta</button>
</form>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
