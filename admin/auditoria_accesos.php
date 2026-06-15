<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$tab = $_GET['tab'] ?? 'accesos';
$pageTitle = 'Auditoria - ' . ($tab === 'accesos' ? 'Accesos' : 'Operaciones');
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();

// ─── Filtros ───
$filtro_usuario = $_GET['usuario'] ?? '';
$filtro_accion = $_GET['accion'] ?? '';
$filtro_modulo = $_GET['modulo'] ?? '';
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$limite = (int)($_GET['limite'] ?? 100);
if ($limite < 10) $limite = 10;
if ($limite > 500) $limite = 500;

// ─── Accesos (auditoria_accesos) ───
$sql = "SELECT a.*, u.username, u.nombre, u.apellido
        FROM auditoria_accesos a
        LEFT JOIN usuarios u ON a.id_usuario = u.id_usuario
        WHERE DATE(a.fecha_hora) BETWEEN ? AND ?";
$params = [$desde, $hasta];

if ($filtro_usuario) {
    $sql .= " AND (u.username LIKE ? OR u.nombre LIKE ? OR u.apellido LIKE ?)";
    $params[] = "%$filtro_usuario%"; $params[] = "%$filtro_usuario%"; $params[] = "%$filtro_usuario%";
}
if ($filtro_accion) {
    $sql .= " AND a.accion LIKE ?";
    $params[] = "%$filtro_accion%";
}
if ($filtro_modulo) {
    $sql .= " AND a.modulo = ?";
    $params[] = $filtro_modulo;
}

$sql .= " ORDER BY a.fecha_hora DESC LIMIT " . $limite;
$registrosList = [];
try {
    $registros = $db->prepare($sql);
    $registros->execute($params);
    $registrosList = $registros->fetchAll();
} catch (PDOException $e) {}

