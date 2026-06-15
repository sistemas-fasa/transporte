<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Gestion de Camiones';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

// Asegurar que las columnas existan por si no corrió la sincronización
foreach (['vtv DATE NULL', 'tara DECIMAL(10,2) NULL', 'proximo_mantenimiento_km DECIMAL(12,2) NULL', 'proximo_mantenimiento_hs DECIMAL(10,2) NULL'] as $col) {
    try { $db->exec("ALTER TABLE camiones ADD COLUMN $col"); } catch (Exception $e) {}
}

// CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $patente = strtoupper(trim($_POST['patente'] ?? ''));
        $marca = trim($_POST['marca'] ?? '');
        $modelo = trim($_POST['modelo'] ?? '');
        $anio = (int)($_POST['anio'] ?? 0);
        $kilometraje = (float)($_POST['kilometraje_actual'] ?? 0);
        $capacidad = (float)($_POST['capacidad_tanque'] ?? 0);
        $vtv = !empty($_POST['vtv']) ? $_POST['vtv'] : null;
        $tara = !empty($_POST['tara']) ? (float)$_POST['tara'] : null;
        $proxKm = !empty($_POST['proximo_mantenimiento_km']) ? (float)$_POST['proximo_mantenimiento_km'] : null;
        $proxHs = !empty($_POST['proximo_mantenimiento_hs']) ? (float)$_POST['proximo_mantenimiento_hs'] : null;
        $estado = $_POST['estado'] ?? 'activo';

        if ($action === 'create') {
            try {
                $stmt = $db->prepare("INSERT INTO camiones (patente, marca, modelo, anio, kilometraje_actual, capacidad_tanque, vtv, tara, proximo_mantenimiento_km, proximo_mantenimiento_hs, estado) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$patente, $marca, $modelo, $anio, $kilometraje, $capacidad, $vtv, $tara, $proxKm, $proxHs, $estado]);
                $idCamion = $db->lastInsertId();
                registrarAuditoria(getCurrentUserId(), 'create', 'camiones', $idCamion, "Creo camion $patente");
                $mensaje = 'Camion creado exitosamente';
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        } else {
            $id = (int)($_POST['id_camion'] ?? 0);
            try {
                $stmt = $db->prepare("UPDATE camiones SET patente=?, marca=?, modelo=?, anio=?, kilometraje_actual=?, capacidad_tanque=?, vtv=?, tara=?, proximo_mantenimiento_km=?, proximo_mantenimiento_hs=?, estado=? WHERE id_camion=?");
                $stmt->execute([$patente, $marca, $modelo, $anio, $kilometraje, $capacidad, $vtv, $tara, $proxKm, $proxHs, $estado, $id]);
                registrarAuditoria(getCurrentUserId(), 'update', 'camiones', $id, "Actualizo camion $patente");
                $mensaje = 'Camion actualizado exitosamente';
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_camion'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM camiones WHERE id_camion = ?");
            $stmt->execute([$id]);
            registrarAuditoria(getCurrentUserId(), 'delete', 'camiones', $id, "Elimino camion ID $id");
            $mensaje = 'Camion eliminado';
        } catch (Exception $e) {
            $error = 'No se puede eliminar el camion, tiene registros asociados';
        }
    }
}

$buscar = $_GET['buscar'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';

$sql = "SELECT c.*, (SELECT COUNT(*) FROM asignaciones WHERE id_camion = c.id_camion AND activa = 1) as asignado FROM camiones c WHERE 1=1";
$params = [];
if ($buscar) {
    $sql .= " AND (c.patente LIKE ? OR c.marca LIKE ? OR c.modelo LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
if ($filtro_estado) {
    $sql .= " AND c.estado = ?";
    $params[] = $filtro_estado;
}
$sql .= " ORDER BY c.created_at DESC";
$camiones = $db->prepare($sql);
$camiones->execute($params);
$camionesList = $camiones->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Camiones</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Control centralizado de activos y disponibilidad de flota.</p>
</div>
<button onclick="resetModalCamion(); openModal('modalCamion')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Camion
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filters -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex flex-col md:flex-row items-center gap-4 mb-8">
<div class="relative w-full md:flex-1">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input id="searchInput" onkeyup="filterTable()" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por patente, marca o modelo..." type="text"/>
</div>
<div class="flex items-center gap-2 w-full md:w-auto">
<select id="estadoFilter" onchange="filterTable()" class="flex-1 md:w-48 px-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary">
<option value="">Todos los Estados</option>
<option value="activo">Activo</option>
<option value="mantenimiento">En Mantenimiento</option>
<option value="fuera_de_servicio">Fuera de Servicio</option>
</select>
</div>
</div>

<!-- Camiones Grid -->
<div id="camionesGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
<?php foreach ($camionesList as $camion):
$estados = [
'activo' => ['color' => 'green', 'text' => 'ACTIVO'],
'mantenimiento' => ['color' => 'yellow', 'text' => 'MANTENIMIENTO'],
'fuera_de_servicio' => ['color' => 'red', 'text' => 'FUERA DE SERVICIO'],
];
$est = $estados[$camion['estado']] ?? $estados['activo'];
$vtvDate = $camion['vtv'] ?? null;
if ($vtvDate) {
    $vtvDiff = floor((strtotime($vtvDate) - time()) / 86400);
    if ($vtvDiff <= 0) { $vtvColor = 'red'; $vtvLabel = 'VENCIDA'; }
    elseif ($vtvDiff <= 30) { $vtvColor = 'red'; $vtvLabel = 'VENCE EN ' . $vtvDiff . ' DIAS'; }
    elseif ($vtvDiff <= 60) { $vtvColor = 'yellow'; $vtvLabel = 'VENCE EN ' . $vtvDiff . ' DIAS'; }
    elseif ($vtvDiff <= 90) { $vtvColor = 'yellow'; $vtvLabel = 'VENCE EN ' . $vtvDiff . ' DIAS'; }
    else { $vtvColor = 'green'; $vtvLabel = date('d/m/Y', strtotime($vtvDate)); }
} else {
    $vtvColor = 'gray'; $vtvLabel = 'SIN VTV';
}
?>
<div class="camion-card bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden hover:border-primary transition-all" data-search="<?= strtolower(htmlspecialchars($camion['patente'] . ' ' . $camion['marca'] . ' ' . $camion['modelo'])) ?>" data-estado="<?= $camion['estado'] ?>">
<div class="h-48 bg-surface-container-high flex items-center justify-center">
<span class="material-symbols-outlined text-6xl text-on-surface-variant">local_shipping</span>
</div>
<div class="p-6">
<div class="flex justify-between items-start mb-4">
<div>
<h3 class="font-headline-sm text-headline-sm text-primary"><?= htmlspecialchars($camion['marca']) ?> <?= htmlspecialchars($camion['modelo']) ?></h3>
<p class="font-body-md text-on-surface-variant">Patente: <span class="font-bold text-primary"><?= htmlspecialchars($camion['patente']) ?></span></p>
</div>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase border border-<?= $est['color'] ?>-200 bg-<?= $est['color'] ?>-100 text-<?= $est['color'] ?>-800"><?= $est['text'] ?></span>
</div>
<div class="grid grid-cols-3 gap-2 mb-6">
<div class="bg-surface-container-low p-3 rounded-lg">
<p class="text-[10px] font-label-caps text-on-surface-variant uppercase">Kilometraje</p>
<p class="font-data-mono text-primary text-sm"><?= number_format($camion['kilometraje_actual'], 0) ?> KM</p>
</div>
<div class="bg-surface-container-low p-3 rounded-lg">
<p class="text-[10px] font-label-caps text-on-surface-variant uppercase">Tanque</p>
<p class="font-data-mono text-primary text-sm"><?= number_format($camion['capacidad_tanque'], 0) ?> L</p>
</div>
<div class="bg-surface-container-low p-3 rounded-lg">
<p class="text-[10px] font-label-caps text-on-surface-variant uppercase">VTV</p>
<p class="font-data-mono text-<?= $vtvColor ?>-600 text-sm font-bold"><?= $vtvLabel ?></p>
</div>
</div>
<?php
$proxKm = $camion['proximo_mantenimiento_km'] ?? null;
$proxHs = $camion['proximo_mantenimiento_hs'] ?? null;
$kmActual = $camion['kilometraje_actual'] ?? 0;
$mantenimientoDue = false;
$mantenimientoLabel = '';
if ($proxKm && $kmActual >= $proxKm) { $mantenimientoDue = true; $mantenimientoLabel = 'VENCE KM'; }
elseif ($proxKm) { $mantenimientoLabel = 'FALTAN ' . number_format($proxKm - $kmActual, 0) . ' KM'; }
if ($proxHs) {
$mantenimientoLabel .= $mantenimientoLabel ? ' / ' : '';
$mantenimientoLabel .= number_format($proxHs, 0) . ' HS';
}
if ($proxKm || $proxHs):
?>
<div class="bg-<?= $mantenimientoDue ? 'red' : 'surface-container-low' ?> p-3 rounded-lg mb-4">
<p class="text-[10px] font-label-caps text-on-surface-variant uppercase">Prox. Mantenimiento</p>
<p class="font-data-mono text-<?= $mantenimientoDue ? 'red' : 'primary' ?>-600 text-sm font-bold"><?= $mantenimientoLabel ?></p>
</div>
<?php endif; ?>
<div class="flex flex-col gap-2">
<div class="flex gap-2">
<button onclick="openAsignar(<?= $camion['id_camion'] ?>)" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold text-sm hover:opacity-90 transition-all flex items-center justify-center gap-1">
<span class="material-symbols-outlined text-sm">person_add</span> Asignar
</button>
<button onclick="openHistorial(<?= $camion['id_camion'] ?>)" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold text-sm hover:bg-surface-container-low transition-all">
Historial
</button>
</div>
<div class="flex gap-2">
<button onclick="editCamion(<?= $camion['id_camion'] ?>)" class="flex-1 bg-secondary-container text-on-secondary-container py-2 rounded-lg font-bold text-sm hover:opacity-80 transition-all flex items-center justify-center gap-1">
<span class="material-symbols-outlined text-sm">edit</span> Editar
</button>
<button onclick="deleteCamion(<?= $camion['id_camion'] ?>)" class="flex-1 bg-red-50 text-red-600 py-2 rounded-lg font-bold text-sm hover:bg-red-100 transition-all flex items-center justify-center gap-1">
<span class="material-symbols-outlined text-sm">delete</span> Eliminar
</button>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
</main>

<!-- Modal Nuevo/Editar Camion -->
<div id="modalCamion" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalCamionTitle" class="font-headline-sm text-headline-sm text-primary">Nuevo Camion</h3>
<button onclick="closeModal('modalCamion')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="camionAction" value="create"/>
<input type="hidden" name="id_camion" id="camionId" value=""/>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Patente</label>
<input name="patente" id="campatente" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Marca</label>
<input name="marca" id="cammarca" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Modelo</label>
<input name="modelo" id="cammodelo" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Anio</label>
<input name="anio" type="number" id="camanio" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Kilometraje</label>
<input name="kilometraje_actual" type="number" step="0.01" id="camkm" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cap. Tanque (L)</label>
<input name="capacidad_tanque" type="number" step="0.01" id="camtanque" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">VTV (Vencimiento)</label>
<input name="vtv" type="date" id="camvtv" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
    <div class="flex flex-col gap-1">
    <label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tara (KG)</label>
    <input name="tara" type="number" step="0.01" id="camtara" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Prox. Mant. (KM)</label>
<input name="proximo_mantenimiento_km" type="number" step="0.01" id="camproxkm" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="KM para el proximo servicio"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Prox. Mant. (HS)</label>
<input name="proximo_mantenimiento_hs" type="number" step="0.01" id="camproxhs" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Horas para el proximo servicio"/>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Estado</label>
<select name="estado" id="camestado" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="activo">Activo</option>
<option value="mantenimiento">En Mantenimiento</option>
<option value="fuera_de_servicio">Fuera de Servicio</option>
</select>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalCamion')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Asignar -->
<div id="modalAsignar" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Asignar Chofer</h3>
<button onclick="closeModal('modalAsignar')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" action="<?= BASE_URL ?>/admin/asignar_chofer.php" class="p-6 space-y-4">
<input type="hidden" name="id_camion" id="asignarCamionId" value=""/>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Chofer</label>
<select name="id_chofer" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccione un chofer...</option>
<?php $choferesActivos = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado='activo' ORDER BY apellido")->fetchAll(); ?>
<?php foreach ($choferesActivos as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>"><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre'] . ' - ' . $ch['dni']) ?></option>
<?php endforeach; ?>
</select>
</div>
<button type="submit" class="w-full bg-primary text-on-primary py-2 rounded-lg font-bold">Asignar Chofer</button>
</form>
</div>
</div>

<!-- Modal Historial -->
<div id="modalHistorial" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Historial del Camion</h3>
<button onclick="closeModal('modalHistorial')"><span class="material-symbols-outlined">close</span></button>
</div>
<div class="p-6" id="historialContent">
<p class="text-on-surface-variant">Cargando...</p>
</div>
</div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function editCamion(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=camion&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_camion) {
alert('Error: No se pudieron obtener los datos del camion');
return;
}
document.getElementById('camionAction').value = 'update';
document.getElementById('camionId').value = data.id_camion;
document.getElementById('campatente').value = data.patente;
document.getElementById('cammarca').value = data.marca;
document.getElementById('cammodelo').value = data.modelo;
document.getElementById('camanio').value = data.anio;
document.getElementById('camkm').value = data.kilometraje_actual;
document.getElementById('camtanque').value = data.capacidad_tanque;
document.getElementById('camvtv').value = data.vtv || '';
document.getElementById('camtara').value = data.tara || '';
document.getElementById('camproxkm').value = data.proximo_mantenimiento_km || '';
document.getElementById('camproxhs').value = data.proximo_mantenimiento_hs || '';
document.getElementById('camestado').value = data.estado;
document.getElementById('modalCamionTitle').textContent = 'Editar Camion';
openModal('modalCamion');
}).catch(err => {
alert('Error al cargar datos del camion: ' + err.message);
});
}

function resetModalCamion() {
document.getElementById('camionAction').value = 'create';
document.getElementById('camionId').value = '';
document.getElementById('campatente').value = '';
document.getElementById('cammarca').value = '';
document.getElementById('cammodelo').value = '';
document.getElementById('camanio').value = '';
document.getElementById('camkm').value = '';
document.getElementById('camtanque').value = '';
document.getElementById('camvtv').value = '';
document.getElementById('camtara').value = '';
document.getElementById('camproxkm').value = '';
document.getElementById('camproxhs').value = '';
document.getElementById('camestado').value = 'activo';
document.getElementById('modalCamionTitle').textContent = 'Nuevo Camion';
}

function openAsignar(id) {
document.getElementById('asignarCamionId').value = id;
openModal('modalAsignar');
}

function openHistorial(id) {
openModal('modalHistorial');
document.getElementById('historialContent').innerHTML = '<p class="text-on-surface-variant">Cargando...</p>';
fetch('<?= BASE_URL ?>/api/get_data.php?action=historial_camion&id=' + id)
.then(r => r.json()).then(data => {
let html = '';
if (data.asignaciones && data.asignaciones.length) {
html += '<h4 class="font-bold mb-2">Asignaciones</h4><div class="space-y-2 mb-6">';
data.asignaciones.forEach(a => {
html += '<div class="bg-surface-container-low p-3 rounded-lg flex justify-between"><span>' + a.chofer + '</span><span class="text-sm text-on-surface-variant">' + a.fecha_desde + (a.fecha_hasta ? ' - ' + a.fecha_hasta : ' - Actual') + '</span></div>';
});
html += '</div>';
}
if (data.mantenimientos && data.mantenimientos.length) {
html += '<h4 class="font-bold mb-2">Mantenimientos</h4><div class="space-y-2">';
data.mantenimientos.forEach(m => {
html += '<div class="bg-surface-container-low p-3 rounded-lg"><div class="flex justify-between"><span class="font-bold">' + m.tipo + '</span><span>$' + parseFloat(m.costo).toFixed(2) + '</span></div><span class="text-sm text-on-surface-variant">' + m.fecha + ' - ' + (m.descripcion || '') + '</span></div>';
});
html += '</div>';
}
if (!html) html = '<p class="text-on-surface-variant">Sin registros</p>';
document.getElementById('historialContent').innerHTML = html;
});
}

function deleteCamion(id) {
showConfirm('¿Eliminar este camion?', function() {
const form = document.createElement('form');
form.method = 'POST';
form.innerHTML = '<input name="action" value="delete"><input name="id_camion" value="' + id + '">';
document.body.appendChild(form);
form.submit();
});
}

function filterTable() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const estado = document.getElementById('estadoFilter').value;
    document.querySelectorAll('.camion-card').forEach(card => {
        const searchData = (card.dataset.search || '').toLowerCase();
        const matchSearch = searchData.includes(search);
        const matchEstado = !estado || card.dataset.estado === estado;
        card.style.display = (matchSearch && matchEstado) ? '' : 'none';
    });
}

document.getElementById('modalCamion').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalCamion');
});
document.getElementById('modalAsignar').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalAsignar');
});
document.getElementById('modalHistorial').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalHistorial');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
