<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('vehiculos_ver');
$pageTitle = 'Gestion de Vehiculos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

// Asegurar que las columnas existan por si no corrió la sincronización
foreach (['vtv DATE NULL', 'tara DECIMAL(10,2) NULL', 'proximo_mantenimiento_km DECIMAL(12,2) NULL', 'proximo_mantenimiento_hs DECIMAL(10,2) NULL', "tipo VARCHAR(50) DEFAULT 'camion'", "foto VARCHAR(255) NULL", "empresa_id INT DEFAULT NULL", "por_hora TINYINT(1) DEFAULT 0"] as $col) {
    try { $db->exec("ALTER TABLE camiones ADD COLUMN $col"); } catch (Exception $e) {}
}

// Upload photo
if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $idCamion = (int)($_POST['id_camion_foto'] ?? 0);
    if ($idCamion) {
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','bmp'];
        if (in_array($ext, $allowed)) {
            $filename = 'vehiculo_' . $idCamion . '_' . time() . '.' . $ext;
            $destino = __DIR__ . '/../assets/uploads/vehiculos/' . $filename;
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $destino)) {
                // Borrar foto anterior
                $old = $db->prepare("SELECT foto FROM camiones WHERE id_camion = ?");
                $old->execute([$idCamion]);
                $oldFoto = $old->fetchColumn();
                if ($oldFoto && file_exists(__DIR__ . '/../assets/uploads/vehiculos/' . $oldFoto)) {
                    unlink(__DIR__ . '/../assets/uploads/vehiculos/' . $oldFoto);
                }
                $db->prepare("UPDATE camiones SET foto = ? WHERE id_camion = ?")->execute([$filename, $idCamion]);
                $mensaje = 'Foto actualizada';
            } else {
                $error = 'Error al subir la foto';
            }
        } else {
            $error = 'Formato no permitido (usar: jpg, png, gif, webp)';
        }
    }
}