$modulos = [];
$acciones = [];
try {
    $modulos = $db->query("SELECT DISTINCT modulo FROM auditoria_accesos WHERE modulo IS NOT NULL ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
try {
    $acciones = $db->query("SELECT DISTINCT accion FROM auditoria_accesos ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ─── Operaciones CRUD (auditoria) ───
$crudSql = "SELECT a.*, u.username, u.nombre, u.apellido
        FROM auditoria a
        LEFT JOIN usuarios u ON a.id_usuario = u.id_usuario
        WHERE DATE(a.created_at) BETWEEN ? AND ?";
$crudParams = [$desde, $hasta];

if ($filtro_usuario) {
    $crudSql .= " AND (u.username LIKE ? OR u.nombre LIKE ? OR u.apellido LIKE ?)";
    $crudParams[] = "%$filtro_usuario%"; $crudParams[] = "%$filtro_usuario%"; $crudParams[] = "%$filtro_usuario%";
}
if ($filtro_accion) {
    $crudSql .= " AND a.accion LIKE ?";
    $crudParams[] = "%$filtro_accion%";
}
if ($filtro_modulo) {
    $crudSql .= " AND a.tabla = ?";
    $crudParams[] = $filtro_modulo;
}

$crudSql .= " ORDER BY a.created_at DESC LIMIT " . $limite;
$crudList = [];
try {
    $crud = $db->prepare($crudSql);
    $crud->execute($crudParams);
    $crudList = $crud->fetchAll();
} catch (PDOException $e) {
    // Tabla puede no existir
}

$tablasCrud = [];
try {
    $tablasCrud = $db->query("SELECT DISTINCT tabla FROM auditoria WHERE tabla IS NOT NULL ORDER BY tabla")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$accionesCrud = [];
try {
    $accionesCrud = $db->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-end justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Auditoria</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Registro detallado de actividad del sistema.</p>
</div>
<div class="flex gap-2">
<a href="?tab=accesos&desde=<?= date('Y-m-d') ?>&hasta=<?= date('Y-m-d') ?>" class="px-4 py-2 border border-outline text-primary rounded-lg font-bold text-sm hover:bg-surface-container-low">Hoy</a>
<a href="?tab=accesos&desde=<?= date('Y-m-d', strtotime('-7 days')) ?>&hasta=<?= date('Y-m-d') ?>" class="px-4 py-2 border border-outline text-primary rounded-lg font-bold text-sm hover:bg-surface-container-low">7 dias</a>
<a href="?tab=accesos&desde=<?= date('Y-m-d', strtotime('-30 days')) ?>&hasta=<?= date('Y-m-d') ?>" class="px-4 py-2 border border-outline text-primary rounded-lg font-bold text-sm hover:bg-surface-container-low">30 dias</a>
</div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-8 bg-surface-container-low p-1 rounded-xl border border-outline-variant w-fit">
<a href="?tab=accesos<?= $filtro_usuario ? '&usuario=' . urlencode($filtro_usuario) : '' ?><?= $filtro_accion ? '&accion=' . urlencode($filtro_accion) : '' ?><?= $filtro_modulo ? '&modulo=' . urlencode($filtro_modulo) : '' ?>&desde=<?= $desde ?>&hasta=<?= $hasta ?>&limite=<?= $limite ?>" class="px-5 py-2 rounded-lg font-bold text-sm transition-all <?= $tab === 'accesos' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">login</span> Accesos
</a>
<a href="?tab=operaciones<?= $filtro_usuario ? '&usuario=' . urlencode($filtro_usuario) : '' ?><?= $filtro_accion ? '&accion=' . urlencode($filtro_accion) : '' ?><?= $filtro_modulo ? '&modulo=' . urlencode($filtro_modulo) : '' ?>&desde=<?= $desde ?>&hasta=<?= $hasta ?>&limite=<?= $limite ?>" class="px-5 py-2 rounded-lg font-bold text-sm transition-all <?= $tab === 'operaciones' ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container-high' ?>">
<span class="material-symbols-outlined text-sm align-text-bottom">history</span> Operaciones CRUD
</a>
</div>

<!-- Filters -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl mb-8">
<form method="GET" class="flex flex-wrap items-end gap-4">
<input type="hidden" name="tab" value="<?= $tab ?>"/>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Desde</label>
<input type="date" name="desde" value="<?= $desde ?>" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm"/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Hasta</label>
<input type="date" name="hasta" value="<?= $hasta ?>" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm"/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Usuario</label>
<input type="text" name="usuario" value="<?= htmlspecialchars($filtro_usuario) ?>" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm w-32" placeholder="Buscar..."/>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Accion</label>
<select name="accion" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="">Todas</option>
<?php $accionOpts = $tab === 'operaciones' ? $accionesCrud : $acciones; ?>
<?php foreach ($accionOpts as $a): ?>
<option value="<?= htmlspecialchars($a) ?>" <?= $filtro_accion === $a ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $a))) ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs"><?= $tab === 'operaciones' ? 'Tabla' : 'Modulo' ?></label>
<select name="modulo" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="">Todos</option>
<?php $modOpts = $tab === 'operaciones' ? $tablasCrud : $modulos; ?>
<?php foreach ($modOpts as $m): ?>
<option value="<?= htmlspecialchars($m) ?>" <?= $filtro_modulo === $m ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $m))) ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Limite</label>
<select name="limite" class="border border-outline-variant rounded p-2 bg-surface-container-low text-sm">
<option value="50" <?= $limite === 50 ? 'selected' : '' ?>>50</option>
<option value="100" <?= $limite === 100 ? 'selected' : '' ?>>100</option>
<option value="200" <?= $limite === 200 ? 'selected' : '' ?>>200</option>
<option value="500" <?= $limite === 500 ? 'selected' : '' ?>>500</option>
</select>
</div>
<button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg font-bold text-sm">Filtrar</button>
</form>
</div>

<?php if ($tab === 'accesos'): ?>

<!-- Summary Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
<?php
$totalReg = count($registrosList ?? []);
$totalAcciones = 0;
$totalUsuariosActivos = 0;
$ultimaActividad = null;
try {
    $totalAcciones = $db->query("SELECT COUNT(DISTINCT accion) FROM auditoria_accesos WHERE DATE(fecha_hora) BETWEEN '$desde' AND '$hasta'")->fetchColumn();
    $totalUsuariosActivos = $db->query("SELECT COUNT(DISTINCT id_usuario) FROM auditoria_accesos WHERE DATE(fecha_hora) BETWEEN '$desde' AND '$hasta'")->fetchColumn();
    $ultimaActividad = $db->query("SELECT MAX(fecha_hora) FROM auditoria_accesos")->fetchColumn();
} catch (PDOException $e) {}
?>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Registros</p>
<p class="font-headline-md text-headline-md text-primary mt-1"><?= number_format($totalReg) ?></p>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Acciones</p>
<p class="font-headline-md text-headline-md text-primary mt-1"><?= $totalAcciones ?></p>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Usuarios</p>
<p class="font-headline-md text-headline-md text-primary mt-1"><?= $totalUsuariosActivos ?></p>
</div>
<div class="bg-surface-container-lowest border border-outline-variant p-4">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs">Ultima Act.</p>
<p class="font-headline-md text-headline-md text-primary mt-1 text-sm"><?= $ultimaActividad ? date('d/m H:i', strtotime($ultimaActividad)) : '-' ?></p>
</div>
</div>

