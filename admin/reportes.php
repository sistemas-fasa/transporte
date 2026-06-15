<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Reportes';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$tipo = $_GET['tipo'] ?? 'combustible';
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$anio = $_GET['anio'] ?? date('Y');
$id_chofer = $_GET['id_chofer'] ?? '';
$id_camion = $_GET['id_camion'] ?? '';

$camionesList = $db->query("SELECT id_camion, patente FROM camiones ORDER BY patente")->fetchAll();
$choferesList = $db->query("SELECT id_chofer, nombre, apellido FROM choferes ORDER BY apellido")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Reportes</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Exportacion de datos y analisis.</p>
</div>
<div class="flex gap-2">
<a href="<?= BASE_URL ?>/api/export.php?tipo=<?= $tipo ?>&formato=pdf&desde=<?= $desde ?>&hasta=<?= $hasta ?>&id_chofer=<?= $id_chofer ?>&id_camion=<?= $id_camion ?>" class="px-4 py-2 bg-red-600 text-white rounded-lg font-bold text-sm flex items-center gap-1 hover:opacity-90">
<span class="material-symbols-outlined text-sm">picture_as_pdf</span> PDF
</a>
<a href="<?= BASE_URL ?>/api/export.php?tipo=<?= $tipo ?>&formato=excel&desde=<?= $desde ?>&hasta=<?= $hasta ?>&id_chofer=<?= $id_chofer ?>&id_camion=<?= $id_camion ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg font-bold text-sm flex items-center gap-1 hover:opacity-90">
<span class="material-symbols-outlined text-sm">table_chart</span> Excel
</a>
</div>
</div>

