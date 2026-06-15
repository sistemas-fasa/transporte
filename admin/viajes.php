<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Gestion de Viajes';

$db = getDB();
$mes = date('m');
$anio = date('Y');

// Migrar columnas de estado si no existen
try { $db->exec("ALTER TABLE km_recorrido MODIFY km_llegada DECIMAL(12,2) DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN estado ENUM('abierto','cerrado','aprobado') DEFAULT 'abierto' AFTER observaciones"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN aprobado_por INT DEFAULT NULL AFTER estado"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN fecha_cierre DATETIME DEFAULT NULL AFTER aprobado_por"); } catch (Exception $e) {}

// POST handlers (antes de header.php para permitir redirects)
$mensaje = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    $id_chofer = (int)($_POST['id_chofer'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $km_salida = (float)($_POST['km_salida'] ?? 0);
    $km_llegada = $_POST['km_llegada'] !== '' ? (float)$_POST['km_llegada'] : null;
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');
    $estado = ($km_salida > 0 && $km_llegada !== null) ? 'cerrado' : 'abierto';

    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET fecha=?, id_chofer=?, id_camion=?, km_salida=?, km_llegada=?, origen=?, destino=?, observaciones=?, estado=?, fecha_cierre=" . ($km_llegada !== null ? 'NOW()' : 'NULL') . " WHERE id_hoja=?");
        $stmt->execute([$fecha, $id_chofer, $id_camion, $km_salida, $km_llegada, $origen, $destino, $observaciones, $estado, $id]);
        $_SESSION['flash'] = 'Viaje actualizado exitosamente';
        header('Location: viajes.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    try {
        $stmt = $db->prepare("DELETE FROM km_recorrido WHERE id_hoja = ?");
        $stmt->execute([$id]);
        $_SESSION['flash'] = 'Viaje eliminado';
        header('Location: viajes.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error al eliminar: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autorizar') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET estado='aprobado', aprobado_por=? WHERE id_hoja=? AND estado='cerrado'");
        $stmt->execute([getCurrentUserId(), $id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['flash'] = 'Viaje autorizado exitosamente';
            header('Location: viajes.php');
            exit;
        }
        else { $error = 'El viaje debe estar en estado "cerrado" para autorizarlo'; }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $id_chofer = (int)($_POST['id_chofer'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $km_salida = (float)($_POST['km_salida'] ?? 0);
    $km_llegada = $_POST['km_llegada'] !== '' ? (float)$_POST['km_llegada'] : null;
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');
    $estado = ($km_salida > 0 && $km_llegada !== null) ? 'cerrado' : 'abierto';

    try {
        $stmt = $db->prepare("INSERT INTO km_recorrido (fecha, id_chofer, id_camion, km_salida, km_llegada, origen, destino, observaciones, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fecha, $id_chofer, $id_camion, $km_salida, $km_llegada, $origen, $destino, $observaciones, $estado]);
        $_SESSION['flash'] = 'Viaje registrado exitosamente';
        header('Location: viajes.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Stats
$statsMes = $db->prepare("SELECT COUNT(*) as viajes, COALESCE(SUM(km_recorridos),0) as km_total, COALESCE(AVG(km_recorridos),0) as km_prom FROM km_recorrido WHERE MONTH(fecha)=? AND YEAR(fecha)=?");
$statsMes->execute([$mes, $anio]);
$stats = $statsMes->fetch();

$buscar = $_GET['buscar'] ?? '';
$sql = "SELECT h.*, c.patente, ch.nombre, ch.apellido FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion JOIN choferes ch ON h.id_chofer = ch.id_chofer WHERE 1=1";
$params = [];
if ($buscar) {
    $sql .= " AND (c.patente LIKE ? OR ch.nombre LIKE ? OR ch.apellido LIKE ? OR h.origen LIKE ? OR h.destino LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
$sql .= " ORDER BY h.fecha DESC, h.id_hoja DESC LIMIT 100";
$registros = $db->prepare($sql);
$registros->execute($params);
$registrosList = $registros->fetchAll();

// Tabla localidad (origen/destino)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS localidad (id_localidad INT AUTO_INCREMENT PRIMARY KEY, localidad VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB");
} catch (Exception $e) {}
$countLoc = $db->query("SELECT COUNT(*) as c FROM localidad")->fetch();
if ($countLoc['c'] == 0) {
    $localidades = ['BUENOS AIRES', 'CORDOBA', 'ROSARIO', 'MENDOZA', 'LA PLATA', 'SAN MIGUEL DE TUCUMAN', 'MAR DEL PLATA', 'SALTA', 'SANTA FE', 'CORRIENTES', 'POSADAS', 'SAN SALVADOR DE JUJUY', 'NEUQUEN', 'RESISTENCIA', 'SANTIAGO DEL ESTERO', 'PARANA', 'FORMOSA', 'LA RIOJA', 'SAN JUAN', 'CATAMARCA', 'COMODORO RIVADAVIA', 'USHUAIA', 'RIO GALLEGOS', 'SANTA ROSA', 'VIEDMA', 'RAWSON', 'QUILMES', 'AVELLANEDA', 'LANUS', 'MORON', 'LOMAS DE ZAMORA', 'SAN ISIDRO', 'VICENTE LOPEZ', 'MERLO', 'MORENO', 'FLORENCIO VARELA', 'BERAZATEGUI', 'TIGRE', 'MALVINAS ARGENTINAS', 'TRES DE FEBRERO', 'ALMIRANTE BROWN', 'ESTEBAN ECHEVERRIA', 'PILAR', 'ESCALADA', 'ZARATE', 'CAMPANA', 'SAN NICOLAS', 'PERGAMINO', 'TANDIL', 'OLAVARRIA', 'AZUL', 'CHIVILCOY', 'JUNIN'];
    $ins = $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)");
    foreach ($localidades as $l) { $ins->execute([$l]); }
}
$localidadesList = $db->query("SELECT id_localidad, localidad FROM localidad ORDER BY localidad ASC")->fetchAll();

$camionesList = $db->query("SELECT id_camion, patente, marca, modelo FROM camiones WHERE estado='activo' ORDER BY patente")->fetchAll();
$choferesList = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado='activo' ORDER BY apellido, nombre")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Viajes</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Registro de kilometraje y recorridos.</p>
</div>
<button onclick="resetModal(); openModal('modalViaje')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Viaje
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Viajes del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= $stats['viajes'] ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($stats['km_total'], 0) ?> km</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Promedio x Viaje</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($stats['km_prom'], 0) ?> km</div>
</div>
</div>

<!-- Search -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex items-center gap-4 mb-8">
<div class="relative w-full">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input onkeyup="filterTable()" id="searchInput" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por patente, chofer, origen o destino..." type="text"/>
</div>
</div>

<!-- Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CHOFER</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CAMION</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">ORIGEN</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">DESTINO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM SALIDA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM LLEGADA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM REC</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="tableBody">
<?php foreach ($registrosList as $r): ?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-4 py-3 font-data-mono"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
<td class="px-4 py-3"><?= htmlspecialchars($r['apellido'] . ', ' . $r['nombre']) ?></td>
<td class="px-4 py-3 font-bold"><?= htmlspecialchars($r['patente']) ?></td>
<td class="px-4 py-3"><?= htmlspecialchars($r['origen'] ?? '-') ?></td>
<td class="px-4 py-3"><?= htmlspecialchars($r['destino'] ?? '-') ?></td>
<td class="px-4 py-3 text-right font-data-mono"><?= number_format($r['km_salida'], 0) ?></td>
<td class="px-4 py-3 text-right font-data-mono"><?= $r['km_llegada'] !== null ? number_format($r['km_llegada'], 0) : '-' ?></td>
<td class="px-4 py-3 text-right font-data-mono font-bold"><?= $r['km_recorridos'] !== null ? number_format($r['km_recorridos'], 0) : '-' ?></td>
<td class="px-4 py-3 text-center">
<?php
$estado = $r['estado'] ?? 'abierto';
if ($estado === 'aprobado') $bCls = 'bg-green-100 text-green-800';
elseif ($estado === 'cerrado') $bCls = 'bg-blue-100 text-blue-800';
else $bCls = 'bg-amber-100 text-amber-800';
?>
<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $bCls ?>"><?= $estado ?></span>
</td>
<td class="px-4 py-3 text-center">
<div class="flex gap-1 justify-center">
<?php if ($estado === 'cerrado'): ?>
<button onclick="autorizarViaje(<?= $r['id_hoja'] ?>)" class="px-3 py-1 bg-green-600 text-white rounded text-xs font-bold hover:bg-green-700">Autorizar</button>
<?php endif; ?>
<button onclick="editViaje(<?= $r['id_hoja'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Editar</button>
<button onclick="deleteViaje(<?= $r['id_hoja'] ?>)" class="px-3 py-1 bg-red-50 text-red-600 rounded text-xs font-bold hover:bg-red-100">Borrar</button>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main>

<!-- Modal Nuevo/Editar Viaje -->
<div id="modalViaje" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto modal-body">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalViajeTitle" class="font-headline-sm text-headline-sm text-primary">Nuevo Viaje</h3>
<button onclick="closeModal('modalViaje')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" id="formViaje" class="p-6 space-y-4">
<input type="hidden" name="action" id="viajeAction" value="create"/>
<input type="hidden" name="id_hoja" id="viajeId" value=""/>
<!-- Precargar Viaje -->
<div class="bg-surface-container-high p-3 rounded-lg space-y-2">
<div class="flex items-center gap-2 text-primary">
<span class="material-symbols-outlined text-[18px]">download</span>
<span class="font-label-caps text-label-caps uppercase font-bold">Precargar Viaje</span>
</div>
<div class="flex gap-2">
<input id="inputPrecargaId" type="number" class="flex-1 border border-outline-variant rounded p-2 bg-surface-container-low text-sm" placeholder="Nro. de Hoja de Ruta..." onkeydown="if(event.key==='Enter'){event.preventDefault();precargarViajeId();}"/>
<button type="button" onclick="precargarViajeId()" class="bg-primary text-on-primary px-4 py-2 rounded text-sm font-bold hover:opacity-90">Cargar</button>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Chofer</label>
<select name="id_chofer" id="viajeChofer" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($choferesList as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>"><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre'] . ' - ' . $ch['dni']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="viajeCamion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($camionesList as $ca): ?>
<option value="<?= $ca['id_camion'] ?>"><?= htmlspecialchars($ca['patente'] . ' - ' . $ca['marca'] . ' ' . $ca['modelo']) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha</label>
<input name="fecha" id="viajeFecha" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Salida</label>
<input name="km_salida" id="viajeKmSalida" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Llegada</label>
<input name="km_llegada" id="viajeKmLlegada" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="bg-surface-container-high p-3 rounded-lg flex justify-between items-center">
<span class="font-label-caps text-label-caps uppercase font-bold">KM Recorridos</span>
<span class="font-headline-md text-headline-md text-primary font-bold font-data-mono" id="viajeKmRec">0 km</span>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Origen</label>
<select name="origen" id="viajeOrigen" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Seleccionar...</option>
<?php foreach ($localidadesList as $loc): ?>
<option value="<?= htmlspecialchars($loc['localidad']) ?>"><?= htmlspecialchars($loc['localidad']) ?></option>
<?php endforeach; ?>
<option value="__OTRO__">Otro...</option>
</select>
<input name="origen_otro" id="viajeOrigenOtro" type="text" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low hidden mt-1" placeholder="Escribir origen..." oninput="document.getElementById('viajeOrigen').value='__OTRO__'"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Destino</label>
<select name="destino" id="viajeDestino" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Seleccionar...</option>
<?php foreach ($localidadesList as $loc): ?>
<option value="<?= htmlspecialchars($loc['localidad']) ?>"><?= htmlspecialchars($loc['localidad']) ?></option>
<?php endforeach; ?>
<option value="__OTRO__">Otro...</option>
</select>
<input name="destino_otro" id="viajeDestinoOtro" type="text" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low hidden mt-1" placeholder="Escribir destino..." oninput="document.getElementById('viajeDestino').value='__OTRO__'"/>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Observaciones</label>
<textarea name="observaciones" id="viajeObs" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" rows="2" placeholder="Opcional..."></textarea>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalViaje')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar Viaje</button>
</div>
</form>
</div>
</div>

<script>
var formSubmitted = false;

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

document.getElementById('formViaje').addEventListener('submit', function(e) {
if (formSubmitted) { e.preventDefault(); return false; }
formSubmitted = true;
this.querySelector('button[type="submit"]').disabled = true;
this.querySelector('button[type="submit"]').innerText = 'Guardando...';
});

function precargarViajeId() {
var id = document.getElementById('inputPrecargaId').value.trim();
if (!id) { alert('Ingrese un numero de Hoja de Ruta'); return; }
fetch('<?= BASE_URL ?>/api/get_data.php?action=precargar_viaje&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_hoja) {
alert('No se encontro la hoja de ruta #' + id);
return;
}
document.getElementById('viajeChofer').value = data.id_chofer;
document.getElementById('viajeCamion').value = data.id_camion;
document.getElementById('viajeFecha').value = data.fecha;
document.getElementById('viajeKmSalida').value = data.km_salida;
document.getElementById('viajeKmLlegada').value = data.km_llegada || '';
var orig = data.origen || '';
var dest = data.destino || '';
var origSel = document.getElementById('viajeOrigen');
origSel.value = orig;
document.getElementById('viajeOrigenOtro').value = '';
document.getElementById('viajeOrigenOtro').classList.add('hidden');
if (origSel.value === '' || !origSel.querySelector('option[value="' + orig.replace(/"/g,'\\"') + '"]')) {
origSel.value = orig ? '__OTRO__' : '';
if (orig) {
document.getElementById('viajeOrigenOtro').value = orig;
document.getElementById('viajeOrigenOtro').classList.remove('hidden');
}
}
var destSel = document.getElementById('viajeDestino');
destSel.value = dest;
document.getElementById('viajeDestinoOtro').value = '';
document.getElementById('viajeDestinoOtro').classList.add('hidden');
if (destSel.value === '' || !destSel.querySelector('option[value="' + dest.replace(/"/g,'\\"') + '"]')) {
destSel.value = dest ? '__OTRO__' : '';
if (dest) {
document.getElementById('viajeDestinoOtro').value = dest;
document.getElementById('viajeDestinoOtro').classList.remove('hidden');
}
}
document.getElementById('viajeAction').value = 'update';
document.getElementById('viajeId').value = data.id_hoja;
document.getElementById('modalViajeTitle').textContent = 'Editar Viaje #' + id;
calcKmRec();
document.getElementById('inputPrecargaId').value = '';
}).catch(err => {
alert('Error al cargar: ' + err.message);
});
}

function resetModal() { formSubmitted = false;
document.getElementById('viajeAction').value = 'create';
document.getElementById('viajeId').value = '';
document.getElementById('viajeKmSalida').value = '';
document.getElementById('viajeKmLlegada').value = '';
document.getElementById('viajeOrigen').value = 'PUERTO RICO';
if (document.getElementById('viajeOrigen').value !== 'PUERTO RICO') {
    document.getElementById('viajeOrigen').value = '__OTRO__';
    document.getElementById('viajeOrigenOtro').value = 'PUERTO RICO';
    document.getElementById('viajeOrigenOtro').classList.remove('hidden');
} else {
    document.getElementById('viajeOrigenOtro').value = '';
    document.getElementById('viajeOrigenOtro').classList.add('hidden');
}
document.getElementById('viajeDestino').value = '';
document.getElementById('viajeDestinoOtro').value = '';
document.getElementById('viajeDestinoOtro').classList.add('hidden');
document.getElementById('viajeObs').value = '';
document.getElementById('viajeKmRec').innerText = '0 km';
document.getElementById('modalViajeTitle').textContent = 'Nuevo Viaje';
document.getElementById('viajeFecha').value = new Date().toISOString().split('T')[0];
}

function toggleOtro(inputId, otroId) {
const el = document.getElementById(inputId);
const otro = document.getElementById(otroId);
if (el.value === '__OTRO__') { otro.classList.remove('hidden'); otro.focus(); }
else { otro.classList.add('hidden'); otro.value = ''; }
}
document.getElementById('viajeOrigen').addEventListener('change', function(){ toggleOtro('viajeOrigen','viajeOrigenOtro'); });
document.getElementById('viajeDestino').addEventListener('change', function(){ toggleOtro('viajeDestino','viajeDestinoOtro'); });

function editViaje(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=viaje&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_hoja) {
alert('Error: No se pudieron obtener los datos');
return;
}
document.getElementById('viajeAction').value = 'update';
document.getElementById('viajeId').value = data.id_hoja;
document.getElementById('viajeChofer').value = data.id_chofer;
document.getElementById('viajeCamion').value = data.id_camion;
document.getElementById('viajeFecha').value = data.fecha;
document.getElementById('viajeKmSalida').value = data.km_salida;
document.getElementById('viajeKmLlegada').value = data.km_llegada || '';
var origSel = document.getElementById('viajeOrigen');
origSel.value = data.origen || '';
if (origSel.value === '' || !origSel.querySelector('option[value="' + origSel.value.replace(/"/g,'\\"') + '"]')) {
origSel.value = '__OTRO__';
document.getElementById('viajeOrigenOtro').value = data.origen || '';
document.getElementById('viajeOrigenOtro').classList.remove('hidden');
}
var destSel = document.getElementById('viajeDestino');
destSel.value = data.destino || '';
if (destSel.value === '' || !destSel.querySelector('option[value="' + destSel.value.replace(/"/g,'\\"') + '"]')) {
destSel.value = '__OTRO__';
document.getElementById('viajeDestinoOtro').value = data.destino || '';
document.getElementById('viajeDestinoOtro').classList.remove('hidden');
}
document.getElementById('viajeObs').value = data.observaciones || '';
document.getElementById('modalViajeTitle').textContent = 'Editar Viaje';
calcKmRec();
openModal('modalViaje');
}).catch(err => {
alert('Error al cargar datos: ' + err.message);
});
}

function autorizarViaje(id) {
    showConfirm('¿Autorizar este viaje?', function() {
        var f = document.createElement('form'); f.method = 'POST'; f.action = '<?= $_SERVER['SCRIPT_NAME'] ?>';
        f.innerHTML = '<input name="action" value="autorizar"><input name="id_hoja" value="' + id + '">';
        document.body.appendChild(f); f.submit();
    });
}

function deleteViaje(id) {
    showConfirm('¿Eliminar este viaje?', function() {
        var form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input name="action" value="delete"><input name="id_hoja" value="' + id + '">';
        document.body.appendChild(form); form.submit();
    });
}

document.getElementById('modalViaje').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalViaje');
});

const kmSalida = document.getElementById('viajeKmSalida');
const kmLlegada = document.getElementById('viajeKmLlegada');
const kmRecDisplay = document.getElementById('viajeKmRec');
function calcKmRec() {
const s = parseFloat(kmSalida.value) || 0;
const l = parseFloat(kmLlegada.value) || 0;
kmRecDisplay.innerText = (l - s).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' km';
}
kmSalida.addEventListener('input', calcKmRec);
kmLlegada.addEventListener('input', calcKmRec);

function filterTable() {
const search = document.getElementById('searchInput').value.toLowerCase();
document.querySelectorAll('#tableBody tr').forEach(row => {
row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
});
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
