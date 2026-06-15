<?php
require_once __DIR__ . '/../includes/auth.php';
requireChofer();
$pageTitle = 'Mi Historial';
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

// Si el usuario no tiene id_chofer vinculado, buscarlo en choferes por usuario_id
if (!$idChofer && $userId) {
    try {
        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
        $stmtCh->execute([$userId]);
        $idChofer = $stmtCh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Combined activity
if ($idChofer) {
    $sql = "SELECT 'combustible' as tipo, co.fecha, CONCAT(c.patente, ' - ', co.litros, 'L - $', co.importe_total) as detalle, co.created_at FROM combustible co JOIN camiones c ON co.id_camion = c.id_camion WHERE co.id_chofer = ?
            UNION ALL
            SELECT 'mantenimiento' as tipo, m.fecha, CONCAT(c.patente, ' - ', m.tipo, ' - $', m.costo) as detalle, m.created_at FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion JOIN asignaciones a ON a.id_camion = c.id_camion WHERE a.id_chofer = ? AND a.activa = 1
            UNION ALL
            SELECT 'viaje' as tipo, h.fecha, CONCAT(c.patente, ' - ', h.origen, ' -> ', h.destino, ' (', h.km_recorridos, 'km)') as detalle, h.created_at FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion WHERE h.id_chofer = ?
            ORDER BY created_at DESC LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute([$idChofer, $idChofer, $idChofer]);
} elseif ($hasUsuarioId) {
    $sql = "SELECT 'combustible' as tipo, co.fecha, CONCAT(c.patente, ' - ', co.litros, 'L - $', co.importe_total) as detalle, co.created_at FROM combustible co JOIN camiones c ON co.id_camion = c.id_camion WHERE co.id_usuario_registra = ?
            UNION ALL
            SELECT 'mantenimiento' as tipo, m.fecha, CONCAT(c.patente, ' - ', m.tipo, ' - $', m.costo) as detalle, m.created_at FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion JOIN vehiculos_usuarios vu ON vu.vehiculo_id = c.id_camion WHERE vu.usuario_id = ?
            UNION ALL
            SELECT 'viaje' as tipo, h.fecha, CONCAT(c.patente, ' - ', h.origen, ' -> ', h.destino, ' (', h.km_recorridos, 'km)') as detalle, h.created_at FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion WHERE h.usuario_id = ?
            ORDER BY created_at DESC LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $userId, $userId]);
} else {
    $actividad = [];
}
if (isset($stmt)) { $actividad = $stmt->fetchAll(); }
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-5xl mx-auto">
<div class="mb-8">
<h2 class="font-headline-lg text-headline-lg text-primary">Mi Historial</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Registro completo de tu actividad.</p>
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Actividad Reciente</h3>
</div>
<div class="divide-y divide-outline-variant">
<?php if (empty($actividad)): ?>
<p class="p-6 text-on-surface-variant text-center">Sin actividad registrada</p>
<?php else: ?>
<?php foreach ($actividad as $a):
$iconos = ['combustible' => ['icon' => 'local_gas_station', 'color' => 'text-blue-600', 'bg' => 'bg-blue-50'],
           'mantenimiento' => ['icon' => 'build', 'color' => 'text-amber-600', 'bg' => 'bg-amber-50'],
           'viaje' => ['icon' => 'map', 'color' => 'text-green-600', 'bg' => 'bg-green-50']];
$ic = $iconos[$a['tipo']] ?? $iconos['viaje'];
?>
<div class="flex items-start gap-4 p-4 <?= $ic['bg'] ?>">
<div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 <?= $ic['color'] ?>">
<span class="material-symbols-outlined"><?= $ic['icon'] ?></span>
</div>
<div class="flex-1">
<div class="flex justify-between">
<p class="font-bold uppercase text-sm"><?= $a['tipo'] ?></p>
<span class="text-xs text-on-surface-variant"><?= date('d/m/Y', strtotime($a['fecha'])) ?></span>
</div>
<p class="text-sm text-on-surface-variant mt-1"><?= htmlspecialchars($a['detalle']) ?></p>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