<!-- Filters -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl mb-8">
<form method="GET" class="flex flex-wrap items-end gap-4">
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Tipo Reporte</label>
<select name="tipo" onchange="this.form.submit()" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="combustible" <?= $tipo === 'combustible' ? 'selected' : '' ?>>Combustible</option>
<option value="mantenimiento" <?= $tipo === 'mantenimiento' ? 'selected' : '' ?>>Mantenimiento</option>
<option value="chofer" <?= $tipo === 'chofer' ? 'selected' : '' ?>>Por Chofer</option>
<option value="camion" <?= $tipo === 'camion' ? 'selected' : '' ?>>Por Camion</option>
<option value="mensual" <?= $tipo === 'mensual' ? 'selected' : '' ?>>Mensual</option>
<option value="anual" <?= $tipo === 'anual' ? 'selected' : '' ?>>Anual</option>
</select>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Desde</label>
<input type="date" name="desde" value="<?= $desde ?>" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm"/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Hasta</label>
<input type="date" name="hasta" value="<?= $hasta ?>" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm"/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Anio (Anual)</label>
<input type="number" name="anio" value="<?= $anio ?>" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm" style="width:80px"/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Chofer</label>
<select name="id_chofer" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="">Todos</option>
<?php foreach($choferesList as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>" <?= $id_chofer == $ch['id_chofer'] ? 'selected' : '' ?>><?= $ch['apellido'] . ', ' . $ch['nombre'] ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Camión</label>
<select name="id_camion" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="">Todos</option>
<?php foreach($camionesList as $cam): ?>
<option value="<?= $cam['id_camion'] ?>" <?= $id_camion == $cam['id_camion'] ? 'selected' : '' ?>><?= $cam['patente'] ?></option>
<?php endforeach; ?>
</select>
</div>
<button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg font-bold text-sm">Filtrar</button>
</form>
</div>

<!-- Report Data -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">
<?php
$titulos = ['combustible' => 'Reporte de Combustible', 'mantenimiento' => 'Reporte de Mantenimiento', 'chofer' => 'Reporte por Chofer', 'camion' => 'Reporte por Camion', 'mensual' => 'Reporte Mensual', 'anual' => 'Reporte Anual'];
echo $titulos[$tipo] ?? 'Reporte';
?>
</h3>
</div>
<div class="p-6 overflow-x-auto">
<?php
switch ($tipo) {
    case 'combustible':
        $sql = "SELECT co.fecha, ch.apellido, ch.nombre, c.patente, co.estacion_servicio, co.litros, co.precio_litro, (co.litros * co.precio_litro) as importe_total, co.kilometraje_al_cargar FROM combustible co JOIN choferes ch ON co.id_chofer = ch.id_chofer JOIN camiones c ON co.id_camion = c.id_camion WHERE DATE(co.fecha) BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($id_chofer) { $sql .= " AND co.id_chofer = ?"; $params[] = $id_chofer; }
        if ($id_camion) { $sql .= " AND co.id_camion = ?"; $params[] = $id_camion; }
        $sql .= " ORDER BY co.fecha DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        echo '<table class="w-full text-sm"><thead><tr class="bg-surface-container-high">';
        echo '<th class="p-2 text-left">Fecha</th><th class="p-2 text-left">Chofer</th><th class="p-2 text-left">Camion</th><th class="p-2 text-left">Estacion</th><th class="p-2 text-right">Litros</th><th class="p-2 text-right">Precio/L</th><th class="p-2 text-right">Total</th>';
        echo '</tr></thead><tbody>';
        $tLitros = 0; $tTotal = 0;
        foreach ($data as $r) {
            echo "<tr class='border-t border-outline-variant hover:bg-surface-container'><td class='p-2'>" . date('d/m/Y', strtotime($r['fecha'])) . "</td><td class='p-2'>{$r['apellido']}, {$r['nombre']}</td><td class='p-2 font-bold'>{$r['patente']}</td><td class='p-2'>{$r['estacion_servicio']}</td><td class='p-2 text-right'>" . number_format($r['litros'], 2) . "</td><td class='p-2 text-right'>$" . number_format($r['precio_litro'], 3) . "</td><td class='p-2 text-right font-bold'>$" . number_format($r['importe_total'], 2) . "</td></tr>";
            $tLitros += $r['litros']; $tTotal += $r['importe_total'];
        }
        echo "<tr class='bg-surface-container font-bold'><td colspan='4' class='p-2 text-right'>TOTALES</td><td class='p-2 text-right'>" . number_format($tLitros, 2) . "</td><td></td><td class='p-2 text-right'>$" . number_format($tTotal, 2) . "</td></tr>";
        echo '</tbody></table>';
        break;

    case 'mantenimiento':
        $sql = "SELECT m.fecha, c.patente, m.tipo, m.descripcion, m.proveedor, m.costo, m.kilometraje FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion WHERE m.fecha BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($id_camion) { $sql .= " AND m.id_camion = ?"; $params[] = $id_camion; }
        $sql .= " ORDER BY m.fecha DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        echo '<table class="w-full text-sm"><thead><tr class="bg-surface-container-high">';
        echo '<th class="p-2 text-left">Fecha</th><th class="p-2 text-left">Camion</th><th class="p-2 text-left">Tipo</th><th class="p-2 text-left">Descripcion</th><th class="p-2 text-left">Proveedor</th><th class="p-2 text-right">Costo</th><th class="p-2 text-right">KM</th>';
        echo '</tr></thead><tbody>';
        $tCosto = 0;
        foreach ($data as $r) {
            echo "<tr class='border-t border-outline-variant hover:bg-surface-container'><td class='p-2'>{$r['fecha']}</td><td class='p-2 font-bold'>{$r['patente']}</td><td class='p-2'>{$r['tipo']}</td><td class='p-2'>" . htmlspecialchars($r['descripcion'] ?? '') . "</td><td class='p-2'>" . htmlspecialchars($r['proveedor'] ?? '') . "</td><td class='p-2 text-right'>$" . number_format($r['costo'], 2) . "</td><td class='p-2 text-right'>" . number_format($r['kilometraje'], 0) . "</td></tr>";
            $tCosto += $r['costo'];
        }
        echo "<tr class='bg-surface-container font-bold'><td colspan='5' class='p-2 text-right'>TOTAL</td><td class='p-2 text-right'>$" . number_format($tCosto, 2) . "</td><td></td></tr>";
        echo '</tbody></table>';
        break;

    case 'chofer':
        $sql = "SELECT ch.id_chofer, ch.nombre, ch.apellido, ch.dni, COUNT(DISTINCT hr.id_hoja) as viajes, COALESCE(SUM(hr.km_recorridos),0) as km_total, COALESCE(SUM(co2.litros),0) as litros_total, COALESCE(SUM(co2.litros * co2.precio_litro),0) as combustible_total FROM choferes ch LEFT JOIN km_recorrido hr ON ch.id_chofer = hr.id_chofer AND hr.fecha BETWEEN ? AND ? LEFT JOIN combustible co2 ON ch.id_chofer = co2.id_chofer AND DATE(co2.fecha) BETWEEN ? AND ?";
        $params = [$desde, $hasta, $desde, $hasta];
        $where = [];
        if ($id_chofer) { $where[] = "ch.id_chofer = ?"; $params[] = $id_chofer; }
        if (!empty($where)) { $sql .= " WHERE " . implode(" AND ", $where); }
        $sql .= " GROUP BY ch.id_chofer ORDER BY km_total DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        echo '<table class="w-full text-sm"><thead><tr class="bg-surface-container-high">';
        echo '<th class="p-2 text-left">Chofer</th><th class="p-2 text-left">DNI</th><th class="p-2 text-right">Viajes</th><th class="p-2 text-right">KM Totales</th><th class="p-2 text-right">Litros</th><th class="p-2 text-right">Gasto Comb.</th>';
        echo '</tr></thead><tbody>';
        foreach ($data as $r) {
            echo "<tr class='border-t border-outline-variant hover:bg-surface-container'><td class='p-2 font-bold'>{$r['apellido']}, {$r['nombre']}</td><td class='p-2'>{$r['dni']}</td><td class='p-2 text-right'>{$r['viajes']}</td><td class='p-2 text-right'>" . number_format($r['km_total'], 0) . "</td><td class='p-2 text-right'>" . number_format($r['litros_total'], 2) . "</td><td class='p-2 text-right'>$" . number_format($r['combustible_total'], 2) . "</td></tr>";
        }
        echo '</tbody></table>';
        break;

    case 'camion':
        $sql = "SELECT c.id_camion, c.patente, c.marca, c.modelo, c.kilometraje_actual, COUNT(DISTINCT hr.id_hoja) as viajes, COALESCE(SUM(hr.km_recorridos),0) as km_total, COALESCE(SUM(co2.litros),0) as litros_total, COALESCE(SUM(co2.litros * co2.precio_litro),0) as combustible_total, COALESCE(SUM(m.costo),0) as mantenimiento_total FROM camiones c LEFT JOIN km_recorrido hr ON c.id_camion = hr.id_camion AND hr.fecha BETWEEN ? AND ? LEFT JOIN combustible co2 ON c.id_camion = co2.id_camion AND DATE(co2.fecha) BETWEEN ? AND ? LEFT JOIN mantenimientos m ON c.id_camion = m.id_camion AND m.fecha BETWEEN ? AND ?";
        $params = [$desde, $hasta, $desde, $hasta, $desde, $hasta];
        $where = [];
        if ($id_camion) { $where[] = "c.id_camion = ?"; $params[] = $id_camion; }
        if (!empty($where)) { $sql .= " WHERE " . implode(" AND ", $where); }
        $sql .= " GROUP BY c.id_camion ORDER BY km_total DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        echo '<table class="w-full text-sm"><thead><tr class="bg-surface-container-high">';
        echo '<th class="p-2 text-left">Camion</th><th class="p-2 text-left">Patente</th><th class="p-2 text-right">Viajes</th><th class="p-2 text-right">KM</th><th class="p-2 text-right">Litros</th><th class="p-2 text-right">Gasto Comb.</th><th class="p-2 text-right">Gasto Mant.</th><th class="p-2 text-right">Total</th>';
        echo '</tr></thead><tbody>';
        foreach ($data as $r) {
            $total = $r['combustible_total'] + $r['mantenimiento_total'];
            echo "<tr class='border-t border-outline-variant hover:bg-surface-container'><td class='p-2 font-bold'>{$r['marca']} {$r['modelo']}</td><td class='p-2'>{$r['patente']}</td><td class='p-2 text-right'>{$r['viajes']}</td><td class='p-2 text-right'>" . number_format($r['km_total'], 0) . "</td><td class='p-2 text-right'>" . number_format($r['litros_total'], 2) . "</td><td class='p-2 text-right'>$" . number_format($r['combustible_total'], 2) . "</td><td class='p-2 text-right'>$" . number_format($r['mantenimiento_total'], 2) . "</td><td class='p-2 text-right font-bold'>$" . number_format($total, 2) . "</td></tr>";
        }
        echo '</tbody></table>';
        break;

    case 'mensual':
        $stmt = $db->prepare("SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(*) as cargas, SUM(litros) as litros, SUM(litros * precio_litro) as total_comb FROM combustible WHERE fecha BETWEEN ? AND ? GROUP BY mes ORDER BY mes");
        $stmt->execute([$desde, $hasta]);
        $combustibleData = $stmt->fetchAll();
        $stmt2 = $db->prepare("SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(*) as servicios, SUM(costo) as total_mant FROM mantenimientos WHERE fecha BETWEEN ? AND ? GROUP BY mes ORDER BY mes");
        $stmt2->execute([$desde, $hasta]);
        $mantData = $stmt2->fetchAll();
        $stmt3 = $db->prepare("SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, COUNT(*) as viajes, SUM(km_recorridos) as km FROM km_recorrido WHERE fecha BETWEEN ? AND ? GROUP BY mes ORDER BY mes");
        $stmt3->execute([$desde, $hasta]);
        $viajesData = $stmt3->fetchAll();

        echo '<table class="w-full text-sm"><thead><tr class="bg-surface-container-high">';
        echo '<th class="p-2 text-left">Mes</th><th class="p-2 text-right">Viajes</th><th class="p-2 text-right">KM</th><th class="p-2 text-right">Cargas Comb.</th><th class="p-2 text-right">Litros</th><th class="p-2 text-right">Gasto Comb.</th><th class="p-2 text-right">Servicios</th><th class="p-2 text-right">Gasto Mant.</th>';
        echo '</tr></thead><tbody>';
        $allMeses = array_unique(array_merge(array_column($combustibleData, 'mes'), array_column($mantData, 'mes'), array_column($viajesData, 'mes')));
        sort($allMeses);
        foreach ($allMeses as $mes) {
            $c = current(array_filter($combustibleData, fn($x) => $x['mes'] === $mes)) ?: ['cargas' => 0, 'litros' => 0, 'total_comb' => 0];
            $m = current(array_filter($mantData, fn($x) => $x['mes'] === $mes)) ?: ['servicios' => 0, 'total_mant' => 0];
            $v = current(array_filter($viajesData, fn($x) => $x['mes'] === $mes)) ?: ['viajes' => 0, 'km' => 0];
            echo "<tr class='border-t border-outline-variant'><td class='p-2 font-bold'>{$mes}</td><td class='p-2 text-right'>{$v['viajes']}</td><td class='p-2 text-right'>" . number_format($v['km'], 0) . "</td><td class='p-2 text-right'>{$c['cargas']}</td><td class='p-2 text-right'>" . number_format($c['litros'], 2) . "</td><td class='p-2 text-right'>$" . number_format($c['total_comb'], 2) . "</td><td class='p-2 text-right'>{$m['servicios']}</td><td class='p-2 text-right'>$" . number_format($m['total_mant'], 2) . "</td></tr>";
        }
        echo '</tbody></table>';
        break;

    case 'anual':
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(fecha, '%Y') as anio, COUNT(*) as cargas, SUM(litros) as litros, SUM(litros * precio_litro) as total_comb,
                (SELECT COUNT(*) FROM mantenimientos WHERE YEAR(fecha) = ?) as servicios,
                (SELECT SUM(costo) FROM mantenimientos WHERE YEAR(fecha) = ?) as total_mant,
                (SELECT COUNT(*) FROM km_recorrido WHERE YEAR(fecha) = ?) as viajes,
                (SELECT SUM(km_recorridos) FROM km_recorrido WHERE YEAR(fecha) = ?) as km
            FROM combustible WHERE YEAR(fecha) = ?
            GROUP BY anio
        ");
        $stmt->execute([$anio, $anio, $anio, $anio, $anio]);
        $data = $stmt->fetchAll();
        echo '<table class="w-full text-sm"><thead><tr class="bg-surface-container-high">';
        echo '<th class="p-2 text-left">Anio</th><th class="p-2 text-right">Viajes</th><th class="p-2 text-right">KM</th><th class="p-2 text-right">Cargas</th><th class="p-2 text-right">Litros</th><th class="p-2 text-right">Gasto Comb.</th><th class="p-2 text-right">Servicios</th><th class="p-2 text-right">Gasto Mant.</th><th class="p-2 text-right">Total Gral</th>';
        echo '</tr></thead><tbody>';
        foreach ($data as $r) {
            $total = $r['total_comb'] + $r['total_mant'];
            echo "<tr class='border-t border-outline-variant'><td class='p-2 font-bold'>{$r['anio']}</td><td class='p-2 text-right'>{$r['viajes']}</td><td class='p-2 text-right'>" . number_format($r['km'], 0) . "</td><td class='p-2 text-right'>{$r['cargas']}</td><td class='p-2 text-right'>" . number_format($r['litros'], 2) . "</td><td class='p-2 text-right'>$" . number_format($r['total_comb'], 2) . "</td><td class='p-2 text-right'>{$r['servicios']}</td><td class='p-2 text-right'>$" . number_format($r['total_mant'], 2) . "</td><td class='p-2 text-right font-bold'>$" . number_format($total, 2) . "</td></tr>";
        }
        echo '</tbody></table>';
        break;
}
?>
</div>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
