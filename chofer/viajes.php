<?php
require_once __DIR__ . '/../includes/auth.php';
requireChofer();
$pageTitle = 'Mis Viajes';
$kmHabilitado = hasPermission('kilometraje_cargar');

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
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN cachape_id INT DEFAULT NULL AFTER peso_carga"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN peso_total DECIMAL(12,2) DEFAULT NULL AFTER cachape_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_salida DECIMAL(10,2) DEFAULT NULL AFTER km_recorridos"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_llegada DECIMAL(10,2) DEFAULT NULL AFTER hs_salida"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_recorridas DECIMAL(10,2) GENERATED ALWAYS AS (hs_llegada - hs_salida) STORED AFTER hs_llegada"); } catch (Exception $e) {}

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
    $km_llegada_val = $_POST['km_llegada'] !== '' ? (float)$_POST['km_llegada'] : null;
    $hs_salida = $_POST['hs_salida'] !== '' ? (float)$_POST['hs_salida'] : null;
    $hs_llegada_val = $_POST['hs_llegada'] !== '' ? (float)$_POST['hs_llegada'] : null;
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');
    $nro_hoja_ruta = trim($_POST['nro_hoja_ruta'] ?? '');
    $tara = $_POST['tara'] !== '' ? (float)$_POST['tara'] : null;
    $peso_carga = $_POST['peso_carga'] !== '' ? (float)$_POST['peso_carga'] : null;
    $peso_total = $_POST['peso_total'] !== '' ? (float)$_POST['peso_total'] : null;
    $ayudante_id = $_POST['ayudante_id'] !== '' ? (int)$_POST['ayudante_id'] : null;

    $estado = (($km_salida > 0 && $km_llegada_val !== null) || ($hs_salida !== null && $hs_llegada_val !== null)) ? 'cerrado' : 'abierto';

    if (!$idChofer) {
        $error = 'No se pudo identificar el chofer. Verifique que el usuario este vinculado a un chofer.';
    } else {
        try {
            $cachape_id = $_POST['cachape_id'] !== '' ? (int)$_POST['cachape_id'] : null;
            if ($hasUsuarioId) {
                $stmt = $db->prepare("INSERT INTO km_recorrido (fecha, id_chofer, id_camion, km_salida, km_llegada, hs_salida, hs_llegada, origen, destino, observaciones, usuario_id, estado, nro_hoja_ruta, tara, peso_carga, cachape_id, peso_total, ayudante_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fecha, $idChofer, $id_camion, $km_salida, $km_llegada_val, $hs_salida, $hs_llegada_val, $origen, $destino, $observaciones, $userId, $estado, $nro_hoja_ruta ?: null, $tara, $peso_carga, $cachape_id, $peso_total, $ayudante_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO km_recorrido (fecha, id_chofer, id_camion, km_salida, km_llegada, hs_salida, hs_llegada, origen, destino, observaciones, estado, nro_hoja_ruta, tara, peso_carga, cachape_id, peso_total, ayudante_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fecha, $idChofer, $id_camion, $km_salida, $km_llegada_val, $hs_salida, $hs_llegada_val, $origen, $destino, $observaciones, $estado, $nro_hoja_ruta ?: null, $tara, $peso_carga, $cachape_id, $peso_total, $ayudante_id]);
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
    $hs_llegada_val = $_POST['hs_llegada'] !== '' ? (float)$_POST['hs_llegada'] : null;
    $hs_salida = $_POST['hs_salida'] !== '' ? (float)$_POST['hs_salida'] : null;
    $origen = trim($_POST['origen'] ?? '');
    if ($origen === '__OTRO__') { $origen = trim($_POST['origen_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$origen]); }
    $destino = trim($_POST['destino'] ?? '');
    if ($destino === '__OTRO__') { $destino = trim($_POST['destino_otro'] ?? ''); $db->prepare("INSERT IGNORE INTO localidad (localidad) VALUES (?)")->execute([$destino]); }
    $observaciones = trim($_POST['observaciones'] ?? '');
    $nro_hoja_ruta = trim($_POST['nro_hoja_ruta'] ?? '');
    $tara = $_POST['tara'] !== '' ? (float)$_POST['tara'] : null;
    $peso_carga = $_POST['peso_carga'] !== '' ? (float)$_POST['peso_carga'] : null;
    $cachape_id = $_POST['cachape_id'] !== '' ? (int)$_POST['cachape_id'] : null;
    $peso_total = $_POST['peso_total'] !== '' ? (float)$_POST['peso_total'] : null;
    $ayudante_id = $_POST['ayudante_id'] !== '' ? (int)$_POST['ayudante_id'] : null;
    
    $estado = (($km_salida > 0 && $km_llegada_val !== null) || ($hs_salida !== null && $hs_llegada_val !== null)) ? 'cerrado' : 'abierto';
    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET km_salida=?, km_llegada=?, hs_salida=?, hs_llegada=?, origen=?, destino=?, observaciones=?, estado=?, nro_hoja_ruta=?, tara=?, peso_carga=?, cachape_id=?, peso_total=?, ayudante_id=?, fecha_cierre=" . (($km_llegada_val !== null || $hs_llegada_val !== null) ? 'NOW()' : 'NULL') . " WHERE id_hoja=? AND estado='abierto'");
        $stmt->execute([$km_salida, $km_llegada_val, $hs_salida, $hs_llegada_val, $origen, $destino, $observaciones, $estado, $nro_hoja_ruta ?: null, $tara, $peso_carga, $cachape_id, $peso_total, $ayudante_id, $id_hoja]);
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
$cachapesList = $db->query("SELECT id_camion, patente, marca, modelo, tara FROM camiones WHERE estado='activo' AND tipo='cachape' ORDER BY patente")->fetchAll();
$choferesAyudantes = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado='activo' ORDER BY apellido, nombre")->fetchAll();
// Solo camiones asignados al chofer (de ambas fuentes)
$camionesList = [];
$vistos = [];
if ($idChofer) {
    try {
        $stmtCam = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.modelo, c.tara, c.por_hora FROM asignaciones a JOIN camiones c ON a.id_camion = c.id_camion WHERE a.id_chofer = ? AND a.activa = 1");
        $stmtCam->execute([$idChofer]);
        foreach ($stmtCam->fetchAll() as $row) {
            $vistos[$row['id_camion']] = true;
            $camionesList[] = $row;
        }
    } catch (Exception $e) {}
}
if ($userId) {
    try {
        $stmtCam2 = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.modelo, c.tara, c.por_hora FROM vehiculos_usuarios vu JOIN camiones c ON vu.vehiculo_id = c.id_camion WHERE vu.usuario_id = ?");
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
    $stmt = $db->prepare("SELECT h.*, c.patente, c.por_hora FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion WHERE h.id_chofer = ? ORDER BY h.fecha DESC LIMIT 50");
    $stmt->execute([$idChofer]);
    $stats = $db->prepare("SELECT COUNT(*) as total, COALESCE(SUM(km_recorridos),0) as km_total FROM km_recorrido WHERE id_chofer = ? AND MONTH(fecha) = MONTH(CURDATE()) AND YEAR(fecha) = YEAR(CURDATE())");
    $stats->execute([$idChofer]);
    $viajes = $stmt->fetchAll();
    $statsData = $stats->fetch();
} elseif ($hasUsuarioId) {
    $stmt = $db->prepare("SELECT h.*, c.patente, c.por_hora FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion WHERE h.usuario_id = ? ORDER BY h.fecha DESC LIMIT 50");
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
<?php if ($kmHabilitado): ?>
<button onclick="resetModal(); openModal('modalViaje')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Cargar KM
</button>
<?php endif; ?>
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
<?php if ($kmHabilitado): ?>
<th class="px-4 py-3 font-label-caps text-[10px] text-right">KM SALIDA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-right">KM LLEGADA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-right">KM REC</th>
<?php endif; ?>
<th class="px-4 py-3 font-label-caps text-[10px] text-center">ESTADO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-center"></th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($viajes)): ?>
<tr><td colspan="<?= $kmHabilitado ? 9 : 6 ?>" class="px-4 py-8 text-center text-on-surface-variant">No hay viajes registrados</td></tr>
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
<?php if ($kmHabilitado): ?>
<td class="px-4 py-3 text-right font-data-mono whitespace-nowrap"><?= $v['por_hora'] ? number_format($v['hs_salida'] ?? 0, 1) : number_format($v['km_salida'], 0) ?></td>
<td class="px-4 py-3 text-right font-data-mono whitespace-nowrap"><?= $v['por_hora'] ? ($v['hs_llegada'] !== null ? number_format($v['hs_llegada'], 1) : '-') : ($v['km_llegada'] !== null ? number_format($v['km_llegada'], 0) : '-') ?></td>
<td class="px-4 py-3 text-right font-data-mono font-bold whitespace-nowrap"><?= $v['por_hora'] ? ($v['hs_recorridas'] !== null ? number_format($v['hs_recorridas'], 1) . ' hs' : '-') : ($v['km_recorridos'] !== null ? number_format($v['km_recorridos'], 0) . ' km' : '-') ?></td>
<?php endif; ?>
<td class="px-4 py-3 text-center whitespace-nowrap">
<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $badge ?>"><?= $estado ?></span>
</td>
        <td class="px-4 py-3 text-center whitespace-nowrap">
        <div class="flex gap-1 justify-center">
        <button onclick="verViaje(<?= $v['id_hoja'] ?>)" class="px-2 py-1 bg-sky-600 text-white rounded text-[10px] font-bold hover:bg-sky-700" title="Ver detalle">V</button>
        <?php if ($estado === 'abierto' && $kmHabilitado): ?>
        <button onclick="editarViaje(<?= $v['id_hoja'] ?>)" class="px-2 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Cerrar</button>
        <?php endif; ?>
        </div>
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
<?php if ($kmHabilitado): ?>
<div><span class="text-on-surface-variant"><?= $v['por_hora'] ? 'HS Salida:' : 'KM Salida:' ?></span> <?= $v['por_hora'] ? number_format($v['hs_salida'] ?? 0, 1) : number_format($v['km_salida'], 0) ?></div>
<div><span class="text-on-surface-variant"><?= $v['por_hora'] ? 'HS Llegada:' : 'KM Llegada:' ?></span> <?= $v['por_hora'] ? ($v['hs_llegada'] !== null ? number_format($v['hs_llegada'], 1) : '-') : ($v['km_llegada'] !== null ? number_format($v['km_llegada'], 0) : '-') ?></div>
<?php endif; ?>
</div>
<?php if ($kmHabilitado): ?>
<div class="flex justify-between items-center">
<div><span class="text-on-surface-variant text-xs"><?= $v['por_hora'] ? 'Horas Recorridas:' : 'KM Recorridos:' ?></span> <span class="font-bold"><?= $v['por_hora'] ? ($v['hs_recorridas'] !== null ? number_format($v['hs_recorridas'], 1) . ' hs' : '-') : ($v['km_recorridos'] !== null ? number_format($v['km_recorridos'], 0) : '-') ?></span></div>
<?php endif; ?>
<button onclick="verViaje(<?= $v['id_hoja'] ?>)" class="px-2 py-1 bg-sky-600 text-white rounded text-[10px] font-bold hover:bg-sky-700" title="Ver detalle">V</button>
<?php if ($estado === 'abierto' && $kmHabilitado): ?>
<button onclick="editarViaje(<?= $v['id_hoja'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Cerrar</button>
<?php endif; ?>
<?php if ($kmHabilitado): ?>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</main>

<!-- Modal Ver Viaje -->
<div id="modalVerViaje" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalVerViaje')">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto modal-body shadow-2xl animate-fadeIn">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Detalle del Viaje</h3>
<button onclick="closeModal('modalVerViaje')"><span class="material-symbols-outlined">close</span></button>
</div>
<div class="p-6 space-y-4" id="verViajeContent">
<div class="flex justify-center py-8">
<span class="material-symbols-outlined animate-spin text-outline">progress_activity</span>
</div>
</div>
<div class="p-6 pt-0">
<button type="button" onclick="closeModal('modalVerViaje')" class="w-full border border-outline text-primary py-2 rounded-lg font-bold hover:bg-surface-container-high transition-colors">Cerrar</button>
</div>
</div>
</div>

<style>
@keyframes fadeIn { from { opacity:0; transform:scale(0.95); } to { opacity:1; transform:scale(1); } }
.animate-fadeIn { animation: fadeIn 0.2s ease-out; }
</style>

<?php if ($kmHabilitado): ?>
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
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nro. Hoja de Ruta</label>
<input name="nro_hoja_ruta" id="viajeNroHoja" type="text" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: 1234"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Ayudante (opcional)</label>
<select name="ayudante_id" id="viajeAyudante" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Sin ayudante</option>
<?php foreach ($choferesAyudantes as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>"><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre'] . ' - ' . $ch['dni']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="viajeCamion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required onchange="calcTaraTotalCh()">
<option value="">Seleccionar...</option>
<?php foreach ($camionesList as $c): ?>
<option value="<?= $c['id_camion'] ?>" data-ultimo-km="<?= $ultimoKmPorCamion[$c['id_camion']] ?? '' ?>" data-tara="<?= $c['tara'] ?? 0 ?>" data-por-hora="<?= $c['por_hora'] ?>"><?= htmlspecialchars($c['patente'] . ' - ' . $c['marca'] . ' ' . $c['modelo']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cachapé (opcional)</label>
<select name="cachape_id" id="viajeCachape" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" onchange="calcTaraTotalCh()">
<option value="">Sin cachapé</option>
<?php foreach ($cachapesList as $ca): ?>
<option value="<?= $ca['id_camion'] ?>" data-tara="<?= $ca['tara'] ?>"><?= htmlspecialchars($ca['patente'] . ' - ' . $ca['marca'] . ' ' . $ca['modelo'] . ($ca['tara'] ? ' (Tara: ' . $ca['tara'] . 'kg)' : '')) ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha</label>
<input type="date" name="fecha" id="viajeFecha" value="<?= date('Y-m-d') ?>" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="grid grid-cols-2 gap-4" id="kmFields">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Salida</label>
<input type="number" step="0.1" name="km_salida" id="viajeKmSalida" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Llegada <span class="text-on-surface-variant text-[10px]">(opcional, dejar vacio)</span></label>
<input type="number" step="0.1" name="km_llegada" id="viajeKmLlegada" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" oninput="calcKmRec()"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4 hidden" id="hsFields">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">HS Salida (Horas)</label>
<input type="number" step="0.1" name="hs_salida" id="viajeHsSalida" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">HS Llegada (Horas) <span class="text-on-surface-variant text-[10px]">(opcional, dejar vacio)</span></label>
<input type="number" step="0.1" name="hs_llegada" id="viajeHsLlegada" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" oninput="calcKmRec()"/>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tara (KG)</label>
<input name="tara" id="viajeTara" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" oninput="calcPesoTotal()"/>
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
<div class="grid grid-cols-2 gap-4">
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
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Observaciones</label>
<textarea name="observaciones" id="viajeObs" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low resize-none"></textarea>
</div>
<button type="submit" class="w-full bg-primary text-on-primary py-3 rounded-lg font-bold hover:opacity-90 transition-opacity">Registrar Viaje</button>
</form>
</div>
</div>
<?php endif; ?>

<script>
var editandoId = null;

function htmlspecialchars(str) {
var div = document.createElement('div');
div.appendChild(document.createTextNode(str || ''));
return div.innerHTML;
}

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function verViaje(id) {
function valor(v) { return (v !== null && v !== undefined && v !== '') ? v : '-'; }
function siHay(v, sufijo) { return (v !== null && v !== undefined && v !== '') ? Number(v).toLocaleString('es-ES') + ' ' + sufijo : '-'; }
fetch('<?= BASE_URL ?>/api/get_data.php?action=viaje_detalle&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_hoja) { alert('Error: No se pudieron obtener los datos'); return; }
var html = '<div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Hoja de Ruta</span><span class="font-bold">' + valor(data.nro_hoja_ruta) + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Fecha</span><span>' + (data.fecha ? data.fecha.split(' ')[0].split('-').reverse().join('/') : '-') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Chofer</span><span>' + htmlspecialchars(valor(data.chofer_apellido + ', ' + data.chofer_nombre)) + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Ayudante</span><span>' + (data.ayudante_nombre ? htmlspecialchars(data.ayudante_apellido + ', ' + data.ayudante_nombre) : '-') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Camion</span><span class="font-bold">' + valor(data.patente) + ' ' + (data.marca || '') + ' ' + (data.modelo || '') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Cachapé</span><span>' + (data.cachape_patente || '-') + '</span></div>';
if (data.por_hora) {
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">HS Salida</span><span class="font-data-mono">' + siHay(data.hs_salida, 'hs') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">HS Llegada</span><span class="font-data-mono">' + (data.hs_llegada !== null && data.hs_llegada !== undefined && data.hs_llegada !== '' ? Number(data.hs_llegada).toLocaleString('es-ES', {minimumFractionDigits:1,maximumFractionDigits:1}) + ' hs' : '-') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">HS Recorridas</span><span class="font-data-mono font-bold text-primary">' + siHay(data.hs_recorridas, 'hs') + '</span></div>';
} else {
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">KM Salida</span><span class="font-data-mono">' + siHay(data.km_salida, 'km') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">KM Llegada</span><span class="font-data-mono">' + (data.km_llegada !== null && data.km_llegada !== undefined && data.km_llegada !== '' ? Number(data.km_llegada).toLocaleString('es-ES') + ' km' : '-') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">KM Recorridos</span><span class="font-data-mono font-bold text-primary">' + siHay(data.km_recorridos, 'km') + '</span></div>';
}
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Tara</span><span class="font-data-mono">' + siHay(data.tara, 'kg') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Peso Carga</span><span class="font-data-mono">' + siHay(data.peso_carga, 'kg') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Peso Total</span><span class="font-data-mono font-bold text-primary">' + siHay(data.peso_total, 'kg') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Origen</span><span>' + valor(data.origen) + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Destino</span><span>' + valor(data.destino) + '</span></div>';
html += '<div class="col-span-2"><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Observaciones</span><span class="italic">' + (data.observaciones ? htmlspecialchars(data.observaciones) : 'Sin observaciones') + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Estado</span><span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ' + (data.estado === 'aprobado' ? 'bg-green-100 text-green-800' : data.estado === 'cerrado' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') + '">' + valor(data.estado) + '</span></div>';
html += '<div><span class="font-label-caps text-[10px] text-on-surface-variant uppercase block">Fecha Cierre</span><span>' + (data.fecha_cierre ? data.fecha_cierre.split('.')[0] : '-') + '</span></div>';
html += '</div>';
document.getElementById('verViajeContent').innerHTML = html;
openModal('modalVerViaje');
}).catch(err => { alert('Error al cargar datos: ' + err.message); });
}

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
        kmFields.classList.add('hidden');
        hsFields.classList.remove('hidden');
        kmSalida.removeAttribute('required');
        hsSalida.setAttribute('required', 'required');
        calcLabel.innerText = 'Horas Recorridas';
    } else {
        kmFields.classList.remove('hidden');
        hsFields.classList.add('hidden');
        hsSalida.removeAttribute('required');
        kmSalida.setAttribute('required', 'required');
        calcLabel.innerText = 'KM Recorridos';
    }
    calcKmRec();
}

function calcTaraTotalCh() {
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

function calcKmRec() {
    var camionSel = document.getElementById('viajeCamion');
    var opt = camionSel ? camionSel.options[camionSel.selectedIndex] : null;
    var porHora = opt && opt.getAttribute('data-por-hora') == '1';
    
    if (porHora) {
        const s = parseFloat(document.getElementById('viajeHsSalida').value) || 0;
        const l = parseFloat(document.getElementById('viajeHsLlegada').value) || 0;
        document.getElementById('viajeKmRec').innerText = (l - s).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' hs';
    } else {
        const s = parseFloat(document.getElementById('viajeKmSalida').value) || 0;
        const l = parseFloat(document.getElementById('viajeKmLlegada').value) || 0;
        document.getElementById('viajeKmRec').innerText = (l - s).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' km';
    }
}

function resetModal() {
    editandoId = null;
    document.getElementById('viajeAction').value = 'create';
    document.getElementById('viajeId').value = '';
    document.getElementById('viajeNroHoja').value = '';
    document.getElementById('viajeKmSalida').value = '';
    document.getElementById('viajeKmLlegada').value = '';
    document.getElementById('viajeHsSalida').value = '';
    document.getElementById('viajeHsLlegada').value = '';
    document.getElementById('viajeCachape').value = '';
    document.getElementById('viajeAyudante').value = '';
    document.getElementById('viajeTara').value = '';
    document.getElementById('viajePesoCarga').value = '';
    document.getElementById('viajePesoTotal').value = '';
    document.getElementById('viajeKmRec').innerText = '0 km';
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
    toggleFieldsByCamion();
}

function editarViaje(id) {
    fetch('<?= BASE_URL ?>/api/get_data.php?action=viaje&id=' + id)
    .then(r => r.json()).then(data => {
        if (!data || !data.id_hoja) { alert('Error al obtener datos'); return; }
        editandoId = id;
        document.getElementById('viajeAction').value = 'update';
        document.getElementById('viajeId').value = data.id_hoja;
        document.getElementById('viajeNroHoja').value = data.nro_hoja_ruta || '';
        document.getElementById('viajeCamion').value = data.id_camion;
        document.getElementById('viajeFecha').value = data.fecha;
        document.getElementById('viajeKmSalida').value = data.km_salida;
        document.getElementById('viajeKmLlegada').value = data.km_llegada || '';
        document.getElementById('viajeHsSalida').value = data.hs_salida || '';
        document.getElementById('viajeHsLlegada').value = data.hs_llegada || '';
        document.getElementById('viajeCachape').value = data.cachape_id || '';
        document.getElementById('viajeAyudante').value = data.ayudante_id || '';
        document.getElementById('viajeTara').value = data.tara || '';
        document.getElementById('viajePesoCarga').value = data.peso_carga || '';
        document.getElementById('viajePesoTotal').value = data.peso_total || '';
        toggleFieldsByCamion();
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
    const kmLlegada = document.getElementById('viajeKmLlegada');
    const hsSalida = document.getElementById('viajeHsSalida');
    const hsLlegada = document.getElementById('viajeHsLlegada');
    if (camionSelect) {
        camionSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const km = opt ? opt.getAttribute('data-ultimo-km') : '';
            if (km && !editandoId) kmSalida.value = km;
            calcTaraTotalCh();
            toggleFieldsByCamion();
        });
    }
    if (kmSalida) kmSalida.addEventListener('input', calcKmRec);
    if (kmLlegada) kmLlegada.addEventListener('input', calcKmRec);
    if (hsSalida) hsSalida.addEventListener('input', calcKmRec);
    if (hsLlegada) hsLlegada.addEventListener('input', calcKmRec);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
