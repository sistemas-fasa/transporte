<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Gestion de Mantenimiento';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $id_camion = (int)($_POST['id_camion'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'otro';
        $descripcion = trim($_POST['descripcion'] ?? '');
        $proveedor = trim($_POST['proveedor'] ?? '');
        $costo = (float)($_POST['costo'] ?? 0);
        $kilometraje = (float)($_POST['kilometraje'] ?? 0);
        $proximo_km = (float)($_POST['proximo_mantenimiento_km'] ?? 0);
        $proximo_fecha = $_POST['proximo_mantenimiento_fecha'] ?? null;

        // Handle file upload
        $foto_factura = null;
        if (isset($_FILES['foto_factura']) && $_FILES['foto_factura']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['foto_factura']['name'], PATHINFO_EXTENSION);
            $foto_factura = 'factura_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['foto_factura']['tmp_name'], __DIR__ . '/../assets/uploads/facturas/' . $foto_factura);
        }

        try {
            $stmt = $db->prepare("INSERT INTO mantenimientos (fecha, id_camion, tipo, descripcion, proveedor, costo, kilometraje, proximo_mantenimiento_km, proximo_mantenimiento_fecha, foto_factura, id_usuario_registra) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$fecha, $id_camion, $tipo, $descripcion, $proveedor, $costo, $kilometraje, $proximo_km, $proximo_fecha, $foto_factura, getCurrentUserId()]);
            $idMant = $db->lastInsertId();
            registrarAuditoria(getCurrentUserId(), 'create', 'mantenimientos', $idMant, "Registro mantenimiento para camion ID $id_camion - $tipo");
            $mensaje = 'Mantenimiento registrado exitosamente';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

$buscar = $_GET['buscar'] ?? '';
$sql = "SELECT m.*, c.patente, c.marca FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion WHERE 1=1";
$params = [];
if ($buscar) {
    $sql .= " AND (c.patente LIKE ? OR m.tipo LIKE ? OR m.proveedor LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
$sql .= " ORDER BY m.fecha DESC LIMIT 100";
$mantenimientos = $db->prepare($sql);
$mantenimientos->execute($params);
$mantList = $mantenimientos->fetchAll();

$camiones = $db->query("SELECT id_camion, patente, marca, modelo FROM camiones ORDER BY patente")->fetchAll();

$gastoTotal = $db->query("SELECT COALESCE(SUM(costo),0) as total FROM mantenimientos WHERE MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())")->fetch();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Mantenimiento</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Registro de servicios y costos.</p>
</div>
<button onclick="openModal('modalMantenimiento')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Mantenimiento
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Gasto del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1">$<?= number_format($gastoTotal['total'], 2) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Servicios Realizados</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= count($mantList) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Costo Promedio</span>
<div class="font-headline-md text-headline-md text-primary mt-1">$<?= count($mantList) > 0 ? number_format(array_sum(array_column($mantList, 'costo')) / count($mantList), 2) : '0.00' ?></div>
</div>
</div>

<!-- Search -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex items-center gap-4 mb-8">
<div class="relative w-full">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input onkeyup="filterTable()" id="searchInput" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por patente, tipo o proveedor..." type="text"/>
</div>
</div>

<!-- Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CAMION</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">TIPO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">PROVEEDOR</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">COSTO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">FACTURA</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="tableBody">
<?php foreach ($mantList as $m):
$tipos = ['cambio_aceite' => 'Cambio Aceite', 'filtros' => 'Filtros', 'cubiertas' => 'Cubiertas', 'frenos' => 'Frenos', 'embrague' => 'Embrague', 'reparacion_general' => 'Reparacion General', 'otro' => 'Otro'];
?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-4 py-3 font-data-mono"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
<td class="px-4 py-3 font-bold"><?= htmlspecialchars($m['patente']) ?></td>
<td class="px-4 py-3"><span class="px-2 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold"><?= $tipos[$m['tipo']] ?? $m['tipo'] ?></span></td>
<td class="px-4 py-3"><?= htmlspecialchars($m['proveedor'] ?? '-') ?></td>
<td class="px-4 py-3 text-right font-data-mono font-bold">$<?= number_format($m['costo'], 2) ?></td>
<td class="px-4 py-3 text-right font-data-mono"><?= $m['kilometraje'] ? number_format($m['kilometraje'], 0) : '-' ?></td>
<td class="px-4 py-3 text-center">
<?php if ($m['foto_factura']): ?>
<a href="<?= BASE_URL ?>/assets/uploads/facturas/<?= $m['foto_factura'] ?>" target="_blank" class="text-primary underline text-xs">Ver</a>
<?php else: ?>
<span class="text-on-surface-variant text-xs">-</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main>

<!-- Modal -->
<div id="modalMantenimiento" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Nuevo Mantenimiento</h3>
<button onclick="closeModal('modalMantenimiento')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
<input type="hidden" name="action" value="create"/>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha</label><input name="fecha" type="date" value="<?= date('Y-m-d') ?>" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($camiones as $c): ?><option value="<?= $c['id_camion'] ?>"><?= htmlspecialchars($c['patente'] . ' - ' . $c['marca'] . ' ' . $c['modelo']) ?></option><?php endforeach; ?>
</select></div>
</div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tipo</label>
<select name="tipo" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="cambio_aceite">Cambio de Aceite</option>
<option value="filtros">Filtros</option>
<option value="cubiertas">Cubiertas</option>
<option value="frenos">Frenos</option>
<option value="embrague">Embrague</option>
<option value="reparacion_general">Reparacion General</option>
<option value="otro">Otro</option>
</select></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Proveedor</label><input name="proveedor" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
</div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label><textarea name="descripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea></div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Costo ($)</label><input name="costo" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Kilometraje</label><input name="kilometraje" type="number" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
</div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Prox. Mant. (KM)</label><input name="proximo_mantenimiento_km" type="number" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Prox. Mant. (Fecha)</label><input name="proximo_mantenimiento_fecha" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
</div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Foto Factura</label><input name="foto_factura" type="file" accept="image/*" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalMantenimiento')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function filterTable() {
const search = document.getElementById('searchInput').value.toLowerCase();
document.querySelectorAll('#tableBody tr').forEach(row => {
row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
});
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
