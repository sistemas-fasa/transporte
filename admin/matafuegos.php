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

$matafuegos = $db->query("SELECT m.*, c.patente, c.marca, c.modelo FROM matafuegos m LEFT JOIN camiones c ON m.id_camion = c.id_camion ORDER BY m.sector ASC, m.numero ASC")->fetchAll();
$camiones = $db->query("SELECT id_camion, patente, marca, modelo FROM camiones ORDER BY patente")->fetchAll();
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

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap overflow-x-auto">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-left">NRO</th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-left">SECTOR</th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center">CLASE</th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center hidden md:table-cell">RECARGA</th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center">VENCIMIENTO</th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-3 py-3 font-label-caps text-[12px] text-on-surface-variant text-left hidden lg:table-cell">VEHICULO</th>
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
} elseif ($diasRestantes <= 30) {
    $estadoClase = 'bg-amber-100 text-amber-700 border-amber-200';
    $estadoTexto = 'PRONTO A VENCER';
} else {
    $estadoClase = 'bg-green-100 text-green-700 border-green-200';
    $estadoTexto = 'OK';
}
?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-3 py-3 font-bold text-[13px]"><?= htmlspecialchars($m['numero']) ?></td>
<td class="px-3 py-3 text-[13px]"><?= htmlspecialchars($m['sector']) ?></td>
<td class="px-3 py-3 text-center">
<span class="px-2 py-1 rounded text-[11px] font-bold uppercase bg-blue-100 text-blue-700 border border-blue-200"><?= htmlspecialchars($m['clase']) ?></span>
</td>
<td class="px-3 py-3 text-center text-[13px] hidden md:table-cell"><?= $m['recarga'] ? date('d/m/Y', strtotime($m['recarga'])) : '-' ?></td>
<td class="px-3 py-3 text-center text-[13px] font-bold"><?= date('d/m/Y', strtotime($m['vencimiento'])) ?></td>
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
