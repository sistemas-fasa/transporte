<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('mantenimiento_ver');

if (isset($_GET['print']) && $_GET['print'] == 1) {
    $db = getDB();
    $pid = (int)($_GET['camion_rt'] ?? 0);
    try { $camion = $db->query("SELECT * FROM camiones WHERE id_camion = $pid")->fetch(); } catch (Exception $e) { $camion = false; }
    $repCheck = [];
    $tarCheck = [];
    if ($camion) {
        try { $repCheck = $db->query("SELECT * FROM camion_repuestos WHERE id_camion = $pid ORDER BY nombre")->fetchAll(); } catch (Exception $e) {}
        try { $tarCheck = $db->query("SELECT * FROM camion_tareas WHERE id_camion = $pid ORDER BY nombre")->fetchAll(); } catch (Exception $e) {}
    }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Checklist Mantenimiento - <?= $camion ? htmlspecialchars($camion['patente']) : '' ?></title>
<style>
body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 24px; }
h1 { font-size: 18px; margin: 0 0 4px 0; }
.sub { color: #555; font-size: 12px; margin-bottom: 16px; }
.vehiculo { border: 1px solid #999; padding: 10px 14px; margin-bottom: 20px; border-radius: 6px; display: flex; gap: 16px; align-items: center; }
.vehiculo img { width: 90px; height: 60px; object-fit: cover; border: 1px solid #ccc; border-radius: 4px; }
.vehiculo .datos { font-size: 13px; }
.vehiculo .datos b { font-size: 15px; }
.section { margin-bottom: 22px; }
.section h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #333; padding-bottom: 4px; margin-bottom: 10px; }
.item { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px dotted #ccc; font-size: 13px; }
.item input[type=checkbox] { width: 16px; height: 16px; }
.item .cod { color: #666; font-family: monospace; font-size: 11px; margin-right: 4px; }
.item .extra { color: #888; font-size: 11px; margin-left: auto; }
.empty { color: #999; font-style: italic; font-size: 12px; padding: 6px 0; }
.firma { margin-top: 40px; display: flex; justify-content: space-between; font-size: 12px; }
.firma div { border-top: 1px solid #333; padding-top: 4px; width: 45%; text-align: center; }
@media print { body { margin: 12px; } }
</style>
</head>
<body>
<?php if (!$camion): ?>
<h1>Checklist de Mantenimiento</h1>
<div class="empty">Vehiculo no encontrado.</div>
<?php else: ?>
<h1>Checklist de Mantenimiento</h1>
<div class="sub"><?= date('d/m/Y') ?> - Emitido por el sistema</div>
<div class="vehiculo">
<?php if ($camion['foto']): ?>
<img src="<?= BASE_URL ?>/assets/uploads/vehiculos/<?= htmlspecialchars($camion['foto']) ?>" alt=""/>
<?php endif; ?>
<div class="datos">
<b><?= htmlspecialchars($camion['patente']) ?></b><br>
<?= htmlspecialchars($camion['marca'] . ' ' . $camion['modelo']) ?>
</div>
</div>

<div class="section">
<h2>Repuestos a Cambiar</h2>
<?php if (empty($repCheck)): ?>
<div class="empty">Sin repuestos asignados a este vehiculo.</div>
<?php else: foreach ($repCheck as $r): ?>
<div class="item"><input type="checkbox"/> <?php if ($r['codigo']): ?><span class="cod">[<?= htmlspecialchars($r['codigo']) ?>]</span><?php endif; ?><?= htmlspecialchars($r['nombre']) ?><?php if ($r['descripcion']): ?><span class="extra"><?= htmlspecialchars($r['descripcion']) ?></span><?php endif; ?></div>
<?php endforeach; endif; ?>
</div>

<div class="section">
<h2>Tareas a Realizar</h2>
<?php if (empty($tarCheck)): ?>
<div class="empty">Sin tareas asignadas a este vehiculo.</div>
<?php else: foreach ($tarCheck as $t): ?>
<div class="item"><input type="checkbox"/> <?= htmlspecialchars($t['nombre']) ?><?php if ($t['frecuencia']): ?><span class="extra"><?= htmlspecialchars($t['frecuencia']) ?></span><?php endif; ?><?php if ($t['km_intervalo'] > 0): ?><span class="extra">Cada <?= number_format($t['km_intervalo'], 0) ?> KM</span><?php endif; ?></div>
<?php endforeach; endif; ?>
</div>

<div class="firma">
<div>Mecanico</div>
<div>Encargado</div>
</div>
<?php endif; ?>

<script>window.onload = function() { window.print(); };</script>
</body>
</html>
<?php
    exit;
}

$pageTitle = 'Gestion de Mantenimiento';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

$vista = $_GET['vista'] ?? 'mantenimientos';
$buscar = $_GET['buscar'] ?? '';
$empresa_filter = isset($_GET['empresa']) ? (int)$_GET['empresa'] : 0;

// Crear tablas si no existen y verificar columnas faltantes (solo una vez)
try { $db->exec("CREATE TABLE IF NOT EXISTS camion_repuestos (id_repuesto INT AUTO_INCREMENT PRIMARY KEY, id_camion INT NOT NULL, codigo VARCHAR(50) DEFAULT NULL, nombre VARCHAR(200) NOT NULL, descripcion TEXT DEFAULT NULL, cantidad INT DEFAULT 1, costo_unitario DECIMAL(10,2) DEFAULT 0, created_at DATETIME DEFAULT NULL, INDEX idx_rep_camion (id_camion)) ENGINE=InnoDB"); } catch (Exception $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS camion_tareas (id_tarea INT AUTO_INCREMENT PRIMARY KEY, id_camion INT NOT NULL, nombre VARCHAR(200) NOT NULL, descripcion TEXT DEFAULT NULL, frecuencia VARCHAR(50) DEFAULT NULL, km_intervalo DECIMAL(10,2) DEFAULT NULL, created_at DATETIME DEFAULT NULL, INDEX idx_tarea_camion (id_camion)) ENGINE=InnoDB"); } catch (Exception $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS mantenimientos (id_mantenimiento INT AUTO_INCREMENT PRIMARY KEY, fecha DATE NOT NULL, id_camion INT NOT NULL, tipo VARCHAR(50) DEFAULT 'otro', taller VARCHAR(30) DEFAULT 'taller_fasa', descripcion TEXT, proveedor VARCHAR(200), costo DECIMAL(10,2) DEFAULT 0, kilometraje DECIMAL(12,2) DEFAULT 0, horas DECIMAL(12,2) DEFAULT NULL, proximo_mantenimiento_km DECIMAL(12,2) DEFAULT NULL, proximo_mantenimiento_hs DECIMAL(12,2) DEFAULT NULL, proximo_mantenimiento_fecha DATE DEFAULT NULL, foto_factura VARCHAR(255), id_usuario_registra INT, created_at DATETIME DEFAULT NULL, INDEX idx_mant_camion (id_camion)) ENGINE=InnoDB"); } catch (Exception $e) {}
$pedidosTableError = '';
try { $db->exec("CREATE TABLE IF NOT EXISTS pedidos_mantenimiento (id_pedido INT AUTO_INCREMENT PRIMARY KEY, id_camion INT NOT NULL, tipo VARCHAR(50) DEFAULT 'otro', prioridad VARCHAR(20) DEFAULT 'normal', titulo VARCHAR(200) DEFAULT NULL, descripcion TEXT, estado VARCHAR(20) DEFAULT 'pendiente', id_usuario_crea INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_pedido_camion (id_camion), INDEX idx_pedido_estado (estado)) ENGINE=InnoDB"); } catch (Exception $e) { $pedidosTableError = $e->getMessage(); error_log("CREATE TABLE pedidos_mantenimiento FAILED: " . $e->getMessage()); }
try { $db->exec("CREATE TABLE IF NOT EXISTS notificaciones (id_notificacion INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, mensaje TEXT NOT NULL, url VARCHAR(255) DEFAULT NULL, leida TINYINT(1) DEFAULT 0, tipo VARCHAR(50) DEFAULT 'info', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_notif_usuario (id_usuario, leida)) ENGINE=InnoDB"); } catch (Exception $e) {}
try { 
    $colsMant = []; 
    $r = $db->query("SHOW COLUMNS FROM mantenimientos"); 
    while ($row = $r->fetch()) { $colsMant[] = $row['Field']; } 
    if (!in_array('taller', $colsMant)) { $db->exec("ALTER TABLE mantenimientos ADD COLUMN taller VARCHAR(30) DEFAULT 'taller_fasa' AFTER tipo"); } 
    if (!in_array('horas', $colsMant)) { $db->exec("ALTER TABLE mantenimientos ADD COLUMN horas DECIMAL(12,2) DEFAULT NULL AFTER kilometraje"); } 
    if (!in_array('proximo_mantenimiento_hs', $colsMant)) { $db->exec("ALTER TABLE mantenimientos ADD COLUMN proximo_mantenimiento_hs DECIMAL(12,2) DEFAULT NULL AFTER proximo_mantenimiento_km"); } 
} catch (Exception $e) {}
try { $colsRep = []; $r = $db->query("SHOW COLUMNS FROM camion_repuestos"); while ($row = $r->fetch()) { $colsRep[] = $row['Field']; } if (!in_array('codigo', $colsRep)) { $db->exec("ALTER TABLE camion_repuestos ADD COLUMN codigo VARCHAR(50) DEFAULT NULL AFTER id_camion"); } } catch (Exception $e) {}
try { $colsPed = []; $r = $db->query("SHOW COLUMNS FROM pedidos_mantenimiento"); while ($row = $r->fetch()) { $colsPed[] = $row['Field']; } if (!in_array('prioridad', $colsPed)) { $db->exec("ALTER TABLE pedidos_mantenimiento ADD COLUMN prioridad VARCHAR(20) DEFAULT 'normal' AFTER tipo"); } } catch (Exception $e) {}
try { $colsPed2 = []; $r = $db->query("SHOW COLUMNS FROM pedidos_mantenimiento"); while ($row = $r->fetch()) { $colsPed2[] = $row['Field']; } if (!in_array('titulo', $colsPed2)) { $db->exec("ALTER TABLE pedidos_mantenimiento ADD COLUMN titulo VARCHAR(200) DEFAULT NULL AFTER prioridad"); } } catch (Exception $e) {}
try { $colsPed3 = []; $r = $db->query("SHOW COLUMNS FROM pedidos_mantenimiento"); while ($row = $r->fetch()) { $colsPed3[] = $row['Field']; } if (!in_array('tareas', $colsPed3)) { $db->exec("ALTER TABLE pedidos_mantenimiento ADD COLUMN tareas TEXT NULL AFTER descripcion"); } if (!in_array('respuesta', $colsPed3)) { $db->exec("ALTER TABLE pedidos_mantenimiento ADD COLUMN respuesta TEXT NULL AFTER tareas"); } } catch (Exception $e) {}
try { 
    $colsCam = []; 
    $r = $db->query("SHOW COLUMNS FROM camiones"); 
    while ($row = $r->fetch()) { $colsCam[] = $row['Field']; } 
    if (!in_array('proximo_mantenimiento_fecha', $colsCam)) { $db->exec("ALTER TABLE camiones ADD COLUMN proximo_mantenimiento_fecha DATE DEFAULT NULL"); } 
    if (!in_array('proximo_mantenimiento_hs', $colsCam)) { $db->exec("ALTER TABLE camiones ADD COLUMN proximo_mantenimiento_hs DECIMAL(10,2) DEFAULT NULL AFTER proximo_mantenimiento_km"); } 
} catch (Exception $e) {}

$empresasList = $db->query("SELECT id_empresa, nombre FROM empresas WHERE activo = 1 ORDER BY nombre")->fetchAll();
if (!$empresa_filter) {
    foreach ($empresasList as $emp) {
        if (stripos($emp['nombre'], 'avenida') !== false) { $empresa_filter = $emp['id_empresa']; break; }
    }
}

// Datos de repuestos/tareas (necesarios tambien para el modal de Nuevo Mantenimiento)
$repuestosPorCamion = [];
$tareasPorCamion = [];
try { foreach ($db->query("SELECT * FROM camion_repuestos ORDER BY id_camion, nombre")->fetchAll() as $r) { $repuestosPorCamion[$r['id_camion']][] = $r; } } catch (Exception $e) {}
try { foreach ($db->query("SELECT * FROM camion_tareas ORDER BY id_camion, nombre")->fetchAll() as $t) { $tareasPorCamion[$t['id_camion']][] = $t; } } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $id_camion = (int)($_POST['id_camion'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'otro';
        $taller = $_POST['taller'] ?? 'taller_fasa';
        $descripcion = trim($_POST['descripcion'] ?? '');
        $proveedor = trim($_POST['proveedor'] ?? '');
        $kilometraje = isset($_POST['kilometraje']) && $_POST['kilometraje'] !== '' ? (float)$_POST['kilometraje'] : null;
        $horas = isset($_POST['horas']) && $_POST['horas'] !== '' ? (float)$_POST['horas'] : null;
        $proximo_km = isset($_POST['proximo_mantenimiento_km']) && $_POST['proximo_mantenimiento_km'] !== '' ? (float)$_POST['proximo_mantenimiento_km'] : null;
        $proximo_hs = isset($_POST['proximo_mantenimiento_hs']) && $_POST['proximo_mantenimiento_hs'] !== '' ? (float)$_POST['proximo_mantenimiento_hs'] : null;
        $proximo_fecha = !empty($_POST['proximo_mantenimiento_fecha']) ? $_POST['proximo_mantenimiento_fecha'] : null;
        $rep_hechos = $_POST['rep_hechos'] ?? [];
        $tareas_hechas = $_POST['tareas_hechas'] ?? [];
        $detalles = [];
        if (!empty($rep_hechos)) {
            $repNombres = [];
            foreach ($rep_hechos as $rid) {
                foreach ($repuestosPorCamion[$id_camion] ?? [] as $r) {
                    if ($r['id_repuesto'] == $rid) { $repNombres[] = ($r['codigo'] ? '[' . $r['codigo'] . '] ' : '') . $r['nombre']; break; }
                }
            }
            $detalles[] = 'Repuestos: ' . implode(', ', $repNombres);
        }
        if (!empty($tareas_hechas)) {
            $tarNombres = [];
            foreach ($tareas_hechas as $tid) {
                foreach ($tareasPorCamion[$id_camion] ?? [] as $t) {
                    if ($t['id_tarea'] == $tid) { $tarNombres[] = $t['nombre']; break; }
                }
            }
            $detalles[] = 'Tareas: ' . implode(', ', $tarNombres);
        }
        if (!empty($detalles)) { $descripcion .= ($descripcion ? "\n" : '') . implode("\n", $detalles); }
        $foto_factura = null;
        if (isset($_FILES['foto_factura']) && $_FILES['foto_factura']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['foto_factura']['name'], PATHINFO_EXTENSION);
            $foto_factura = 'factura_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['foto_factura']['tmp_name'], __DIR__ . '/../assets/uploads/facturas/' . $foto_factura);
        }
        try {
            $stmt = $db->prepare("INSERT INTO mantenimientos (fecha, id_camion, tipo, taller, descripcion, proveedor, costo, kilometraje, horas, proximo_mantenimiento_km, proximo_mantenimiento_hs, proximo_mantenimiento_fecha, foto_factura, id_usuario_registra) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$fecha, $id_camion, $tipo, $taller, $descripcion, '', 0, $kilometraje, $horas, $proximo_km, $proximo_hs, $proximo_fecha, $foto_factura, getCurrentUserId()]);
            
            // Actualizar camión si corresponde
            if ($id_camion) {
                $camUpdates = [];
                $camParams = [];
                if ($kilometraje !== null && $kilometraje > 0) {
                    $camUpdates[] = "kilometraje_actual = GREATEST(COALESCE(kilometraje_actual,0), ?)";
                    $camParams[] = $kilometraje;
                }
                if ($horas !== null && $horas > 0) {
                    $camUpdates[] = "horas_actuales = GREATEST(COALESCE(horas_actuales,0), ?)";
                    $camParams[] = $horas;
                }
                if ($proximo_km !== null && $proximo_km > 0) {
                    $camUpdates[] = "proximo_mantenimiento_km = ?";
                    $camParams[] = $proximo_km;
                }
                if ($proximo_hs !== null && $proximo_hs > 0) {
                    $camUpdates[] = "proximo_mantenimiento_hs = ?";
                    $camParams[] = $proximo_hs;
                }
                if ($proximo_fecha) {
                    $camUpdates[] = "proximo_mantenimiento_fecha = ?";
                    $camParams[] = $proximo_fecha;
                }
                if (!empty($camUpdates)) {
                    $camParams[] = $id_camion;
                    $db->prepare("UPDATE camiones SET " . implode(", ", $camUpdates) . " WHERE id_camion=?")->execute($camParams);
                }
            }

            registrarAuditoria(getCurrentUserId(), 'create', 'mantenimientos', $db->lastInsertId(), "Registro mantenimiento para camion ID $id_camion");
            $mensaje = 'Mantenimiento registrado exitosamente';
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
} elseif ($action === 'pedido_create') {
    if (!empty($pedidosTableError)) { $error = 'No se pudo crear la tabla pedidos_mantenimiento: ' . $pedidosTableError; }
    $id_camion = (int)($_POST['id_camion'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'otro';
        $prioridad = $_POST['prioridad'] ?? 'normal';
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $estado = 'pendiente';
        if ($id_camion) {
            try {
                $db->prepare("INSERT INTO pedidos_mantenimiento (id_camion, tipo, prioridad, titulo, descripcion, estado, id_usuario_crea) VALUES (?,?,?,?,?,?,?)")->execute([$id_camion, $tipo, $prioridad, $titulo ?: null, $descripcion ?: null, $estado, getCurrentUserId()]);
                $id_pedido = $db->lastInsertId();
                registrarAuditoria(getCurrentUserId(), 'create', 'pedidos_mantenimiento', $id_pedido, "Creo pedido mantenimiento para camion ID $id_camion");
                
                // Notificar a usuarios con rol mantenimiento
                try {
                    $usuariosMant = $db->query("SELECT id_usuario FROM usuario_rol ur JOIN roles r ON ur.id_rol = r.id_rol WHERE r.nombre = 'mantenimiento'");
                    $mensajeNotif = "Nuevo pedido de mantenimiento: " . ($titulo ?: "Sin título") . " - Camión: " . ($db->query("SELECT patente FROM camiones WHERE id_camion = $id_camion")->fetch()['patente'] ?? '');
                    foreach ($usuariosMant as $um) {
                        $db->prepare("INSERT INTO notificaciones (id_usuario, mensaje, url, tipo) VALUES (?, ?, ?, 'pedido_mantenimiento')")->execute([$um['id_usuario'], $mensajeNotif, 'chofer/panel.php']);
                    }
                } catch (Exception $e) {}
                
                $mensaje = 'Pedido de mantenimiento enviado';
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'pedido_update') {
        $id_pedido = (int)($_POST['id_pedido'] ?? 0);
        $estado = $_POST['estado'] ?? 'pendiente';
        $descripcion = trim($_POST['descripcion'] ?? '');
        if ($id_pedido) {
            try {
                $db->prepare("UPDATE pedidos_mantenimiento SET estado = ?, descripcion = ? WHERE id_pedido = ?")->execute([$estado, $descripcion ?: null, $id_pedido]);
                $mensaje = 'Pedido actualizado';
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'repuesto_add') {
        $id_camion = (int)($_POST['id_camion'] ?? 0);
        $codigo = trim($_POST['codigo'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $cantidad = (int)($_POST['cantidad'] ?? 1);
        $costo = (float)($_POST['costo_unitario'] ?? 0);
        if ($id_camion && $nombre) {
            try {
                $db->prepare("INSERT INTO camion_repuestos (id_camion, codigo, nombre, descripcion, cantidad, costo_unitario, created_at) VALUES (?,?,?,?,?,?,NOW())")->execute([$id_camion, $codigo ?: null, $nombre, $descripcion ?: null, $cantidad, $costo]);
                $mensaje = 'Repuesto agregado';
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'repuesto_delete') {
        $id = (int)($_POST['id_repuesto'] ?? 0);
        try { $db->prepare("DELETE FROM camion_repuestos WHERE id_repuesto=?")->execute([$id]); $mensaje = 'Repuesto eliminado'; } catch (Exception $e) { $error = 'Error'; }
    } elseif ($action === 'repuesto_update') {
        $id = (int)($_POST['id_repuesto'] ?? 0);
        $codigo = trim($_POST['codigo'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $cantidad = (int)($_POST['cantidad'] ?? 1);
        $costo = (float)($_POST['costo_unitario'] ?? 0);
        if ($id && $nombre) {
            try {
                $db->prepare("UPDATE camion_repuestos SET codigo=?, nombre=?, descripcion=?, cantidad=?, costo_unitario=? WHERE id_repuesto=?")->execute([$codigo ?: null, $nombre, $descripcion ?: null, $cantidad, $costo, $id]);
                $mensaje = 'Repuesto actualizado';
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'tarea_add') {
        $id_camion = (int)($_POST['id_camion'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $frecuencia = trim($_POST['frecuencia'] ?? '');
        $km_intervalo = (float)($_POST['km_intervalo'] ?? 0);
        if ($id_camion && $nombre) {
            try {
                $db->prepare("INSERT INTO camion_tareas (id_camion, nombre, descripcion, frecuencia, km_intervalo, created_at) VALUES (?,?,?,?,?,NOW())")->execute([$id_camion, $nombre, $descripcion ?: null, $frecuencia ?: null, $km_intervalo ?: null]);
                $mensaje = 'Tarea agregada';
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'tarea_update') {
        $id = (int)($_POST['id_tarea'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $frecuencia = trim($_POST['frecuencia'] ?? '');
        $km_intervalo = (float)($_POST['km_intervalo'] ?? 0);
        if ($id && $nombre) {
            try {
                $db->prepare("UPDATE camion_tareas SET nombre=?, descripcion=?, frecuencia=?, km_intervalo=? WHERE id_tarea=?")->execute([$nombre, $descripcion ?: null, $frecuencia ?: null, $km_intervalo ?: null, $id]);
                $mensaje = 'Tarea actualizada';
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'tarea_delete') {
        $id = (int)($_POST['id_tarea'] ?? 0);
        try { $db->prepare("DELETE FROM camion_tareas WHERE id_tarea=?")->execute([$id]); $mensaje = 'Tarea eliminada'; } catch (Exception $e) { $error = 'Error'; }
    } elseif ($action === 'update_prog') {
        $id_camion = (int)($_POST['id_camion'] ?? 0);
        $km_actual = $_POST['kilometraje_actual'] ?? '';
        $horas_actual = $_POST['horas_actuales'] ?? '';
        $prox_km = $_POST['proximo_mantenimiento_km'] ?? '';
        $prox_hs = $_POST['proximo_mantenimiento_hs'] ?? '';
        $prox_fecha = $_POST['proximo_mantenimiento_fecha'] ?? '';
        $km_actual = ($km_actual !== '' && $km_actual !== null) ? (float)$km_actual : null;
        $horas_actual = ($horas_actual !== '' && $horas_actual !== null) ? (float)$horas_actual : null;
        $prox_km = ($prox_km !== '' && $prox_km !== null) ? (float)$prox_km : null;
        $prox_hs = ($prox_hs !== '' && $prox_hs !== null) ? (float)$prox_hs : null;
        $prox_fecha = ($prox_fecha !== '' && $prox_fecha !== null) ? $prox_fecha : null;
        if ($id_camion) {
            try {
                $db->prepare("UPDATE camiones SET 
                    kilometraje_actual = COALESCE(?, kilometraje_actual), 
                    horas_actuales = COALESCE(?, horas_actuales), 
                    proximo_mantenimiento_km = COALESCE(?, proximo_mantenimiento_km), 
                    proximo_mantenimiento_hs = COALESCE(?, proximo_mantenimiento_hs), 
                    proximo_mantenimiento_fecha = COALESCE(?, proximo_mantenimiento_fecha) 
                    WHERE id_camion=?")->execute([$km_actual, $horas_actual, $prox_km, $prox_hs, $prox_fecha, $id_camion]);
                $mensaje = 'Programacion actualizada';
            } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
        }
    }
}

$vista = $_GET['vista'] ?? 'mantenimientos';
$buscar = $_GET['buscar'] ?? '';
$empresa_filter = isset($_GET['empresa']) ? (int)$_GET['empresa'] : 0;

$empresasList = $db->query("SELECT id_empresa, nombre FROM empresas WHERE activo = 1 ORDER BY nombre")->fetchAll();
if (!$empresa_filter) {
    foreach ($empresasList as $emp) {
        if (stripos($emp['nombre'], 'avenida') !== false) { $empresa_filter = $emp['id_empresa']; break; }
    }
}

$sqlCamiones = "SELECT id_camion, patente, marca, modelo, foto, por_hora, horas_actuales, kilometraje_actual, proximo_mantenimiento_km, proximo_mantenimiento_hs FROM camiones WHERE 1=1";
if ($empresa_filter) { $sqlCamiones .= " AND empresa_id = $empresa_filter"; }
$camiones = $db->query($sqlCamiones . " ORDER BY patente")->fetchAll();

// Datos mantenimientos (solo en esa pestaña)
$mantList = [];
if ($vista === 'mantenimientos') {
    try {
        $sql = "SELECT m.*, c.patente, c.marca, c.por_hora FROM mantenimientos m JOIN camiones c ON m.id_camion = c.id_camion WHERE 1=1";
        $params = [];
        if ($empresa_filter) { $sql .= " AND c.empresa_id = ?"; $params[] = $empresa_filter; }
        if ($buscar) { $sql .= " AND (c.patente LIKE ? OR m.tipo LIKE ? OR m.descripcion LIKE ?)"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; }
        $sql .= " ORDER BY m.fecha DESC LIMIT 100";
        $stmt = $db->prepare($sql); $stmt->execute($params); $mantList = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$camionRepTareas = $_GET['camion_rt'] ?? ($camiones[0]['id_camion'] ?? 0);
$repuestosCamionActual = $repuestosPorCamion[$camionRepTareas] ?? [];
$tareasCamionActual = $tareasPorCamion[$camionRepTareas] ?? [];
$camionActualRT = null;
foreach ($camiones as $c) { if ($c['id_camion'] == $camionRepTareas) { $camionActualRT = $c; break; } }

// Programacion: proximos servicios por camion (solo en esa pestaña)
$progList = [];
if ($vista === 'programacion') {
    try {
        $sqlP = "SELECT c.id_camion, c.patente, c.marca, c.modelo, c.foto, c.por_hora, c.horas_actuales, c.kilometraje_actual, c.proximo_mantenimiento_km, c.proximo_mantenimiento_hs, c.proximo_mantenimiento_fecha,
            (SELECT m.fecha FROM mantenimientos m WHERE m.id_camion = c.id_camion ORDER BY m.fecha DESC LIMIT 1) as ultimo_mant
            FROM camiones c WHERE 1=1";
        if ($empresa_filter) { $sqlP .= " AND c.empresa_id = $empresa_filter"; }
        $sqlP .= " ORDER BY c.patente";
        $progList = $db->query($sqlP)->fetchAll();
    } catch (Exception $e) {}
}

// Reportes (solo en esa pestaña)
$repTaller = [];
$repConsumo = [];
if ($vista === 'reportes') {
    try {
        $repTaller = $db->query("SELECT taller, COUNT(*) as cantidad FROM mantenimientos GROUP BY taller ORDER BY cantidad DESC")->fetchAll();
    } catch (Exception $e) {}
    try {
        foreach ($db->query("SELECT r.nombre, r.codigo, COUNT(*) as veces FROM camion_repuestos r GROUP BY r.id_repuesto ORDER BY veces DESC LIMIT 10")->fetchAll() as $rc) { $repConsumo[] = $rc; }
    } catch (Exception $e) {}
}
?>
<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Mantenimiento</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Registro de servicios y costos.</p>
</div>
<?php if ($vista === 'planes'): ?>
<div class="flex gap-2">
<button onclick="openModal('modalRepuesto')" class="bg-blue-600 text-white px-4 py-2 rounded-lg font-bold text-sm flex items-center gap-1 hover:bg-blue-700"><span class="material-symbols-outlined text-sm">add</span> Repuesto</button>
<button onclick="openModal('modalTarea')" class="bg-purple-600 text-white px-4 py-2 rounded-lg font-bold text-sm flex items-center gap-1 hover:bg-purple-700"><span class="material-symbols-outlined text-sm">add</span> Tarea</button>
<button onclick="imprimirChecklist(<?= (int)$camionRepTareas ?>)" class="bg-emerald-600 text-white px-4 py-2 rounded-lg font-bold text-sm flex items-center gap-1 hover:bg-emerald-700"><span class="material-symbols-outlined text-sm">print</span> Imprimir Checklist</button>
</div>
<?php elseif ($vista === 'mantenimientos'): ?>
<div class="flex gap-2">
<button onclick="openModal('modalMantenimiento')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Mantenimiento
</button>
<button onclick="openModal('modalPedido')" class="bg-blue-600 text-white px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:bg-blue-700 transition-opacity">
<span class="material-symbols-outlined">local_offer</span> Enviar Pedido
</button>
</div>
<?php endif; ?>
</div>

<!-- Tabs -->
<div class="flex border-b border-outline-variant mb-6 overflow-x-auto">
<a href="?vista=planes<?= $empresa_filter ? '&empresa=' . $empresa_filter : '' ?>" class="px-6 py-3 font-bold text-sm border-b-2 transition-colors whitespace-nowrap <?= $vista === 'planes' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">inventory_2</span> Planes de Mantenimiento
</a>
<a href="?vista=programacion<?= $empresa_filter ? '&empresa=' . $empresa_filter : '' ?>" class="px-6 py-3 font-bold text-sm border-b-2 transition-colors whitespace-nowrap <?= $vista === 'programacion' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">event</span> Programacion
</a>
<a href="?vista=mantenimientos<?= $empresa_filter ? '&empresa=' . $empresa_filter : '' ?>" class="px-6 py-3 font-bold text-sm border-b-2 transition-colors whitespace-nowrap <?= $vista === 'mantenimientos' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">build</span> Mantenimientos
</a>
<a href="?vista=reportes<?= $empresa_filter ? '&empresa=' . $empresa_filter : '' ?>" class="px-6 py-3 font-bold text-sm border-b-2 transition-colors whitespace-nowrap <?= $vista === 'reportes' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">bar_chart</span> Reportes
</a>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filtro Empresa -->
<div class="bg-surface-container-lowest border border-outline-variant p-3 rounded-xl flex items-center gap-3 mb-6">
<span class="material-symbols-outlined text-outline text-sm">business</span>
<select id="empresaFilter" onchange="filtrarEmpresa()" class="border border-outline-variant rounded-lg px-3 py-2 bg-surface-container-low text-sm font-bold">
<option value="">Todas las empresas</option>
<?php foreach ($empresasList as $emp): ?>
<option value="<?= $emp['id_empresa'] ?>" <?= $empresa_filter == $emp['id_empresa'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['nombre']) ?></option>
<?php endforeach; ?>
</select>
<div class="relative flex-1">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-sm">search</span>
<input onkeyup="filterTable()" id="searchInput" class="w-full pl-9 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary text-sm" placeholder="Buscar por patente, tipo o descripcion..." type="text"/>
</div>
</div>

<?php if ($vista === 'mantenimientos'): ?>
<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Servicios Realizados</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= count($mantList) ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camiones Atendidos</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= count(array_unique(array_column($mantList, 'patente'))) ?></div>
</div>
</div>

<!-- Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CAMION / MAQUINA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">TIPO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">TALLER</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">DESCRIPCION</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM / HORAS</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">FACTURA</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="tableBody">
<?php foreach ($mantList as $m):
$tipos = ['cambio_aceite' => 'Cambio Aceite', 'filtros' => 'Filtros', 'cubiertas' => 'Cubiertas', 'frenos' => 'Frenos', 'embrague' => 'Embrague', 'reparacion_general' => 'Reparacion General', 'otro' => 'Otro'];
$talleres = ['taller_fasa' => 'Taller FASA', 'taller_externo' => 'Taller Externo'];
?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-4 py-3 font-data-mono"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
<td class="px-4 py-3 font-bold"><?= htmlspecialchars($m['patente']) ?></td>
<td class="px-4 py-3"><span class="px-2 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold"><?= $tipos[$m['tipo']] ?? $m['tipo'] ?></span></td>
<td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs font-bold <?= ($m['taller'] ?? 'taller_fasa') === 'taller_fasa' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' ?>"><?= $talleres[$m['taller']] ?? 'Taller FASA' ?></span></td>
<td class="px-4 py-3 text-xs max-w-xs truncate" title="<?= htmlspecialchars($m['descripcion'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth($m['descripcion'] ?? '-', 0, 60, '...')) ?></td>
<td class="px-4 py-3 text-right font-data-mono">
<?php if (!empty($m['horas']) && (float)$m['horas'] > 0): ?>
<span class="font-bold text-amber-700"><?= number_format((float)$m['horas'], 1) ?> HS</span>
<?php elseif (!empty($m['kilometraje']) && (float)$m['kilometraje'] > 0): ?>
<?= number_format((float)$m['kilometraje'], 0) ?> KM
<?php else: ?>
<span class="text-on-surface-variant text-xs">-</span>
<?php endif; ?>
</td>
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

<?php elseif ($vista === 'planes'): ?>
<!-- ==================== PLANES DE MANTENIMIENTO ==================== -->
<div class="mb-6">
<h3 class="font-headline-sm text-headline-sm text-primary mb-4">Planes de Mantenimiento</h3>

<!-- Selector de camion -->
<div class="bg-surface-container-lowest border border-outline-variant p-6 rounded-xl mb-6">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-3 block">Seleccionar Camion</label>
<div class="flex flex-wrap gap-3">
<?php foreach ($camiones as $c):
$isActive = $c['id_camion'] == $camionRepTareas;
$tieneRep = count($repuestosPorCamion[$c['id_camion']] ?? []) > 0;
$tieneTarea = count($tareasPorCamion[$c['id_camion']] ?? []) > 0;
$urlSel = '?vista=planes&camion_rt=' . $c['id_camion'];
if ($empresa_filter) { $urlSel .= '&empresa=' . $empresa_filter; }
?>
<div class="relative camion-btn">
<a href="<?= $urlSel ?>" class="inline-block px-5 py-3 rounded-lg text-sm font-bold transition-all <?= $isActive ? 'bg-primary text-on-primary shadow-md' : 'bg-surface-container-high text-on-surface-variant border border-outline-variant hover:bg-surface-container-highest' ?>">
<?= htmlspecialchars($c['patente']) ?>
<span class="text-[10px] ml-1 opacity-70">(
<?= count($repuestosPorCamion[$c['id_camion']] ?? []) > 1 ? '<span class="text-green-600">' . count($repuestosPorCamion[$c['id_camion']] ?? []) . '</span>' : count($repuestosPorCamion[$c['id_camion']] ?? []) ?>R/
<?= count($tareasPorCamion[$c['id_camion']] ?? []) > 1 ? '<span class="text-green-600">' . count($tareasPorCamion[$c['id_camion']] ?? []) . '</span>' : count($tareasPorCamion[$c['id_camion']] ?? []) ?>T)</span>
</a>
<div class="camion-tooltip hidden absolute z-50 bottom-full left-1/2 -translate-x-1/2 mb-3 w-52 bg-surface-container-lowest border border-outline-variant rounded-xl shadow-2xl overflow-hidden pointer-events-none">
<?php if ($c['foto']): ?>
<img src="<?= BASE_URL ?>/assets/uploads/vehiculos/<?= htmlspecialchars($c['foto']) ?>" class="w-full h-32 object-cover" alt="<?= htmlspecialchars($c['patente']) ?>"/>
<?php else: ?>
<div class="w-full h-32 bg-surface-container-high flex items-center justify-center">
<span class="material-symbols-outlined text-on-surface-variant text-4xl">local_shipping</span>
</div>
<?php endif; ?>
<div class="p-3">
<p class="font-bold text-xs text-primary"><?= htmlspecialchars($c['patente']) ?></p>
<p class="text-[11px] text-on-surface-variant"><?= htmlspecialchars($c['marca'] . ' ' . $c['modelo']) ?></p>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
</div>

<?php if ($camionActualRT): ?>
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl mb-6">
<div class="flex justify-between items-center">
<div>
<h4 class="font-bold text-primary"><?= htmlspecialchars($camionActualRT['patente'] . ' - ' . $camionActualRT['marca'] . ' ' . $camionActualRT['modelo']) ?></h4>
<p class="text-sm text-on-surface-variant">Repuestos: <?= count($repuestosCamionActual) ?> | Tareas: <?= count($tareasCamionActual) ?></p>
</div>
</div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- REPUESTOS -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-4 border-b border-outline-variant bg-surface-container-high/30">
<h4 class="font-bold text-primary flex items-center gap-2"><span class="material-symbols-outlined text-sm">inventory_2</span> Repuestos Asignados</h4>
</div>
<div class="p-4">
<?php if (empty($repuestosCamionActual)): ?>
<div class="text-center py-8">
<span class="material-symbols-outlined text-on-surface-variant text-4xl">inventory_2</span>
<p class="text-on-surface-variant mt-2 text-sm">Sin repuestos asignados</p>
<button onclick="openModal('modalRepuesto')" class="mt-2 text-primary text-xs font-bold">+ Agregar repuesto</button>
</div>
<?php else: ?>
<div class="space-y-2">
<?php foreach ($repuestosCamionActual as $r): ?>
<div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg border border-outline-variant">
<div class="flex-1">
<p class="font-bold text-sm"><?= htmlspecialchars($r['nombre']) ?></p>
<?php if ($r['codigo']): ?><p class="text-[10px] text-on-surface-variant font-data-mono">Cod: <?= htmlspecialchars($r['codigo']) ?></p><?php endif; ?>
<?php if ($r['descripcion']): ?><p class="text-xs text-on-surface-variant"><?= htmlspecialchars($r['descripcion']) ?></p><?php endif; ?>
<div class="flex gap-3 mt-1">
<span class="text-[10px] text-on-surface-variant">Cant: <?= $r['cantidad'] ?></span>
<?php if ($r['costo_unitario'] > 0): ?><span class="text-[10px] text-on-surface-variant">$<?= number_format($r['costo_unitario'], 2) ?></span><?php endif; ?>
</div>
</div>
 <div class="flex items-center gap-2">
  <button onclick="openEditarRepuesto(<?= $r['id_repuesto'] ?>, '<?= htmlspecialchars($r['codigo'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['nombre']) ?>', '<?= htmlspecialchars($r['descripcion'], ENT_QUOTES) ?>', <?= $r['cantidad'] ?>, <?= $r['costo_unitario'] ?>)" class="text-blue-500 hover:text-blue-700 px-2 py-1" title="Editar"><span class="material-symbols-outlined text-sm">edit</span></button>
 <form method="POST" class="inline" onsubmit="return confirm('Eliminar este repuesto?')">
 <input type="hidden" name="action" value="repuesto_delete"/>
 <input type="hidden" name="id_repuesto" value="<?= $r['id_repuesto'] ?>"/>
 <button type="submit" class="text-red-500 hover:text-red-700 px-2 py-1" title="Eliminar"><span class="material-symbols-outlined text-sm">delete</span></button>
 </form>
 </div>
 </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>

<!-- TAREAS -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-4 border-b border-outline-variant bg-surface-container-high/30">
<h4 class="font-bold text-primary flex items-center gap-2"><span class="material-symbols-outlined text-sm">task_alt</span> Tareas Asignadas</h4>
</div>
<div class="p-4">
<?php if (empty($tareasCamionActual)): ?>
<div class="text-center py-8">
<span class="material-symbols-outlined text-on-surface-variant text-4xl">task_alt</span>
<p class="text-on-surface-variant mt-2 text-sm">Sin tareas asignadas</p>
<button onclick="openModal('modalTarea')" class="mt-2 text-primary text-xs font-bold">+ Agregar tarea</button>
</div>
<?php else: ?>
<div class="space-y-2">
<?php foreach ($tareasCamionActual as $t): ?>
<div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg border border-outline-variant">
<div class="flex-1">
<p class="font-bold text-sm"><?= htmlspecialchars($t['nombre']) ?></p>
<?php if ($t['descripcion']): ?><p class="text-xs text-on-surface-variant"><?= htmlspecialchars($t['descripcion']) ?></p><?php endif; ?>
<div class="flex gap-3 mt-1">
<?php if ($t['frecuencia']): ?><span class="text-[10px] px-1.5 py-0.5 bg-blue-50 text-blue-700 rounded"><?= htmlspecialchars($t['frecuencia']) ?></span><?php endif; ?>
<?php if ($t['km_intervalo'] > 0): ?><span class="text-[10px] text-on-surface-variant">Cada <?= number_format($t['km_intervalo'], 0) ?> KM</span><?php endif; ?>
</div>
</div>
 <div class="flex items-center gap-2">
  <button onclick="openEditarTarea(<?= $t['id_tarea'] ?>, '<?= htmlspecialchars($t['nombre'], ENT_QUOTES) ?>', '<?= htmlspecialchars($t['descripcion'], ENT_QUOTES) ?>', '<?= htmlspecialchars($t['frecuencia'], ENT_QUOTES) ?>', <?= $t['km_intervalo'] ?>)" class="text-blue-500 hover:text-blue-700 px-2 py-1" title="Editar"><span class="material-symbols-outlined text-sm">edit</span></button>
 <form method="POST" class="inline" onsubmit="return confirm('Eliminar esta tarea?')">
 <input type="hidden" name="action" value="tarea_delete"/>
 <input type="hidden" name="id_tarea" value="<?= $t['id_tarea'] ?>"/>
 <button type="submit" class="text-red-500 hover:text-red-700 px-2 py-1" title="Eliminar"><span class="material-symbols-outlined text-sm">delete</span></button>
 </form>
 </div>
 </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>

<?php else: ?>
<div class="text-center py-12">
<span class="material-symbols-outlined text-on-surface-variant text-5xl">local_shipping</span>
<p class="text-on-surface-variant mt-2">Seleccione un camion para ver repuestos y tareas</p>
</div>
<?php endif; ?>
</div>
<?php elseif ($vista === 'programacion'): ?>
<!-- ==================== PROGRAMACION ==================== -->
<div class="mb-6">
<h3 class="font-headline-sm text-headline-sm text-primary mb-4">Programacion de Mantenimiento</h3>
<p class="text-sm text-on-surface-variant mb-6">A continuacion se listan los vehiculos y maquinas con su proximo servicio segun KM u Horas de uso.</p>

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">VEHICULO / MAQUINA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">MODELO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">USO ACTUAL</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">PROX. SERVICIO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">PROX. FECHA</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">FALTANTE</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">ULTIMO MANT.</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-4 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php foreach ($progList as $p):
$isPorHora = (bool)$p['por_hora'];
if ($isPorHora) {
    $usoActual = (float)($p['horas_actuales'] ?? 0);
    $proxServicio = ($p['proximo_mantenimiento_hs'] ?? 0) ? (float)$p['proximo_mantenimiento_hs'] : null;
    $unidad = 'HS';
    $faltante = $proxServicio !== null ? ($proxServicio - $usoActual) : null;
    $estado = 'al_dia';
    if ($faltante === null) { $estado = 'sin_datos'; }
    elseif ($faltante <= 0) { $estado = 'vencido'; }
    elseif ($faltante <= 50) { $estado = 'proximo'; }
} else {
    $usoActual = (float)($p['kilometraje_actual'] ?? 0);
    $proxServicio = ($p['proximo_mantenimiento_km'] ?? 0) ? (float)$p['proximo_mantenimiento_km'] : null;
    $unidad = 'KM';
    $faltante = $proxServicio !== null ? ($proxServicio - $usoActual) : null;
    $estado = 'al_dia';
    if ($faltante === null) { $estado = 'sin_datos'; }
    elseif ($faltante <= 0) { $estado = 'vencido'; }
    elseif ($faltante <= 5000) { $estado = 'proximo'; }
}
$estLabels = ['al_dia' => 'Al dia', 'proximo' => 'Proximo servicio', 'vencido' => 'Vencido', 'sin_datos' => 'Sin programar'];
$estColors = ['al_dia' => 'bg-green-50 text-green-700', 'proximo' => 'bg-amber-50 text-amber-700', 'vencido' => 'bg-red-50 text-red-700', 'sin_datos' => 'bg-gray-100 text-gray-600'];
?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-4 py-3">
<div class="flex items-center gap-2">
<?php if ($p['foto']): ?>
<img src="<?= BASE_URL ?>/assets/uploads/vehiculos/<?= htmlspecialchars($p['foto']) ?>" class="w-9 h-9 rounded object-cover" alt=""/>
<?php else: ?>
<span class="material-symbols-outlined text-on-surface-variant w-9 h-9 flex items-center justify-center"><?= $isPorHora ? 'precision_manufacturing' : 'local_shipping' ?></span>
<?php endif; ?>
<div>
<span class="font-bold"><?= htmlspecialchars($p['patente']) ?></span>
<?php if ($isPorHora): ?><span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-bold uppercase">Máquina</span><?php endif; ?>
</div>
</div>
</td>
<td class="px-4 py-3 text-xs"><?= htmlspecialchars($p['marca'] . ' ' . $p['modelo']) ?></td>
<td class="px-4 py-3 text-right font-data-mono"><?= number_format($usoActual, $isPorHora ? 1 : 0) ?> <span class="text-xs text-on-surface-variant"><?= $unidad ?></span></td>
<td class="px-4 py-3 text-right font-data-mono"><?= $proxServicio !== null ? number_format($proxServicio, $isPorHora ? 1 : 0) . " <span class='text-xs text-on-surface-variant'>$unidad</span>" : '-' ?></td>
<td class="px-4 py-3 text-xs font-data-mono"><?= ($p['proximo_mantenimiento_fecha'] ?? '') ? date('d/m/Y', strtotime($p['proximo_mantenimiento_fecha'])) : '-' ?></td>
<td class="px-4 py-3 text-right font-bold <?= $estado === 'vencido' ? 'text-red-600' : ($estado === 'proximo' ? 'text-amber-600' : ($estado === 'sin_datos' ? 'text-gray-400' : 'text-green-600')) ?>">
<?= $faltante === null ? '-' : ($faltante > 0 ? number_format($faltante, $isPorHora ? 1 : 0) . " $unidad" : 'VENCIDO') ?>
</td>
<td class="px-4 py-3 text-xs font-data-mono"><?= $p['ultimo_mant'] ? date('d/m/Y', strtotime($p['ultimo_mant'])) : '-' ?></td>
<td class="px-4 py-3 text-center"><span class="px-2 py-1 rounded text-xs font-bold <?= $estColors[$estado] ?>"><?= $estLabels[$estado] ?></span></td>
<td class="px-4 py-3 text-center">
<button onclick="openEditarProg(<?= (int)$p['id_camion'] ?>, '<?= htmlspecialchars($p['patente'], ENT_QUOTES) ?>', <?= (float)$p['kilometraje_actual'] ?>, <?= (float)($p['horas_actuales'] ?? 0) ?>, <?= ($p['proximo_mantenimiento_km'] ?? 0) ? (float)$p['proximo_mantenimiento_km'] : 0 ?>, <?= ($p['proximo_mantenimiento_hs'] ?? 0) ? (float)$p['proximo_mantenimiento_hs'] : 0 ?>, '<?= htmlspecialchars($p['proximo_mantenimiento_fecha'] ?? '', ENT_QUOTES) ?>', <?= $isPorHora ? 1 : 0 ?>)" class="bg-primary text-on-primary rounded-lg px-2.5 py-1.5 text-[10px] font-bold hover:opacity-90 flex items-center gap-1 mx-auto" title="Editar programacion"><span class="material-symbols-outlined text-sm">edit_calendar</span> Editar</button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<?php else: ?>
<!-- ==================== REPORTES ==================== -->
<div class="mb-6">
<h3 class="font-headline-sm text-headline-sm text-primary mb-4">Reportes de Mantenimiento</h3>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
<!-- Servicios por Taller -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-4 border-b border-outline-variant bg-surface-container-high/30">
<h4 class="font-bold text-primary flex items-center gap-2"><span class="material-symbols-outlined text-sm">garage</span> Servicios por Taller</h4>
</div>
<div class="p-4">
<?php $talleresLbl = ['taller_fasa' => 'Taller FASA', 'taller_externo' => 'Taller Externo']; ?>
<?php if (empty($repTaller)): ?>
<p class="text-on-surface-variant text-sm">Sin datos.</p>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($repTaller as $rt): ?>
<div class="flex items-center justify-between">
<span class="text-sm font-bold"><?= $talleresLbl[$rt['taller']] ?? $rt['taller'] ?></span>
<span class="px-2 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold"><?= $rt['cantidad'] ?> servicios</span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>

<!-- Consumo de Repuestos -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-4 border-b border-outline-variant bg-surface-container-high/30">
<h4 class="font-bold text-primary flex items-center gap-2"><span class="material-symbols-outlined text-sm">inventory_2</span> Consumo de Repuestos</h4>
</div>
<div class="p-4">
<?php if (empty($repConsumo)): ?>
<p class="text-on-surface-variant text-sm">Sin repuestos registrados.</p>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($repConsumo as $rc): ?>
<div class="flex items-center justify-between">
<span class="text-sm"><?= $rc['codigo'] ? '[' . htmlspecialchars($rc['codigo']) . '] ' : '' ?><?= htmlspecialchars($rc['nombre']) ?></span>
<span class="px-2 py-1 bg-green-50 text-green-700 rounded text-xs font-bold"><?= $rc['veces'] ?>x</span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
<?php endif; ?>
</main>

<!-- Modal Mantenimiento -->
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
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Vehículo / Máquina</label>
<select name="id_camion" id="mantCamion" onchange="cargarRepTareas(); onMantCamionChange();" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($camiones as $c): ?>
<option value="<?= $c['id_camion'] ?>" data-por-hora="<?= (int)$c['por_hora'] ?>" data-horas="<?= (float)($c['horas_actuales'] ?? 0) ?>" data-km="<?= (float)($c['kilometraje_actual'] ?? 0) ?>" data-prox-km="<?= (float)($c['proximo_mantenimiento_km'] ?? 0) ?>" data-prox-hs="<?= (float)($c['proximo_mantenimiento_hs'] ?? 0) ?>"><?= htmlspecialchars($c['patente'] . ' - ' . $c['marca'] . ' ' . $c['modelo']) . ($c['por_hora'] ? ' (Máquina / Horas)' : '') ?></option>
<?php endforeach; ?>
</select></div>
</div>
<div id="repTareasInfo" class="hidden bg-surface-container-low border border-outline-variant rounded-lg p-4">
<div class="flex gap-6">
<div class="flex-1">
<h5 class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-2 flex items-center gap-1"><span class="material-symbols-outlined text-sm">inventory_2</span> Repuestos (marcar los cambiados)</h5>
<ul id="listaRepuestos" class="text-xs text-on-surface-variant space-y-1"></ul>
</div>
<div class="flex-1">
<h5 class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-2 flex items-center gap-1"><span class="material-symbols-outlined text-sm">task_alt</span> Tareas (marcar las realizadas)</h5>
<ul id="listaTareas" class="text-xs text-on-surface-variant space-y-1"></ul>
</div>
</div>
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
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Taller</label>
<select name="taller" id="mantTaller" onchange="toggleFactura()" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="taller_fasa">Taller FASA</option>
<option value="taller_externo">Taller Externo</option>
</select></div>
</div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label><textarea name="descripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea></div>

<!-- Inputs de KM y Horas de la máquina -->
<div class="grid grid-cols-2 gap-4">
<div id="mantKmBox"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase" id="lblMantKm">Kilometraje</label><input name="kilometraje" id="mantKilometraje" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: 154000"/></div>
<div id="mantHsBox"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase" id="lblMantHs">Horas de Máquina (HS)</label><input name="horas" id="mantHoras" type="number" step="0.1" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: 1250.5"/></div>
</div>

<div class="grid grid-cols-3 gap-3">
<div id="mantProxKmBox"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[11px]" id="lblMantProxKm">Prox. Mant. (KM)</label><input name="proximo_mantenimiento_km" id="mantProxKm" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm" placeholder="KM"/></div>
<div id="mantProxHsBox"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[11px]" id="lblMantProxHs">Prox. Mant. (Horas)</label><input name="proximo_mantenimiento_hs" id="mantProxHs" type="number" step="0.1" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm" placeholder="HS"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[11px]">Prox. Mant. (Fecha)</label><input name="proximo_mantenimiento_fecha" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm"/></div>
</div>

<div id="facturaBox" class="hidden"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Subir Factura *</label><input name="foto_factura" type="file" accept="image/*" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalMantenimiento')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Repuesto -->
<div id="modalRepuesto" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalRepuesto')">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Agregar Repuesto</h3>
<button onclick="closeModal('modalRepuesto')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="repuesto_add"/>
<input type="hidden" name="id_camion" value="<?= $camionRepTareas ?>"/>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Código</label><input name="codigo" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: FIL-001"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre del Repuesto *</label><input name="nombre" required class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: Filtro de aceite"/></div>
</div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label><textarea name="descripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea></div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cantidad</label><input name="cantidad" type="number" value="1" min="1" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Costo Unitario ($)</label><input name="costo_unitario" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalRepuesto')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Tarea -->
<div id="modalTarea" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalTarea')">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Agregar Tarea</h3>
<button onclick="closeModal('modalTarea')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="tarea_add"/>
<input type="hidden" name="id_camion" value="<?= $camionRepTareas ?>"/>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre de la Tarea *</label><input name="nombre" required class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: Sopletear caja de cambios"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label><textarea name="descripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea></div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Frecuencia</label>
<select name="frecuencia" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Sin frecuencia</option>
<option value="Cada servicio">Cada servicio</option>
<option value="Mensual">Mensual</option>
<option value="Trimestral">Trimestral</option>
<option value="Semestral">Semestral</option>
<option value="Anual">Anual</option>
</select></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Intervalo KM</label><input name="km_intervalo" type="number" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: 10000"/></div>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalTarea')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Editar Programacion -->
<div id="modalEditarProg" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalEditarProg')">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Editar Programacion</h3>
<button onclick="closeModal('modalEditarProg')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="update_prog"/>
<input type="hidden" name="id_camion" id="progCamionId"/>
<p class="text-sm text-on-surface-variant">Vehiculo / Máquina: <strong id="progPatente"></strong></p>
<div class="grid grid-cols-2 gap-3">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">KM Actual</label><input name="kilometraje_actual" id="progKmActual" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm" placeholder="Dejar vacio para no cambiar"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Prox. Servicio KM</label><input name="proximo_mantenimiento_km" id="progKmProx" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm" placeholder="Dejar vacio para no cambiar"/></div>
</div>
<div class="grid grid-cols-2 gap-3">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Horas Actuales (HS)</label><input name="horas_actuales" id="progHsActual" type="number" step="0.1" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm" placeholder="Horas Actuales"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Prox. Servicio (HS)</label><input name="proximo_mantenimiento_hs" id="progHsProx" type="number" step="0.1" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm" placeholder="Prox. Horas"/></div>
</div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Prox. Servicio Fecha</label><input name="proximo_mantenimiento_fecha" id="progFecha" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low text-sm"/></div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalEditarProg')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
  </div>
  </div>

<!-- Modal Editar Repuesto -->
<div id="modalEditarRepuesto" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalEditarRepuesto')">
 <div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
 <div class="p-6 border-b border-outline-variant flex justify-between items-center">
 <h3 class="font-headline-sm text-headline-sm text-primary">Editar Repuesto</h3>
 <button onclick="closeModal('modalEditarRepuesto')"><span class="material-symbols-outlined">close</span></button>
 </div>
 <form method="POST" class="p-6 space-y-4">
 <input type="hidden" name="action" value="repuesto_update"/>
 <input type="hidden" name="id_repuesto" id="repEditId"/>
 <div class="grid grid-cols-2 gap-4">
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Código</label><input name="codigo" id="repEditCodigo" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: FIL-001"/></div>
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre del Repuesto *</label><input name="nombre" id="repEditNombre" required class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: Filtro de aceite"/></div>
 </div>
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label><textarea name="descripcion" id="repEditDescripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea></div>
 <div class="grid grid-cols-2 gap-4">
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Cantidad</label><input name="cantidad" id="repEditCantidad" type="number" value="1" min="1" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Costo Unitario ($)</label><input name="costo_unitario" id="repEditCosto" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
 </div>
 <div class="flex gap-3 pt-4">
 <button type="button" onclick="closeModal('modalEditarRepuesto')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
 <button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
 </div>
 </form>
 </div>
 </div>

<!-- Modal Editar Tarea -->
<div id="modalEditarTarea" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalEditarTarea')">
 <div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
 <div class="p-6 border-b border-outline-variant flex justify-between items-center">
 <h3 class="font-headline-sm text-headline-sm text-primary">Editar Tarea</h3>
 <button onclick="closeModal('modalEditarTarea')"><span class="material-symbols-outlined">close</span></button>
 </div>
 <form method="POST" class="p-6 space-y-4">
 <input type="hidden" name="action" value="tarea_update"/>
 <input type="hidden" name="id_tarea" id="tareaEditId"/>
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre de la Tarea *</label><input name="nombre" id="tareaEditNombre" required class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: Sopletear caja de cambios"/></div>
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label><textarea name="descripcion" id="tareaEditDescripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea></div>
 <div class="grid grid-cols-2 gap-4">
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Frecuencia</label>
 <select name="frecuencia" id="tareaEditFrecuencia" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
 <option value="">Sin frecuencia</option>
 <option value="Cada servicio">Cada servicio</option>
 <option value="Mensual">Mensual</option>
 <option value="Trimestral">Trimestral</option>
 <option value="Semestral">Semestral</option>
 <option value="Anual">Anual</option>
 </select></div>
 <div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Intervalo KM</label><input name="km_intervalo" id="tareaEditKmIntervalo" type="number" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: 10000"/></div>
 </div>
 <div class="flex gap-3 pt-4">
 <button type="button" onclick="closeModal('modalEditarTarea')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
 <button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
 </div>
 </form>
 </div>
 </div>

<!-- Modal Enviar Pedido -->
<div id="modalPedido" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalPedido')">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Enviar Pedido de Mantenimiento</h3>
<button onclick="closeModal('modalPedido')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="pedido_create"/>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion *</label>
<select name="id_camion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<?php foreach ($camiones as $c): ?><option value="<?= $c['id_camion'] ?>"><?= htmlspecialchars($c['patente'] . ' - ' . $c['marca'] . ' ' . $c['modelo']) ?></option><?php endforeach; ?>
</select></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Titulo *</label><input name="titulo" required class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Ej: Reparar freno delantero"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Prioridad</label>
<select name="prioridad" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="normal">Normal</option>
<option value="urgente">Urgente</option>
<option value="critica">Critica</option>
</select></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label><textarea name="descripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Detalle del problema..."></textarea></div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalPedido')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded-lg font-bold hover:bg-blue-700">Enviar Pedido</button>
</div>
</form>
</div>
</div>

<script>
var repuestosData = <?= json_encode($repuestosPorCamion) ?>;
var tareasData = <?= json_encode($tareasPorCamion) ?>;
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function openEditarProg(id, patente, kmActual, hsActual, kmProx, hsProx, fecha, porHora) {
     document.getElementById('progCamionId').value = id;
     document.getElementById('progPatente').textContent = patente;
     document.getElementById('progKmActual').value = kmActual > 0 ? kmActual : '';
     document.getElementById('progHsActual').value = hsActual > 0 ? hsActual : '';
     document.getElementById('progKmProx').value = kmProx > 0 ? kmProx : '';
     document.getElementById('progHsProx').value = hsProx > 0 ? hsProx : '';
     document.getElementById('progFecha').value = fecha || '';
     openModal('modalEditarProg');
 }
 function openEditarRepuesto(id, codigo, nombre, descripcion, cantidad, costo) {
     document.getElementById('repEditId').value = id;
     document.getElementById('repEditCodigo').value = codigo;
     document.getElementById('repEditNombre').value = nombre;
     document.getElementById('repEditDescripcion').value = descripcion;
     document.getElementById('repEditCantidad').value = cantidad;
     document.getElementById('repEditCosto').value = costo;
     openModal('modalEditarRepuesto');
 }
 function openEditarTarea(id, nombre, descripcion, frecuencia, kmIntervalo) {
     document.getElementById('tareaEditId').value = id;
     document.getElementById('tareaEditNombre').value = nombre;
     document.getElementById('tareaEditDescripcion').value = descripcion;
     document.getElementById('tareaEditFrecuencia').value = frecuencia;
     document.getElementById('tareaEditKmIntervalo').value = kmIntervalo;
     openModal('modalEditarTarea');
 }
 function filterTable() {
 const search = document.getElementById('searchInput').value.toLowerCase();
 document.querySelectorAll('#tableBody tr').forEach(row => {
 row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
 });
 }
function filtrarEmpresa() {
const id = document.getElementById('empresaFilter').value;
const url = new URL(window.location);
url.searchParams.set('vista', '<?= $vista ?>');
if (id) { url.searchParams.set('empresa', id); } else { url.searchParams.delete('empresa'); }
window.location = url;
}
function toggleFactura() {
const val = document.getElementById('mantTaller').value;
document.getElementById('facturaBox').classList.toggle('hidden', val === 'taller_fasa');
}
function imprimirChecklist(camionId) {
if (!camionId) { alert('Seleccione un camion primero'); return; }
window.open('<?= BASE_URL ?>/admin/mantenimiento.php?vista=planes&camion_rt=' + camionId + '&print=1' + '<?= $empresa_filter ? '&empresa=' . $empresa_filter : '' ?>', '_blank', 'width=850,height=650');
}
function onMantCamionChange() {
    const sel = document.getElementById('mantCamion');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    const isPorHora = opt.getAttribute('data-por-hora') === '1';
    const km = opt.getAttribute('data-km') || '';
    const hs = opt.getAttribute('data-horas') || '';
    const proxKm = opt.getAttribute('data-prox-km') || '';
    const proxHs = opt.getAttribute('data-prox-hs') || '';

    if (isPorHora) {
        document.getElementById('mantHoras').value = hs > 0 ? hs : '';
        document.getElementById('mantProxHs').value = proxHs > 0 ? proxHs : '';
        document.getElementById('lblMantHs').classList.add('text-primary', 'font-bold');
        document.getElementById('lblMantKm').classList.remove('text-primary', 'font-bold');
    } else {
        document.getElementById('mantKilometraje').value = km > 0 ? km : '';
        document.getElementById('mantProxKm').value = proxKm > 0 ? proxKm : '';
        document.getElementById('lblMantKm').classList.add('text-primary', 'font-bold');
        document.getElementById('lblMantHs').classList.remove('text-primary', 'font-bold');
    }
}
function cargarRepTareas() {
const id = document.getElementById('mantCamion').value;
const box = document.getElementById('repTareasInfo');
const ulRep = document.getElementById('listaRepuestos');
const ulTar = document.getElementById('listaTareas');
if (!id) { box.classList.add('hidden'); return; }
const rep = repuestosData[id] || [];
const tar = tareasData[id] || [];
if (rep.length === 0 && tar.length === 0) { box.classList.add('hidden'); return; }
box.classList.remove('hidden');
ulRep.innerHTML = rep.length ? rep.map(r => '<li class="flex items-center gap-2 p-1 rounded hover:bg-surface-container-lowest"><input type="checkbox" name="rep_hechos[]" value="' + r.id_repuesto + '" class="rounded accent-green-600"/><label class="cursor-pointer">' + (r.codigo ? '<span class="font-data-mono">[' + r.codigo + ']</span> ' : '') + r.nombre + '</label></li>').join('') : '<li class="text-outline py-2">Sin repuestos asignados</li>';
ulTar.innerHTML = tar.length ? tar.map(t => '<li class="flex items-center gap-2 p-1 rounded hover:bg-surface-container-lowest"><input type="checkbox" name="tareas_hechas[]" value="' + t.id_tarea + '" class="rounded accent-amber-600"/><label class="cursor-pointer">' + t.nombre + (t.frecuencia ? ' <span class="text-outline">(' + t.frecuencia + ')</span>' : '') + '</label></li>').join('') : '<li class="text-outline py-2">Sin tareas asignadas</li>';
}
</script>
<style>
.camion-btn:hover .camion-tooltip { display: block !important; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
