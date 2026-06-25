<!-- Top Navigation Bar -->
<header class="fixed top-0 w-full z-50 glass-header flex items-center justify-between px-margin-mobile md:px-margin-desktop h-16 max-w-[1440px] mx-auto left-0 right-0">
<div class="flex items-center gap-4">
<span class="material-symbols-outlined text-primary cursor-pointer md:hidden" onclick="document.querySelector('aside').classList.toggle('hidden');document.querySelector('aside').classList.toggle('flex')">menu</span>
<a href="<?= BASE_URL ?>/admin/dashboard.php" class="logo-link">
<img src="<?= BASE_URL ?>/Logo/Logo_App.png" alt="Logo" class="h-9 w-auto object-contain logo-img"/>
</a>
<h1 class="font-headline-md text-headline-md font-bold text-primary tracking-tight">CONTROL COMBUSTIBLE Y KM</h1>
</div>
<div class="flex items-center gap-4">
<span class="text-right hidden md:block">
<p class="font-label-caps text-label-caps text-on-surface-variant"><?= htmlspecialchars(getCurrentUserName()) ?></p>
<p class="text-[10px] text-outline uppercase"><?= strtoupper(htmlspecialchars(getCurrentUserRoles()[0]['nombre'] ?? 'ADMIN')) ?></p>
</span>
<a href="<?= BASE_URL ?>/logout.php" class="material-symbols-outlined text-primary cursor-pointer">logout</a>
</div>
</header>

<!-- Sidebar (Desktop) -->
<aside class="fixed left-0 top-0 bottom-0 z-40 h-full w-64 hidden md:flex flex-col sidebar-modern pt-20 transition-all duration-300 border-r border-white/5">
<div class="px-6 py-4 mb-4 flex items-center gap-3">
<a href="<?= BASE_URL ?>/admin/dashboard.php" class="logo-link">
<img src="<?= BASE_URL ?>/Logo/Logo_App.png" alt="Logo" class="h-10 w-auto object-contain logo-img"/>
</a>
<h2 class="font-headline-sm text-headline-sm text-on-primary-container opacity-80">SISTEMA</h2>
</div>
<nav class="flex-1 overflow-y-auto no-scrollbar">
<?php
$allNavItems = [
    ['label' => 'Dashboard', 'icon' => 'dashboard', 'link' => '/admin/dashboard.php', 'page' => 'dashboard.php', 'permiso' => null],
    ['label' => 'Vehículos', 'icon' => 'local_shipping', 'link' => '/admin/camiones.php', 'page' => 'camiones.php', 'permiso' => 'vehiculos_ver'],
    ['label' => 'Choferes', 'icon' => 'person', 'link' => '/admin/choferes.php', 'page' => 'choferes.php', 'permiso' => 'choferes_ver'],
    ['label' => 'Combustible', 'icon' => 'local_gas_station', 'link' => '/admin/combustible.php', 'page' => 'combustible.php', 'permiso' => 'combustible_ver'],
    ['label' => 'Viajes', 'icon' => 'alt_route', 'link' => '/admin/viajes.php', 'page' => 'viajes.php', 'permiso' => 'kilometraje_ver'],
    ['label' => 'Cargar Viajes', 'icon' => 'edit_note', 'link' => '/admin/cargar_viajes.php', 'page' => 'cargar_viajes.php', 'permiso' => 'kilometraje_cargar'],
    ['label' => 'Mantenimiento', 'icon' => 'build', 'link' => '/admin/mantenimiento.php', 'page' => 'mantenimiento.php', 'permiso' => 'mantenimiento_ver'],
    ['label' => 'Alertas', 'icon' => 'notifications_active', 'link' => '/admin/alertas.php', 'page' => 'alertas.php', 'permiso' => 'alertas_ver'],
    ['label' => 'Empresas', 'icon' => 'business', 'link' => '/admin/empresas.php', 'page' => 'empresas.php', 'permiso' => 'empresas_ver'],
    ['label' => 'Matafuegos', 'icon' => 'local_fire_department', 'link' => '/admin/matafuegos.php', 'page' => 'matafuegos.php', 'permiso' => 'matafuegos_ver'],
];

$allNavItems[] = ['label' => 'Reportes', 'icon' => 'analytics', 'link' => '/admin/reportes.php', 'page' => 'reportes.php', 'permiso' => 'reportes_ver'];
$allNavItems[] = ['label' => 'Importar Viajes', 'icon' => 'upload', 'link' => '/admin/importar_viajes.php', 'page' => 'importar_viajes.php', 'permiso' => 'viajes_importar'];
$allNavItems[] = ['label' => 'Importar Combustible', 'icon' => 'upload', 'link' => '/admin/importar_combustible.php', 'page' => 'importar_combustible.php', 'permiso' => 'combustible_importar'];

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

<?php if (hasPermission('usuarios_ver')): ?>
<div class="px-6 pt-6 pb-2">
    <p class="font-label-caps text-label-caps text-white/30 uppercase tracking-widest">Administracion</p>
</div>
<?php
$adminItems = [];
if (hasPermission('usuarios_ver')) $adminItems[] = ['label' => 'Usuarios', 'icon' => 'manage_accounts', 'link' => '/admin/usuarios.php', 'page' => 'usuarios.php'];
if (hasPermission('usuarios_crear')) $adminItems[] = ['label' => 'Roles', 'icon' => 'admin_panel_settings', 'link' => '/admin/roles.php', 'page' => 'roles.php'];
$adminItems[] = ['label' => 'Auditoria', 'icon' => 'security', 'link' => '/admin/auditoria_accesos.php', 'page' => 'auditoria_accesos.php'];


foreach ($adminItems as $item):
    $active = $currentPage === $item['page'];
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex items-center gap-3 px-4 py-3 mx-3 my-0.5 rounded-xl transition-all duration-200 <?= $active ? 'bg-white/10 text-white font-semibold' : 'text-white/60 hover:text-white hover:bg-white/5' ?>">
<span class="material-symbols-outlined text-lg"><?= $item['icon'] ?></span>
<span class="font-body-md"><?= $item['label'] ?></span>
</a>
<?php endforeach; ?>
<?php endif; ?>
</nav>
<div class="p-4 border-t border-white/10">
<a href="<?= BASE_URL ?>/logout.php" class="flex items-center gap-3 text-white/50 hover:text-white transition-all p-2 rounded-lg hover:bg-white/5">
<span class="material-symbols-outlined">logout</span>
<span class="font-body-md">Cerrar Sesion</span>
</a>
</div>
</aside>


