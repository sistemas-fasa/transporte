<!-- Top Navigation Bar -->
<header class="fixed top-0 w-full z-50 bg-surface border-b border-outline-variant flex items-center justify-between px-margin-mobile md:px-margin-desktop h-16 max-w-[1440px] mx-auto left-0 right-0">
<div class="flex items-center gap-4">
<span class="material-symbols-outlined text-primary cursor-pointer md:hidden" onclick="toggleMobileMenu()">menu</span>
<h1 class="font-headline-md text-headline-md font-bold text-primary tracking-tight">GESTION DE FLOTA</h1>
</div>
<div class="flex items-center gap-4">
<div class="text-right hidden md:block">
<p class="font-label-caps text-label-caps text-on-surface-variant">HOLA, <?= strtoupper(htmlspecialchars(getCurrentUserName())) ?></p>
<p class="text-[10px] text-outline">CHOFER OPERATIVO</p>
</div>
<a href="<?= BASE_URL ?>/logout.php" class="material-symbols-outlined text-primary cursor-pointer">logout</a>
</div>
</header>

<!-- Sidebar (Desktop) -->
<aside id="sidebar" class="fixed left-0 top-0 bottom-0 z-40 h-full w-64 hidden md:flex flex-col bg-primary-container pt-20 transition-transform duration-300">
<div class="px-6 py-4 mb-4">
<h2 class="font-headline-sm text-headline-sm text-on-primary-container opacity-80">PANEL CHOFER</h2>
</div>
<nav class="flex-1 overflow-y-auto no-scrollbar">
<?php
$navItems = [
    ['label' => 'Mi Panel', 'icon' => 'dashboard', 'link' => '/chofer/panel.php', 'page' => 'panel.php'],
    ['label' => 'Cargar Combustible', 'icon' => 'local_gas_station', 'link' => '/chofer/cargar_combustible.php', 'page' => 'cargar_combustible.php'],
    ['label' => 'Registrar Mantenimiento', 'icon' => 'build', 'link' => '/chofer/registrar_mantenimiento.php', 'page' => 'registrar_mantenimiento.php'],
    ['label' => 'Mis Viajes', 'icon' => 'map', 'link' => '/chofer/viajes.php', 'page' => 'viajes.php'],
    ['label' => 'Mi Historial', 'icon' => 'history', 'link' => '/chofer/historial.php', 'page' => 'historial.php'],
];
foreach ($navItems as $item):
    $active = $currentPage === $item['page'];
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex items-center gap-3 p-4 mx-2 my-1 rounded-lg transition-all <?= $active ? 'bg-secondary text-on-secondary font-bold' : 'text-on-primary-container hover:bg-primary' ?>">
<span class="material-symbols-outlined"><?= $item['icon'] ?></span>
<span class="font-body-md"><?= $item['label'] ?></span>
</a>
<?php endforeach; ?>
</nav>
<div class="p-4 border-t border-on-primary-container/20">
<a href="<?= BASE_URL ?>/logout.php" class="flex items-center gap-3 text-on-primary-container hover:text-on-primary transition-colors p-2">
<span class="material-symbols-outlined">logout</span>
<span class="font-body-md">Cerrar Sesion</span>
</a>
</div>
</aside>

<!-- Mobile Sidebar Overlay -->
<div id="mobile-overlay" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden" onclick="toggleMobileMenu()"></div>

<!-- Mobile Sidebar -->
<aside id="mobile-sidebar" class="fixed left-0 top-0 bottom-0 z-40 h-full w-64 flex md:hidden flex-col bg-primary-container pt-20 transition-transform duration-300 -translate-x-full">
<nav class="flex-1 overflow-y-auto no-scrollbar">
<?php foreach ($navItems as $item):
    $active = $currentPage === $item['page'];
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex items-center gap-3 p-4 mx-2 my-1 rounded-lg transition-all <?= $active ? 'bg-secondary text-on-secondary font-bold' : 'text-on-primary-container hover:bg-primary' ?>">
<span class="material-symbols-outlined"><?= $item['icon'] ?></span>
<span class="font-body-md"><?= $item['label'] ?></span>
</a>
<?php endforeach; ?>
</nav>
</aside>

<script>
function toggleMobileMenu() {
const sidebar = document.getElementById('mobile-sidebar');
const overlay = document.getElementById('mobile-overlay');
sidebar.classList.toggle('-translate-x-full');
overlay.classList.toggle('hidden');
}
</script>
