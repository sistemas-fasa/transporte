<?php
require_once __DIR__ . '/../includes/auth.php';
requireChoferAccess('kilometraje_cargar');
$pageTitle = 'Mis Viajes';

$db = getDB();
$userId = getCurrentUserId();
$idChofer = getChoferIdFromUser();

$mensaje = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Migrar columnas de estado si no existen
try { $db->exec("ALTER TABLE km_recorrido MODIFY km_llegada DECIMAL(12,2) DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN estado ENUM('abierto','cerrado','aprobado') DEFAULT 'abierto' AFTER observaciones"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN aprobado_por INT DEFAULT NULL AFTER estado"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN fecha_cierre DATETIME DEFAULT NULL AFTER aprobado_por"); } catch (Exception $e) {}

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

// Si aun no hay id_chofer, buscarlo a traves de vehiculos asignados
if (!$idChofer && $userId) {
    try {
        $stmtVh = $db->prepare("SELECT a.id_chofer FROM vehiculos_usuarios vu JOIN asignaciones a ON vu.vehiculo_id = a.id_camion AND a.activa = 1 WHERE vu.usuario_id = ? LIMIT 1");
        $stmtVh->execute([$userId]);
        $idChofer = $stmtVh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Crear viaje
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $km_salida = (float)($_POST['km_salida'] ?? 0);
    $km_llegada = (float)($_POST['km_llegada'] ?? 0);
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');

    $km_llegada_val = $_POST['km_llegada'] !== '' ? (float)$_POST['km_llegada'] : null;
    $estado = ($km_salida > 0 && $km_llegada_val !== null) ? 'cerrado' : 'abierto';

    if (!$idChofer) {
        $error = 'No se pudo identificar el chofer. Verifique que el usuario este vinculado a un chofer.';
    } else {
        try {
            if ($hasUsuarioId) {
                $stmt = $db->prepare("INSERT INTO km_recorrido (fecha, id_chofer, id_camion, km_salida, km_llegada, origen, destino, observaciones, usuario_id, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fecha, $idChofer, $id_camion, $km_salida, $km_llegada_val, $origen, $destino, $observaciones, $userId, $estado]);
            } else {
                $stmt = $db->prepare("INSERT INTO km_recorrido (fecha, id_chofer, id_camion, km_salida, km_llegada, origen, destino, observaciones, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fecha, $idChofer, $id_camion, $km_salida, $km_llegada_val, $origen, $destino, $observaciones, $estado]);
            }
            $_SESSION['flash'] = 'Viaje registrado exitosamente';
            header('Location: viajes.php');
            exit;
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Editar viaje (chofer cierra viaje abierto)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id_hoja = (int)($_POST['id_hoja'] ?? 0);
    $km_llegada_val = $_POST['km_llegada'] !== '' ? (float)$_POST['km_llegada'] : null;
    $km_salida = (float)($_POST['km_salida'] ?? 0);
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');
    $estado = ($km_salida > 0 && $km_llegada_val !== null) ? 'cerrado' : 'abierto';
    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET km_salida=?, km_llegada=?, origen=?, destino=?, observaciones=?, estado=?, fecha_cierre=" . ($km_llegada_val !== null ? 'NOW()' : 'NULL') . " WHERE id_hoja=? AND estado='abierto'");
        $stmt->execute([$km_salida, $km_llegada_val, $origen, $destino, $observaciones, $estado, $id_hoja]);
        if ($stmt->rowCount() === 0) {
            $error = 'El viaje no esta abierto o no existe.';
        } else {
            $_SESSION['flash'] = 'Viaje actualizado exitosamente';
            header('Location: viajes.php');
            exit;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Tabla localidad
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
// Solo camiones asignados al chofer (de ambas fuentes)
$camionesList = [];
$vistos = [];
if ($idChofer) {
    try {
        $stmtCam = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.modelo FROM asignaciones a JOIN camiones c ON a.id_camion = c.id_camion WHERE a.id_chofer = ? AND a.activa = 1");
        $stmtCam->execute([$idChofer]);
        foreach ($stmtCam->fetchAll() as $row) {
            $vistos[$row['id_camion']] = true;
            $camionesList[] = $row;
        }
    } catch (Exception $e) {}
}
if ($userId) {
    try {
        $stmtCam2 = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.modelo FROM vehiculos_usuarios vu JOIN camiones c ON vu.vehiculo_id = c.id_camion WHERE vu.usuario_id = ?");
        $stmtCam2->execute([$userId]);
        foreach ($stmtCam2->fetchAll() as $row) {
            if (!isset($vistos[$row['id_camion']])) {
                $vistos[$row['id_camion']] = true;
                $camionesList[] = $row;
            }
        }
    } catch (Exception $e) {}
}

// Ultimo km_llegada por camion
$ultimoKmPorCamion = [];
foreach ($camionesList as $ca) {
    try {
        $stmtKm = $db->prepare("SELECT km_llegada FROM km_recorrido WHERE id_camion = ? ORDER BY fecha DESC, id_hoja DESC LIMIT 1");
        $stmtKm->execute([$ca['id_camion']]);
        $ultimoKmPorCamion[$ca['id_camion']] = $stmtKm->fetchColumn();
    } catch (Exception $e) {}
}

// Viajes del chofer (por id_chofer o usuario_id)
if ($idChofer) {
    $stmt = $db->prepare("SELECT h.*, c.patente FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion WHERE h.id_chofer = ? ORDER BY h.fecha DESC LIMIT 50");
    $stmt->execute([$idChofer]);
    $stats = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(km_recorridos),0) as km_total FROM km_recorrido WHERE id_chofer = ? AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())");
    $stats->execute([$idChofer]);
    $viajes = $stmt->fetchAll();
    $statsData = $stats->fetch();
} elseif ($hasUsuarioId) {
    $stmt = $db->prepare("SELECT h.*, c.patente FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion WHERE h.usuario_id = ? ORDER BY h.fecha DESC LIMIT 50");
    $stmt->execute([$userId]);
    $stats = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(km_recorridos),0) as km_total FROM km_recorrido WHERE usuario_id = ? AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())");
    $stats->execute([$userId]);
    $viajes = $stmt->fetchAll();
    $statsData = $stats->fetch();
} else {
    $viajes = [];
    $statsData = ['total' => 0, 'km_total' => 0];
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/sidebar_chofer.php'; ?>
<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-5xl mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Mis Viajes</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Historial de viajes y kilometraje.</p>
</div>
<button onclick="resetModal(); openModal('modalViaje')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Cargar KM
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="grid grid-cols-2 gap-4 mb-8">
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Viajes del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= $statsData['total'] ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM del Mes</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($statsData['km_total'], 0) ?> km</div>
</div>
</div>

<!-- Desktop table -->
<div class="hidden md:block bg-surface-container-lowest border border-outline-variant rounded-xl overflow-x-auto">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-4 py-3 font-label-caps text-[10px] text-left">FECHA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-left">CAMION</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-left">ORIGEN</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-left">DESTINO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-right">KM SALIDA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-right">KM LLEGADA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-right">KM REC</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-center">ESTADO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-center"></th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($viajes)): ?>
<tr><td colspan="9" class="px-4 py-8 text-center text-on-surface-variant">No hay viajes registrados</td></tr>
<?php else: ?>
<?php foreach ($viajes as $v):
$estado = $v['estado'] ?? 'abierto';
if ($estado === 'aprobado') $badge = 'bg-green-100 text-green-800';
elseif ($estado === 'cerrado') $badge = 'bg-blue-100 text-blue-800';
else $badge = 'bg-amber-100 text-amber-800';
?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-4 py-3 font-data-mono whitespace-nowrap"><?= date('d/m/Y', strtotime($v['fecha'])) ?></td>
<td class="px-4 py-3 font-bold whitespace-nowrap"><?= htmlspecialchars($v['patente']) ?></td>
<td class="px-4 py-3 max-w-[120px] truncate"><?= htmlspecialchars($v['origen'] ?? '-') ?></td>
<td class="px-4 py-3 max-w-[120px] truncate"><?= htmlspecialchars($v['destino'] ?? '-') ?></td>
<td class="px-4 py-3 text-right font-data-mono whitespace-nowrap"><?= number_format($v['km_salida'], 0) ?></td>
<td class="px-4 py-3 text-right font-data-mono whitespace-nowrap"><?= $v['km_llegada'] !== null ? number_format($v['km_llegada'], 0) : '-' ?></td>
<td class="px-4 py-3 text-right font-data-mono font-bold whitespace-nowrap"><?= $v['km_recorridos'] !== null ? number_format($v['km_recorridos'], 0) : '-' ?></td>
<td class="px-4 py-3 text-center whitespace-nowrap">
<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $badge ?>"><?= $estado ?></span>
</td>
<td class="px-4 py-3 text-center whitespace-nowrap">
<?php if ($estado === 'abierto'): ?>
<button onclick="editarViaje(<?= $v['id_hoja'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Cerrar</button>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- Mobile cards -->
<div class="md:hidden space-y-3">
<?php if (empty($viajes)): ?>
<p class="text-center text-on-surface-variant py-8">No hay viajes registrados</p>
<?php else: ?>
<?php foreach ($viajes as $v):
$estado = $v['estado'] ?? 'abierto';
if ($estado === 'aprobado') $badge = 'bg-green-100 text-green-800';
elseif ($estado === 'cerrado') $badge = 'bg-blue-100 text-blue-800';
else $badge = 'bg-amber-100 text-amber-800';
?>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4">
<div class="flex justify-between items-start mb-2">
<div>
<p class="font-bold text-sm"><?= htmlspecialchars($v['patente']) ?></p>
<p class="font-data-mono text-xs text-on-surface-variant"><?= date('d/m/Y', strtotime($v['fecha'])) ?></p>
</div>
<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $badge ?>"><?= $estado ?></span>
</div>
<div class="grid grid-cols-2 gap-2 text-xs mb-3">
<div><span class="text-on-surface-variant">Origen:</span> <?= htmlspecialchars($v['origen'] ?? '-') ?></div>
<div><span class="text-on-surface-variant">Destino:</span> <?= htmlspecialchars($v['destino'] ?? '-') ?></div>
<div><span class="text-on-surface-variant">KM Salida:</span> <?= number_format($v['km_salida'], 0) ?></div>
<div><span class="text-on-surface-variant">KM Llegada:</span> <?= $v['km_llegada'] !== null ? number_format($v['km_llegada'], 0) : '-' ?></div>
</div>
<div class="flex justify-between items-center">
<div><span class="text-on-surface-variant text-xs">KM Recorridos:</span> <span class="font-bold"><?= $v['km_recorridos'] !== null ? number_format($v['km_recorridos'], 0) : '-' ?></span></div>
<?php if ($estado === 'abierto'): ?>
<button onclick="editarViaje(<?= $v['id_hoja'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Cerrar</button>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</main>

<!-- Modal Nuevo Viaje -->
<div id="modalViaje" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto modal-body">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalViajeTitulo" class="font-headline-sm text-headline-sm text-primary">Cargar KM</h3>
<button onclick="closeModal('modalViaje')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="viajeAction" value="create"/>
<input type="hidden" name="id_hoja" id="viajeId" value=""/>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="viajeCamion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($camionesList as $c): ?>
<option value="<?= $c['id_camion'] ?>" data-ultimo-km="<?= $ultimoKmPorCamion[$c['id_camion']] ?? '' ?>"><?= htmlspecialchars($c['patente'] . ' - ' . $c['marca'] . ' ' . $c['modelo']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha</label>
<input type="date" name="fecha" id="viajeFecha" value="<?= date('Y-m-d') ?>" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Salida</label>
<input type="number" step="0.1" name="km_salida" id="viajeKmSalida" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Llegada <span class="text-on-surface-variant text-[10px]">(opcional, dejar vacio si el viaje continua)</span></label>
<input type="number" step="0.1" name="km_llegada" id="viajeKmLlegada" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Origen</label>
<select name="origen" id="origenSelect" onchange="if(this.value==='__OTRO__'){document.getElementById('origenOtro').classList.remove('hidden')}else{document.getElementById('origenOtro').classList.add('hidden')}" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Seleccionar...</option>
<?php foreach ($localidadesList as $loc): ?>
<option value="<?= htmlspecialchars($loc['localidad']) ?>"><?= htmlspecialchars($loc['localidad']) ?></option>
<?php endforeach; ?>
<option value="__OTRO__">OTRO...</option>
</select>
<input type="text" name="origen_otro" id="origenOtro" placeholder="Escribir origen..." class="hidden w-full border border-outline-variant rounded p-3 bg-surface-container-low mt-2"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Destino</label>
<select name="destino" id="destinoSelect" onchange="if(this.value==='__OTRO__'){document.getElementById('destinoOtro').classList.remove('hidden')}else{document.getElementById('destinoOtro').classList.add('hidden')}" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Seleccionar...</option>
<?php foreach ($localidadesList as $loc): ?>
<option value="<?= htmlspecialchars($loc['localidad']) ?>"><?= htmlspecialchars($loc['localidad']) ?></option>
<?php endforeach; ?>
<option value="__OTRO__">OTRO...</option>
</select>
<input type="text" name="destino_otro" id="destinoOtro" placeholder="Escribir destino..." class="hidden w-full border border-outline-variant rounded p-3 bg-surface-container-low mt-2"/>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Observaciones</label>
<textarea name="observaciones" id="viajeObs" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low resize-none"></textarea>
</div>
<button type="submit" class="w-full bg-primary text-on-primary py-3 rounded-lg font-bold hover:opacity-90 transition-opacity">Registrar Viaje</button>
</form>
</div>
</div>

<script>
var editandoId = null;

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function resetModal() {
    editandoId = null;
    document.getElementById('viajeAction').value = 'create';
    document.getElementById('viajeId').value = '';
    document.getElementById('viajeKmSalida').value = '';
    document.getElementById('viajeKmLlegada').value = '';
    document.getElementById('viajeFecha').value = new Date().toISOString().split('T')[0];
    var origSel = document.getElementById('origenSelect');
    origSel.value = 'PUERTO RICO';
    if (origSel.value !== 'PUERTO RICO') {
        origSel.value = '__OTRO__';
        document.getElementById('origenOtro').value = 'PUERTO RICO';
        document.getElementById('origenOtro').classList.remove('hidden');
    } else {
        document.getElementById('origenOtro').value = '';
        document.getElementById('origenOtro').classList.add('hidden');
    }
    document.getElementById('destinoSelect').value = '';
    document.getElementById('destinoOtro').value = '';
    document.getElementById('destinoOtro').classList.add('hidden');
    document.getElementById('viajeObs').value = '';
    document.getElementById('modalViajeTitulo').textContent = 'Cargar KM';
    document.getElementById('modalViaje').querySelector('button[type="submit"]').textContent = 'Registrar Viaje';
}

function editarViaje(id) {
    fetch('<?= BASE_URL ?>/api/get_data.php?action=viaje&id=' + id)
    .then(r => r.json()).then(data => {
        if (!data || !data.id_hoja) { alert('Error al obtener datos'); return; }
        editandoId = id;
        document.getElementById('viajeAction').value = 'update';
        document.getElementById('viajeId').value = data.id_hoja;
        document.getElementById('viajeCamion').value = data.id_camion;
        document.getElementById('viajeFecha').value = data.fecha;
        document.getElementById('viajeKmSalida').value = data.km_salida;
        document.getElementById('viajeKmLlegada').value = data.km_llegada || '';
        var origSel = document.getElementById('origenSelect');
        origSel.value = data.origen || '';
        document.getElementById('origenOtro').value = '';
        document.getElementById('origenOtro').classList.add('hidden');
        if (!origSel.value || !origSel.querySelector('option[value="' + origSel.value.replace(/"/g,'\\"') + '"]')) {
            if (data.origen) { origSel.value = '__OTRO__'; document.getElementById('origenOtro').value = data.origen; document.getElementById('origenOtro').classList.remove('hidden'); }
        }
        var destSel = document.getElementById('destinoSelect');
        destSel.value = data.destino || '';
        document.getElementById('destinoOtro').value = '';
        document.getElementById('destinoOtro').classList.add('hidden');
        if (!destSel.value || !destSel.querySelector('option[value="' + destSel.value.replace(/"/g,'\\"') + '"]')) {
            if (data.destino) { destSel.value = '__OTRO__'; document.getElementById('destinoOtro').value = data.destino; document.getElementById('destinoOtro').classList.remove('hidden'); }
        }
        document.getElementById('viajeObs').value = data.observaciones || '';
        document.getElementById('modalViajeTitulo').textContent = 'Cerrar Viaje #' + id;
        document.getElementById('modalViaje').querySelector('button[type="submit"]').textContent = 'Guardar';
        openModal('modalViaje');
    }).catch(err => { alert('Error: ' + err.message); });
}

document.addEventListener('DOMContentLoaded', function() {
    const camionSelect = document.getElementById('viajeCamion');
    const kmSalida = document.getElementById('viajeKmSalida');
    if (camionSelect && kmSalida) {
        camionSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const km = opt ? opt.getAttribute('data-ultimo-km') : '';
            if (km && !editandoId) kmSalida.value = km;
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
