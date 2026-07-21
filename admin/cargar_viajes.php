<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$pageTitle = 'Cargar Viajes';

$db = getDB();
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN cachape_id INT DEFAULT NULL AFTER peso_carga"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN peso_total DECIMAL(12,2) DEFAULT NULL AFTER cachape_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_salida DECIMAL(10,2) DEFAULT NULL AFTER km_recorridos"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_llegada DECIMAL(10,2) DEFAULT NULL AFTER hs_salida"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_recorridas DECIMAL(10,2) GENERATED ALWAYS AS (hs_llegada - hs_salida) STORED AFTER hs_llegada"); } catch (Exception $e) {}

$mensaje = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$error = '';

// update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    $id_chofer = (int)($_POST['id_chofer'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $km_salida = (float)($_POST['km_salida'] ?? 0);
    $km_llegada = $_POST['km_llegada'] !== '' ? (float)$_POST['km_llegada'] : null;
    $hs_salida = $_POST['hs_salida'] !== '' ? (float)$_POST['hs_salida'] : null;
    $hs_llegada = $_POST['hs_llegada'] !== '' ? (float)$_POST['hs_llegada'] : null;
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');
    $nro_hoja_ruta = trim($_POST['nro_hoja_ruta'] ?? '');
    $ayudante_id = $_POST['ayudante_id'] !== '' ? (int)$_POST['ayudante_id'] : null;
    $tara = $_POST['tara'] !== '' ? (float)$_POST['tara'] : null;
    $peso_carga = $_POST['peso_carga'] !== '' ? (float)$_POST['peso_carga'] : null;
    $cachape_id = $_POST['cachape_id'] !== '' ? (int)$_POST['cachape_id'] : null;
    $peso_total = $_POST['peso_total'] !== '' ? (float)$_POST['peso_total'] : null;
    
    $estado = (($km_salida > 0 && $km_llegada !== null) || ($hs_salida !== null && $hs_llegada !== null)) ? 'cerrado' : 'abierto';

    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET fecha=?, id_chofer=?, id_camion=?, km_salida=?, km_llegada=?, hs_salida=?, hs_llegada=?, origen=?, destino=?, observaciones=?, estado=?, nro_hoja_ruta=?, ayudante_id=?, tara=?, peso_carga=?, cachape_id=?, peso_total=?, fecha_cierre=" . (($km_llegada !== null || $hs_llegada !== null) ? 'NOW()' : 'NULL') . " WHERE id_hoja=?");
        $stmt->execute([$fecha, $id_chofer, $id_camion, $km_salida, $km_llegada, $hs_salida, $hs_llegada, $origen, $destino, $observaciones, $estado, $nro_hoja_ruta ?: null, $ayudante_id, $tara, $peso_carga, $cachape_id, $peso_total, $id]);
        $_SESSION['flash'] = 'Viaje actualizado exitosamente';
        header('Location: cargar_viajes.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $id_chofer = (int)($_POST['id_chofer'] ?? 0);
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $km_salida = (float)($_POST['km_salida'] ?? 0);
    $km_llegada = $_POST['km_llegada'] !== '' ? (float)$_POST['km_llegada'] : null;
    $hs_salida = $_POST['hs_salida'] !== '' ? (float)$_POST['hs_salida'] : null;
    $hs_llegada = $_POST['hs_llegada'] !== '' ? (float)$_POST['hs_llegada'] : null;
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');
    $nro_hoja_ruta = trim($_POST['nro_hoja_ruta'] ?? '');
    $ayudante_id = $_POST['ayudante_id'] !== '' ? (int)$_POST['ayudante_id'] : null;
    $tara = $_POST['tara'] !== '' ? (float)$_POST['tara'] : null;
    $peso_carga = $_POST['peso_carga'] !== '' ? (float)$_POST['peso_carga'] : null;
    $cachape_id = $_POST['cachape_id'] !== '' ? (int)$_POST['cachape_id'] : null;
    $peso_total = $_POST['peso_total'] !== '' ? (float)$_POST['peso_total'] : null;
    
    $estado = (($km_salida > 0 && $km_llegada !== null) || ($hs_salida !== null && $hs_llegada !== null)) ? 'cerrado' : 'abierto';

    try {
        $stmt = $db->prepare("INSERT INTO km_recorrido (fecha, id_chofer, id_camion, km_salida, km_llegada, hs_salida, hs_llegada, origen, destino, observaciones, estado, nro_hoja_ruta, ayudante_id, tara, peso_carga, cachape_id, peso_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fecha, $id_chofer, $id_camion, $km_salida, $km_llegada, $hs_salida, $hs_llegada, $origen, $destino, $observaciones, $estado, $nro_hoja_ruta ?: null, $ayudante_id, $tara, $peso_carga, $cachape_id, $peso_total]);
        $_SESSION['flash'] = 'Viaje registrado exitosamente';
        header('Location: cargar_viajes.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// autorizar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autorizar') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET estado='aprobado', aprobado_por=? WHERE id_hoja=? AND estado='cerrado'");
        $stmt->execute([getCurrentUserId(), $id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['flash'] = 'Viaje autorizado exitosamente';
        } else {
            $error = 'El viaje debe estar en estado "cerrado" para autorizarlo';
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
    header('Location: cargar_viajes.php');
    exit;
}

// autorizar todo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autorizar_todo') {
    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET estado='aprobado', aprobado_por=? WHERE estado='cerrado' AND km_llegada IS NOT NULL");
        $stmt->execute([getCurrentUserId()]);
        $_SESSION['flash'] = $stmt->rowCount() . ' viajes autorizados exitosamente';
        header('Location: cargar_viajes.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    try {
        $stmt = $db->prepare("DELETE FROM km_recorrido WHERE id_hoja = ?");
        $stmt->execute([$id]);
        $_SESSION['flash'] = 'Viaje eliminado';
    } catch (Exception $e) {
        $error = 'Error al eliminar: ' . $e->getMessage();
    }
    header('Location: cargar_viajes.php');
    exit;
}

// Tabla localidad (origen/destino)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS localidad (id_localidad INT AUTO_INCREMENT PRIMARY KEY, localidad VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB");
} catch (Exception $e) {}
$countLoc = $db->query("SELECT COUNT(*) as c FROM localidad")->fetch();
if ($countLoc['c'] == 0) {
    $localidades = ['PUERTO RICO', 'PUERTO ESPAÑA', 'ESPINILLO', 'COLONIA VIERA', 'SANTA RITA', '25 DE MAYO', '9 DE JULIO', 'FLORENCIA', 'VILLA ANGELA', 'CHARATA'];
    $insLoc = $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)");
    foreach ($localidades as $l) $insLoc->execute([$l]);
}
$localidadesList = $db->query("SELECT localidad FROM localidad ORDER BY localidad")->fetchAll();

$choferesList = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado='activo' ORDER BY apellido, nombre")->fetchAll();
$camionesList = $db->query("SELECT id_camion, patente, marca, modelo, por_hora FROM camiones WHERE estado='activo' ORDER BY patente")->fetchAll();
$cachapesList = $db->query("SELECT id_camion, patente, marca, modelo, tara FROM camiones WHERE estado='activo' AND tipo='cachape' ORDER BY patente")->fetchAll();

// Filtros
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$orden = $_GET['orden'] ?? 'DESC';
$orden = strtoupper($orden) === 'ASC' ? 'ASC' : 'DESC';

$sql = "SELECT h.*, c.patente, c.por_hora, ch.nombre, ch.apellido,
        ay.nombre as ayudante_nombre, ay.apellido as ayudante_apellido
        FROM km_recorrido h
        JOIN camiones c ON h.id_camion = c.id_camion
        JOIN choferes ch ON h.id_chofer = ch.id_chofer
        LEFT JOIN choferes ay ON h.ayudante_id = ay.id_chofer
        WHERE h.estado != 'aprobado'
        AND h.fecha >= ? AND h.fecha <= ?";
$params = [$fecha_desde, $fecha_hasta];
$sql .= " ORDER BY h.fecha $orden, h.id_hoja $orden";
$registros = $db->prepare($sql);
$registros->execute($params);
$registrosList = $registros->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';
?>
<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Cargar Viajes</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Viajes pendientes de autorizacion.</p>
</div>
<button onclick="resetModalCarga(); openModal('modalViaje')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Viaje
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filtros -->
<form method="GET" class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex flex-wrap items-end gap-3 mb-6">
<div class="flex items-center gap-2">
<label class="font-label-caps text-[10px] text-on-surface-variant">Desde</label>
<input name="fecha_desde" type="date" value="<?= $fecha_desde ?>" class="border border-outline-variant rounded px-2 py-2 bg-surface-container-low text-sm"/>
</div>
<div class="flex items-center gap-2">
<label class="font-label-caps text-[10px] text-on-surface-variant">Hasta</label>
<input name="fecha_hasta" type="date" value="<?= $fecha_hasta ?>" class="border border-outline-variant rounded px-2 py-2 bg-surface-container-low text-sm"/>
</div>
<div class="flex items-center gap-1">
<label class="font-label-caps text-[10px] text-on-surface-variant">Orden</label>
<select name="orden" class="border border-outline-variant rounded px-2 py-2 bg-surface-container-low text-sm">
<option value="DESC" <?= $orden === 'DESC' ? 'selected' : '' ?>>Mas reciente</option>
<option value="ASC" <?= $orden === 'ASC' ? 'selected' : '' ?>>Mas antiguo</option>
</select>
</div>
<button type="submit" class="bg-primary text-on-primary px-4 py-2 rounded-lg text-sm font-bold hover:opacity-90 transition-opacity">Filtrar</button>
</form>
<form method="POST" onsubmit="return confirm('¿Autorizar TODOS los viajes cerrados con KM de llegada?')" class="bg-green-50 border border-green-200 p-4 rounded-xl mb-6 flex items-center gap-3">
<input type="hidden" name="action" value="autorizar_todo">
<span class="text-sm text-green-800">Accion rapida:</span>
<button class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-green-700 transition-colors flex items-center gap-1">
<span class="material-symbols-outlined text-base">how_to_reg</span> Autorizar Todo
</button>
</form>

<!-- Tabla -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl">
<table class="w-full table-fixed">
<thead class="bg-surface-container-high/50">
<tr>
<th class="w-[80px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA</th>
<th class="px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CHOFER</th>
<th class="w-[80px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CAM</th>
<th class="px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">RECORRIDO</th>
<th class="w-[100px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM</th>
<th class="w-[70px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM REC</th>
<th class="w-[90px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">T/P</th>
<th class="w-[50px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">EST</th>
<th class="w-[110px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ACC</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($registrosList)): ?>
<tr><td colspan="9" class="px-4 py-8 text-center text-on-surface-variant">No hay viajes pendientes de autorizacion.</td></tr>
<?php else: ?>
<?php foreach ($registrosList as $r):
$estado = $r['estado'] ?? 'abierto';
if ($estado === 'aprobado') $bCls = 'bg-green-100 text-green-800';
elseif ($estado === 'cerrado') $bCls = 'bg-blue-100 text-blue-800';
else $bCls = 'bg-amber-100 text-amber-800';
?>
<tr class="hover:bg-surface-container transition-colors text-[12px]">
<td class="px-2 py-2 font-data-mono whitespace-nowrap"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
<td class="px-2 py-2 leading-tight"><span class="block text-[11px]"><?= htmlspecialchars($r['apellido'] ?? '') ?></span><span class="block text-[11px]"><?= htmlspecialchars($r['nombre'] ?? '') ?></span></td>
<td class="px-2 py-2 font-bold whitespace-nowrap"><?= htmlspecialchars($r['patente']) ?></td>
<td class="px-2 py-2 truncate" title="<?= htmlspecialchars(($r['origen'] ?? '') . ' → ' . ($r['destino'] ?? '')) ?>"><?= htmlspecialchars(($r['origen'] ?? '-') . ' → ' . ($r['destino'] ?? '-')) ?></td>
<td class="px-2 py-2 text-right font-data-mono whitespace-nowrap text-[11px]"><?= $r['por_hora'] ? (number_format($r['hs_salida'], 1) . '&rarr;' . ($r['hs_llegada'] !== null ? number_format($r['hs_llegada'], 1) : '-')) : (number_format($r['km_salida'], 0) . '&rarr;' . ($r['km_llegada'] !== null ? number_format($r['km_llegada'], 0) : '-')) ?></td>
<td class="px-2 py-2 text-right font-data-mono font-bold whitespace-nowrap"><?= $r['por_hora'] ? ($r['hs_recorridas'] !== null ? number_format($r['hs_recorridas'], 1) . ' hs' : '-') : ($r['km_recorridos'] !== null ? number_format($r['km_recorridos'], 0) : '-') ?></td>
<td class="px-2 py-2 text-right font-data-mono whitespace-nowrap text-[11px]"><?= $r['tara'] !== null ? number_format($r['tara'], 0) : '' ?><?= $r['tara'] !== null && $r['peso_carga'] !== null ? '/' : '' ?><?= $r['peso_carga'] !== null ? number_format($r['peso_carga'], 0) : '' ?><?= $r['tara'] === null && $r['peso_carga'] === null ? '-' : '' ?></td>
<td class="px-2 py-2 text-center whitespace-nowrap">
<span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase <?= $bCls ?>"><?= $estado ?></span>
</td>
<td class="px-2 py-2 text-center whitespace-nowrap">
<div class="flex gap-1 justify-center">
<button onclick="editViaje(<?= $r['id_hoja'] ?>)" class="bg-blue-600 text-white px-2 py-1 rounded text-[10px] font-bold hover:bg-blue-700 transition-colors flex items-center gap-0.5">
<span class="material-symbols-outlined text-xs">edit</span>
</button>
<?php if ($estado === 'cerrado'): ?>
<form method="POST" class="inline" onsubmit="return confirm('¿Autorizar este viaje?')">
<input type="hidden" name="action" value="autorizar">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="bg-green-600 text-white px-2 py-1 rounded text-[10px] font-bold hover:bg-green-700 transition-colors flex items-center gap-0.5">
<span class="material-symbols-outlined text-xs">how_to_reg</span>
</button>
</form>
<?php endif; ?>
<form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este viaje?')">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="bg-red-600 text-white px-2 py-1 rounded text-[10px] font-bold hover:bg-red-700 transition-colors flex items-center gap-0.5">
<span class="material-symbols-outlined text-xs">delete</span>
</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</main>

<!-- Modal Editar Viaje -->
<div id="modalViaje" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto modal-body">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalViajeTitle" class="font-headline-sm text-headline-sm text-primary">Editar Viaje</h3>
<button onclick="closeModal('modalViaje')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" id="formViaje" class="p-6 space-y-4">
<input type="hidden" name="action" id="viajeAction" value="update"/>
<input type="hidden" name="id_hoja" id="viajeId" value=""/>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nro. Hoja de Ruta</label>
<input name="nro_hoja_ruta" id="viajeNroHoja" type="text" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: 1234"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha</label>
<input name="fecha" id="viajeFecha" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
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
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Ayudante</label>
<select name="ayudante_id" id="viajeAyudante" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Sin ayudante</option>
<?php foreach ($choferesList as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>"><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre'] . ' - ' . $ch['dni']) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="viajeCamion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar chofer primero...</option>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cachapé (opcional)</label>
<select name="cachape_id" id="viajeCachape" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" onchange="calcTaraTotal()">
<option value="">Sin cachapé</option>
<?php foreach ($cachapesList as $ca): ?>
<option value="<?= $ca['id_camion'] ?>" data-tara="<?= $ca['tara'] ?>"><?= htmlspecialchars($ca['patente'] . ' - ' . $ca['marca'] . ' ' . $ca['modelo'] . ($ca['tara'] ? ' (Tara: ' . $ca['tara'] . 'kg)' : '')) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="grid grid-cols-2 gap-4" id="kmFields">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Salida</label>
<input name="km_salida" id="viajeKmSalida" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" oninput="calcKmRec()" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Llegada</label>
<input name="km_llegada" id="viajeKmLlegada" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4 hidden" id="hsFields">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">HS Salida (Horas)</label>
<input name="hs_salida" id="viajeHsSalida" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" oninput="calcKmRec()"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">HS Llegada (Horas)</label>
<input name="hs_llegada" id="viajeHsLlegada" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-3 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tara (KG)</label>
<input name="tara" id="viajeTara" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" oninput="calcPesoTotal()"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Peso Carga (KG)</label>
<input name="peso_carga" id="viajePesoCarga" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" oninput="calcPesoTotal()"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Peso Total (KG)</label>
<input name="peso_total" id="viajePesoTotal" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low bg-surface-container-high font-bold" readonly/>
</div>
</div>
<div class="bg-surface-container-high p-3 rounded-lg flex justify-between items-center">
<span class="font-label-caps text-label-caps uppercase font-bold" id="viajeCalculadoLabel">KM Recorridos</span>
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

<!-- Confirmacion Modal -->
<div id="confirmModal" class="fixed inset-0 bg-black/50 z-[60] hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeConfirmModal()">
<div class="bg-surface-container-lowest rounded-2xl w-full max-w-sm p-6 text-center shadow-2xl animate-fadeIn">
<div id="confirmIcon" class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-4">
<span class="material-symbols-outlined text-amber-600 text-3xl">help</span>
</div>
<h3 class="font-headline-sm text-headline-sm text-on-surface mb-2">Confirmar</h3>
<p id="confirmMessage" class="text-on-surface-variant mb-6">¿Esta seguro?</p>
<div class="flex gap-3 justify-center">
<button onclick="closeConfirmModal()" class="px-6 py-2.5 rounded-lg border border-outline-variant text-on-surface font-medium hover:bg-surface-container-high transition-colors">Cancelar</button>
<button id="confirmOkBtn" class="px-6 py-2.5 rounded-lg bg-primary text-on-primary font-bold hover:opacity-90 transition-opacity">Confirmar</button>
</div>
</div>
</div>

<style>
@keyframes fadeIn { from { opacity:0; transform:scale(0.95); } to { opacity:1; transform:scale(1); } }
.animate-fadeIn { animation: fadeIn 0.2s ease-out; }
</style>

<script>
var formSubmitted = false;

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function toggleFieldsByCamion() {
    var camionSel = document.getElementById('viajeCamion');
    if (!camionSel) return;
    var opt = camionSel.options[camionSel.selectedIndex];
    var porHora = opt && opt.getAttribute('data-por-hora') == '1';
    
    var kmFields = document.getElementById('kmFields');
    var hsFields = document.getElementById('hsFields');
    var kmSalida = document.getElementById('viajeKmSalida');
    var hsSalida = document.getElementById('viajeHsSalida');
    var calcLabel = document.getElementById('viajeCalculadoLabel');
    
    if (porHora) {
        kmFields.classList.remove('hidden');
        hsFields.classList.remove('hidden');
        kmSalida.setAttribute('required', 'required');
        hsSalida.setAttribute('required', 'required');
        calcLabel.innerText = 'KM y HS Recorridos';
    } else {
        kmFields.classList.remove('hidden');
        hsFields.classList.add('hidden');
        hsSalida.removeAttribute('required');
        kmSalida.setAttribute('required', 'required');
        calcLabel.innerText = 'KM Recorridos';
    }
    calcKmRec();
}

function resetModalCarga() {
formSubmitted = false;
document.getElementById('viajeAction').value = 'create';
document.getElementById('viajeId').value = '';
document.getElementById('viajeNroHoja').value = '';
document.getElementById('viajeChofer').value = '';
document.getElementById('viajeAyudante').value = '';
document.getElementById('viajeCamion').innerHTML = '<option value="">Seleccionar chofer primero...</option>';
document.getElementById('viajeFecha').value = new Date().toISOString().split('T')[0];
document.getElementById('viajeKmSalida').value = '';
document.getElementById('viajeKmLlegada').value = '';
document.getElementById('viajeHsSalida').value = '';
document.getElementById('viajeHsLlegada').value = '';
document.getElementById('viajeCachape').value = '';
document.getElementById('viajeTara').value = '';
document.getElementById('viajePesoCarga').value = '';
document.getElementById('viajePesoTotal').value = '';
document.getElementById('viajeOrigen').value = 'PUERTO RICO';
document.getElementById('viajeOrigenOtro').value = '';
document.getElementById('viajeOrigenOtro').classList.add('hidden');
document.getElementById('viajeDestino').value = '';
document.getElementById('viajeDestinoOtro').value = '';
document.getElementById('viajeDestinoOtro').classList.add('hidden');
document.getElementById('viajeObs').value = '';
document.getElementById('viajeKmRec').innerText = '0 km';
document.getElementById('modalViajeTitle').textContent = 'Nuevo Viaje';
toggleFieldsByCamion();
}

document.getElementById('formViaje').addEventListener('submit', function(e) {
if (formSubmitted) { e.preventDefault(); return false; }
formSubmitted = true;
this.querySelector('button[type="submit"]').disabled = true;
this.querySelector('button[type="submit"]').innerText = 'Guardando...';
});

function editViaje(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=viaje&id=' + id)
.then(r => r.json()).then(function(data) {
if (!data || !data.id_hoja) { alert('Error: No se pudieron obtener los datos'); return; }
document.getElementById('viajeAction').value = 'update';
document.getElementById('viajeId').value = data.id_hoja;
document.getElementById('viajeNroHoja').value = data.nro_hoja_ruta || '';
document.getElementById('viajeChofer').value = data.id_chofer;
document.getElementById('viajeAyudante').value = data.ayudante_id || '';
document.getElementById('viajeFecha').value = data.fecha;
cargarCamionesChofer(data.id_chofer, data.id_camion);
document.getElementById('viajeKmSalida').value = data.km_salida;
document.getElementById('viajeKmLlegada').value = data.km_llegada || '';
document.getElementById('viajeHsSalida').value = data.hs_salida || '';
document.getElementById('viajeHsLlegada').value = data.hs_llegada || '';
    document.getElementById('viajeCachape').value = data.cachape_id || '';
    document.getElementById('viajeTara').value = data.tara || '';
document.getElementById('viajePesoCarga').value = data.peso_carga || '';
document.getElementById('viajePesoTotal').value = data.peso_total || '';
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
openModal('modalViaje');
}).catch(function(err) {
alert('Error al cargar datos: ' + err.message);
});
}

function cargarCamionesChofer(idChofer, selectedId) {
var sel = document.getElementById('viajeCamion');
sel.innerHTML = '<option value="">Cargando...</option>';
sel.disabled = true;
fetch('<?= BASE_URL ?>/api/get_data.php?action=chofer_camiones&id_chofer=' + idChofer)
.then(function(r) { return r.json(); }).then(function(data) {
sel.innerHTML = '<option value="">Seleccionar...</option>';
data.forEach(function(c) {
var opt = document.createElement('option');
opt.value = c.id_camion;
opt.textContent = c.patente + ' - ' + c.marca + ' ' + c.modelo;
opt.setAttribute('data-tara', c.tara || 0);
opt.setAttribute('data-por-hora', c.por_hora || 0);
sel.appendChild(opt);
});
sel.disabled = false;
if (selectedId) {
    sel.value = selectedId;
    toggleFieldsByCamion();
}
}).catch(function() {
sel.innerHTML = '<option value="">Error al cargar</option>';
sel.disabled = false;
});
}

document.getElementById('viajeChofer').addEventListener('change', function() {
var id = parseInt(this.value);
if (id > 0) { cargarCamionesChofer(id); }
else {
var sel = document.getElementById('viajeCamion');
sel.innerHTML = '<option value="">Seleccionar chofer primero...</option>';
}
});

const kmSalida = document.getElementById('viajeKmSalida');
const kmLlegada = document.getElementById('viajeKmLlegada');
const hsSalida = document.getElementById('viajeHsSalida');
const hsLlegada = document.getElementById('viajeHsLlegada');
const kmRecDisplay = document.getElementById('viajeKmRec');

function calcKmRec() {
    var camionSel = document.getElementById('viajeCamion');
    var opt = camionSel.options[camionSel.selectedIndex];
    var porHora = opt && opt.getAttribute('data-por-hora') == '1';
    
    if (porHora) {
        const kmS = parseFloat(kmSalida.value) || 0;
        const kmL = parseFloat(kmLlegada.value) || 0;
        const hsS = parseFloat(hsSalida.value) || 0;
        const hsL = parseFloat(hsLlegada.value) || 0;
        
        let parts = [];
        if (kmL >= kmS && kmL > 0) parts.push((kmL - kmS).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + ' km');
        if (hsL >= hsS && hsL > 0) parts.push((hsL - hsS).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' hs');
        kmRecDisplay.innerText = parts.length > 0 ? parts.join(' / ') : '0';
    } else {
        const s = parseFloat(kmSalida.value) || 0;
        const l = parseFloat(kmLlegada.value) || 0;
        kmRecDisplay.innerText = (l - s >= 0 ? l - s : 0).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + ' km';
    }
}
kmSalida.addEventListener('input', calcKmRec);
kmLlegada.addEventListener('input', calcKmRec);
hsSalida.addEventListener('input', calcKmRec);
hsLlegada.addEventListener('input', calcKmRec);

function calcTaraTotal() {
var camionSel = document.getElementById('viajeCamion');
var camionOpt = camionSel.options[camionSel.selectedIndex];
var camionTara = parseFloat(camionOpt.getAttribute('data-tara')) || 0;
var cachapeSel = document.getElementById('viajeCachape');
var cachapeOpt = cachapeSel.options[cachapeSel.selectedIndex];
var cachapeTara = parseFloat(cachapeOpt.getAttribute('data-tara')) || 0;
var total = camionTara + cachapeTara;
document.getElementById('viajeTara').value = total > 0 ? total : '';
calcPesoTotal();
}
function calcPesoTotal() {
var tara = parseFloat(document.getElementById('viajeTara').value) || 0;
var peso = parseFloat(document.getElementById('viajePesoCarga').value) || 0;
document.getElementById('viajePesoTotal').value = (tara + peso).toFixed(2);
}
document.getElementById('viajeCamion').addEventListener('change', function() {
    calcTaraTotal();
    toggleFieldsByCamion();
});

// Confirm modal logic
var pendingForm = null;
var pendingAction = null;

function showConfirmModal(msg, icon, btnText, btnClass, callback) {
document.getElementById('confirmMessage').textContent = msg;
document.getElementById('confirmIcon').innerHTML = '<span class="material-symbols-outlined text-3xl">' + icon + '</span>';
var btn = document.getElementById('confirmOkBtn');
btn.textContent = btnText;
btn.className = 'px-6 py-2.5 rounded-lg font-bold hover:opacity-90 transition-opacity ' + btnClass;
document.getElementById('confirmModal').classList.remove('hidden');
pendingAction = callback;
}

function closeConfirmModal() {
document.getElementById('confirmModal').classList.add('hidden');
pendingAction = null;
pendingForm = null;
}

document.getElementById('confirmOkBtn').addEventListener('click', function() {
if (pendingAction) pendingAction();
closeConfirmModal();
});

document.querySelectorAll('form[onsubmit*="confirm"]').forEach(function(form) {
var origMsg = form.getAttribute('onsubmit').match(/confirm\('([^']+)'\)/);
if (origMsg) {
var msg = origMsg[1];
form.removeAttribute('onsubmit');
var isDelete = msg.includes('Eliminar');
form.addEventListener('submit', function(e) {
e.preventDefault();
var icon = isDelete ? 'delete' : 'how_to_reg';
var btnText = isDelete ? 'Eliminar' : 'Autorizar';
var btnClass = isDelete ? 'bg-red-600 text-white' : 'bg-green-600 text-white';
showConfirmModal(msg, icon, btnText, btnClass, function() { form.submit(); });
});
}
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
