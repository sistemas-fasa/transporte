<!-- Top Navigation Bar -->
<header class="fixed top-0 w-full z-50 bg-surface border-b border-outline-variant flex items-center justify-between px-margin-mobile md:px-margin-desktop h-16 max-w-[1440px] mx-auto left-0 right-0">
<div class="flex items-center gap-4">
<span class="material-symbols-outlined text-primary cursor-pointer md:hidden" onclick="document.querySelector('aside').classList.toggle('hidden');document.querySelector('aside').classList.toggle('flex')">menu</span>
<h1 class="font-headline-md text-headline-md font-bold text-primary tracking-tight">GESTION DE FLOTA</h1>
</div>
<div class="flex items-center gap-4">
<span class="text-right hidden md:block">
<p class="font-label-caps text-label-caps text-on-surface-variant"><?= htmlspecialchars(getCurrentUserName()) ?></p>
<p class="text-[10px] text-outline uppercase"><?= getCurrentUserRol() === 'admin' ? 'Administrador' : 'Chofer' ?></p>
</span>
<a href="<?= BASE_URL ?>/logout.php" class="material-symbols-outlined text-primary cursor-pointer">logout</a>
</div>
</header>

<!-- Sidebar (Desktop) -->
<aside class="fixed left-0 top-0 bottom-0 z-40 h-full w-64 hidden md:flex flex-col bg-primary-container pt-20 transition-transform">
<div class="px-6 py-4 mb-4">
<h2 class="font-headline-sm text-headline-sm text-on-primary-container opacity-80">SISTEMA OPERATIVO</h2>
</div>
<nav class="flex-1 overflow-y-auto no-scrollbar">
<?php
$navItems = [
    ['label' => 'Dashboard', 'icon' => 'dashboard', 'link' => '/admin/dashboard.php', 'page' => 'dashboard.php'],
    ['label' => 'Camiones', 'icon' => 'local_shipping', 'link' => '/admin/camiones.php', 'page' => 'camiones.php'],
    ['label' => 'Choferes', 'icon' => 'person', 'link' => '/admin/choferes.php', 'page' => 'choferes.php'],
    ['label' => 'Combustible', 'icon' => 'local_gas_station', 'link' => '/admin/combustible.php', 'page' => 'combustible.php'],
    ['label' => 'Viajes', 'icon' => 'alt_route', 'link' => '/admin/viajes.php', 'page' => 'viajes.php'],
    ['label' => 'Mantenimiento', 'icon' => 'build', 'link' => '/admin/mantenimiento.php', 'page' => 'mantenimiento.php'],
    ['label' => 'Reportes', 'icon' => 'analytics', 'link' => '/admin/reportes.php', 'page' => 'reportes.php'],
    ['label' => 'Alertas', 'icon' => 'notifications_active', 'link' => '/admin/alertas.php', 'page' => 'alertas.php'],
];
foreach ($navItems as $item):
    $active = $currentPage === $item['page'];
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex items-center gap-3 p-4 mx-2 my-1 rounded-lg transition-all <?= $active ? 'bg-secondary text-on-secondary font-bold' : 'text-on-primary-container hover:bg-primary' ?>">
<span class="material-symbols-outlined"><?= $item['icon'] ?></span>
<span class="font-body-md"><?= $item['label'] ?></span>
</a>
<?php endforeach; ?>

<?php if (hasPermission('usuarios_ver')): ?>
<div class="px-6 pt-6 pb-2">
    <p class="font-label-caps text-label-caps text-on-primary-container opacity-50 uppercase tracking-widest">Administracion</p>
</div>
<?php
$adminItems = [];
if (hasPermission('usuarios_ver')) $adminItems[] = ['label' => 'Usuarios', 'icon' => 'manage_accounts', 'link' => '/admin/usuarios.php', 'page' => 'usuarios.php'];
if (hasPermission('usuarios_crear')) $adminItems[] = ['label' => 'Roles', 'icon' => 'admin_panel_settings', 'link' => '/admin/roles.php', 'page' => 'roles.php'];
$adminItems[] = ['label' => 'Auditoria', 'icon' => 'security', 'link' => '/admin/auditoria_accesos.php', 'page' => 'auditoria_accesos.php'];
$adminItems[] = ['label' => 'Sincronizar', 'icon' => 'sync', 'link' => '/admin/sincronizar.php', 'page' => 'sincronizar.php'];
foreach ($adminItems as $item):
    $active = $currentPage === $item['page'];
?>
<a href="<?= BASE_URL . $item['link'] ?>" class="flex items-center gap-3 p-4 mx-2 my-1 rounded-lg transition-all <?= $active ? 'bg-secondary text-on-secondary font-bold' : 'text-on-primary-container hover:bg-primary' ?>">
<span class="material-symbols-outlined"><?= $item['icon'] ?></span>
<span class="font-body-md"><?= $item['label'] ?></span>
</a>
<?php endforeach; ?>
<?php endif; ?>
</nav>
<div class="p-4 border-t border-on-primary-container/20">
<a href="<?= BASE_URL ?>/logout.php" class="flex items-center gap-3 text-on-primary-container hover:text-on-primary transition-colors p-2">
<span class="material-symbols-outlined">logout</span>
<span class="font-body-md">Cerrar Sesion</span>
</a>
</div>
</aside>
