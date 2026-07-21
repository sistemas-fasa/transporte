<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('alertas_ver');

$db = getDB();

// POST Handlers (Resolver / Reabrir Alertas) ANTES de output HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resolver'])) {
        $id = (int)$_POST['id_alerta'];
        $db->prepare("UPDATE alertas SET resuelta = 1 WHERE id_alerta = ?")->execute([$id]);
        header('Location: ' . BASE_URL . '/admin/alertas.php?ver=activas');
        exit;
    }
    if (isset($_POST['reabrir'])) {
        $id = (int)$_POST['id_alerta'];
        $db->prepare("UPDATE alertas SET resuelta = 0 WHERE id_alerta = ?")->execute([$id]);
        header('Location: ' . BASE_URL . '/admin/alertas.php?ver=activas');
        exit;
    }
}

$pageTitle = 'Alertas';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

// Generate alerts automatically
require_once __DIR__ . '/../includes/alertas_helper.php';
generarAlertasAutomaticas($db);

$ver = $_GET['ver'] ?? 'activas';
$isResueltas = ($ver === 'resueltas');

$countActivas = (int)$db->query("SELECT COUNT(*) FROM alertas WHERE resuelta = 0")->fetchColumn();
$countResueltas = (int)$db->query("SELECT COUNT(*) FROM alertas WHERE resuelta = 1")->fetchColumn();

$sqlWhere = $isResueltas ? "WHERE resuelta = 1" : "WHERE resuelta = 0";
$alertas = $db->query("SELECT a.*, c.patente FROM alertas a LEFT JOIN camiones c ON a.id_referencia = c.id_camion $sqlWhere ORDER BY FIELD(severidad,'rojo','amarillo','verde'), fecha_creacion DESC")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Centro de Alertas</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Monitoreo y notificaciones del sistema.</p>
</div>
</div>

<!-- Traffic Light Panel -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
<div class="bg-green-50 border border-green-200 rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-4xl text-green-600">check_circle</span>
<div class="font-headline-md text-headline-md text-green-800 mt-2"><?= count(array_filter($alertas, fn($a) => $a['severidad'] === 'verde')) ?></div>
<p class="font-label-caps text-label-caps text-green-700 uppercase">Estado OK</p>
</div>
<div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-4xl text-amber-500">warning</span>
<div class="font-headline-md text-headline-md text-amber-800 mt-2"><?= count(array_filter($alertas, fn($a) => $a['severidad'] === 'amarillo')) ?></div>
<p class="font-label-caps text-label-caps text-amber-700 uppercase">Advertencias</p>
</div>
<div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
<span class="material-symbols-outlined text-4xl text-red-600">error</span>
<div class="font-headline-md text-headline-md text-red-800 mt-2"><?= count(array_filter($alertas, fn($a) => $a['severidad'] === 'rojo')) ?></div>
<p class="font-label-caps text-label-caps text-red-700 uppercase">Criticos</p>
</div>
</div>

<!-- Alertas List & Tabs -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-6 border-b border-outline-variant flex flex-col sm:flex-row sm:items-center justify-between gap-4">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase"><?= $isResueltas ? 'Historial de Alertas Resueltas' : 'Alertas Activas' ?></h3>
<div class="flex gap-2 bg-surface-container-high/50 p-1 rounded-lg">
<a href="?ver=activas" class="px-4 py-2 rounded-md font-bold text-xs transition-colors <?= !$isResueltas ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:text-primary' ?>">
    Activas (<?= $countActivas ?>)
</a>
<a href="?ver=resueltas" class="px-4 py-2 rounded-md font-bold text-xs transition-colors <?= $isResueltas ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:text-primary' ?>">
    Resueltas (<?= $countResueltas ?>)
</a>
</div>
</div>

<div class="divide-y divide-outline-variant">
<?php if (empty($alertas)): ?>
<p class="p-6 text-on-surface-variant text-center"><?= $isResueltas ? 'No hay alertas en el historial de resueltas.' : 'No hay alertas activas. Todo en orden.' ?></p>
<?php else: ?>
<?php foreach ($alertas as $a):
$cfg = [
'rojo' => ['bg' => 'bg-red-50', 'border' => 'border-red-500', 'icon' => 'error', 'iconbg' => 'bg-red-600', 'label' => 'CRITICO', 'labeltext' => 'text-red-700'],
'amarillo' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-400', 'icon' => 'warning', 'iconbg' => 'bg-amber-500', 'label' => 'ADVERTENCIA', 'labeltext' => 'text-amber-700'],
'verde' => ['bg' => 'bg-green-50', 'border' => 'border-green-500', 'icon' => 'check_circle', 'iconbg' => 'bg-green-600', 'label' => 'OK', 'labeltext' => 'text-green-700'],
];
$c = $cfg[$a['severidad']] ?? $cfg['verde'];
?>
<div class="p-4 <?= $c['bg'] ?> border-l-4 <?= $c['border'] ?> flex items-start gap-4">
<div class="w-10 h-10 rounded-full <?= $c['iconbg'] ?> flex items-center justify-center text-white shrink-0">
<span class="material-symbols-outlined"><?= $c['icon'] ?></span>
</div>
<div class="flex-1">
<div class="flex justify-between items-start">
<div>
<p class="font-bold uppercase"><?= htmlspecialchars($a['mensaje']) ?></p>
<p class="text-xs text-on-surface-variant mt-1"><?= date('d/m/Y H:i', strtotime($a['fecha_creacion'])) ?></p>
</div>
<span class="text-[10px] font-bold <?= $c['labeltext'] ?>"><?= $c['label'] ?></span>
</div>
<form method="POST" class="mt-3">
<input type="hidden" name="id_alerta" value="<?= $a['id_alerta'] ?>"/>
<?php if ($isResueltas): ?>
<button type="submit" name="reabrir" class="text-xs font-bold text-amber-800 bg-amber-100 hover:bg-amber-200 px-3 py-1.5 rounded-lg border border-amber-300 transition-colors inline-flex items-center gap-1">
<span class="material-symbols-outlined text-[16px]">unarchive</span> Reabrir Alerta (Volver a Activas)
</button>
<?php else: ?>
<button type="submit" name="resolver" class="text-xs font-bold text-primary underline inline-flex items-center gap-1">
<span class="material-symbols-outlined text-[16px]">check_circle</span> Marcar como resuelta
</button>
<?php endif; ?>
</form>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