<!-- Access Logs Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Registro de Accesos</h3>
</div>
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA/HORA</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">USUARIO</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">ACCION</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">MODULO</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">DETALLE</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">IP</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($registrosList)): ?>
<tr><td colspan="6" class="px-6 py-12 text-center text-on-surface-variant">No se encontraron registros para los filtros seleccionados</td></tr>
<?php else: ?>
<?php foreach ($registrosList as $r): ?>
<tr class="hover:bg-surface-container transition-colors text-sm">
<td class="px-6 py-4 font-data-mono whitespace-nowrap"><?= date('d/m/Y H:i:s', strtotime($r['fecha_hora'])) ?></td>
<td class="px-6 py-4">
<?php if ($r['id_usuario']): ?>
<span class="font-medium"><?= htmlspecialchars($r['username'] ?? 'Usuario #' . $r['id_usuario']) ?></span>
<?php else: ?>
<span class="text-on-surface-variant">-</span>
<?php endif; ?>
</td>
<td class="px-6 py-4">
<?php
$accionClass = '';
if (strpos($r['accion'], 'inicio_sesion') !== false) $accionClass = 'text-green-600';
elseif (strpos($r['accion'], 'error') !== false || strpos($r['accion'], 'fallido') !== false) $accionClass = 'text-red-600';
elseif (strpos($r['accion'], 'creacion') !== false || strpos($r['accion'], 'create') !== false) $accionClass = 'text-blue-600';
elseif (strpos($r['accion'], 'cambio') !== false) $accionClass = 'text-amber-600';
?>
<span class="font-medium <?= $accionClass ?>"><?= htmlspecialchars(str_replace('_', ' ', $r['accion'])) ?></span>
</td>
<td class="px-6 py-4"><?= htmlspecialchars($r['modulo'] ?? '-') ?></td>
<td class="px-6 py-4 max-w-xs truncate" title="<?= htmlspecialchars($r['detalle'] ?? '') ?>"><?= htmlspecialchars($r['detalle'] ?? '-') ?></td>
<td class="px-6 py-4 font-data-mono text-on-surface-variant"><?= htmlspecialchars($r['ip_address'] ?? '-') ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<?php elseif ($tab === 'operaciones'): ?>

<!-- CRUD Operations Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Operaciones CRUD</h3>
</div>
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">FECHA/HORA</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">USUARIO</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">ACCION</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">TABLA</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">ID REG.</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">DETALLE</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">IP</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($crudList)): ?>
<tr><td colspan="7" class="px-6 py-12 text-center text-on-surface-variant">No se encontraron registros. Puede que la tabla de auditoria aun no tenga datos.</td></tr>
<?php else: ?>
<?php foreach ($crudList as $r): ?>
<tr class="hover:bg-surface-container transition-colors text-sm">
<td class="px-6 py-4 font-data-mono whitespace-nowrap"><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></td>
<td class="px-6 py-4">
<span class="font-medium"><?= htmlspecialchars($r['username'] ?? 'Usuario #' . $r['id_usuario']) ?></span>
</td>
<td class="px-6 py-4">
<?php
$cClass = '';
if ($r['accion'] === 'create') $cClass = 'text-green-600';
elseif ($r['accion'] === 'update') $cClass = 'text-blue-600';
elseif ($r['accion'] === 'delete') $cClass = 'text-red-600';
elseif ($r['accion'] === 'asignar') $cClass = 'text-amber-600';
?>
<span class="font-bold uppercase <?= $cClass ?>"><?= htmlspecialchars($r['accion']) ?></span>
</td>
<td class="px-6 py-4"><span class="bg-surface-container-high px-2 py-0.5 rounded text-xs font-mono"><?= htmlspecialchars($r['tabla'] ?? '-') ?></span></td>
<td class="px-6 py-4 font-data-mono"><?= $r['id_registro'] ? '#' . $r['id_registro'] : '-' ?></td>
<td class="px-6 py-4 max-w-xs truncate" title="<?= htmlspecialchars($r['detalle'] ?? '') ?>"><?= htmlspecialchars($r['detalle'] ?? '-') ?></td>
<td class="px-6 py-4 font-data-mono text-on-surface-variant"><?= htmlspecialchars($r['ip_address'] ?? '-') ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<?php endif; ?>
</main>

<script>
// Filtro por tecla rapida en inputs de texto
document.querySelectorAll('input[name="usuario"]').forEach(el => {
el.addEventListener('keyup', function(e) {
if (e.key === 'Enter') this.closest('form').submit();
});
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
