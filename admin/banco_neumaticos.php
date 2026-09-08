<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Banco de Neumaticos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

if (isset($_GET['ok'])) {
    $okMsgs = array('created' => 'Neumatico agregado.', 'asignado' => 'Neumatico asignado al camion.', 'devuelto' => 'Neumatico devuelto a bodega.', 'retirado' => 'Neumatico retirado.', 'deleted' => 'Neumatico eliminado.', 'updated' => 'Neumatico actualizado.');
    $mensaje = isset($okMsgs[$_GET['ok']]) ? $okMsgs[$_GET['ok']] : '';
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS neumaticos (id_neumatico INT AUTO_INCREMENT PRIMARY KEY, id_camion INT DEFAULT NULL, nro_serie VARCHAR(50) DEFAULT NULL, posicion VARCHAR(4) DEFAULT NULL, marca VARCHAR(100) DEFAULT NULL, modelo VARCHAR(100) DEFAULT NULL, medida VARCHAR(50) DEFAULT NULL, kilometraje_actual DECIMAL(12,2) DEFAULT 0, kilometraje_retiro DECIMAL(12,2) DEFAULT NULL, vida_util_km DECIMAL(12,2) DEFAULT NULL, fecha_instalacion DATE DEFAULT NULL, fecha_retiro DATE DEFAULT NULL, estado VARCHAR(20) DEFAULT 'en_bodega', observaciones TEXT DEFAULT NULL, id_usuario_registra INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_neum_camion (id_camion), INDEX idx_neum_estado (estado)) ENGINE=InnoDB");
} catch (Exception $e) {}
try { $db->exec("ALTER TABLE neumaticos MODIFY COLUMN id_camion INT DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE neumaticos MODIFY COLUMN posicion VARCHAR(4) DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE neumaticos ADD COLUMN nro_serie VARCHAR(50) DEFAULT NULL AFTER id_camion"); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'create') {
        $nro_serie = trim($_POST['nro_serie'] ?? '');
        $marca = trim($_POST['marca'] ?? '');
        $modelo = trim($_POST['modelo'] ?? '');
        $medida = trim($_POST['medida'] ?? '');
        $km_inicial = (float)($_POST['kilometraje_actual'] ?? 0);
        $obs = trim($_POST['observaciones'] ?? '');
        try {
            $stmt = $db->prepare("INSERT INTO neumaticos (nro_serie, marca, modelo, medida, kilometraje_actual, observaciones, estado, id_usuario_registra) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute(array($nro_serie ?: null, $marca, $modelo, $medida, $km_inicial ?: 0, $obs ?: null, 'en_bodega', getCurrentUserId()));
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ok=created');
            exit;
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    } elseif ($action === 'asignar') {
        $id = (int)($_POST['id_neumatico'] ?? 0);
        $id_camion = (int)($_POST['id_camion'] ?? 0);
        $posicion = $_POST['posicion'] ?? '';
        $km = (float)($_POST['kilometraje_actual'] ?? 0);
        $fecha = $_POST['fecha_instalacion'] ?? date('Y-m-d');
        $redir = $_POST['redir'] ?? '';
        try {
            $stmt = $db->prepare("UPDATE neumaticos SET id_camion=?, posicion=?, kilometraje_actual=?, fecha_instalacion=?, estado='en_uso' WHERE id_neumatico=?");
            $stmt->execute(array($id_camion, $posicion, $km, $fecha, $id));
            $extra = $redir ? '&vista=asignar&camion=' . $id_camion : '';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ok=asignado' . $extra);
            exit;
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    } elseif ($action === 'devolver') {
        $id = (int)($_POST['id_neumatico'] ?? 0);
        $km = (float)($_POST['kilometraje_actual'] ?? 0);
        $redir = $_POST['redir'] ?? '';
        $id_camion = $_POST['id_camion'] ?? '';
        try {
            $stmt = $db->prepare("UPDATE neumaticos SET id_camion=NULL, posicion=NULL, kilometraje_retiro=?, fecha_retiro=CURDATE(), estado='en_bodega', kilometraje_actual=? WHERE id_neumatico=?");
            $stmt->execute(array($km, $km, $id));
            $extra = $redir && $id_camion ? '&vista=asignar&camion=' . $id_camion : '';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ok=devuelto' . $extra);
            exit;
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    } elseif ($action === 'retirar') {
        $id = (int)($_POST['id_neumatico'] ?? 0);
        $km = (float)($_POST['kilometraje_retiro'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE neumaticos SET id_camion=NULL, posicion=NULL, kilometraje_retiro=?, fecha_retiro=CURDATE(), estado='retirado' WHERE id_neumatico=?");
            $stmt->execute(array($km, $id));
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ok=retirado');
            exit;
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_neumatico'] ?? 0);
        try { $db->prepare("DELETE FROM neumaticos WHERE id_neumatico=?")->execute(array($id)); header('Location: ' . $_SERVER['PHP_SELF'] . '?ok=deleted'); exit; } catch (Exception $e) { $error = 'Error al eliminar'; }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id_neumatico'] ?? 0);
        $nro_serie = trim($_POST['nro_serie'] ?? '');
        $marca = trim($_POST['marca'] ?? '');
        $modelo = trim($_POST['modelo'] ?? '');
        $medida = trim($_POST['medida'] ?? '');
        $km_inicial = (float)($_POST['kilometraje_actual'] ?? 0);
        $obs = trim($_POST['observaciones'] ?? '');
        try {
            $stmt = $db->prepare("UPDATE neumaticos SET nro_serie=?, marca=?, modelo=?, medida=?, kilometraje_actual=?, observaciones=? WHERE id_neumatico=?");
            $stmt->execute(array($nro_serie ?: null, $marca, $modelo, $medida, $km_inicial ?: 0, $obs ?: null, $id));
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ok=updated');
            exit;
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    }
}

$vista = $_GET['vista'] ?? 'inventario';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$buscar = isset($_GET['buscar']) ? $_GET['buscar'] : '';

$camiones = $db->query("SELECT id_camion, patente, marca, modelo, foto, tipo FROM camiones WHERE estado='activo' AND tipo != 'sector' AND control_neumaticos = 1 ORDER BY patente")->fetchAll();

// Inventario
$sql = "SELECT n.*, c.patente FROM neumaticos n LEFT JOIN camiones c ON n.id_camion = c.id_camion WHERE 1=1";
$params = array();
if ($filtro_estado) { $sql .= " AND n.estado = ?"; $params[] = $filtro_estado; }
if ($buscar) { $sql .= " AND (n.nro_serie LIKE ? OR n.marca LIKE ? OR n.modelo LIKE ? OR n.medida LIKE ? OR c.patente LIKE ?)"; for ($i = 0; $i < 5; $i++) $params[] = "%$buscar%"; }
$sql .= " ORDER BY n.estado ASC, n.marca ASC, n.modelo ASC";
$stmt = $db->prepare($sql); $stmt->execute($params); $neumaticos = $stmt->fetchAll();
$counts = array('en_uso' => 0, 'en_bodega' => 0, 'retirado' => 0);
foreach ($neumaticos as $n) { $est = isset($n['estado']) ? $n['estado'] : 'en_bodega'; if (!isset($counts[$est])) $counts[$est] = 0; $counts[$est]++; }
$total = $counts['en_uso'] + $counts['en_bodega'] + $counts['retirado'];

// Asignar por camion
$camionSel = $_GET['camion'] ?? ($camiones[0]['id_camion'] ?? 0);
$neumaticosBodega = $db->query("SELECT * FROM neumaticos WHERE estado='en_bodega' ORDER BY marca, modelo, nro_serie")->fetchAll();
$neumaticosCamion = [];
if ($camionSel) {
    $stmtN = $db->prepare("SELECT * FROM neumaticos WHERE id_camion=? AND estado='en_uso' ORDER BY posicion ASC");
    $stmtN->execute(array($camionSel));
    $neumaticosCamion = $stmtN->fetchAll();
}
$neumaticosMap = array();
foreach ($neumaticosCamion as $nc) { $neumaticosMap[$nc['posicion']] = $nc; }
$camionInfo = null;
foreach ($camiones as $c) { if ($c['id_camion'] == $camionSel) { $camionInfo = $c; break; } }
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Banco de Neumaticos</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Inventario y asignacion de neumaticos.</p>
</div>
<?php if ($vista !== 'asignar'): ?>
<button onclick="openModal('modalNuevo')" class="btn-modern bg-primary text-on-primary px-6 py-3 rounded-xl font-bold flex items-center gap-2">
<span class="material-symbols-outlined">add</span> Nuevo Neumatico
</button>
<?php endif; ?>
</div>

<!-- Tabs -->
<div class="flex border-b border-outline-variant mb-6">
<a href="?vista=inventario" class="px-6 py-3 font-bold text-sm border-b-2 transition-colors <?= $vista === 'inventario' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">inventory_2</span> Inventario
</a>
<a href="?vista=asignar" class="px-6 py-3 font-bold text-sm border-b-2 transition-colors <?= $vista === 'asignar' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">add_link</span> Asignar por Camion
</a>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?php echo htmlspecialchars($mensaje); ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if ($vista === 'inventario'): ?>
<!-- ==================== INVENTARIO ==================== -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
<a href="?vista=inventario" class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 text-center hover:border-primary transition-all <?php echo !$filtro_estado ? 'border-primary ring-1 ring-primary' : ''; ?>">
<p class="font-data-mono text-primary text-2xl font-bold"><?php echo $total; ?></p>
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Total</p>
</a>
<a href="?vista=inventario&estado=en_uso" class="bg-green-50 border border-green-200 rounded-xl p-4 text-center hover:border-green-500 transition-all <?php echo $filtro_estado === 'en_uso' ? 'ring-2 ring-green-500' : ''; ?>">
<p class="font-data-mono text-green-700 text-2xl font-bold"><?php echo $counts['en_uso']; ?></p>
<p class="font-label-caps text-green-600 uppercase text-[10px]">En Uso</p>
</a>
<a href="?vista=inventario&estado=en_bodega" class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-center hover:border-blue-500 transition-all <?php echo $filtro_estado === 'en_bodega' ? 'ring-2 ring-blue-500' : ''; ?>">
<p class="font-data-mono text-blue-700 text-2xl font-bold"><?php echo $counts['en_bodega']; ?></p>
<p class="font-label-caps text-blue-600 uppercase text-[10px]">En Bodega</p>
</a>
<a href="?vista=inventario&estado=retirado" class="bg-red-50 border border-red-200 rounded-xl p-4 text-center hover:border-red-500 transition-all <?php echo $filtro_estado === 'retirado' ? 'ring-2 ring-red-500' : ''; ?>">
<p class="font-data-mono text-red-700 text-2xl font-bold"><?php echo $counts['retirado']; ?></p>
<p class="font-label-caps text-red-600 uppercase text-[10px]">Retirados</p>
</a>
</div>

<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl mb-6 card-modern">
<div class="relative w-full">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input id="searchInput" onkeyup="filterTable()" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por nro serie, marca, modelo, medida o patente..." type="text" value="<?php echo htmlspecialchars($buscar); ?>"/>
</div>
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden card-modern">
<div class="overflow-x-auto">
<table class="w-full text-sm">
<thead class="bg-surface-container-low">
<tr>
<th class="px-4 py-3 text-left font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Nro Serie</th>
<th class="px-4 py-3 text-left font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Marca</th>
<th class="px-4 py-3 text-left font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Modelo</th>
<th class="px-4 py-3 text-left font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Medida</th>
<th class="px-4 py-3 text-center font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Estado</th>
<th class="px-4 py-3 text-left font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Camion</th>
<th class="px-4 py-3 text-center font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Posicion</th>
<th class="px-4 py-3 text-right font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">KM</th>
<th class="px-4 py-3 text-center font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Acciones</th>
</tr>
</thead>
<tbody id="neumaticosTable">
<?php if (empty($neumaticos)): ?>
<tr><td colspan="9" class="px-4 py-8 text-center text-on-surface-variant">No hay neumaticos registrados.</td></tr>
<?php else: ?>
<?php
$posLabels = array('1'=>'Del.Izq','2'=>'Del.Der','3'=>'Tr.Izq1','4'=>'Tr.Der1','5'=>'Tr.Izq2','6'=>'Tr.Der2','7'=>'Tr.Izq3','8'=>'Tr.Der3','9'=>'Tr.Izq4','10'=>'Tr.Der4','11'=>'Res.Izq','12'=>'Res.Der');
foreach ($neumaticos as $n):
$posLabel = isset($n['posicion']) && isset($posLabels[$n['posicion']]) ? $posLabels[$n['posicion']] : '-';
$searchStr = strtolower($n['nro_serie'].' '.$n['marca'].' '.$n['modelo'].' '.$n['medida'].' '.($n['patente'] ?? ''));
?>
<tr class="border-t border-outline-variant hover:bg-surface-container-low transition-colors" data-search="<?php echo htmlspecialchars($searchStr); ?>">
<td class="px-4 py-3 font-bold text-primary"><?php echo htmlspecialchars($n['nro_serie'] ?? '-'); ?></td>
<td class="px-4 py-3"><?php echo htmlspecialchars($n['marca']); ?></td>
<td class="px-4 py-3"><?php echo htmlspecialchars($n['modelo']); ?></td>
<td class="px-4 py-3 text-on-surface-variant"><?php echo htmlspecialchars($n['medida']); ?></td>
<td class="px-4 py-3 text-center">
<?php if ($n['estado'] === 'en_uso'): ?>
<span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase bg-green-100 text-green-800 border border-green-200">En Uso</span>
<?php elseif ($n['estado'] === 'en_bodega'): ?>
<span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase bg-blue-100 text-blue-800 border border-blue-200">En Bodega</span>
<?php else: ?>
<span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase bg-red-100 text-red-800 border border-red-200">Retirado</span>
<?php endif; ?>
</td>
<td class="px-4 py-3 font-bold"><?php echo htmlspecialchars($n['patente'] ?? '-'); ?></td>
<td class="px-4 py-3 text-center text-on-surface-variant"><?php echo $posLabel; ?></td>
<td class="px-4 py-3 text-right font-data-mono text-sm"><?php echo number_format($n['kilometraje_actual'], 0); ?></td>
<td class="px-4 py-3 text-center">
<div class="flex items-center justify-center gap-1">
<?php if ($n['estado'] !== 'retirado'): ?>
<button onclick="openEditar(<?php echo $n['id_neumatico']; ?>, '<?php echo htmlspecialchars($n['nro_serie'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($n['marca'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($n['modelo'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($n['medida'] ?? '', ENT_QUOTES); ?>', <?php echo $n['kilometraje_actual'] ?? 0; ?>, '<?php echo htmlspecialchars($n['observaciones'] ?? '', ENT_QUOTES); ?>')" class="bg-purple-50 text-purple-700 rounded-lg px-2 py-1 text-[10px] font-bold hover:bg-purple-100" title="Editar"><span class="material-symbols-outlined text-sm">edit</span></button>
<?php endif; ?>
<?php if ($n['estado'] === 'en_bodega'): ?>
<button onclick="openAsignar(<?php echo $n['id_neumatico']; ?>, '<?php echo htmlspecialchars($n['nro_serie'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($n['marca'] ?? '', ENT_QUOTES); ?> <?php echo htmlspecialchars($n['modelo'] ?? '', ENT_QUOTES); ?>')" class="bg-green-50 text-green-700 rounded-lg px-2 py-1 text-[10px] font-bold hover:bg-green-100" title="Asignar a camion"><span class="material-symbols-outlined text-sm">add_link</span></button>
<?php elseif ($n['estado'] === 'en_uso'): ?>
<button onclick="openDevolver(<?php echo $n['id_neumatico']; ?>, '<?php echo htmlspecialchars($n['nro_serie'] ?? '', ENT_QUOTES); ?>', <?php echo $n['kilometraje_actual']; ?>)" class="bg-amber-50 text-amber-700 rounded-lg px-2 py-1 text-[10px] font-bold hover:bg-amber-100" title="Devolver a bodega"><span class="material-symbols-outlined text-sm">inventory_2</span></button>
<?php endif; ?>
<?php if ($n['estado'] !== 'retirado'): ?>
<button onclick="openRetirar(<?php echo $n['id_neumatico']; ?>, '<?php echo htmlspecialchars($n['nro_serie'] ?? '', ENT_QUOTES); ?>', <?php echo $n['kilometraje_actual']; ?>)" class="bg-red-50 text-red-700 rounded-lg px-2 py-1 text-[10px] font-bold hover:bg-red-100" title="Retirar"><span class="material-symbols-outlined text-sm">delete</span></button>
<?php else: ?>
<form method="POST" class="inline" onsubmit="return confirm('Eliminar permanentemente?')"><input type="hidden" name="action" value="delete"/><input type="hidden" name="id_neumatico" value="<?php echo $n['id_neumatico']; ?>"/><button type="submit" class="bg-red-100 text-red-800 rounded-lg px-2 py-1 text-[10px] font-bold hover:bg-red-200" title="Eliminar"><span class="material-symbols-outlined text-sm">delete_forever</span></button></form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<?php else: ?>
<!-- ==================== ASIGNAR POR CAMION ==================== -->
<div class="mb-6">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-3 block">Seleccionar Camion</label>
<div class="flex flex-wrap gap-3">
<?php foreach ($camiones as $c):
$tiene = count($neumaticosPorCamion[$c['id_camion']] ?? []) > 0;
$isActive = $c['id_camion'] == $camionSel;
$urlSel = '?vista=asignar&camion=' . $c['id_camion'];
?>
<div class="relative camion-btn">
<a href="<?php echo $urlSel; ?>" class="inline-block px-5 py-3 rounded-lg text-sm font-bold transition-all <?php echo $isActive ? 'bg-primary text-on-primary shadow-md' : 'bg-surface-container-high text-on-surface-variant border border-outline-variant hover:bg-surface-container-highest'; ?>">
<?php echo htmlspecialchars($c['patente']); ?>
</a>
<div class="camion-tooltip hidden absolute z-50 bottom-full left-1/2 -translate-x-1/2 mb-3 w-52 bg-surface-container-lowest border border-outline-variant rounded-xl shadow-2xl overflow-hidden pointer-events-none">
<?php if ($c['foto']): ?>
<img src="<?php echo BASE_URL; ?>/assets/uploads/vehiculos/<?php echo htmlspecialchars($c['foto']); ?>" class="w-full h-32 object-cover" alt="<?php echo htmlspecialchars($c['patente']); ?>"/>
<?php else: ?>
<div class="w-full h-32 bg-surface-container-high flex items-center justify-center">
<span class="material-symbols-outlined text-on-surface-variant text-4xl">local_shipping</span>
</div>
<?php endif; ?>
<div class="p-3">
<p class="font-bold text-xs text-primary"><?php echo htmlspecialchars($c['patente']); ?></p>
<p class="text-[11px] text-on-surface-variant"><?php echo htmlspecialchars($c['marca'] . ' ' . $c['modelo']); ?></p>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
</div>

<?php if ($camionInfo): ?>
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl mb-6">
<div class="flex justify-between items-center">
<div>
<h4 class="font-bold text-primary"><?php echo htmlspecialchars($camionInfo['patente'] . ' - ' . $camionInfo['marca'] . ' ' . $camionInfo['modelo']); ?></h4>
<p class="text-sm text-on-surface-variant">Neumaticos en uso: <?php echo count($neumaticosCamion); ?> | Disponibles en bodega: <?php echo count($neumaticosBodega); ?></p>
</div>
<button onclick="openAsigPos(<?php echo $camionSel; ?>)" class="bg-primary text-on-primary px-4 py-2 rounded-lg font-bold text-sm flex items-center gap-1">
<span class="material-symbols-outlined text-sm">add</span> Asignar
</button>
</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
<?php
$posiciones = array('1'=>'Delantero Izq','2'=>'Delantero Der','3'=>'Trasero Izq 1','4'=>'Trasero Der 1','5'=>'Trasero Izq 2','6'=>'Trasero Der 2','7'=>'Trasero Izq 3','8'=>'Trasero Der 3','9'=>'Trasero Izq 4','10'=>'Trasero Der 4','11'=>'Respaldo Izq','12'=>'Respaldo Der');
$tipos4Gomas = array('auto', 'camioneta');
$usa4Gomas = isset($camionInfo['tipo']) && in_array($camionInfo['tipo'], $tipos4Gomas);
if ($usa4Gomas) { $posiciones = array('1'=>'Delantero Izq','2'=>'Delantero Der','3'=>'Trasero Izq','4'=>'Trasero Der'); }
foreach ($posiciones as $pos => $nombrePos):
    $neum = $neumaticosMap[$pos] ?? null;
?>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden <?php echo $neum ? '' : 'border-dashed border-2'; ?>">
<div class="p-4">
<div class="flex justify-between items-start mb-2">
<span class="font-label-caps text-[10px] text-on-surface-variant uppercase">Pos <?php echo $pos; ?> - <?php echo $nombrePos; ?></span>
<?php if ($neum): ?>
<div class="flex gap-1">
<form method="POST" class="inline" onsubmit="return confirm('Devolver a bodega?')">
<input type="hidden" name="action" value="devolver"/>
<input type="hidden" name="id_neumatico" value="<?php echo $neum['id_neumatico']; ?>"/>
<input type="hidden" name="kilometraje_actual" value="<?php echo $neum['kilometraje_actual']; ?>"/>
<input type="hidden" name="redir" value="1"/>
<input type="hidden" name="id_camion" value="<?php echo $camionSel; ?>"/>
<button type="submit" class="text-amber-500 hover:text-amber-700" title="Devolver a bodega"><span class="material-symbols-outlined text-sm">inventory_2</span></button>
</form>
</div>
<?php endif; ?>
</div>
<?php if ($neum): ?>
<div class="space-y-1">
<p class="font-bold text-sm"><?php echo htmlspecialchars($neum['marca'] ?: 'Sin marca'); ?></p>
<?php if ($neum['modelo']): ?><p class="text-xs text-on-surface-variant"><?php echo htmlspecialchars($neum['modelo']); ?></p><?php endif; ?>
<?php if ($neum['medida']): ?><p class="text-xs text-on-surface-variant">Medida: <?php echo htmlspecialchars($neum['medida']); ?></p><?php endif; ?>
<div class="flex justify-between items-center mt-2 pt-2 border-t border-outline-variant">
<span class="text-xs text-on-surface-variant">KM:</span>
<span class="font-data-mono font-bold text-sm"><?php echo number_format($neum['kilometraje_actual'], 0); ?></span>
</div>
<?php if ($neum['nro_serie']): ?><p class="text-[10px] text-on-surface-variant mt-1">Serie: <?php echo htmlspecialchars($neum['nro_serie']); ?></p><?php endif; ?>
</div>
<?php else: ?>
<div class="text-center py-4">
<span class="material-symbols-outlined text-on-surface-variant text-3xl">tire_repair</span>
<p class="text-xs text-on-surface-variant mt-1">Sin neumatico</p>
<button onclick="openAsigPos(<?php echo $camionSel; ?>, '<?php echo $pos; ?>')" class="mt-2 text-primary text-xs font-bold">+ Asignar</button>
</div>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="text-center py-12">
<span class="material-symbols-outlined text-on-surface-variant text-5xl">local_shipping</span>
<p class="text-on-surface-variant mt-2">Seleccione un camion para ver sus neumaticos</p>
</div>
<?php endif; ?>
<?php endif; ?>
</main>

<!-- Modal Nuevo Neumatico -->
<div id="modalNuevo" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalNuevo')">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-md modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Nuevo Neumatico</h3>
<button onclick="closeModal('modalNuevo')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="create"/>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nro Serie</label><input name="nro_serie" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Marca</label><input name="marca" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Modelo</label><input name="modelo" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required/></div>
</div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Medida</label><input name="medida" placeholder="ej: 295/80R22.5" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM</label><input name="kilometraje_actual" type="number" step="1" value="0" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Observaciones</label><textarea name="observaciones" rows="2" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"></textarea></div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalNuevo')" class="flex-1 border border-outline text-primary py-2.5 rounded-xl font-bold hover:bg-surface-container-low transition-all">Cancelar</button>
<button type="submit" class="btn-modern flex-1 bg-primary text-on-primary py-2.5 rounded-xl font-bold">Guardar en Bodega</button>
</div>
</form>
</div>
</div>

<!-- Modal Asignar (desde inventario) -->
<div id="modalAsignar" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalAsignar')">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-md modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Asignar a Camion</h3>
<button onclick="closeModal('modalAsignar')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="asignar"/>
<input type="hidden" name="id_neumatico" id="asignarId"/>
<p class="text-sm text-on-surface-variant">Neumatico: <strong id="asignarInfo"></strong></p>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Camion</label>
<select name="id_camion" id="asigCamion" onchange="filtrarPosiciones()" required class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none">
<option value="">Seleccionar camion...</option>
<?php foreach ($camiones as $c): ?><option value="<?php echo $c['id_camion']; ?>" data-tipo="<?php echo $c['tipo']; ?>"><?php echo htmlspecialchars($c['patente']); ?> - <?php echo htmlspecialchars($c['marca']); ?> <?php echo htmlspecialchars($c['modelo']); ?></option><?php endforeach; ?>
</select></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Posicion</label>
<select name="posicion" id="asigPosicion" required class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none">
<option value="">Seleccionar...</option>
<option value="1" data-g4="1">1 - Delantero Izquierdo</option><option value="2" data-g4="1">2 - Delantero Derecho</option>
<option value="3" data-g4="1">3 - Trasero Izq 1</option><option value="4" data-g4="1">4 - Trasero Der 1</option>
<option value="5">5 - Trasero Izq 2</option><option value="6">6 - Trasero Der 2</option>
<option value="7">7 - Trasero Izq 3</option><option value="8">8 - Trasero Der 3</option>
<option value="9">9 - Trasero Izq 4</option><option value="10">10 - Trasero Der 4</option>
<option value="11">11 - Respaldo Izq</option><option value="12">12 - Respaldo Der</option>
</select></div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Actual</label><input name="kilometraje_actual" type="number" step="0.01" value="0" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha Instalacion</label><input name="fecha_instalacion" type="date" value="<?php echo date('Y-m-d'); ?>" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalAsignar')" class="flex-1 border border-outline text-primary py-2.5 rounded-xl font-bold hover:bg-surface-container-low transition-all">Cancelar</button>
<button type="submit" class="btn-modern flex-1 bg-green-600 text-white py-2.5 rounded-xl font-bold">Asignar</button>
</div>
</form>
</div>
</div>

<!-- Modal Asignar por posicion (desde grilla) -->
<div id="modalAsigPos" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalAsigPos')">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-md modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Asignar Neumatico</h3>
<button onclick="closeModal('modalAsigPos')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="asignar"/>
<input type="hidden" name="redir" value="1"/>
<input type="hidden" name="id_camion" value="<?php echo $camionSel; ?>"/>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Neumatico (Bodega)</label>
<select name="id_neumatico" required class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none">
<option value="">Seleccionar neumatico...</option>
<?php foreach ($neumaticosBodega as $nb): ?>
<option value="<?php echo $nb['id_neumatico']; ?>"><?php echo htmlspecialchars(($nb['nro_serie'] ?: '?') . ' - ' . $nb['marca'] . ' ' . $nb['modelo']); ?></option>
<?php endforeach; ?>
</select></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Posicion</label>
<select name="posicion" id="asigPosPosicion" required class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none">
<option value="">Seleccionar...</option>
<?php foreach ($posiciones as $pp => $np): ?><option value="<?php echo $pp; ?>"><?php echo $pp; ?> - <?php echo $np; ?></option><?php endforeach; ?>
</select></div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Actual</label><input name="kilometraje_actual" type="number" step="0.01" value="0" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Fecha Instalacion</label><input name="fecha_instalacion" type="date" value="<?php echo date('Y-m-d'); ?>" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalAsigPos')" class="flex-1 border border-outline text-primary py-2.5 rounded-xl font-bold hover:bg-surface-container-low transition-all">Cancelar</button>
<button type="submit" class="btn-modern flex-1 bg-green-600 text-white py-2.5 rounded-xl font-bold">Asignar</button>
</div>
</form>
</div>
</div>

<!-- Modal Devolver -->
<div id="modalDevolver" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalDevolver')">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-md modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Devolver a Bodega</h3>
<button onclick="closeModal('modalDevolver')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="devolver"/>
<input type="hidden" name="id_neumatico" id="devolverId"/>
<p class="text-sm text-on-surface-variant">Neumatico: <strong id="devolverInfo"></strong></p>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Actual al Retirar</label><input name="kilometraje_actual" type="number" step="0.01" id="devolverKm" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalDevolver')" class="flex-1 border border-outline text-primary py-2.5 rounded-xl font-bold hover:bg-surface-container-low transition-all">Cancelar</button>
<button type="submit" class="btn-modern flex-1 bg-amber-600 text-white py-2.5 rounded-xl font-bold">Devolver</button>
</div>
</form>
</div>
</div>

<!-- Modal Retirar -->
<div id="modalRetirar" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalRetirar')">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-md modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Retirar del Sistema</h3>
<button onclick="closeModal('modalRetirar')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="retirar"/>
<input type="hidden" name="id_neumatico" id="retirarId"/>
<p class="text-sm text-on-surface-variant">Neumatico: <strong id="retirarInfo"></strong></p>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Inicial</label><input type="number" step="0.01" id="retirarDesde" readonly class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM Final al Retirar</label><input name="kilometraje_retiro" type="number" step="0.01" id="retirarKm" oninput="calcRetirarKm()" required class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
</div>
<div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-center">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Km que aguanto</p>
<p id="retirarAguanto" class="font-data-mono text-blue-700 font-bold text-lg">0 km</p>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalRetirar')" class="flex-1 border border-outline text-primary py-2.5 rounded-xl font-bold hover:bg-surface-container-low transition-all">Cancelar</button>
<button type="submit" class="btn-modern flex-1 bg-red-600 text-white py-2.5 rounded-xl font-bold">Retirar</button>
</div>
</form>
</div>
</div>

<!-- Modal Editar -->
<div id="modalEditar" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4" onclick="if(event.target===this)closeModal('modalEditar')">
<div class="bg-surface-container-lowest rounded-2xl shadow-2xl w-full max-w-md modal-modern">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Editar Neumatico</h3>
<button onclick="closeModal('modalEditar')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="update"/>
<input type="hidden" name="id_neumatico" id="editarId"/>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nro Serie</label><input name="nro_serie" id="editarSerie" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Marca</label><input name="marca" id="editarMarca" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Modelo</label><input name="modelo" id="editarModelo" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none" required/></div>
</div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Medida</label><input name="medida" id="editarMedida" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">KM</label><input name="kilometraje_actual" id="editarKm" type="number" step="1" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"/></div>
<div class="flex flex-col gap-1"><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Observaciones</label><textarea name="observaciones" id="editarObs" rows="2" class="input-modern w-full border border-outline-variant rounded-xl p-3 bg-surface-container-low focus:outline-none"></textarea></div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalEditar')" class="flex-1 border border-outline text-primary py-2.5 rounded-xl font-bold hover:bg-surface-container-low transition-all">Cancelar</button>
<button type="submit" class="btn-modern flex-1 bg-primary text-on-primary py-2.5 rounded-xl font-bold">Guardar Cambios</button>
</div>
</form>
</div>
</div>

<script>
function filterTable() {
    var q = document.getElementById('searchInput').value.toLowerCase();
    var rows = document.querySelectorAll('#neumaticosTable tr[data-search]');
    for (var i = 0; i < rows.length; i++) { rows[i].style.display = rows[i].getAttribute('data-search').indexOf(q) >= 0 ? '' : 'none'; }
}
function openAsignar(id, serie, info) {
    document.getElementById('asignarId').value = id;
    document.getElementById('asignarInfo').textContent = serie ? serie + ' - ' + info : info;
    openModal('modalAsignar');
}
function openDevolver(id, serie, km) {
    document.getElementById('devolverId').value = id;
    document.getElementById('devolverInfo').textContent = serie || 'ID: ' + id;
    document.getElementById('devolverKm').value = km || 0;
    openModal('modalDevolver');
}
function openRetirar(id, serie, km) {
    document.getElementById('retirarId').value = id;
    document.getElementById('retirarInfo').textContent = serie || 'ID: ' + id;
    document.getElementById('retirarKm').value = km || 0;
    document.getElementById('retirarDesde').value = km || 0;
    calcRetirarKm();
    openModal('modalRetirar');
}
function calcRetirarKm() {
    var desde = parseFloat(document.getElementById('retirarDesde').value) || 0;
    var hasta = parseFloat(document.getElementById('retirarKm').value) || 0;
    var diff = Math.max(0, hasta - desde);
    document.getElementById('retirarAguanto').textContent = diff.toLocaleString('es-AR') + ' km';
}
function openEditar(id, serie, marca, modelo, medida, km, obs) {
    document.getElementById('editarId').value = id;
    document.getElementById('editarSerie').value = serie || '';
    document.getElementById('editarMarca').value = marca || '';
    document.getElementById('editarModelo').value = modelo || '';
    document.getElementById('editarMedida').value = medida || '';
    document.getElementById('editarKm').value = km || '';
    document.getElementById('editarObs').value = obs || '';
    openModal('modalEditar');
}
function openAsigPos(camionId, pos) {
    if (pos) document.getElementById('asigPosPosicion').value = pos;
    openModal('modalAsigPos');
}
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function filtrarPosiciones() {
    var sel = document.getElementById('asigCamion');
    var tipo = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].getAttribute('data-tipo') : '';
    var usa4 = (tipo === 'auto' || tipo === 'camioneta');
    var pos = document.getElementById('asigPosicion');
    for (var i = 0; i < pos.options.length; i++) {
        var g4 = pos.options[i].getAttribute('data-g4');
        pos.options[i].style.display = (usa4 && !g4) ? 'none' : '';
    }
    if (usa4) {
        for (var i = 0; i < pos.options.length; i++) {
            if (pos.options[i].selected && pos.options[i].getAttribute('data-g4') !== '1') { pos.value = ''; }
        }
    }
}
</script>
<style>
.camion-btn:hover .camion-tooltip { display: block !important; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