// Delete photo
if (isset($_POST['action']) && $_POST['action'] === 'delete_foto') {
    $id = (int)($_POST['id_camion'] ?? 0);
    $stmt = $db->prepare("SELECT foto FROM camiones WHERE id_camion = ?");
    $stmt->execute([$id]);
    $foto = $stmt->fetchColumn();
    if ($foto && file_exists(__DIR__ . '/../assets/uploads/vehiculos/' . $foto)) {
        unlink(__DIR__ . '/../assets/uploads/vehiculos/' . $foto);
    }
    $db->prepare("UPDATE camiones SET foto = NULL WHERE id_camion = ?")->execute([$id]);
    $mensaje = 'Foto eliminada';
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
        $tipo = $_POST['tipo'] ?? 'camion';
        $empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
        $por_hora = isset($_POST['por_hora']) ? 1 : 0;
        $horas_actuales = !empty($_POST['horas_actuales']) ? (float)$_POST['horas_actuales'] : 0;

        if ($action === 'create') {
            try {
                $stmt = $db->prepare("INSERT INTO camiones (patente, marca, modelo, anio, kilometraje_actual, horas_actuales, capacidad_tanque, vtv, tara, proximo_mantenimiento_km, proximo_mantenimiento_hs, estado, tipo, empresa_id, por_hora) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$patente, $marca, $modelo, $anio, $kilometraje, $horas_actuales, $capacidad, $vtv, $tara, $proxKm, $proxHs, $estado, $tipo, $empresa_id, $por_hora]);
                $idCamion = $db->lastInsertId();
                if ($vtv) {
                    $db->prepare("UPDATE alertas SET resuelta = 1 WHERE tipo = 'vencimiento_vtv' AND id_referencia = ? AND resuelta = 0")->execute([$idCamion]);
                }
                registrarAuditoria(getCurrentUserId(), 'create', 'camiones', $idCamion, "Creo vehiculo $patente");
                $mensaje = 'Vehiculo creado exitosamente';
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        } else {
            $id = (int)($_POST['id_camion'] ?? 0);
            try {
                $stmt = $db->prepare("UPDATE camiones SET patente=?, marca=?, modelo=?, anio=?, kilometraje_actual=?, horas_actuales=?, capacidad_tanque=?, vtv=?, tara=?, proximo_mantenimiento_km=?, proximo_mantenimiento_hs=?, estado=?, tipo=?, empresa_id=?, por_hora=? WHERE id_camion=?");
                $stmt->execute([$patente, $marca, $modelo, $anio, $kilometraje, $horas_actuales, $capacidad, $vtv, $tara, $proxKm, $proxHs, $estado, $tipo, $empresa_id, $por_hora, $id]);
                if ($vtv) {
                    $db->prepare("UPDATE alertas SET resuelta = 1 WHERE tipo = 'vencimiento_vtv' AND id_referencia = ? AND resuelta = 0")->execute([$id]);
                }
                registrarAuditoria(getCurrentUserId(), 'update', 'camiones', $id, "Actualizo vehiculo $patente");
                $mensaje = 'Vehiculo actualizado exitosamente';
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_camion'] ?? 0);
        try {
            // Borrar foto asociada
            $stmt = $db->prepare("SELECT foto FROM camiones WHERE id_camion = ?");
            $stmt->execute([$id]);
            $foto = $stmt->fetchColumn();
            if ($foto && file_exists(__DIR__ . '/../assets/uploads/vehiculos/' . $foto)) {
                unlink(__DIR__ . '/../assets/uploads/vehiculos/' . $foto);
            }
            $stmt = $db->prepare("DELETE FROM camiones WHERE id_camion = ?");
            $stmt->execute([$id]);
            registrarAuditoria(getCurrentUserId(), 'delete', 'camiones', $id, "Elimino camion ID $id");
            $mensaje = 'Vehiculo eliminado';
        } catch (Exception $e) {
            $error = 'No se puede eliminar el vehiculo, tiene registros asociados';
        }
    }
}

$buscar = $_GET['buscar'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';

$sql = "SELECT c.*, e.nombre as empresa_nombre, (SELECT COUNT(*) FROM asignaciones WHERE id_camion = c.id_camion AND activa = 1) as asignado,
    (SELECT GROUP_CONCAT(CONCAT(ch.apellido, ', ', ch.nombre) SEPARATOR ' | ') FROM asignaciones a JOIN choferes ch ON a.id_chofer = ch.id_chofer WHERE a.id_camion = c.id_camion AND a.activa = 1) as choferes_asignados
    FROM camiones c LEFT JOIN empresas e ON c.empresa_id = e.id_empresa WHERE 1=1";
$params = [];
if ($buscar) {
    $sql .= " AND (c.patente LIKE ? OR c.marca LIKE ? OR c.modelo LIKE ? OR c.id_camion IN (SELECT a.id_camion FROM asignaciones a JOIN choferes ch ON a.id_chofer = ch.id_chofer WHERE a.activa = 1 AND CONCAT(ch.nombre, ' ', ch.apellido, ' ', ch.apellido, ', ', ch.nombre, ' ', ch.dni) LIKE ?))";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
if ($filtro_estado) {
    $sql .= " AND c.estado = ?";
    $params[] = $filtro_estado;
}
$sql .= " ORDER BY c.created_at DESC";
$camiones = $db->prepare($sql);
$camiones->execute($params);
$camionesList = $camiones->fetchAll();
$empresasList = $db->query("SELECT id_empresa, nombre FROM empresas WHERE activo = 1 ORDER BY nombre")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Vehiculos</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Control centralizado de activos y disponibilidad de flota.</p>
</div>
<button onclick="resetModalCamion(); openModal('modalCamion')" class="btn-modern bg-primary text-on-primary px-6 py-3 rounded-xl font-bold flex items-center gap-2">
<span class="material-symbols-outlined">add</span> Nuevo Vehiculo
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filters -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex flex-col md:flex-row items-center gap-4 mb-8 card-modern">
<div class="relative w-full md:flex-1">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input id="searchInput" onkeyup="filterTable()" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por patente, marca, modelo o chofer..." type="text"/>
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
<div class="camion-card bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden hover:border-primary transition-all" data-search="<?= strtolower(htmlspecialchars($camion['patente'] . ' ' . $camion['marca'] . ' ' . $camion['modelo'] . ' ' . ($tipoLabels[$camion['tipo'] ?? 'camion'] ?? '') . ' ' . ($camion['choferes_asignados'] ?? ''))) ?>" data-estado="<?= $camion['estado'] ?>">
<?php
$tipoIconos = [
    'camion' => 'local_shipping',
    'semi' => 'local_shipping',
    'camioneta' => 'local_shipping',
    'tanque_cisterna' => 'local_shipping',
    'auto' => 'directions_car',
    'autoelevador' => 'forklift',
    'maquina' => 'precision_manufacturing',
    'grua_prensa' => 'crane',
    'moto' => 'motorcycle',
    'cachape' => 'local_shipping',
];
$tipoLabels = [
    'camion' => 'Camión',
    'semi' => 'Semi',
    'camioneta' => 'Camioneta',
    'tanque_cisterna' => 'Tanque / Cisterna',
    'auto' => 'Auto',
    'autoelevador' => 'Autoelevador',
    'maquina' => 'Máquina',
    'grua_prensa' => 'Grúa/Prensa',
    'moto' => 'Moto',
    'cachape' => 'Cachapé',
];
$tipo = $camion['tipo'] ?? 'camion';
$icono = $tipoIconos[$tipo] ?? 'local_shipping';
$tipoLabel = $tipoLabels[$tipo] ?? $tipo;
?>
<div class="h-48 bg-surface-container-high flex items-center justify-center relative group overflow-hidden">
<?php if (!empty($camion['foto'])): ?>
<img src="<?= BASE_URL ?>/assets/uploads/vehiculos/<?= htmlspecialchars($camion['foto']) ?>" alt="Foto" class="w-full h-full object-cover"/>
<?php else: ?>
<span class="material-symbols-outlined text-6xl text-on-surface-variant"><?= $icono ?></span>
<?php endif; ?>
<div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-all flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100">
<form method="POST" enctype="multipart/form-data" id="fotoForm_<?= $camion['id_camion'] ?>" class="inline">
<input type="hidden" name="id_camion_foto" value="<?= $camion['id_camion'] ?>"/>
<label class="cursor-pointer bg-white/90 hover:bg-white text-primary rounded-full w-10 h-10 flex items-center justify-center shadow-lg transition-all hover:scale-110" for="fotoInput_<?= $camion['id_camion'] ?>">
<span class="material-symbols-outlined text-lg">camera_alt</span>
</label>
<input type="file" name="foto" id="fotoInput_<?= $camion['id_camion'] ?>" accept="image/*" class="hidden" onchange="document.getElementById('fotoForm_<?= $camion['id_camion'] ?>').submit()"/>
</form>
<?php if (!empty($camion['foto'])): ?>
<form method="POST" class="inline" onsubmit="return confirm('Eliminar foto?')">
<input type="hidden" name="action" value="delete_foto"/>
<input type="hidden" name="id_camion" value="<?= $camion['id_camion'] ?>"/>
<button type="submit" class="bg-white/90 hover:bg-white text-red-500 rounded-full w-10 h-10 flex items-center justify-center shadow-lg transition-all hover:scale-110">
<span class="material-symbols-outlined text-lg">delete</span>
</button>
</form>
<?php endif; ?>
</div>
</div>
<div class="p-6">
<div class="flex justify-between items-start mb-4">
<div>
<h3 class="font-headline-sm text-headline-sm text-primary"><?= htmlspecialchars($camion['marca']) ?> <?= htmlspecialchars($camion['modelo']) ?></h3>
<p class="font-body-md text-on-surface-variant">Patente: <span class="font-bold text-primary"><?= htmlspecialchars($camion['patente']) ?></span></p>
<?php if ($camion['empresa_nombre']): ?><p class="text-xs text-on-surface-variant mt-1"><span class="material-symbols-outlined text-[14px] align-text-bottom">business</span> <?= htmlspecialchars($camion['empresa_nombre']) ?></p><?php endif; ?>
<?php if ($camion['choferes_asignados']): ?><p class="text-xs text-on-surface-variant mt-1"><span class="material-symbols-outlined text-[14px] align-text-bottom">person</span> <?= htmlspecialchars($camion['choferes_asignados']) ?></p><?php endif; ?>
</div>
<div class="flex flex-col items-end gap-1">
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase border border-<?= $est['color'] ?>-200 bg-<?= $est['color'] ?>-100 text-<?= $est['color'] ?>-800"><?= $est['text'] ?></span>
<span class="text-[10px] font-medium text-on-surface-variant"><?= $tipoLabel ?></span>
</div>
</div>
<div class="grid grid-cols-3 gap-2 mb-6">
<div class="bg-surface-container-low p-3 rounded-lg">
<p class="text-[10px] font-label-caps text-on-surface-variant uppercase">KM / HS</p>
<p class="font-data-mono text-primary text-sm">
    <?= number_format($camion['kilometraje_actual'], 0) ?> KM
    <?php if ($camion['por_hora']): ?>
        / <?= number_format($camion['horas_actuales'], 1) ?> HS
    <?php endif; ?>
</p>
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

<!-- Modal Nuevo/Editar Vehiculo -->
<div id="modalCamion" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" style="animation: modalFadeIn 0.2s ease;">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalCamionTitle" class="font-headline-sm text-headline-sm text-primary">Nuevo Vehiculo</h3>
<button onclick="closeModal('modalCamion')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="camionAction" value="create"/>
<input type="hidden" name="id_camion" id="camionId" value=""/>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Patente</label>
<input name="patente" id="campatente" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Marca</label>
<input name="marca" id="cammarca" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Modelo</label>
<input name="modelo" id="cammodelo" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Anio</label>
<input name="anio" type="number" id="camanio" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label id="camkmLabel" class="font-label-caps text-label-caps text-on-surface-variant uppercase">Kilometraje</label>
<input name="kilometraje_actual" type="number" step="0.01" id="camkm" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/>
</div>
<div class="flex flex-col gap-1 hidden" id="camhorasContainer">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Horas Actuales (Horómetro)</label>
<input name="horas_actuales" type="number" step="0.01" id="camhoras" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/>
</div>
<div class="flex flex-col gap-1" id="camtanqueContainer">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cap. Tanque (L)</label>
<input name="capacidad_tanque" type="number" step="0.01" id="camtanque" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">VTV (Vencimiento)</label>
<input name="vtv" type="date" id="camvtv" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/>
</div>
    <div class="flex flex-col gap-1">
    <label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tara (KG)</label>
    <input name="tara" type="number" step="0.01" id="camtara" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Prox. Mant. (KM)</label>
<input name="proximo_mantenimiento_km" type="number" step="0.01" id="camproxkm" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" placeholder="KM para el proximo servicio"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Prox. Mant. (HS)</label>
<input name="proximo_mantenimiento_hs" type="number" step="0.01" id="camproxhs" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" placeholder="Horas para el proximo servicio"/>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Tipo</label>
<select name="tipo" id="camtipo" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none">
<option value="camion">Camión</option>
<option value="semi">Semi</option>
<option value="camioneta">Camioneta</option>
<option value="tanque_cisterna">Tanque / Cisterna</option>
<option value="auto">Auto</option>
<option value="autoelevador">Autoelevador</option>
<option value="maquina">Máquina</option>
<option value="grua_prensa">Grúa/Prensa</option>
<option value="moto">Moto</option>
<option value="cachape">Cachapé</option>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Empresa</label>
<select name="empresa_id" id="camepresa" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none">
<option value="">Sin empresa</option>
<?php foreach ($empresasList as $emp): ?>
<option value="<?= $emp['id_empresa'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Estado</label>
<select name="estado" id="camestado" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none">
<option value="activo">Activo</option>
<option value="mantenimiento">En Mantenimiento</option>
<option value="fuera_de_servicio">Fuera de Servicio</option>
</select>
</div>
<div class="flex items-center gap-2 pt-2">
<input type="checkbox" name="por_hora" id="camporhora" value="1" class="rounded border-outline-variant text-primary focus:ring-primary h-5 w-5 bg-surface-container-low"/>
<label for="camporhora" class="font-label-caps text-label-caps text-on-surface-variant uppercase cursor-pointer">Control por Horas (Horómetro)</label>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalCamion')" class="flex-1 border border-outline text-primary py-2.5 rounded-xl font-bold hover:bg-surface-container-low transition-all">Cancelar</button>
<button type="submit" class="btn-modern flex-1 bg-primary text-on-primary py-2.5 rounded-xl font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Asignar -->
<div id="modalAsignar" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-md modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Asignar Chofer</h3>
<button onclick="closeModal('modalAsignar')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" action="<?= BASE_URL ?>/admin/asignar_chofer.php" class="p-6 space-y-4">
<input type="hidden" name="id_camion" id="asignarCamionId" value=""/>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Chofer</label>
<select name="id_chofer" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required>
<option value="">Seleccione un chofer...</option>
<?php $choferesActivos = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado='activo' ORDER BY apellido")->fetchAll(); ?>
<?php foreach ($choferesActivos as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>"><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre'] . ' - ' . $ch['dni']) ?></option>
<?php endforeach; ?>
</select>
</div>
<button type="submit" class="btn-modern w-full bg-primary text-on-primary py-2.5 rounded-xl font-bold">Asignar Chofer</button>
</form>
</div>
</div>

<!-- Modal Historial -->
<div id="modalHistorial" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Historial del Vehiculo</h3>
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
alert('Error: No se pudieron obtener los datos del vehiculo');
return;
}
document.getElementById('camionAction').value = 'update';
document.getElementById('camionId').value = data.id_camion;
document.getElementById('campatente').value = data.patente;
document.getElementById('cammarca').value = data.marca;
document.getElementById('cammodelo').value = data.modelo;
document.getElementById('camanio').value = data.anio;
document.getElementById('camkm').value = data.kilometraje_actual;
document.getElementById('camhoras').value = data.horas_actuales || '';
document.getElementById('camtanque').value = data.capacidad_tanque;
document.getElementById('camvtv').value = data.vtv || '';
document.getElementById('camtara').value = data.tara || '';
document.getElementById('camproxkm').value = data.proximo_mantenimiento_km || '';
document.getElementById('camproxhs').value = data.proximo_mantenimiento_hs || '';
document.getElementById('camtipo').value = data.tipo || 'camion';
document.getElementById('camepresa').value = data.empresa_id || '';
document.getElementById('camestado').value = data.estado;
document.getElementById('camporhora').checked = parseInt(data.por_hora) === 1;
updateCamionFields();
document.getElementById('modalCamionTitle').textContent = 'Editar Vehiculo';
openModal('modalCamion');
}).catch(err => {
alert('Error al cargar datos del vehiculo: ' + err.message);
});
}

const camporhora = document.getElementById('camporhora');
const camkmLabel = document.getElementById('camkmLabel');
const camkm = document.getElementById('camkm');

function updateCamionFields() {
    var horasContainer = document.getElementById('camhorasContainer');
    var camkmLabel = document.getElementById('camkmLabel');
    if (camporhora && camporhora.checked) {
        if (camkmLabel) camkmLabel.textContent = 'Kilometraje Actual';
        if (horasContainer) horasContainer.classList.remove('hidden');
    } else {
        if (camkmLabel) camkmLabel.textContent = 'Kilometraje';
        if (horasContainer) {
            horasContainer.classList.add('hidden');
            document.getElementById('camhoras').value = '';
        }
    }
}

if (camporhora) {
    camporhora.addEventListener('change', updateCamionFields);
}

function resetModalCamion() {
document.getElementById('camionAction').value = 'create';
document.getElementById('camionId').value = '';
document.getElementById('campatente').value = '';
document.getElementById('cammarca').value = '';
document.getElementById('cammodelo').value = '';
document.getElementById('camanio').value = '';
document.getElementById('camkm').value = '';
document.getElementById('camhoras').value = '';
document.getElementById('camtanque').value = '';
document.getElementById('camvtv').value = '';
document.getElementById('camtara').value = '';
document.getElementById('camproxkm').value = '';
document.getElementById('camproxhs').value = '';
document.getElementById('camtipo').value = 'camion';
document.getElementById('camepresa').value = '';
document.getElementById('camestado').value = 'activo';
document.getElementById('camporhora').checked = false;
updateCamionFields();
document.getElementById('modalCamionTitle').textContent = 'Nuevo Vehiculo';
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
showConfirm('¿Eliminar este vehiculo?', function() {
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


</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
