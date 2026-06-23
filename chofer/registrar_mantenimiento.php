<?php
require_once __DIR__ . '/../includes/auth.php';
requireChoferAccess('mantenimiento_crear');
$pageTitle = 'Registrar Mantenimiento';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_chofer.php';

$db = getDB();
$userId = getCurrentUserId();
$idChofer = getChoferIdFromUser();
$mensaje = '';
$error = '';

// Si el usuario no tiene id_chofer vinculado, buscarlo en choferes por usuario_id
if (!$idChofer && $userId) {
    try {
        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
        $stmtCh->execute([$userId]);
        $idChofer = $stmtCh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Obtener camion asignado
$camion = null;
if ($idChofer) {
    $stmt = $db->prepare("SELECT c.* FROM asignaciones a JOIN camiones c ON a.id_camion = c.id_camion WHERE a.id_chofer = ? AND a.activa = 1 LIMIT 1");
    $stmt->execute([$idChofer]);
    $camion = $stmt->fetch();
}

// Si no hay camion por asignaciones, buscar por vehiculos_usuarios
if (!$camion && $userId) {
    try {
        $stmt2 = $db->prepare("SELECT c.*, vu.fecha_asignacion FROM vehiculos_usuarios vu JOIN camiones c ON vu.vehiculo_id = c.id_camion WHERE vu.usuario_id = ? LIMIT 1");
        $stmt2->execute([$userId]);
        $camion = $stmt2->fetch();
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $tipo = $_POST['tipo'] ?? 'otro';
    $descripcion = trim($_POST['descripcion'] ?? '');
    $proveedor = trim($_POST['proveedor'] ?? '');
    $costo = (float)($_POST['costo'] ?? 0);
    $kilometraje = (float)($_POST['kilometraje'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);

    $foto_factura = null;
    if (isset($_FILES['foto_factura']) && $_FILES['foto_factura']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['foto_factura']['name'], PATHINFO_EXTENSION);
        $foto_factura = 'factura_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['foto_factura']['tmp_name'], __DIR__ . '/../assets/uploads/facturas/' . $foto_factura);
    }

    try {
        $stmt = $db->prepare("INSERT INTO mantenimientos (fecha, id_camion, tipo, descripcion, proveedor, costo, kilometraje, foto_factura, id_usuario_registra) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fecha, $id_camion, $tipo, $descripcion, $proveedor, $costo, $kilometraje, $foto_factura, getCurrentUserId()]);
        registrarAuditoria(getCurrentUserId(), 'create', 'mantenimientos', $db->lastInsertId(), "Chofer reporto mantenimiento: $tipo");
        $mensaje = 'Mantenimiento reportado exitosamente';
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-5xl mx-auto">
<div class="mb-8">
<nav class="flex items-center gap-2 text-on-surface-variant font-label-caps text-label-caps mb-2">
<span class="uppercase">Mantenimiento</span>
<span class="material-symbols-outlined text-[16px]">chevron_right</span>
<span class="uppercase text-primary font-bold">Nuevo Reporte</span>
</nav>
<h2 class="font-headline-lg text-headline-lg text-primary">Reportar Mantenimiento</h2>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (!$camion): ?><div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg mb-4">No tiene un vehiculo asignado.</div><?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="bg-surface-container-lowest border border-outline-variant p-lg max-w-2xl">
<?php if ($camion): ?>
<input type="hidden" name="id_camion" value="<?= $camion['id_camion'] ?>"/>
<div class="bg-primary-container/10 p-4 rounded-lg mb-6">
<p class="font-bold">Vehiculo: <?= htmlspecialchars($camion['marca'] . ' ' . $camion['patente']) ?></p>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha</label>
<input name="fecha" type="date" value="<?= date('Y-m-d') ?>" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tipo</label>
<select name="tipo" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="cambio_aceite">Cambio de Aceite</option>
<option value="filtros">Filtros</option>
<option value="cubiertas">Cubiertas</option>
<option value="frenos">Frenos</option>
<option value="embrague">Embrague</option>
<option value="reparacion_general">Reparacion General</option>
<option value="otro">Otro</option>
</select>
</div>
</div>

<div class="mt-4">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label>
<textarea name="descripcion" rows="3" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Describa el problema o servicio realizado..."></textarea>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Proveedor</label>
<input name="proveedor" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Costo ($)</label>
<input name="costo" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Kilometraje</label>
<input name="kilometraje" type="number" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>

<div class="mt-4">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Foto Factura (opcional)</label>
<input name="foto_factura" type="file" accept="image/*" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>

<div class="flex gap-3 mt-6">
<a href="<?= BASE_URL ?>/chofer/panel.php" class="px-6 py-2 border border-outline text-primary font-bold rounded-lg hover:bg-surface-container-high">Cancelar</a>
<button type="submit" class="px-6 py-2 bg-primary text-on-primary font-bold rounded-lg hover:opacity-90 flex items-center gap-2">
<span class="material-symbols-outlined">save</span> Reportar
</button>
</div>
</form>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
