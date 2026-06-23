<!-- Bottom Navigation Bar (Mobile Only) -->
<nav class="fixed bottom-0 left-0 w-full z-50 flex justify-around items-center px-2 pb-safe bg-surface-container-lowest md:hidden border-t border-outline-variant h-16">
<?php
$bottomNav = [];
if (isAdmin()) {
    $bottomNav = [
        ['label' => 'Inicio', 'icon' => 'home', 'link' => '/admin/dashboard.php'],
        ['label' => 'Vehículos', 'icon' => 'local_shipping', 'link' => '/admin/camiones.php'],
        ['label' => 'Reportes', 'icon' => 'analytics', 'link' => '/admin/reportes.php'],
    ];
} else {
    $bottomNav = [
        ['label' => 'Panel', 'icon' => 'home', 'link' => '/chofer/panel.php'],
    ];
    if (hasPermission('kilometraje_cargar')) {
        $bottomNav[] = ['label' => 'Viajes', 'icon' => 'map', 'link' => '/chofer/viajes.php'];
    }
    $bottomNav[] = ['label' => 'Combustible', 'icon' => 'local_gas_station', 'link' => '/chofer/cargar_combustible.php'];
}
<?php $currentUrl = $_SERVER['PHP_SELF']; ?>
<?php foreach ($bottomNav as $item):
    $isActive = strpos($currentUrl, $item['link']) !== false;
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex-1 flex flex-col items-center py-2 gap-1 transition-all cursor-pointer <?= $isActive ? 'bg-secondary-container text-on-secondary-container rounded-full px-4 py-1' : 'text-on-surface-variant' ?>">
<span class="material-symbols-outlined <?= $isActive ? 'text-on-secondary-container' : '' ?>"><?= $item['icon'] ?></span>
<span class="font-label-caps text-label-caps"><?= $isActive ? 'font-bold' : '' ?><?= $item['label'] ?></span>
</a>
<?php endforeach; ?>
</nav>

<!-- Modal Confirmacion Global -->
<div id="modalConfirm" class="fixed inset-0 bg-black/50 z-[60] hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-sm p-6 shadow-xl">
<p id="confirmMsg" class="font-body-md mb-6 text-center">¿Esta seguro?</p>
<div class="flex gap-3">
<button onclick="closeConfirm()" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold hover:bg-surface-container-low transition-colors">Cancelar</button>
<button id="confirmBtn" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold hover:opacity-90 transition-opacity">Confirmar</button>
</div>
</div>
</div>

<script>
var confirmCallback = null;
function showConfirm(msg, cb) {
document.getElementById('confirmMsg').textContent = msg;
confirmCallback = cb;
document.getElementById('modalConfirm').classList.remove('hidden');
}
function closeConfirm() {
document.getElementById('modalConfirm').classList.add('hidden');
confirmCallback = null;
}
document.addEventListener('DOMContentLoaded', function() {
document.getElementById('confirmBtn').addEventListener('click', function() {
if (confirmCallback) { confirmCallback(); }
closeConfirm();
});
});

document.querySelectorAll('button, a').forEach(elem => {
elem.addEventListener('mousedown', function() { this.classList.add('scale-[0.98]'); });
elem.addEventListener('mouseup', function() { this.classList.remove('scale-[0.98]'); });
});
document.querySelectorAll('.bg-primary, .bg-secondary').forEach(bar => {
if (bar.style.width) {
const finalWidth = bar.style.width;
bar.style.width = '0';
setTimeout(() => { bar.style.width = finalWidth; }, 300);
}
});
</script>
</body>
</html>
