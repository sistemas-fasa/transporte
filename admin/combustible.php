<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('combustible_ver');
$pageTitle = 'Gestion de Combustible';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mes = date('m');
$anio = date('Y');

$buscar = $_GET['buscar'] ?? '';
$filtroChofer = (int)($_GET['id_chofer'] ?? 0);
$filtroCamion = (int)($_GET['id_camion'] ?? 0);
$fechaDesde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fechaHasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

// Si no es admin pleno, solo ve sus propias cargas
$idChoferRestriccion = 0;
$esRestringido = false;
if (!esAdminPleno()) {
    $esRestringido = true;
    $idChoferRestriccion = (int)($_SESSION['id_chofer'] ?? 0);
    if (!$idChoferRestriccion) {
        $tmp = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
        $tmp->execute([getCurrentUserId()]);
        $idChoferRestriccion = (int)$tmp->fetchColumn();
    }
    if (!$idChoferRestriccion) {
        $tmp = $db->prepare("SELECT a.id_chofer FROM vehiculos_usuarios vu JOIN asignaciones a ON vu.vehiculo_id = a.id_camion AND a.activa = 1 WHERE vu.usuario_id = ? LIMIT 1");
        $tmp->execute([getCurrentUserId()]);
        $idChoferRestriccion = (int)$tmp->fetchColumn();
    }
}

// Stats con filtros
$sqlStats = "SELECT COUNT(*) as cargas, COALESCE(SUM(litros),0) as litros, COALESCE(SUM(litros * precio_litro),0) as total, COALESCE(AVG(precio_litro),0) as precio_prom FROM combustible WHERE 1=1";
$paramsStats = [];
if ($esRestringido && $idChoferRestriccion) {
    $sqlStats .= " AND id_chofer = ?";
    $paramsStats[] = $idChoferRestriccion;
}
if ($buscar) {
    $sqlStats .= " AND (id_chofer IN (SELECT id_chofer FROM choferes WHERE nombre LIKE ? OR apellido LIKE ?) OR id_camion IN (SELECT id_camion FROM camiones WHERE patente LIKE ?) OR estacion_servicio LIKE ?)";
    $paramsStats[] = "%$buscar%"; $paramsStats[] = "%$buscar%"; $paramsStats[] = "%$buscar%"; $paramsStats[] = "%$buscar%";
}
if ($filtroChofer) { $sqlStats .= " AND id_chofer = ?"; $paramsStats[] = $filtroChofer; }
if ($filtroCamion) { $sqlStats .= " AND id_camion = ?"; $paramsStats[] = $filtroCamion; }
if ($fechaDesde) { $sqlStats .= " AND fecha >= ?"; $paramsStats[] = $fechaDesde . ' 00:00:00'; }
if ($fechaHasta) { $sqlStats .= " AND fecha <= ?"; $paramsStats[] = $fechaHasta . ' 23:59:59'; }
$statsMes = $db->prepare($sqlStats);
$statsMes->execute($paramsStats);
$stats = $statsMes->fetch();

