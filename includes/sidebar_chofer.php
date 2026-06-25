<!-- Top Navigation Bar -->
<header class="fixed top-0 w-full z-50 glass-header flex items-center justify-between px-margin-mobile md:px-margin-desktop h-16 max-w-[1440px] mx-auto left-0 right-0">
<div class="flex items-center gap-4">
<span class="material-symbols-outlined text-primary cursor-pointer md:hidden" onclick="toggleMobileMenu()">menu</span>
<a href="<?= BASE_URL ?>/chofer/panel.php" class="logo-link">
<img src="<?= BASE_URL ?>/Logo/Logo_App.png" alt="Logo" class="h-9 w-auto object-contain logo-img"/>
</a>
<h1 class="font-headline-md text-headline-md font-bold text-primary tracking-tight">CONTROL COMBUSTIBLE Y KM</h1>
</div>
<div class="flex items-center gap-4">
<div class="text-right hidden md:block">
<p class="font-label-caps text-label-caps text-on-surface-variant">HOLA, <?= strtoupper(htmlspecialchars(getCurrentUserName())) ?></p>
<p class="text-[10px] text-outline"><?= strtoupper(htmlspecialchars(getCurrentUserRoles()[0]['nombre'] ?? 'CHOFER')) ?></p>
</div>
<a href="<?= BASE_URL ?>/logout.php" class="material-symbols-outlined text-primary cursor-pointer">logout</a>
</div>
</header>

<!-- Sidebar (Desktop) -->
<aside id="sidebar" class="fixed left-0 top-0 bottom-0 z-40 h-full w-64 hidden md:flex flex-col sidebar-modern pt-20 transition-all duration-300 border-r border-white/5">
<div class="px-6 py-4 mb-4 flex items-center gap-3">
<a href="<?= BASE_URL ?>/chofer/panel.php" class="logo-link">
<img src="<?= BASE_URL ?>/Logo/Logo_App.png" alt="Logo" class="h-10 w-auto object-contain logo-img"/>
</a>
<h2 class="font-headline-sm text-headline-sm text-on-primary-container opacity-80"><?= strtoupper(htmlspecialchars(getCurrentUserRoles()[0]['nombre'] ?? 'CHOFER')) ?></h2>
</div>
<nav class="flex-1 overflow-y-auto no-scrollbar">
<?php
$allNavItems = [
    ['label' => 'Mi Panel', 'icon' => 'dashboard', 'link' => '/chofer/panel.php', 'page' => 'panel.php', 'permiso' => null],
    ['label' => 'Cargar Combustible', 'icon' => 'local_gas_station', 'link' => '/chofer/cargar_combustible.php', 'page' => 'cargar_combustible.php', 'permiso' => 'combustible_cargar'],
    ['label' => 'Registrar Mantenimiento', 'icon' => 'build', 'link' => '/chofer/registrar_mantenimiento.php', 'page' => 'registrar_mantenimiento.php', 'permiso' => 'mantenimiento_crear'],
    ['label' => 'Mis Viajes', 'icon' => 'map', 'link' => '/chofer/viajes.php', 'page' => 'viajes.php', 'permiso' => 'kilometraje_cargar'],
    ['label' => 'Mi Historial', 'icon' => 'history', 'link' => '/chofer/historial.php', 'page' => 'historial.php', 'permiso' => null],
];
$navItems = [];
foreach ($allNavItems as $item):
    if ($item['permiso'] === null || hasPermission($item['permiso'])):
        $navItems[] = $item;
    endif;
endforeach;
foreach ($navItems as $item):
    $active = $currentPage === $item['page'];
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex items-center gap-3 px-4 py-3 mx-3 my-0.5 rounded-xl transition-all duration-200 <?= $active ? 'bg-white/10 text-white font-semibold' : 'text-white/60 hover:text-white hover:bg-white/5' ?>">
<span class="material-symbols-outlined text-lg"><?= $item['icon'] ?></span>
<span class="font-body-md"><?= $item['label'] ?></span>
</a>
<?php endforeach; ?>
</nav>
<div class="p-4 border-t border-white/10">
<a href="<?= BASE_URL ?>/logout.php" class="flex items-center gap-3 text-white/50 hover:text-white transition-all p-2 rounded-lg hover:bg-white/5">
<span class="material-symbols-outlined">logout</span>
<span class="font-body-md">Cerrar Sesion</span>
</a>
</div>
</aside>

<!-- Mobile Sidebar Overlay -->
<div id="mobile-overlay" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden" onclick="toggleMobileMenu()"></div>

<!-- Mobile Sidebar -->
<aside id="mobile-sidebar" class="fixed left-0 top-0 bottom-0 z-40 h-full w-64 flex md:hidden flex-col sidebar-modern pt-20 transition-all duration-300 -translate-x-full">
<nav class="flex-1 overflow-y-auto no-scrollbar">
<?php foreach ($navItems as $item):
    $active = $currentPage === $item['page'];
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex items-center gap-3 px-4 py-3 mx-3 my-0.5 rounded-xl transition-all duration-200 <?= $active ? 'bg-white/10 text-white font-semibold' : 'text-white/60 hover:text-white hover:bg-white/5' ?>">
<span class="material-symbols-outlined text-lg"><?= $item['icon'] ?></span>
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
