<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('kilometraje_ver');
$pageTitle = 'Gestion de Viajes';

$db = getDB();
$mes = date('m');
$anio = date('Y');

// Migrar columnas de estado si no existen
try { $db->exec("ALTER TABLE km_recorrido MODIFY km_llegada DECIMAL(12,2) DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN estado ENUM('abierto','cerrado','aprobado') DEFAULT 'abierto' AFTER observaciones"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN aprobado_por INT DEFAULT NULL AFTER estado"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN fecha_cierre DATETIME DEFAULT NULL AFTER aprobado_por"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN nro_hoja_ruta VARCHAR(50) DEFAULT NULL AFTER id_hoja"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN ayudante_id INT DEFAULT NULL AFTER nro_hoja_ruta"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN tara DECIMAL(10,2) DEFAULT NULL AFTER ayudante_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN peso_carga DECIMAL(10,2) DEFAULT NULL AFTER tara"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN cachape_id INT DEFAULT NULL AFTER peso_carga"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN peso_total DECIMAL(12,2) DEFAULT NULL AFTER cachape_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_salida DECIMAL(10,2) DEFAULT NULL AFTER km_recorridos"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_llegada DECIMAL(10,2) DEFAULT NULL AFTER hs_salida"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN hs_recorridas DECIMAL(10,2) GENERATED ALWAYS AS (hs_llegada - hs_salida) STORED AFTER hs_llegada"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE choferes ADD COLUMN id_empresa INT DEFAULT NULL AFTER id_chofer"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE camiones ADD COLUMN id_empresa INT DEFAULT NULL AFTER id_camion"); } catch (Exception $e) {}

// POST handlers (antes de header.php para permitir redirects)
$mensaje = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    $viajeActual = $db->prepare("SELECT estado FROM km_recorrido WHERE id_hoja = ?");
    $viajeActual->execute([$id]);
    $estadoActual = $viajeActual->fetchColumn();
    if ($estadoActual === 'aprobado') {
        $error = 'No se puede editar un viaje ya autorizado. Revierta a pendiente primero.';
    } else {
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
            header('Location: viajes.php');
            exit;
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    $viajeActual = $db->prepare("SELECT estado FROM km_recorrido WHERE id_hoja = ?");
    $viajeActual->execute([$id]);
    if ($viajeActual->fetchColumn() === 'aprobado') {
        $error = 'No se puede eliminar un viaje ya autorizado. Revierta a pendiente primero.';
    } else {
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autorizar') {
    if (esChofer()) {
        $error = 'No tienes permiso para autorizar viajes';
    } else {
        $id = (int)($_POST['id_hoja'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE km_recorrido SET estado='aprobado', aprobado_por=? WHERE id_hoja=? AND estado='cerrado'");
            $stmt->execute([getCurrentUserId(), $id]);
            if ($stmt->rowCount() > 0) {
                $_SESSION['flash'] = 'Viaje autorizado exitosamente';
                header('Location: viajes.php');
                exit;
            } else { $error = 'El viaje debe estar en estado "cerrado" para autorizarlo'; }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revertir') {
    $id = (int)($_POST['id_hoja'] ?? 0);
    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET estado='abierto', aprobado_por=NULL, fecha_cierre=NULL WHERE id_hoja=? AND estado != 'abierto'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['flash'] = 'Viaje revertido a pendiente de autorizacion';
            header('Location: viajes.php');
            exit;
        } else { $error = 'El viaje ya esta en estado pendiente'; }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autorizar_todo') {
    try {
        $stmt = $db->prepare("UPDATE km_recorrido SET estado='aprobado', aprobado_por=? WHERE estado='cerrado' AND km_llegada IS NOT NULL");
        $stmt->execute([getCurrentUserId()]);
        $_SESSION['flash'] = $stmt->rowCount() . ' viajes autorizados exitosamente';
        header('Location: viajes.php');
        exit;
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
        header('Location: viajes.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Stats
$statsMes = $db->prepare("SELECT COUNT(*) as viajes, COALESCE(SUM(km_recorridos),0) as km_total, COALESCE(AVG(km_recorridos),0) as km_prom FROM km_recorrido WHERE MONTH(fecha)=? AND YEAR(fecha)=? AND estado='aprobado'");
$statsMes->execute([$mes, $anio]);
$stats = $statsMes->fetch();

$vista = $_GET['vista'] ?? 'todos';
$buscar = $_GET['buscar'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$orden = $_GET['orden'] ?? 'DESC';
$orden = strtoupper($orden) === 'ASC' ? 'ASC' : 'DESC';
$id_chofer_filtro = (int)($_GET['id_chofer'] ?? 0);
$id_empresa_filtro = (int)($_GET['id_empresa'] ?? 0);
try { $empresas = $db->query("SELECT id_empresa, nombre FROM empresas WHERE activo=1 ORDER BY nombre")->fetchAll(); } catch (Exception $e) { $empresas = []; }

$tabParams = http_build_query(['fecha_desde' => $fecha_desde, 'fecha_hasta' => $fecha_hasta, 'orden' => $orden, 'buscar' => $buscar]);

// Datos para vista Por Chofer
$statsChofer = [];

if ($vista === 'todos') {
    $sql = "SELECT h.*, c.patente, c.por_hora, ch.nombre, ch.apellido, ay.nombre as ayudante_nombre, ay.apellido as ayudante_apellido, cx.patente as cachape_patente, 'Chofer' as rol FROM km_recorrido h JOIN camiones c ON h.id_camion = c.id_camion JOIN choferes ch ON h.id_chofer = ch.id_chofer LEFT JOIN choferes ay ON h.ayudante_id = ay.id_chofer LEFT JOIN camiones cx ON h.cachape_id = cx.id_camion WHERE 1=1";
    $params = [];
    if ($buscar) {
        $sql .= " AND (c.patente LIKE ? OR ch.nombre LIKE ? OR ch.apellido LIKE ? OR h.origen LIKE ? OR h.destino LIKE ?)";
        $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
    }
    $sql .= " AND h.fecha >= ? AND h.fecha <= ?";
    $params[] = $fecha_desde; $params[] = $fecha_hasta;
    $sql .= " ORDER BY h.fecha $orden, h.id_hoja $orden LIMIT 100";
    $registros = $db->prepare($sql);
    $registros->execute($params);
    $registrosList = $registros->fetchAll();
} else {
    // Vista Por Chofer: incluye viajes como chofer y como ayudante
    $sql = "SELECT h.*, c.patente, c.por_hora, ch.nombre, ch.apellido, ch.id_empresa, ay.nombre as ayudante_nombre, ay.apellido as ayudante_apellido, cx.patente as cachape_patente,
            CASE WHEN h.id_chofer = ? THEN 'Chofer' ELSE 'Ayudante' END as rol
            FROM km_recorrido h
            JOIN camiones c ON h.id_camion = c.id_camion
            JOIN choferes ch ON h.id_chofer = ch.id_chofer
            LEFT JOIN choferes ay ON h.ayudante_id = ay.id_chofer
            LEFT JOIN camiones cx ON h.cachape_id = cx.id_camion
            WHERE (h.id_chofer = ? OR h.ayudante_id = ?)
            AND h.fecha >= ? AND h.fecha <= ?";
    $params = [$id_chofer_filtro, $id_chofer_filtro, $id_chofer_filtro, $fecha_desde, $fecha_hasta];
    if ($id_empresa_filtro > 0) {
        $sql .= " AND ch.id_empresa = ?";
        $params[] = $id_empresa_filtro;
    }
    $sql .= " ORDER BY h.fecha $orden, h.id_hoja $orden LIMIT 100";
    $registros = $db->prepare($sql);
    $registros->execute($params);
    $registrosList = $registros->fetchAll();

    // Stats del chofer en el periodo
    $sSql = "SELECT
        COUNT(*) as total_viajes,
        COALESCE(SUM(CASE WHEN h.id_chofer = ? THEN km_recorridos ELSE 0 END),0) as km_como_chofer,
        COALESCE(SUM(CASE WHEN h.ayudante_id = ? THEN km_recorridos ELSE 0 END),0) as km_como_ayudante,
        COALESCE(SUM(km_recorridos),0) as km_total
        FROM km_recorrido h
        JOIN choferes ch ON h.id_chofer = ch.id_chofer
        WHERE (h.id_chofer = ? OR h.ayudante_id = ?)
        AND h.estado='aprobado' AND h.fecha >= ? AND h.fecha <= ?";
    $sParams = [$id_chofer_filtro, $id_chofer_filtro, $id_chofer_filtro, $id_chofer_filtro, $fecha_desde, $fecha_hasta];
    if ($id_empresa_filtro > 0) {
        $sSql .= " AND ch.id_empresa = ?";
        $sParams[] = $id_empresa_filtro;
    }
    $sStmt = $db->prepare($sSql);
    $sStmt->execute($sParams);
    $statsChofer = $sStmt->fetch();
}

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

$camionesList = $db->query("SELECT id_camion, patente, marca, modelo, por_hora FROM camiones WHERE estado='activo' ORDER BY patente")->fetchAll();
$choferesList = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado='activo' ORDER BY apellido, nombre")->fetchAll();
$cachapesList = $db->query("SELECT id_camion, patente, marca, modelo, tara FROM camiones WHERE estado='activo' AND tipo='cachape' ORDER BY patente")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
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

<!-- Tabs / Submenu -->
<div class="flex gap-1 mb-6 border-b border-outline-variant">
<a href="?vista=todos&amp;<?= $tabParams ?>" class="px-5 py-3 text-sm font-bold rounded-t-lg transition-colors <?= $vista === 'todos' ? 'bg-primary text-on-primary' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">Todos los Viajes</a>
<a href="?vista=por_chofer&amp;<?= $tabParams ?>" class="px-5 py-3 text-sm font-bold rounded-t-lg transition-colors <?= $vista === 'por_chofer' ? 'bg-primary text-on-primary' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">Por Chofer</a>
</div>

<?php if ($vista === 'todos'): ?>

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

<!-- Search + Filters -->
<form method="GET" class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex flex-wrap items-end gap-3 mb-8">
<input type="hidden" name="vista" value="todos"/>
<div class="relative flex-1 min-w-[200px]">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input name="buscar" value="<?= htmlspecialchars($buscar) ?>" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por patente, chofer, origen o destino..." type="text"/>
</div>
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

<!-- Export Buttons -->
<div class="flex justify-end gap-2 mb-4">
<a href="<?= BASE_URL ?>/api/export.php?tipo=viajes&amp;vista=todos&amp;formato=pdf&amp;desde=<?= $fecha_desde ?>&amp;hasta=<?= $fecha_hasta ?>&amp;orden=<?= $orden ?>&amp;buscar=<?= urlencode($buscar) ?>" target="_blank" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 hover:bg-red-700 transition-colors">
<span class="material-symbols-outlined text-sm">picture_as_pdf</span> PDF
</a>
<a href="<?= BASE_URL ?>/api/export.php?tipo=viajes&amp;vista=todos&amp;formato=excel&amp;desde=<?= $fecha_desde ?>&amp;hasta=<?= $fecha_hasta ?>&amp;orden=<?= $orden ?>&amp;buscar=<?= urlencode($buscar) ?>" class="bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 hover:bg-green-800 transition-colors">
<span class="material-symbols-outlined text-sm">table_chart</span> Excel
</a>
</div>

<!-- Table Todos -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap overflow-x-auto">
<table class="w-full table-fixed" style="min-width:850px">
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
<th class="w-[80px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ACC</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="tableBody">
<?php foreach ($registrosList as $r): ?>
<tr class="hover:bg-surface-container transition-colors text-[12px]">
<td class="px-2 py-2 font-data-mono whitespace-nowrap"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
<td class="px-2 py-2 truncate" title="<?= htmlspecialchars(($r['apellido'] ?? '') . ', ' . ($r['nombre'] ?? '')) ?>"><?= htmlspecialchars(($r['apellido'] ?? '') . ', ' . ($r['nombre'] ?? '')) ?></td>
<td class="px-2 py-2 font-bold whitespace-nowrap"><?= htmlspecialchars($r['patente']) ?><?php if (!empty($r['cachape_patente'])): ?> <span class="text-[9px] text-on-surface-variant font-normal">+<?= htmlspecialchars($r['cachape_patente']) ?></span><?php endif; ?></td>
<td class="px-2 py-2 truncate" title="<?= htmlspecialchars(($r['origen'] ?? '') . ' → ' . ($r['destino'] ?? '')) ?>"><?= htmlspecialchars(($r['origen'] ?? '-') . ' → ' . ($r['destino'] ?? '-')) ?></td>
<td class="px-2 py-2 text-right font-data-mono whitespace-nowrap text-[11px]"><?= $r['por_hora'] ? (number_format($r['hs_salida'], 1) . '&rarr;' . ($r['hs_llegada'] !== null ? number_format($r['hs_llegada'], 1) : '-')) : (number_format($r['km_salida'], 0) . '&rarr;' . ($r['km_llegada'] !== null ? number_format($r['km_llegada'], 0) : '-')) ?></td>
<td class="px-2 py-2 text-right font-data-mono font-bold whitespace-nowrap"><?= $r['por_hora'] ? ($r['hs_recorridas'] !== null ? number_format($r['hs_recorridas'], 1) . ' hs' : '-') : ($r['km_recorridos'] !== null ? number_format($r['km_recorridos'], 0) : '-') ?></td>
<td class="px-2 py-2 text-right font-data-mono whitespace-nowrap text-[11px]"><?= $r['tara'] !== null ? number_format($r['tara'], 0) : '' ?><?= $r['tara'] !== null && $r['peso_carga'] !== null ? '/' : '' ?><?= $r['peso_carga'] !== null ? number_format($r['peso_carga'], 0) : '' ?><?= $r['tara'] === null && $r['peso_carga'] === null ? '-' : '' ?></td>
<td class="px-2 py-2 text-center whitespace-nowrap">
<?php
$estado = $r['estado'] ?? 'abierto';
if ($estado === 'aprobado') $bCls = 'bg-green-100 text-green-800';
elseif ($estado === 'cerrado') $bCls = 'bg-blue-100 text-blue-800';
else $bCls = 'bg-amber-100 text-amber-800';
?>
<span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase <?= $bCls ?>"><?= $estado ?></span>
</td>
<td class="px-2 py-2 text-center whitespace-nowrap">
<div class="flex gap-1 justify-center">
<?php if ($estado === 'cerrado'): ?>
<form method="POST" class="inline" onsubmit="return confirm('¿Autorizar este viaje?')">
<input type="hidden" name="action" value="autorizar">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="px-1.5 py-1 bg-green-600 text-white rounded text-[9px] font-bold hover:bg-green-700" title="Autorizar">A</button>
</form>
<?php endif; ?>
<?php if ($estado !== 'abierto' && (esAdminPleno() || hasRole('Bascula') || hasRole('Báscula'))): ?>
<form method="POST" class="inline" onsubmit="return confirm('¿Revertir a pendiente de autorizacion?')">
<input type="hidden" name="action" value="revertir">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="px-1.5 py-1 bg-amber-500 text-white rounded text-[9px] font-bold hover:bg-amber-600" title="Revertir a Pendiente">R</button>
</form>
<?php endif; ?>
<button onclick="verViaje(<?= $r['id_hoja'] ?>)" class="px-1.5 py-1 bg-sky-600 text-white rounded text-[9px] font-bold hover:bg-sky-700" title="Ver detalle">V</button>
<?php if ($estado !== 'aprobado'): ?>
<button onclick="editViaje(<?= $r['id_hoja'] ?>)" class="px-1.5 py-1 bg-secondary-container text-on-secondary-container rounded text-[9px] font-bold hover:opacity-80" title="Editar">E</button>
<form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este viaje?')">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="px-1.5 py-1 bg-red-50 text-red-600 rounded text-[9px] font-bold hover:bg-red-100" title="Borrar">X</button>
</form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php else: ?>

<!-- Vista Por Chofer -->
<form method="GET" class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex flex-wrap items-end gap-3 mb-4">
<input type="hidden" name="vista" value="por_chofer"/>
<div class="flex items-center gap-2">
<label class="font-label-caps text-[10px] text-on-surface-variant">Chofer</label>
<select name="id_chofer" class="border border-outline-variant rounded px-2 py-2 bg-surface-container-low text-sm min-w-[200px]" required>
<option value="">Seleccionar...</option>
<?php foreach ($choferesList as $ch): ?>
<option value="<?= $ch['id_chofer'] ?>" <?= $id_chofer_filtro === (int)$ch['id_chofer'] ? 'selected' : '' ?>><?= htmlspecialchars($ch['apellido'] . ', ' . $ch['nombre'] . ' - ' . $ch['dni']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex items-center gap-2">
<label class="font-label-caps text-[10px] text-on-surface-variant">Empresa</label>
<select name="id_empresa" class="border border-outline-variant rounded px-2 py-2 bg-surface-container-low text-sm">
<option value="0">Todas</option>
<?php foreach ($empresas as $emp): ?>
<option value="<?= $emp['id_empresa'] ?>" <?= $id_empresa_filtro === (int)$emp['id_empresa'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['nombre']) ?></option>
<?php endforeach; ?>
</select>
</div>
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
<?php if ($id_chofer_filtro > 0): ?>
<a href="<?= BASE_URL ?>/api/export.php?tipo=viajes&amp;vista=por_chofer&amp;formato=pdf&amp;desde=<?= $fecha_desde ?>&amp;hasta=<?= $fecha_hasta ?>&amp;orden=<?= $orden ?>&amp;id_chofer=<?= $id_chofer_filtro ?>&amp;id_empresa=<?= $id_empresa_filtro ?>" target="_blank" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 hover:bg-red-700 transition-colors">
<span class="material-symbols-outlined text-sm">picture_as_pdf</span> PDF
</a>
<a href="<?= BASE_URL ?>/api/export.php?tipo=viajes&amp;vista=por_chofer&amp;formato=excel&amp;desde=<?= $fecha_desde ?>&amp;hasta=<?= $fecha_hasta ?>&amp;orden=<?= $orden ?>&amp;id_chofer=<?= $id_chofer_filtro ?>&amp;id_empresa=<?= $id_empresa_filtro ?>" class="bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 hover:bg-green-800 transition-colors">
<span class="material-symbols-outlined text-sm">table_chart</span> Excel
</a>
<?php endif; ?>
</form>

<?php if ($id_chofer_filtro > 0 && !empty($statsChofer)): ?>
<!-- Stats Chofer -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">Total Viajes</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= $statsChofer['total_viajes'] ?></div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM como Chofer</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($statsChofer['km_como_chofer'], 0) ?> km</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM como Ayudante</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($statsChofer['km_como_ayudante'], 0) ?> km</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<span class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Totales</span>
<div class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($statsChofer['km_total'], 0) ?> km</div>
</div>
</div>
<?php endif; ?>

<!-- Table Por Chofer -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap overflow-x-auto">
<table class="w-full table-fixed" style="min-width:900px">
<thead class="bg-surface-container-high/50">
<tr>
<th class="w-[80px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA</th>
<th class="w-[55px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">ROL</th>
<th class="px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CHOFER</th>
<th class="w-[80px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">CAM</th>
<th class="px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">RECORRIDO</th>
<th class="w-[100px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM</th>
<th class="w-[70px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">KM REC</th>
<th class="w-[90px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-right">T/P</th>
<th class="w-[50px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">EST</th>
<th class="w-[80px] px-2 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ACC</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="tableBody">
<?php foreach ($registrosList as $r):
$estado = $r['estado'] ?? 'abierto';
$rol = $r['rol'] ?? 'Chofer';
$rolCls = $rol === 'Ayudante' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800';
if ($estado === 'aprobado') $bCls = 'bg-green-100 text-green-800';
elseif ($estado === 'cerrado') $bCls = 'bg-blue-100 text-blue-800';
else $bCls = 'bg-amber-100 text-amber-800';
?>
<tr class="hover:bg-surface-container transition-colors text-[12px]">
<td class="px-2 py-2 font-data-mono whitespace-nowrap"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
<td class="px-2 py-2 whitespace-nowrap"><span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase <?= $rolCls ?>"><?= $rol ?></span></td>
<td class="px-2 py-2 truncate" title="<?= htmlspecialchars(($r['apellido'] ?? '') . ', ' . ($r['nombre'] ?? '')) ?>"><?= htmlspecialchars(($r['apellido'] ?? '') . ', ' . ($r['nombre'] ?? '')) ?></td>
<td class="px-2 py-2 font-bold whitespace-nowrap"><?= htmlspecialchars($r['patente']) ?><?php if (!empty($r['cachape_patente'])): ?> <span class="text-[9px] text-on-surface-variant font-normal">+<?= htmlspecialchars($r['cachape_patente']) ?></span><?php endif; ?></td>
<td class="px-2 py-2 truncate" title="<?= htmlspecialchars(($r['origen'] ?? '') . ' → ' . ($r['destino'] ?? '')) ?>"><?= htmlspecialchars(($r['origen'] ?? '-') . ' → ' . ($r['destino'] ?? '-')) ?></td>
<td class="px-2 py-2 text-right font-data-mono whitespace-nowrap text-[11px]"><?= $r['por_hora'] ? (number_format($r['hs_salida'], 1) . '&rarr;' . ($r['hs_llegada'] !== null ? number_format($r['hs_llegada'], 1) : '-')) : (number_format($r['km_salida'], 0) . '&rarr;' . ($r['km_llegada'] !== null ? number_format($r['km_llegada'], 0) : '-')) ?></td>
<td class="px-2 py-2 text-right font-data-mono font-bold whitespace-nowrap"><?= $r['por_hora'] ? ($r['hs_recorridas'] !== null ? number_format($r['hs_recorridas'], 1) . ' hs' : '-') : ($r['km_recorridos'] !== null ? number_format($r['km_recorridos'], 0) : '-') ?></td>
<td class="px-2 py-2 text-right font-data-mono whitespace-nowrap text-[11px]"><?= $r['tara'] !== null ? number_format($r['tara'], 0) : '' ?><?= $r['tara'] !== null && $r['peso_carga'] !== null ? '/' : '' ?><?= $r['peso_carga'] !== null ? number_format($r['peso_carga'], 0) : '' ?><?= $r['tara'] === null && $r['peso_carga'] === null ? '-' : '' ?></td>
<td class="px-2 py-2 text-center whitespace-nowrap">
<span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase <?= $bCls ?>"><?= $estado ?></span>
</td>
<td class="px-2 py-2 text-center whitespace-nowrap">
<div class="flex gap-1 justify-center">
<?php if ($estado === 'cerrado'): ?>
<form method="POST" class="inline" onsubmit="return confirm('¿Autorizar este viaje?')">
<input type="hidden" name="action" value="autorizar">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="px-1.5 py-1 bg-green-600 text-white rounded text-[9px] font-bold hover:bg-green-700" title="Autorizar">A</button>
</form>
<?php endif; ?>
<?php if ($estado !== 'abierto' && (esAdminPleno() || hasRole('Bascula') || hasRole('Báscula'))): ?>
<form method="POST" class="inline" onsubmit="return confirm('¿Revertir a pendiente de autorizacion?')">
<input type="hidden" name="action" value="revertir">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="px-1.5 py-1 bg-amber-500 text-white rounded text-[9px] font-bold hover:bg-amber-600" title="Revertir a Pendiente">R</button>
</form>
<?php endif; ?>
<button onclick="verViaje(<?= $r['id_hoja'] ?>)" class="px-1.5 py-1 bg-sky-600 text-white rounded text-[9px] font-bold hover:bg-sky-700" title="Ver detalle">V</button>
<?php if ($estado !== 'aprobado'): ?>
<button onclick="editViaje(<?= $r['id_hoja'] ?>)" class="px-1.5 py-1 bg-secondary-container text-on-secondary-container rounded text-[9px] font-bold hover:opacity-80" title="Editar">E</button>
<form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este viaje?')">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id_hoja" value="<?= $r['id_hoja'] ?>">
<button class="px-1.5 py-1 bg-red-50 text-red-600 rounded text-[9px] font-bold hover:bg-red-100" title="Borrar">X</button>
</form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php endif; ?>
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
<input name="km_salida" id="viajeKmSalida" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Llegada</label>
<input name="km_llegada" id="viajeKmLlegada" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4 hidden" id="hsFields">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">HS Salida (Horas)</label>
<input name="hs_salida" id="viajeHsSalida" type="number" step="0.01" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
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

<script>
var formSubmitted = false;

function htmlspecialchars(str) {
var div = document.createElement('div');
div.appendChild(document.createTextNode(str || ''));
return div.innerHTML;
}

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

document.getElementById('formViaje').addEventListener('submit', function(e) {
if (formSubmitted) { e.preventDefault(); return false; }
formSubmitted = true;
this.querySelector('button[type="submit"]').disabled = true;
this.querySelector('button[type="submit"]').innerText = 'Guardando...';
});



function resetModal() { formSubmitted = false;
document.getElementById('viajeAction').value = 'create';
document.getElementById('viajeId').value = '';
document.getElementById('viajeNroHoja').value = '';
document.getElementById('viajeAyudante').value = '';
document.getElementById('viajeCachape').value = '';
document.getElementById('viajeTara').value = '';
document.getElementById('viajePesoCarga').value = '';
document.getElementById('viajePesoTotal').value = '';
document.getElementById('viajeKmSalida').value = '';
document.getElementById('viajeKmLlegada').value = '';
document.getElementById('viajeHsSalida').value = '';
document.getElementById('viajeHsLlegada').value = '';
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
toggleFieldsByCamion();
}

function toggleOtro(inputId, otroId) {
const el = document.getElementById(inputId);
const otro = document.getElementById(otroId);
if (el.value === '__OTRO__') { otro.classList.remove('hidden'); otro.focus(); }
else { otro.classList.add('hidden'); otro.value = ''; }
}
document.getElementById('viajeOrigen').addEventListener('change', function(){ toggleOtro('viajeOrigen','viajeOrigenOtro'); });
document.getElementById('viajeDestino').addEventListener('change', function(){ toggleOtro('viajeDestino','viajeDestinoOtro'); });

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

function verViaje(id) {
function valor(v) { return (v !== null && v !== undefined && v !== '') ? v : '-'; }
function siHay(v, sufijo) { return (v !== null && v !== undefined && v !== '') ? v.toLocaleString('es-ES') + ' ' + sufijo : '-'; }
fetch('<?= BASE_URL ?>/api/get_data.php?action=viaje_detalle&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_hoja) {
alert('Error: No se pudieron obtener los datos');
return;
}
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
}).catch(err => {
alert('Error al cargar datos: ' + err.message);
});
}

function editViaje(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=viaje&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_hoja) {
alert('Error: No se pudieron obtener los datos');
return;
}
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
// Note: calcKmRec will be called inside toggleFieldsByCamion once the camion options populate
openModal('modalViaje');
}).catch(err => {
alert('Error al cargar datos: ' + err.message);
});
}

function cargarCamionesChofer(idChofer, selectedId) {
var sel = document.getElementById('viajeCamion');
sel.innerHTML = '<option value="">Cargando...</option>';
sel.disabled = true;
fetch('<?= BASE_URL ?>/api/get_data.php?action=chofer_camiones&id_chofer=' + idChofer)
.then(r => r.json()).then(data => {
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
        const s = parseFloat(hsSalida.value) || 0;
        const l = parseFloat(hsLlegada.value) || 0;
        kmRecDisplay.innerText = (l - s).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' hs';
    } else {
        const s = parseFloat(kmSalida.value) || 0;
        const l = parseFloat(kmLlegada.value) || 0;
        kmRecDisplay.innerText = (l - s).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' km';
    }
}
kmSalida.addEventListener('input', calcKmRec);
kmLlegada.addEventListener('input', calcKmRec);
hsSalida.addEventListener('input', calcKmRec);
hsLlegada.addEventListener('input', calcKmRec);

function filterTable() {
const search = document.getElementById('searchInput').value.toLowerCase();
document.querySelectorAll('#tableBody tr').forEach(row => {
row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
});
}
</script>

<!-- Confirmacion Modal -->
<div id="confirmModal" class="fixed inset-0 bg-black/50 z-[60] hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeConfirmModal()">
<div class="bg-surface-container-lowest rounded-2xl w-full max-w-sm p-6 text-center shadow-2xl animate-fadeIn">
<div id="confirmIcon" class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-4">
<span class="material-symbols-outlined text-amber-600 text-3xl">help</span>
</div>
<h3 id="confirmTitle" class="font-headline-sm text-headline-sm text-on-surface mb-2">Confirmar</h3>
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
