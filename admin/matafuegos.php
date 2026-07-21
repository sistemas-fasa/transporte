<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('matafuegos_ver');
$pageTitle = 'Gestion de Matafuegos';
$mensaje = '';
$error = '';
if (isset($_GET['msg'])) {
    $msgMap = ['ok' => 'Matafuego guardado exitosamente', 'del' => 'Matafuego eliminado', 'err' => 'Error al guardar el matafuego'];
    $mensaje = $msgMap[$_GET['msg']] ?? '';
    $error = $_GET['msg'] === 'err' ? $mensaje : '';
}
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$mensaje = '';
$error = '';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS matafuegos (
        id_matafuego INT AUTO_INCREMENT PRIMARY KEY,
        numero VARCHAR(50) NOT NULL,
        sector VARCHAR(200) NOT NULL,
        clase VARCHAR(20) NOT NULL,
        recarga DATE DEFAULT NULL,
        vencimiento DATE NOT NULL,
        id_camion INT DEFAULT NULL,
        observaciones TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $numero = trim($_POST['numero'] ?? '');
        $sector = trim($_POST['sector'] ?? '');
        $clase = trim($_POST['clase'] ?? '');
        $recarga = trim($_POST['recarga'] ?? '');
        $vencimiento = trim($_POST['vencimiento'] ?? '');
        $id_camion = !empty($_POST['id_camion']) ? (int)$_POST['id_camion'] : null;
        $observaciones = trim($_POST['observaciones'] ?? '');

        if ($numero === '' || $sector === '' || $clase === '' || $vencimiento === '') {
            $error = 'Numero, sector, clase y vencimiento son obligatorios';
        } else {
            if ($action === 'create') {
                try {
                    $db->prepare("INSERT INTO matafuegos (numero, sector, clase, recarga, vencimiento, id_camion, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$numero, $sector, $clase, $recarga ?: null, $vencimiento, $id_camion, $observaciones ?: null]);
                    $_SESSION['flash_msg'] = 'Matafuego creado exitosamente';
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } catch (Exception $e) {
                    $_SESSION['flash_msg'] = 'Error: ' . $e->getMessage();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            } else {
                $id = (int)($_POST['id_matafuego'] ?? 0);
                try {
                    $db->prepare("UPDATE matafuegos SET numero=?, sector=?, clase=?, recarga=?, vencimiento=?, id_camion=?, observaciones=? WHERE id_matafuego=?")->execute([$numero, $sector, $clase, $recarga ?: null, $vencimiento, $id_camion, $observaciones ?: null, $id]);
                    $_SESSION['flash_msg'] = 'Matafuego actualizado';
                } catch (Exception $e) {
                    $_SESSION['flash_msg'] = 'Error: ' . $e->getMessage();
                }
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_matafuego'] ?? 0);
        try {
            $db->prepare("DELETE FROM matafuegos WHERE id_matafuego = ?")->execute([$id]);
            $_SESSION['flash_msg'] = 'Matafuego eliminado';
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = 'Error: ' . $e->getMessage();
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$sort = $_GET['sort'] ?? 'recarga';
$dir = strtolower($_GET['dir'] ?? 'desc');
if (!in_array($dir, ['asc', 'desc'])) $dir = 'desc';

$buscar = trim($_GET['buscar'] ?? '');
$estadoFiltro = $_GET['estado'] ?? '';

$sortMap = [
    'recarga' => "m.recarga IS NULL ASC, m.recarga " . strtoupper($dir) . ", m.numero ASC",
    'vencimiento' => "m.vencimiento " . strtoupper($dir) . ", m.numero ASC",
    'sector' => "m.sector " . strtoupper($dir) . ", m.numero ASC",
    'numero' => "m.numero " . strtoupper($dir),
    'clase' => "m.clase " . strtoupper($dir) . ", m.numero ASC",
    'patente' => "c.patente " . strtoupper($dir) . ", m.numero ASC"
];

$orderBy = $sortMap[$sort] ?? "m.recarga IS NULL ASC, m.recarga DESC, m.numero ASC";

$sql = "SELECT m.*, c.patente, c.marca, c.modelo FROM matafuegos m LEFT JOIN camiones c ON m.id_camion = c.id_camion WHERE 1=1";
$params = [];

if ($buscar !== '') {
    $sql .= " AND (m.numero LIKE ? OR m.sector LIKE ? OR m.clase LIKE ? OR c.patente LIKE ?)";
    $params = ["%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%"];
}

$sql .= " ORDER BY " . $orderBy;
$stmtM = $db->prepare($sql);
$stmtM->execute($params);
$matafuegosRaw = $stmtM->fetchAll();

$matafuegos = [];
$hoy = time();
foreach ($matafuegosRaw as $m) {
    $venc = strtotime($m['vencimiento']);
    $diasRestantes = ($venc - $hoy) / 86400;
    if ($diasRestantes < 0) {
        $est = 'vencido';
    } elseif ($diasRestantes <= 90) {
        $est = 'pronto';
    } else {
        $est = 'ok';
    }
    if ($estadoFiltro !== '' && $estadoFiltro !== $est) {
        continue;
    }
    $matafuegos[] = $m;
}

$camiones = $db->query("SELECT id_camion, patente, marca, modelo FROM camiones ORDER BY patente")->fetchAll();

function sortLink($columnName, $label, $currentSort, $currentDir) {
    $isCurrent = ($currentSort === $columnName);
    $newDir = ($isCurrent && $currentDir === 'asc') ? 'desc' : ($isCurrent ? 'asc' : ($columnName === 'recarga' ? 'desc' : 'asc'));
    $params = $_GET;
    $params['sort'] = $columnName;
    $params['dir'] = $newDir;
    $url = '?' . http_build_query($params);
    $icon = '';
    if ($isCurrent) {
        $icon = '<span class="material-symbols-outlined text-[14px] align-middle">' . ($currentDir === 'asc' ? 'arrow_upward' : 'arrow_downward') . '</span>';
    } else {
        $icon = '<span class="material-symbols-outlined text-[14px] opacity-30 group-hover:opacity-100 align-middle">unfold_more</span>';
    }
    return '<a href="' . htmlspecialchars($url) . '" class="inline-flex items-center gap-0.5 group hover:text-primary">' . htmlspecialchars($label) . ' ' . $icon . '</a>';
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Matafuegos</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Control de matafuegos, recargas, vencimientos y asignacion a vehiculos.</p>
</div>
<button onclick="resetModal(); openModal('modalMatafuego')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Matafuego
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Filtros y Ordenación -->
<form method="GET" class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl mb-6">
<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 items-end">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Buscar</label>
<input name="buscar" type="text" value="<?= htmlspecialchars($buscar) ?>" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm" placeholder="Número, sector, clase, patente..."/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Ordenar por</label>
<select name="sort_dir" onchange="applySortSelect(this)" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="recarga_desc" <?= ($sort==='recarga' && $dir==='desc') ? 'selected' : '' ?>>Fecha Recarga (Recientes primero)</option>
<option value="recarga_asc" <?= ($sort==='recarga' && $dir==='asc') ? 'selected' : '' ?>>Fecha Recarga (Antiguos primero)</option>
<option value="vencimiento_asc" <?= ($sort==='vencimiento' && $dir==='asc') ? 'selected' : '' ?>>Vencimiento (Próximos a vencer)</option>
<option value="vencimiento_desc" <?= ($sort==='vencimiento' && $dir==='desc') ? 'selected' : '' ?>>Vencimiento (Lejanos)</option>
<option value="sector_asc" <?= ($sort==='sector' && $dir==='asc') ? 'selected' : '' ?>>Sector (A - Z)</option>
<option value="numero_asc" <?= ($sort==='numero' && $dir==='asc') ? 'selected' : '' ?>>Número (A - Z)</option>
</select>
<input type="hidden" name="sort" id="sortInput" value="<?= htmlspecialchars($sort) ?>"/>
<input type="hidden" name="dir" id="dirInput" value="<?= htmlspecialchars($dir) ?>"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-[10px]">Estado</label>
<select name="estado" class="w-full border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="">Todos</option>
<option value="ok" <?= $estadoFiltro === 'ok' ? 'selected' : '' ?>>OK</option>
<option value="pronto" <?= $estadoFiltro === 'pronto' ? 'selected' : '' ?>>Pronto a vencer</option>
<option value="vencido" <?= $estadoFiltro === 'vencido' ? 'selected' : '' ?>>Vencidos</option>
</select>
</div>
<div class="flex gap-2">
<button type="submit" class="flex-1 bg-primary text-on-primary px-4 py-2 rounded-lg font-bold text-sm hover:opacity-90">Filtrar</button>
<a href="matafuegos.php" class="px-4 py-2 border border-outline text-primary rounded-lg text-sm font-bold hover:bg-surface-container-low text-center">Limpiar</a>
</div>
</div>
</form>

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap overflow-x-auto">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-left"><?= sortLink('numero', 'NRO', $sort, $dir) ?></th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-left"><?= sortLink('sector', 'SECTOR', $sort, $dir) ?></th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center"><?= sortLink('clase', 'CLASE', $sort, $dir) ?></th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center hidden md:table-cell"><?= sortLink('recarga', 'RECARGA', $sort, $dir) ?></th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center"><?= sortLink('vencimiento', 'VENCIMIENTO', $sort, $dir) ?></th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-left hidden lg:table-cell"><?= sortLink('patente', 'VEHICULO', $sort, $dir) ?></th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center">ACC</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($matafuegos)): ?>
<tr><td colspan="8" class="px-6 py-12 text-center text-on-surface-variant">No hay matafuegos registrados.</td></tr>
<?php else: ?>
<?php foreach ($matafuegos as $m): ?>
<?php
$hoy = time();
$venc = strtotime($m['vencimiento']);
$diasRestantes = ($venc - $hoy) / 86400;
if ($diasRestantes < 0) {
    $estadoClase = 'bg-red-100 text-red-700 border-red-200';
    $estadoTexto = 'VENCIDO';
    $rowClase = 'bg-red-50 hover:bg-red-100';
    $vencClase = 'text-red-600 font-bold';
} elseif ($diasRestantes <= 30) {
    $estadoClase = 'bg-red-100 text-red-700 border-red-200';
    $estadoTexto = 'VENCE PRONTO';
    $rowClase = 'bg-red-50 hover:bg-red-100';
    $vencClase = 'text-red-600 font-bold';
} elseif ($diasRestantes <= 90) {
    $estadoClase = 'bg-amber-100 text-amber-700 border-amber-200';
    $estadoTexto = 'PRONTO A VENCER';
    $rowClase = 'bg-amber-50 hover:bg-amber-100';
    $vencClase = 'text-amber-700 font-bold';
} else {
    $estadoClase = 'bg-green-100 text-green-700 border-green-200';
    $estadoTexto = 'OK';
    $rowClase = 'hover:bg-surface-container';
    $vencClase = 'text-on-surface font-bold';
}
?>
<tr class="transition-colors <?= $rowClase ?>">
<td class="px-3 py-3 font-bold text-[13px]"><?= htmlspecialchars($m['numero']) ?></td>
<td class="px-3 py-3 text-[13px]"><?= htmlspecialchars($m['sector']) ?></td>
<td class="px-3 py-3 text-center">
<span class="px-2 py-1 rounded text-[11px] font-bold uppercase bg-blue-100 text-blue-700 border border-blue-200"><?= htmlspecialchars($m['clase']) ?></span>
</td>
<td class="px-3 py-3 text-center text-[13px] hidden md:table-cell"><?= $m['recarga'] ? date('d/m/Y', strtotime($m['recarga'])) : '-' ?></td>
<td class="px-3 py-3 text-center text-[13px] <?= $vencClase ?>"><?= date('d/m/Y', strtotime($m['vencimiento'])) ?></td>
<td class="px-3 py-3 text-center">
<span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase border <?= $estadoClase ?>"><?= $estadoTexto ?></span>
</td>
<td class="px-3 py-3 text-[13px] hidden lg:table-cell"><?= $m['patente'] ? htmlspecialchars($m['patente'] . ' - ' . $m['marca'] . ' ' . $m['modelo']) : '<span class="text-on-surface-variant">-</span>' ?></td>
<td class="px-3 py-3">
<div class="flex gap-1 justify-center">
<button onclick="editMatafuego(<?= $m['id_matafuego'] ?>)" class="p-1 bg-secondary-container text-on-secondary-container rounded hover:opacity-80" title="Editar">
<span class="material-symbols-outlined text-[14px]">edit</span>
</button>
<form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este matafuego?')">
<input type="hidden" name="action" value="delete"/>
<input type="hidden" name="id_matafuego" value="<?= $m['id_matafuego'] ?>"/>
<button type="submit" class="p-1 bg-red-50 text-red-600 rounded hover:bg-red-100" title="Eliminar">
<span class="material-symbols-outlined text-[14px]">delete</span>
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

<!-- Modal -->
<div id="modalMatafuego" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalTitle" class="font-headline-sm text-headline-sm text-primary">Nuevo Matafuego</h3>
<button onclick="closeModal('modalMatafuego')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="matafuegoAction" value="create"/>
<input type="hidden" name="id_matafuego" id="matafuegoId" value=""/>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Numero *</label>
<input name="numero" id="mNumero" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Clase *</label>
<select name="clase" id="mClase" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar...</option>
<option value="A">A (Madera, Papel)</option>
<option value="B">B (Liquidos)</option>
<option value="C">C (Electricos)</option>
<option value="ABC">ABC (Polvo Quimico)</option>
<option value="CO2">CO2</option>
<option value="K">K (Aceites/Grasas)</option>
</select>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Sector *</label>
<input name="sector" id="mSector" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required placeholder="Ej: Deposito, Oficina 1, Taller..."/>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Recarga</label>
<input name="recarga" id="mRecarga" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Vencimiento *</label>
<input name="vencimiento" id="mVencimiento" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Asignar a Vehiculo</label>
<select name="id_camion" id="mIdCamion" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Sin vehiculo (solo sector)</option>
<?php foreach ($camiones as $c): ?>
<option value="<?= $c['id_camion'] ?>"><?= htmlspecialchars($c['patente'] . ' - ' . $c['marca'] . ' ' . $c['modelo']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Observaciones</label>
<textarea name="observaciones" id="mObservaciones" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalMatafuego')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<script>
function applySortSelect(selectEl) {
    const val = selectEl.value.split('_');
    document.getElementById('sortInput').value = val[0];
    document.getElementById('dirInput').value = val[1] || 'asc';
}

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function resetModal() {
document.getElementById('matafuegoAction').value = 'create';
document.getElementById('matafuegoId').value = '';
document.getElementById('mNumero').value = '';
document.getElementById('mSector').value = '';
document.getElementById('mClase').value = '';
document.getElementById('mRecarga').value = '';
document.getElementById('mVencimiento').value = '';
document.getElementById('mIdCamion').value = '';
document.getElementById('mObservaciones').value = '';
document.getElementById('modalTitle').textContent = 'Nuevo Matafuego';
}

var matafuegosData = <?= json_encode($matafuegos) ?>;

function editMatafuego(id) {
var m = matafuegosData.find(function(item) { return item.id_matafuego == id; });
if (!m) return;
document.getElementById('matafuegoAction').value = 'update';
document.getElementById('matafuegoId').value = m.id_matafuego;
document.getElementById('mNumero').value = m.numero;
document.getElementById('mSector').value = m.sector;
document.getElementById('mClase').value = m.clase;
document.getElementById('mRecarga').value = m.recarga ? m.recarga.split(' ')[0] : '';
document.getElementById('mVencimiento').value = m.vencimiento ? m.vencimiento.split(' ')[0] : '';
document.getElementById('mIdCamion').value = m.id_camion || '';
document.getElementById('mObservaciones').value = m.observaciones || '';
document.getElementById('modalTitle').textContent = 'Editar Matafuego';
openModal('modalMatafuego');
}


</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