$sql = "SELECT co.*, c.patente, ch.nombre, ch.apellido FROM combustible co JOIN camiones c ON co.id_camion = c.id_camion JOIN choferes ch ON co.id_chofer = ch.id_chofer WHERE 1=1";
$params = [];
if ($esRestringido && $idChoferRestriccion) {
    $sql .= " AND co.id_chofer = ?";
    $params[] = $idChoferRestriccion;
}
if ($buscar) {
    $sql .= " AND (c.patente LIKE ? OR ch.nombre LIKE ? OR ch.apellido LIKE ? OR co.estacion_servicio LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
if ($filtroChofer) {
    $sql .= " AND co.id_chofer = ?";
    $params[] = $filtroChofer;
}
if ($filtroCamion) {
    $sql .= " AND co.id_camion = ?";
    $params[] = $filtroCamion;
}
if ($fechaDesde) {
    $sql .= " AND co.fecha >= ?";
    $params[] = $fechaDesde . ' 00:00:00';
}
if ($fechaHasta) {
    $sql .= " AND co.fecha <= ?";
    $params[] = $fechaHasta . ' 23:59:59';
}
$sql .= " ORDER BY co.fecha DESC LIMIT 500";
$registros = $db->prepare($sql);
$registros->execute($params);
$registrosList = $registros->fetchAll();

// POST handlers
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = (int)($_POST['id_combustible'] ?? 0);
    $id_chofer = (int)($_POST['id_chofer'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d H:i:s');
    $estacion = trim($_POST['estacion_servicio'] ?? '');
    $litros = (float)($_POST['litros'] ?? 0);
    $precio_litro = (float)($_POST['precio_litro'] ?? 0);
    $km_carga = (float)($_POST['kilometraje'] ?? 0);

    try {
        $stmt = $db->prepare("UPDATE combustible SET fecha=?, id_chofer=?, id_camion=?, estacion_servicio=?, litros=?, precio_litro=?, kilometraje_al_cargar=? WHERE id_combustible=?");
        $stmt->execute([$fecha, $id_chofer, $id_camion, $estacion, $litros, $precio_litro, $km_carga, $id]);
        if ($km_carga > 0) {
            $db->prepare("UPDATE camiones SET kilometraje_actual = ? WHERE id_camion = ?")->execute([$km_carga, $id_camion]);
        }
        $mensaje = 'Carga actualizada exitosamente';
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id_combustible'] ?? 0);
    try {
        $stmt = $db->prepare("DELETE FROM combustible WHERE id_combustible = ?");
        $stmt->execute([$id]);
        $mensaje = 'Carga eliminada';
    } catch (Exception $e) {
        $error = 'Error al eliminar: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $id_chofer = (int)($_POST['id_chofer'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d H:i:s');
    $estacion = trim($_POST['estacion_servicio'] ?? '');
    $litros = (float)($_POST['litros'] ?? 0);
    $precio_litro = (float)($_POST['precio_litro'] ?? 0);
    $km_carga = (float)($_POST['kilometraje'] ?? 0);

    $foto_ticket = null;
    if (isset($_FILES['foto_ticket']) && $_FILES['foto_ticket']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['foto_ticket']['name'], PATHINFO_EXTENSION);
        $foto_ticket = 'ticket_' . uniqid() . '.' . $ext;
        @move_uploaded_file($_FILES['foto_ticket']['tmp_name'], UPLOAD_DIR . 'tickets/' . $foto_ticket);
    }

    try {
        $stmt = $db->prepare("INSERT INTO combustible (fecha, id_chofer, id_camion, estacion_servicio, litros, precio_litro, kilometraje_al_cargar, foto_ticket, id_usuario_registra) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fecha, $id_chofer, $id_camion, $estacion, $litros, $precio_litro, $km_carga, $foto_ticket, getCurrentUserId()]);
        if ($km_carga > 0) {
            $db->prepare("UPDATE camiones SET kilometraje_actual = ? WHERE id_camion = ?")->execute([$km_carga, $id_camion]);
        }
        $mensaje = 'Carga de combustible registrada exitosamente';
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

if ($esRestringido && $idChoferRestriccion) {
    $camionesList = $db->prepare("SELECT DISTINCT c.id_camion, c.patente, c.marca, c.modelo, c.por_hora FROM camiones c LEFT JOIN asignaciones a ON c.id_camion = a.id_camion AND a.activa = 1 LEFT JOIN vehiculos_usuarios vu ON c.id_camion = vu.vehiculo_id WHERE c.estado='activo' AND (a.id_chofer = ? OR vu.usuario_id = ?) ORDER BY c.patente");
    $camionesList->execute([$idChoferRestriccion, getCurrentUserId()]);
    $camionesList = $camionesList->fetchAll();
} else {
    $camionesList = $db->query("SELECT id_camion, patente, marca, modelo, por_hora FROM camiones WHERE estado='activo' ORDER BY patente")->fetchAll();
}
$choferesList = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado='activo' ORDER BY apellido, nombre")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Combustible</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Control de cargas y consumo.<?php if ($esRestringido): ?> <span class="bg-secondary-container text-on-secondary-container px-2 py-0.5 rounded text-[10px] font-bold uppercase ml-2">Solo mis cargas</span><?php endif; ?></p>
</div>
<button onclick="resetModal(); openModal('modalCombustible')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nueva Carga
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cargas del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= $stats['cargas'] ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Litros del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($stats['litros'], 2) ?> L</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Gasto del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1">$<?= number_format($stats['total'], 2) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Precio Promedio</span>
<div class="font-headline-md text-headline-md text-primary mt-1">$<?= number_format($stats['precio_prom'], 3) ?>/L</div>
</div>
</div>

<!-- Filtros -->
<form method="GET" class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl mb-8">
<div class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Buscar</label>
<input name="buscar" type="text" value="<?= htmlspecialchars($buscar) ?>" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm" placeholder="Patente, chofer, estacion..."/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Chofer</label>
<?php if ($esRestringido): ?>
<input type="text" disabled value="Solo mis cargas" class="w-full border border-outline-variant rounded p-2 bg-surface-container-high text-sm text-on-surface-variant"/>
<input name="id_chofer" type="hidden" value="<?= $idChoferRestriccion ?>"/>
<?php else: ?>
<select name="id_chofer" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="">Todos</option>
<?php foreach ($choferesList as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>" <?= $filtroChofer == $ch['id_chofer'] ? 'selected' : '' ?>><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre']) ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Camion</label>
<select name="id_camion" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="">Todos</option>
<?php foreach ($camionesList as $ca): ?>
<option value="<?= $ca['id_camion'] ?>" <?= $filtroCamion == $ca['id_camion'] ? 'selected' : '' ?>><?= htmlspecialchars($ca['patente'] . ' - ' . $ca['marca'] . ' ' . $ca['modelo']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Fecha Desde</label>
<input name="fecha_desde" type="date" value="<?= htmlspecialchars($fechaDesde) ?>" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Fecha Hasta</label>
<input name="fecha_hasta" type="date" value="<?= htmlspecialchars($fechaHasta) ?>" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm"/>
</div>
<div class="flex gap-2">
<button type="submit" class="w-full bg-primary text-on-primary px-4 py-2 rounded-lg font-bold text-sm hover:opacity-90">Filtrar</button>
<a href="<?= BASE_URL ?>/admin/combustible.php" class="w-full px-4 py-2 border border-outline text-primary rounded-lg text-sm font-bold hover:bg-surface-container-low text-center">Limpiar</a>
</div>
</div>
</form>

<!-- Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CHOFER</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CAMION</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">ESTACION</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">LITROS</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">P. LITRO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">TOTAL</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">TICKET</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="tableBody">
<?php foreach ($registrosList as $r): ?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-4 py-3 font-data-mono"><?= date('d/m/Y H:i', strtotime($r['fecha'])) ?></td>
<td class="px-4 py-3"><?= htmlspecialchars($r['apellido'] . ', ' . $r['nombre']) ?></td>
<td class="px-4 py-3 font-bold"><?= htmlspecialchars($r['patente']) ?></td>
<td class="px-4 py-3"><?= htmlspecialchars($r['estacion_servicio'] ?? '-') ?></td>
<td class="px-4 py-3 text-right font-data-mono"><?= number_format($r['litros'], 2) ?></td>
<td class="px-4 py-3 text-right font-data-mono">$<?= number_format($r['precio_litro'], 3) ?></td>
<td class="px-4 py-3 text-right font-data-mono font-bold">$<?= number_format($r['litros'] * $r['precio_litro'], 2) ?></td>
<td class="px-4 py-3 text-center">
<?php if ($r['foto_ticket']): ?>
<a href="<?= BASE_URL ?>/assets/uploads/tickets/<?= $r['foto_ticket'] ?>" target="_blank" class="text-primary underline text-xs">Ver</a>
<?php else: ?>
<span class="text-on-surface-variant text-xs">-</span>
<?php endif; ?>
</td>
<td class="px-4 py-3 text-center">
<div class="flex gap-1 justify-center">
<button onclick="editCombustible(<?= $r['id_combustible'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Editar</button>
<button onclick="deleteCombustible(<?= $r['id_combustible'] ?>)" class="px-3 py-1 bg-red-50 text-red-600 rounded text-xs font-bold hover:bg-red-100">Borrar</button>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main>

<!-- Modal Nueva/Editar Carga -->
<div id="modalCombustible" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalCombustibleTitle" class="font-headline-sm text-headline-sm text-primary">Nueva Carga de Combustible</h3>
<button onclick="closeModal('modalCombustible')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
<input type="hidden" name="action" id="combAction" value="create"/>
<input type="hidden" name="id_combustible" id="combId" value=""/>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Chofer</label>
<?php if ($esRestringido && $idChoferRestriccion): ?>
<input type="text" disabled value="<?= htmlspecialchars($choferesList[array_search($idChoferRestriccion, array_column($choferesList, 'id_chofer'))]['apellido'] ?? '') ?>, <?= htmlspecialchars($choferesList[array_search($idChoferRestriccion, array_column($choferesList, 'id_chofer'))]['nombre'] ?? 'Carga personal') ?>" class="w-full border border-outline-variant rounded p-3 bg-surface-container-high text-on-surface-variant"/>
<input name="id_chofer" type="hidden" value="<?= $idChoferRestriccion ?>"/>
<?php else: ?>
<select name="id_chofer" id="combChofer" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($choferesList as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>"><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre'] . ' - ' . $ch['dni']) ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="combCamion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($camionesList as $ca): ?>
<option value="<?= $ca['id_camion'] ?>" data-por-hora="<?= $ca['por_hora'] ?>"><?= htmlspecialchars($ca['patente'] . ' - ' . $ca['marca'] . ' ' . $ca['modelo']) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha y Hora</label>
<input name="fecha" id="combFecha" type="datetime-local" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Estacion de Servicio</label>
<select name="estacion_servicio" id="combEstacion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<option value="YPF">YPF</option>
<option value="SHELL">SHELL</option>
<option value="AXION">AXION</option>
<option value="CISTERNA">CISTERNA</option>
</select>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Litros</label>
<input name="litros" id="modLitros" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Precio x Litro ($)</label>
<input name="precio_litro" id="modPrecio" type="number" step="0.001" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
</div>
<div class="flex flex-col gap-1">
<label id="kmLabel" class="font-label-caps text-label-caps text-on-surface-variant uppercase">Kilometraje</label>
<input name="kilometraje" id="combKm" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej. 124500"/>
</div>
<div class="bg-surface-container-high p-3 rounded-lg flex justify-between items-center">
<span class="font-label-caps text-label-caps uppercase font-bold">Total Calculado</span>
<span class="font-headline-md text-headline-md text-primary font-bold font-data-mono" id="modTotal">$ 0.00</span>
</div>
    <div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Foto Ticket (opcional)</label>
<input name="foto_ticket" id="combFotoInput" type="file" accept="image/*" capture="environment" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
<div id="combFotoPreview" class="hidden mt-2 relative">
<img id="combFotoImg" class="w-full max-h-48 object-contain rounded border border-outline-variant"/>
</div>
<button type="button" id="combScanBtn" onclick="escanearTicketAdmin()" disabled class="mt-2 w-full px-3 py-2 bg-secondary-container text-on-secondary-container font-bold rounded-lg text-sm hover:opacity-90 transition-opacity flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
<span class="material-symbols-outlined text-[18px]">document_scanner</span> Escanear Ticket
</button>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalCombustible')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar Carga</button>
</div>
</form>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function resetModal() {
document.getElementById('combAction').value = 'create';
document.getElementById('combId').value = '';
var elChofer = document.getElementById('combChofer'); if (elChofer) elChofer.value = '';
document.getElementById('combCamion').value = '';
document.getElementById('modLitros').value = '';
document.getElementById('modPrecio').value = '';
document.getElementById('combKm').value = '';
document.getElementById('modTotal').innerText = '$ 0.00';
document.getElementById('modalCombustibleTitle').textContent = 'Nueva Carga de Combustible';
document.getElementById('combFotoPreview').classList.add('hidden');
document.getElementById('combScanBtn').disabled = true;
updateCargaFields();
}

const combCamion = document.getElementById('combCamion');
const kmLabel = document.getElementById('kmLabel');
const combKm = document.getElementById('combKm');

function updateCargaFields() {
    if (!combCamion) return;
    const opt = combCamion.options[combCamion.selectedIndex];
    if (opt && opt.value) {
        const porHora = opt.getAttribute('data-por-hora') == '1';
        if (porHora) {
            if (kmLabel) kmLabel.textContent = 'Horas Actuales (Horómetro)';
            if (combKm) combKm.placeholder = 'Ej. 3450';
        } else {
            if (kmLabel) kmLabel.textContent = 'Kilometraje';
            if (combKm) combKm.placeholder = 'Ej. 124500';
        }
    } else {
        if (kmLabel) kmLabel.textContent = 'Kilometraje';
        if (combKm) combKm.placeholder = 'Ej. 124500';
    }
}

if (combCamion) {
    combCamion.addEventListener('change', updateCargaFields);
}

// Admin OCR
const combFotoInput = document.getElementById('combFotoInput');
const combFotoPreview = document.getElementById('combFotoPreview');
const combFotoImg = document.getElementById('combFotoImg');
const combScanBtn = document.getElementById('combScanBtn');
combFotoInput.addEventListener('change', function(e) {
const file = e.target.files[0];
if (file) {
const reader = new FileReader();
reader.onload = function(ev) {
combFotoImg.src = ev.target.result;
combFotoPreview.classList.remove('hidden');
combScanBtn.disabled = false;
};
reader.readAsDataURL(file);
}
});

async function escanearTicketAdmin() {
const img = combFotoImg;
if (!img.src || img.src === '') { alert('Primero seleccione una foto del ticket.'); return; }
combScanBtn.disabled = true;
combScanBtn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">sync</span> Escaneando...';
try {
const result = await Tesseract.recognize(img.src, 'spa', { logger: function(){} });
const text = result.data.text;
    let litrosMatch = text.match(/(\d+[.,]\d+)\s*L(?:itros?|T|\.)?\b/i);
    if (!litrosMatch) litrosMatch = text.match(/(?:Litros?|Cantidad|Neto)\s*:?\s*(\d+[.,]\d+)/i);
    if (!litrosMatch) litrosMatch = text.match(/(?:Total\s*L[íi]quidos?|Volumen)\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
    if (!litrosMatch) litrosMatch = text.match(/(\d+[.,']\d+)\s*(?:[a-zA-Z]\s*)?x\s*(?:\$)?\s*\d+(?:[.,']\d+)?/i);
    if (!litrosMatch) litrosMatch = text.match(/(\d+[.,']\d{2,4})\s*(?:u\s*)?x/i);
    if (litrosMatch) { document.getElementById('modLitros').value = litrosMatch[1].replace(/[,']/g, '.'); }
let precMatch = text.match(/(?:Precio\s*(?:por\s*)?[Ll]itro|P\.?\s*[Uu]nitario|\$\/[Ll]|\$\s*\/?\s*[Ll]itro)\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
if (!precMatch) precMatch = text.match(/\$\s*(\d+[.,]\d{3,4})(?:\s*\/\s*L)?/i);
if (!precMatch) precMatch = text.match(/[Pp]recio\s*:?\s*\$?\s*(\d+[.,]\d+)/i);
if (precMatch) { document.getElementById('modPrecio').value = precMatch[1].replace(',', '.'); }
let fechaMatch = text.match(/(\d{2})[\/\-](\d{2})[\/\-](\d{4})/);
if (!fechaMatch) fechaMatch = text.match(/(\d{2})\s*[\/\-]\s*(\d{2})\s*[\/\-]\s*(\d{4})/);
if (fechaMatch) {
document.getElementById('combFecha').value = fechaMatch[3] + '-' + fechaMatch[2] + '-' + fechaMatch[1] + 'T00:00';
}
const estacionSelect = document.getElementById('combEstacion');
['YPF','SHELL','AXION','CISTERNA'].forEach(v => {
if (text.toUpperCase().includes(v)) { estacionSelect.value = v; }
});
calcTotal();
alert('Datos extraidos del ticket. Revise y corrija si es necesario.');
} catch (err) {
alert('Error al escanear: ' + err.message);
console.error(err);
} finally {
combScanBtn.disabled = false;
combScanBtn.innerHTML = '<span class="material-symbols-outlined text-[18px]">document_scanner</span> Escanear Ticket';
}
}

function editCombustible(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=combustible&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_combustible) {
alert('Error: No se pudieron obtener los datos');
return;
}
document.getElementById('combAction').value = 'update';
document.getElementById('combId').value = data.id_combustible;
var elChofer = document.getElementById('combChofer'); if (elChofer) elChofer.value = data.id_chofer;
document.getElementById('combCamion').value = data.id_camion;
document.getElementById('combFecha').value = data.fecha.replace(' ', 'T');
document.getElementById('combEstacion').value = data.estacion_servicio;
document.getElementById('modLitros').value = data.litros;
document.getElementById('modPrecio').value = data.precio_litro;
document.getElementById('combKm').value = data.kilometraje_al_cargar || '';
document.getElementById('modalCombustibleTitle').textContent = 'Editar Carga de Combustible';
updateCargaFields();
calcTotal();
openModal('modalCombustible');
}).catch(err => {
alert('Error al cargar datos: ' + err.message);
});
}

function deleteCombustible(id) {
showConfirm('¿Eliminar esta carga de combustible?', function() {
const form = document.createElement('form');
form.method = 'POST';
form.innerHTML = '<input name="action" value="delete"><input name="id_combustible" value="' + id + '">';
document.body.appendChild(form);
form.submit();
});
}



const litrosInput = document.getElementById('modLitros');
const precioInput = document.getElementById('modPrecio');
const totalDisplay = document.getElementById('modTotal');
function calcTotal() {
const l = parseFloat(litrosInput.value) || 0;
const p = parseFloat(precioInput.value) || 0;
totalDisplay.innerText = '$ ' + (l * p).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
litrosInput.addEventListener('input', calcTotal);
precioInput.addEventListener('input', calcTotal);


</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
