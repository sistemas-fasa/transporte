<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Auditoría del Sistema';
$db = getDB();

// ─── Asegurar que las tablas de auditoría existan ───
try {
    $db->exec("CREATE TABLE IF NOT EXISTS auditoria (
        id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT DEFAULT NULL,
        accion VARCHAR(50) NOT NULL,
        tabla VARCHAR(50) DEFAULT NULL,
        id_registro INT DEFAULT NULL,
        detalle TEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario (id_usuario),
        INDEX idx_tabla (tabla),
        INDEX idx_fecha (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS auditoria_accesos (
        id_auditoria_acceso INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT DEFAULT NULL,
        fecha_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) DEFAULT NULL,
        accion VARCHAR(100) NOT NULL,
        modulo VARCHAR(50) DEFAULT NULL,
        id_registro INT DEFAULT NULL,
        detalle TEXT,
        user_agent VARCHAR(500) DEFAULT NULL,
        INDEX idx_usuario (id_usuario),
        INDEX idx_fecha (fecha_hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("Error inicializando tablas de auditoria: " . $e->getMessage());
}

// ─── Exportar a CSV si se solicita ───
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $tipoExport = $_GET['tab'] ?? 'operaciones';
    $desdeExp = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
    $hastaExp = $_GET['hasta'] ?? date('Y-m-d');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=auditoria_' . $tipoExport . '_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    // BOM para Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    if ($tipoExport === 'accesos') {
        fputcsv($output, ['ID', 'Fecha y Hora', 'Usuario', 'Acción', 'Módulo', 'Detalle', 'IP', 'User Agent']);
        $stmtExp = $db->prepare("SELECT a.*, u.username FROM auditoria_accesos a LEFT JOIN usuarios u ON a.id_usuario = u.id_usuario WHERE DATE(a.fecha_hora) BETWEEN ? AND ? ORDER BY a.fecha_hora DESC");
        $stmtExp->execute([$desdeExp, $hastaExp]);
        while ($row = $stmtExp->fetch()) {
            fputcsv($output, [
                $row['id_auditoria_acceso'],
                $row['fecha_hora'],
                $row['username'] ?: 'ID #' . $row['id_usuario'],
                $row['accion'],
                $row['modulo'] ?: '-',
                $row['detalle'] ?: '-',
                $row['ip_address'] ?: '-',
                $row['user_agent'] ?: '-'
            ]);
        }
    } else {
        fputcsv($output, ['ID', 'Fecha y Hora', 'Usuario', 'Acción', 'Tabla / Entidad', 'ID Registro', 'Detalle', 'IP']);
        $stmtExp = $db->prepare("SELECT a.*, u.username FROM auditoria a LEFT JOIN usuarios u ON a.id_usuario = u.id_usuario WHERE DATE(a.created_at) BETWEEN ? AND ? ORDER BY a.created_at DESC");
        $stmtExp->execute([$desdeExp, $hastaExp]);
        while ($row = $stmtExp->fetch()) {
            fputcsv($output, [
                $row['id_auditoria'],
                $row['created_at'],
                $row['username'] ?: 'ID #' . $row['id_usuario'],
                $row['accion'],
                $row['tabla'] ?: '-',
                $row['id_registro'] ?: '-',
                $row['detalle'] ?: '-',
                $row['ip_address'] ?: '-'
            ]);
        }
    }
    fclose($output);
    exit;
}

$tab = $_GET['tab'] ?? 'operaciones';
if (!in_array($tab, ['operaciones', 'accesos'])) {
    $tab = 'operaciones';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

// ─── Filtros ───
$filtro_usuario = trim($_GET['usuario'] ?? '');
$filtro_accion = trim($_GET['accion'] ?? '');
$filtro_modulo = trim($_GET['modulo'] ?? '');
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$limite = (int)($_GET['limite'] ?? 100);
if ($limite < 10) $limite = 10;
if ($limite > 1000) $limite = 1000;

// ─── 1. Accesos (auditoria_accesos) ───
$sqlAccesos = "SELECT a.*, u.username, u.email
        FROM auditoria_accesos a
        LEFT JOIN usuarios u ON a.id_usuario = u.id_usuario
        WHERE DATE(a.fecha_hora) BETWEEN ? AND ?";
$paramsAccesos = [$desde, $hasta];

if ($filtro_usuario !== '') {
    $sqlAccesos .= " AND (u.username LIKE ? OR u.email LIKE ? OR a.id_usuario = ?)";
    $paramsAccesos[] = "%$filtro_usuario%";
    $paramsAccesos[] = "%$filtro_usuario%";
    $paramsAccesos[] = is_numeric($filtro_usuario) ? (int)$filtro_usuario : 0;
}
if ($filtro_accion !== '') {
    $sqlAccesos .= " AND a.accion LIKE ?";
    $paramsAccesos[] = "%$filtro_accion%";
}
if ($filtro_modulo !== '') {
    $sqlAccesos .= " AND a.modulo = ?";
    $paramsAccesos[] = $filtro_modulo;
}

$sqlAccesos .= " ORDER BY a.fecha_hora DESC LIMIT " . $limite;

$registrosAccesos = [];
try {
    $stmtAcc = $db->prepare($sqlAccesos);
    $stmtAcc->execute($paramsAccesos);
    $registrosAccesos = $stmtAcc->fetchAll();
} catch (Exception $e) {
    error_log("Error query auditoria_accesos: " . $e->getMessage());
}

// ─── 2. Operaciones CRUD (auditoria) ───
$sqlCrud = "SELECT a.*, u.username, u.email
        FROM auditoria a
        LEFT JOIN usuarios u ON a.id_usuario = u.id_usuario
        WHERE DATE(a.created_at) BETWEEN ? AND ?";
$paramsCrud = [$desde, $hasta];

if ($filtro_usuario !== '') {
    $sqlCrud .= " AND (u.username LIKE ? OR u.email LIKE ? OR a.id_usuario = ?)";
    $paramsCrud[] = "%$filtro_usuario%";
    $paramsCrud[] = "%$filtro_usuario%";
    $paramsCrud[] = is_numeric($filtro_usuario) ? (int)$filtro_usuario : 0;
}
if ($filtro_accion !== '') {
    $sqlCrud .= " AND a.accion LIKE ?";
    $paramsCrud[] = "%$filtro_accion%";
}
if ($filtro_modulo !== '') {
    $sqlCrud .= " AND a.tabla = ?";
    $paramsCrud[] = $filtro_modulo;
}

$sqlCrud .= " ORDER BY a.created_at DESC LIMIT " . $limite;

$registrosCrud = [];
try {
    $stmtCrud = $db->prepare($sqlCrud);
    $stmtCrud->execute($paramsCrud);
    $registrosCrud = $stmtCrud->fetchAll();
} catch (Exception $e) {
    error_log("Error query auditoria: " . $e->getMessage());
}

// Opciones para combos de filtro
$modulosAccesos = [];
$accionesAccesos = [];
try {
    $modulosAccesos = $db->query("SELECT DISTINCT modulo FROM auditoria_accesos WHERE modulo IS NOT NULL AND modulo != '' ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
    $accionesAccesos = $db->query("SELECT DISTINCT accion FROM auditoria_accesos WHERE accion IS NOT NULL ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$tablasCrud = [];
$accionesCrud = [];
try {
    $tablasCrud = $db->query("SELECT DISTINCT tabla FROM auditoria WHERE tabla IS NOT NULL AND tabla != '' ORDER BY tabla")->fetchAll(PDO::FETCH_COLUMN);
    $accionesCrud = $db->query("SELECT DISTINCT accion FROM auditoria WHERE accion IS NOT NULL ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// KPIs Generales
$kpiTotalOperaciones = (int)$db->query("SELECT COUNT(*) FROM auditoria")->fetchColumn();
$kpiTotalAccesos = (int)$db->query("SELECT COUNT(*) FROM auditoria_accesos")->fetchColumn();
$kpiUsuariosAuditados = (int)$db->query("SELECT COUNT(DISTINCT id_usuario) FROM auditoria WHERE id_usuario IS NOT NULL")->fetchColumn();
$kpiUltimaActividad = $db->query("SELECT MAX(created_at) FROM auditoria")->fetchColumn();
?>

<div class="md:ml-64 pt-20 px-4 md:px-8 pb-16 min-h-screen bg-surface">
    <!-- Encabezado Principal -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-primary/10 rounded-xl text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">security</span>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-on-surface tracking-tight">Auditoría y Registro de Actividad</h1>
                    <p class="text-sm text-on-surface-variant">Trazabilidad detallada de cambios, operaciones y accesos al sistema.</p>
                </div>
            </div>
        </div>

        <!-- Botones de Rango Rápido y Exportación -->
        <div class="flex flex-wrap items-center gap-2">
            <a href="?tab=<?= $tab ?>&desde=<?= date('Y-m-d') ?>&hasta=<?= date('Y-m-d') ?>" class="px-3 py-2 bg-white border border-outline-variant text-xs font-bold rounded-xl hover:bg-slate-50 transition-all <?= ($desde === date('Y-m-d') && $hasta === date('Y-m-d')) ? 'bg-slate-100 border-primary text-primary' : 'text-slate-700' ?>">
                Hoy
            </a>
            <a href="?tab=<?= $tab ?>&desde=<?= date('Y-m-d', strtotime('-7 days')) ?>&hasta=<?= date('Y-m-d') ?>" class="px-3 py-2 bg-white border border-outline-variant text-xs font-bold rounded-xl hover:bg-slate-50 transition-all <?= ($desde === date('Y-m-d', strtotime('-7 days')) && $hasta === date('Y-m-d')) ? 'bg-slate-100 border-primary text-primary' : 'text-slate-700' ?>">
                7 días
            </a>
            <a href="?tab=<?= $tab ?>&desde=<?= date('Y-m-d', strtotime('-30 days')) ?>&hasta=<?= date('Y-m-d') ?>" class="px-3 py-2 bg-white border border-outline-variant text-xs font-bold rounded-xl hover:bg-slate-50 transition-all <?= ($desde === date('Y-m-d', strtotime('-30 days')) && $hasta === date('Y-m-d')) ? 'bg-slate-100 border-primary text-primary' : 'text-slate-700' ?>">
                30 días
            </a>
            <a href="?tab=<?= $tab ?>&export=csv&desde=<?= $desde ?>&hasta=<?= $hasta ?>" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold flex items-center gap-1.5 shadow-sm transition-all">
                <span class="material-symbols-outlined text-sm">download</span>
                <span>Exportar CSV</span>
            </a>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Operaciones CRUD</p>
                <h3 class="text-2xl font-bold text-primary mt-1"><?= number_format($kpiTotalOperaciones) ?></h3>
            </div>
            <div class="w-11 h-11 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">history</span>
            </div>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Inicios de Sesión</p>
                <h3 class="text-2xl font-bold text-emerald-600 mt-1"><?= number_format($kpiTotalAccesos) ?></h3>
            </div>
            <div class="w-11 h-11 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">login</span>
            </div>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Usuarios Registrados</p>
                <h3 class="text-2xl font-bold text-indigo-600 mt-1"><?= number_format($kpiUsuariosAuditados) ?></h3>
            </div>
            <div class="w-11 h-11 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">group</span>
            </div>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Último Evento</p>
                <h3 class="text-sm font-bold text-slate-800 mt-1"><?= $kpiUltimaActividad ? date('d/m/Y H:i', strtotime($kpiUltimaActividad)) : 'Sin registros' ?></h3>
            </div>
            <div class="w-11 h-11 bg-slate-100 text-slate-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">schedule</span>
            </div>
        </div>
    </div>

    <!-- Navegación por Pestañas -->
    <div class="border-b border-outline-variant mb-6">
        <nav class="flex space-x-6 overflow-x-auto no-scrollbar">
            <a href="?tab=operaciones&desde=<?= $desde ?>&hasta=<?= $hasta ?>" class="pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-all whitespace-nowrap <?= $tab === 'operaciones' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>">
                <span class="material-symbols-outlined text-lg">edit_note</span>
                Operaciones del Sistema (CRUD)
                <span class="px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700"><?= count($registrosCrud) ?></span>
            </a>

            <a href="?tab=accesos&desde=<?= $desde ?>&hasta=<?= $hasta ?>" class="pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-all whitespace-nowrap <?= $tab === 'accesos' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>">
                <span class="material-symbols-outlined text-lg">vpn_key</span>
                Registro de Accesos y Logins
                <span class="px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700"><?= count($registrosAccesos) ?></span>
            </a>
        </nav>
    </div>

    <!-- Filtros de Búsqueda -->
    <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">

            <div>
                <label class="block text-xs font-semibold text-on-surface-variant mb-1">Fecha Desde</label>
                <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl">
            </div>

            <div>
                <label class="block text-xs font-semibold text-on-surface-variant mb-1">Fecha Hasta</label>
                <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl">
            </div>

            <div>
                <label class="block text-xs font-semibold text-on-surface-variant mb-1">Usuario</label>
                <input type="text" name="usuario" value="<?= htmlspecialchars($filtro_usuario) ?>" placeholder="Buscar usuario..." class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl">
            </div>

            <div>
                <label class="block text-xs font-semibold text-on-surface-variant mb-1">Acción</label>
                <select name="accion" class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl">
                    <option value="">Todas las acciones</option>
                    <?php $listaAcc = ($tab === 'operaciones') ? $accionesCrud : $accionesAccesos; ?>
                    <?php foreach ($listaAcc as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>" <?= $filtro_accion === $a ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $a))) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-on-surface-variant mb-1"><?= $tab === 'operaciones' ? 'Tabla / Módulo' : 'Módulo' ?></label>
                <select name="modulo" class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl">
                    <option value="">Todos</option>
                    <?php $listaMod = ($tab === 'operaciones') ? $tablasCrud : $modulosAccesos; ?>
                    <?php foreach ($listaMod as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>" <?= $filtro_modulo === $m ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $m))) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="flex-1 py-2 px-4 bg-primary text-white text-sm font-semibold rounded-xl hover:bg-primary/90 transition-all flex items-center justify-center gap-1">
                    <span class="material-symbols-outlined text-sm">filter_alt</span> Filtrar
                </button>
                <a href="?tab=<?= $tab ?>" class="py-2 px-3 bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm font-semibold rounded-xl transition-all" title="Limpiar filtros">
                    <span class="material-symbols-outlined text-sm">refresh</span>
                </a>
            </div>
        </form>
    </div>

    <!-- ============================================================= -->
    <!-- TAB 1: OPERACIONES CRUD (CREACIONES, CAMBIOS, ELIMINACIONES)  -->
    <!-- ============================================================= -->
    <?php if ($tab === 'operaciones'): ?>
    <div class="bg-white rounded-3xl border border-outline-variant/60 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-on-surface border-collapse">
                <thead class="bg-slate-50 text-xs uppercase font-semibold text-on-surface-variant border-b border-outline-variant">
                    <tr>
                        <th class="py-3.5 px-4 w-16"># ID</th>
                        <th class="py-3.5 px-4">Fecha y Hora</th>
                        <th class="py-3.5 px-4">Usuario</th>
                        <th class="py-3.5 px-4 text-center">Acción</th>
                        <th class="py-3.5 px-4">Entidad / Módulo</th>
                        <th class="py-3.5 px-4">ID Reg.</th>
                        <th class="py-3.5 px-4">Detalle del Cambio</th>
                        <th class="py-3.5 px-4 font-mono text-xs">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/50">
                    <?php if (empty($registrosCrud)): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-on-surface-variant">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-outlined text-5xl text-slate-300 mb-2">history_toggle_off</span>
                                <p class="font-medium text-base text-slate-600">No se encontraron operaciones registradas</p>
                                <p class="text-xs text-slate-400 mt-1">Las altas, bajas y modificaciones realizadas en los distintos módulos se listarán aquí.</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($registrosCrud as $r): ?>
                    <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="py-3.5 px-4 font-mono font-semibold text-slate-400">#<?= $r['id_auditoria'] ?></td>
                        <td class="py-3.5 px-4 whitespace-nowrap">
                            <p class="font-semibold text-slate-800"><?= date('d/m/Y', strtotime($r['created_at'])) ?></p>
                            <p class="text-xs text-slate-500 font-mono"><?= date('H:i:s', strtotime($r['created_at'])) ?></p>
                        </td>
                        <td class="py-3.5 px-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center text-primary font-bold text-xs">
                                    <?= strtoupper(substr($r['username'] ?: 'U', 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($r['username'] ?: 'Usuario #' . $r['id_usuario']) ?></p>
                                    <?php if (!empty($r['email'])): ?>
                                    <p class="text-[10px] text-slate-400"><?= htmlspecialchars($r['email']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="py-3.5 px-4 text-center whitespace-nowrap">
                            <?php
                            $acc = strtolower($r['accion']);
                            $badgeClass = 'bg-slate-100 text-slate-700';
                            $icon = 'info';

                            if (in_array($acc, ['create', 'crear', 'alta', 'creacion'])) {
                                $badgeClass = 'bg-emerald-100 text-emerald-800 border border-emerald-200';
                                $icon = 'add_circle';
                            } elseif (in_array($acc, ['update', 'editar', 'modificar', 'actualizar'])) {
                                $badgeClass = 'bg-blue-100 text-blue-800 border border-blue-200';
                                $icon = 'edit';
                            } elseif (in_array($acc, ['delete', 'eliminar', 'baja', 'desactivar'])) {
                                $badgeClass = 'bg-red-100 text-red-800 border border-red-200';
                                $icon = 'delete';
                            } elseif (in_array($acc, ['asignar', 'asignar_vehiculo', 'asociar_chofer', 'asociar_usuario'])) {
                                $badgeClass = 'bg-amber-100 text-amber-800 border border-amber-200';
                                $icon = 'link';
                            } elseif (in_array($acc, ['activar', 'reset_password'])) {
                                $badgeClass = 'bg-purple-100 text-purple-800 border border-purple-200';
                                $icon = 'key';
                            }
                            ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold uppercase <?= $badgeClass ?>">
                                <span class="material-symbols-outlined text-xs"><?= $icon ?></span>
                                <?= htmlspecialchars($r['accion']) ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4">
                            <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-slate-100 text-slate-700">
                                <?= htmlspecialchars($r['tabla'] ?: 'General') ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-xs font-bold text-slate-500">
                            <?= $r['id_registro'] ? '#' . $r['id_registro'] : '-' ?>
                        </td>
                        <td class="py-3.5 px-4 text-slate-800 font-medium max-w-md">
                            <?= htmlspecialchars($r['detalle'] ?: '-') ?>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-xs text-slate-500 whitespace-nowrap">
                            <?= htmlspecialchars($r['ip_address'] ?: '-') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- TAB 2: ACCESOS Y LOGINS                                       -->
    <!-- ============================================================= -->
    <?php if ($tab === 'accesos'): ?>
    <div class="bg-white rounded-3xl border border-outline-variant/60 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-on-surface border-collapse">
                <thead class="bg-slate-50 text-xs uppercase font-semibold text-on-surface-variant border-b border-outline-variant">
                    <tr>
                        <th class="py-3.5 px-4 w-16"># ID</th>
                        <th class="py-3.5 px-4">Fecha y Hora</th>
                        <th class="py-3.5 px-4">Usuario</th>
                        <th class="py-3.5 px-4 text-center">Acción</th>
                        <th class="py-3.5 px-4">Módulo</th>
                        <th class="py-3.5 px-4">Detalle</th>
                        <th class="py-3.5 px-4 font-mono text-xs">IP</th>
                        <th class="py-3.5 px-4">Navegador / Dispositivo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/50">
                    <?php if (empty($registrosAccesos)): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-on-surface-variant">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-outlined text-5xl text-slate-300 mb-2">vpn_key_off</span>
                                <p class="font-medium text-base text-slate-600">No se encontraron accesos registrados</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($registrosAccesos as $r): ?>
                    <tr class="hover:bg-slate-50/80 transition-colors">
                        <td class="py-3.5 px-4 font-mono font-semibold text-slate-400">#<?= $r['id_auditoria_acceso'] ?></td>
                        <td class="py-3.5 px-4 whitespace-nowrap">
                            <p class="font-semibold text-slate-800"><?= date('d/m/Y', strtotime($r['fecha_hora'])) ?></p>
                            <p class="text-xs text-slate-500 font-mono"><?= date('H:i:s', strtotime($r['fecha_hora'])) ?></p>
                        </td>
                        <td class="py-3.5 px-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center text-primary font-bold text-xs">
                                    <?= strtoupper(substr($r['username'] ?: 'U', 0, 1)) ?>
                                </div>
                                <div>
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($r['username'] ?: 'Usuario #' . $r['id_usuario']) ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="py-3.5 px-4 text-center whitespace-nowrap">
                            <?php
                            $acc = strtolower($r['accion']);
                            $badgeClass = 'bg-slate-100 text-slate-700';
                            $icon = 'info';

                            if (strpos($acc, 'inicio_sesion') !== false || strpos($acc, 'login') !== false) {
                                $badgeClass = 'bg-emerald-100 text-emerald-800 border border-emerald-200';
                                $icon = 'login';
                            } elseif (strpos($acc, 'cierre_sesion') !== false || strpos($acc, 'logout') !== false) {
                                $badgeClass = 'bg-slate-200 text-slate-800 border border-slate-300';
                                $icon = 'logout';
                            } elseif (strpos($acc, 'fallido') !== false || strpos($acc, 'bloqueado') !== false || strpos($acc, 'error') !== false) {
                                $badgeClass = 'bg-red-100 text-red-800 border border-red-200';
                                $icon = 'warning';
                            }
                            ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold uppercase <?= $badgeClass ?>">
                                <span class="material-symbols-outlined text-xs"><?= $icon ?></span>
                                <?= htmlspecialchars(str_replace('_', ' ', $r['accion'])) ?>
                            </span>
                        </td>
                        <td class="py-3.5 px-4 font-semibold text-xs text-slate-700">
                            <?= htmlspecialchars($r['modulo'] ?: 'General') ?>
                        </td>
                        <td class="py-3.5 px-4 text-slate-800 font-medium">
                            <?= htmlspecialchars($r['detalle'] ?: '-') ?>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-xs text-slate-500 whitespace-nowrap">
                            <?= htmlspecialchars($r['ip_address'] ?: '-') ?>
                        </td>
                        <td class="py-3.5 px-4 text-xs text-slate-400 max-w-xs truncate" title="<?= htmlspecialchars($r['user_agent'] ?? '') ?>">
                            <?= htmlspecialchars($r['user_agent'] ?: '-') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
