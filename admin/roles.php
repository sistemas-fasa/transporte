<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Gestion de Roles';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $permisos = $_POST['permisos'] ?? [];

        if ($action === 'create') {
            try {
                $stmt = $db->prepare("INSERT INTO roles (nombre, descripcion) VALUES (?, ?)");
                $stmt->execute([$nombre, $descripcion]);
                $idRol = $db->lastInsertId();

                foreach ($permisos as $codigo) {
                    $permStmt = $db->prepare("SELECT id_permiso FROM permisos WHERE codigo = ?");
                    $permStmt->execute([$codigo]);
                    $idPermiso = $permStmt->fetchColumn();
                    if ($idPermiso) {
                        $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (?, ?)")->execute([$idRol, $idPermiso]);
                    }
                }

                registrarAuditoria(getCurrentUserId(), 'create', 'roles', $idRol, "Creo rol $nombre");
                $mensaje = 'Rol creado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al crear: ' . $e->getMessage();
            }
        } else {
            $id = (int)($_POST['id_rol'] ?? 0);
            try {
                $db->prepare("UPDATE roles SET nombre = ?, descripcion = ? WHERE id_rol = ?")->execute([$nombre, $descripcion, $id]);

                // Reasignar permisos
                $db->prepare("DELETE FROM rol_permiso WHERE id_rol = ?")->execute([$id]);
                foreach ($permisos as $codigo) {
                    $permStmt = $db->prepare("SELECT id_permiso FROM permisos WHERE codigo = ?");
                    $permStmt->execute([$codigo]);
                    $idPermiso = $permStmt->fetchColumn();
                    if ($idPermiso) {
                        $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (?, ?)")->execute([$id, $idPermiso]);
                    }
                }

                registrarAuditoria(getCurrentUserId(), 'update', 'roles', $id, "Actualizo rol $nombre");
                $mensaje = 'Rol actualizado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al actualizar: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_rol'] ?? 0);
        try {
            $db->prepare("DELETE FROM roles WHERE id_rol = ?")->execute([$id]);
            registrarAuditoria(getCurrentUserId(), 'delete', 'roles', $id, "Elimino rol ID $id");
            $mensaje = 'Rol eliminado';
        } catch (Exception $e) {
            $error = 'No se puede eliminar el rol, tiene usuarios asignados';
        }
    }
}

$roles = $db->query("SELECT r.*, (SELECT COUNT(*) FROM usuario_rol ur WHERE ur.id_rol = r.id_rol) as total_usuarios FROM roles r ORDER BY r.nombre")->fetchAll();
$permisos = $db->query("SELECT * FROM permisos ORDER BY modulo, nombre")->fetchAll();
$permisosPorModulo = [];
foreach ($permisos as $p) {
    $permisosPorModulo[$p['modulo']][] = $p;
}

// Obtener permisos de cada rol
$rolPermisos = [];
foreach ($roles as $r) {
    $stmt = $db->prepare("SELECT p.codigo FROM permisos p JOIN rol_permiso rp ON p.id_permiso = rp.id_permiso WHERE rp.id_rol = ?");
    $stmt->execute([$r['id_rol']]);
    $rolPermisos[$r['id_rol']] = array_column($stmt->fetchAll(), 'codigo');
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Roles</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Configuracion de permisos del sistema.</p>
</div>
<button onclick="resetModalRol(); openModal('modalRol')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Rol
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Roles Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
<?php foreach ($roles as $r):
    $colors = ['bg-purple-50 border-purple-200 text-purple-800', 'bg-blue-50 border-blue-200 text-blue-800', 'bg-green-50 border-green-200 text-green-800'];
    $colorIdx = ($r['id_rol'] - 1) % count($colors);
?>
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl overflow-hidden">
<div class="p-6 border-b border-outline-variant">
<div class="flex justify-between items-start mb-4">
<div>
<h3 class="font-headline-sm text-headline-sm text-primary"><?= htmlspecialchars($r['nombre']) ?></h3>
<p class="text-sm text-on-surface-variant mt-1"><?= htmlspecialchars($r['descripcion'] ?? '') ?></p>
</div>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase border <?= $colors[$colorIdx] ?>"><?= $r['total_usuarios'] ?> usuarios</span>
</div>
<div class="flex gap-2">
<button onclick="editRol(<?= $r['id_rol'] ?>)" class="flex-1 bg-secondary-container text-on-secondary-container py-2 rounded-lg font-bold text-sm hover:opacity-80 flex items-center justify-center gap-1">
<span class="material-symbols-outlined text-sm">edit</span> Editar
</button>
<?php if ($r['id_rol'] > 3): ?>
<button onclick="deleteRol(<?= $r['id_rol'] ?>)" class="flex-1 bg-red-50 text-red-600 py-2 rounded-lg font-bold text-sm hover:bg-red-100 flex items-center justify-center gap-1">
<span class="material-symbols-outlined text-sm">delete</span> Eliminar
</button>
<?php endif; ?>
</div>
</div>
<div class="p-4">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase text-xs mb-3">Permisos asignados</p>
<div class="flex flex-wrap gap-1">
<?php
$perms = $rolPermisos[$r['id_rol']] ?? [];
$modulos = [];
foreach ($perms as $p) {
    $mod = explode('_', $p)[0];
    $modulos[ucfirst($mod)] = true;
}
foreach (array_keys($modulos) as $m):
?>
<span class="px-2 py-1 bg-surface-container-high rounded text-[10px] font-medium"><?= htmlspecialchars($m) ?></span>
<?php endforeach; ?>
<?php if (empty($modulos)): ?>
<span class="text-[10px] text-on-surface-variant">Sin permisos asignados</span>
<?php endif; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<!-- Tabla de Roles existentes -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<div class="p-6 border-b border-outline-variant">
<h3 class="font-headline-sm text-headline-sm text-primary uppercase">Roles del Sistema</h3>
</div>
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">ROL</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">DESCRIPCION</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-center">USUARIOS</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php foreach ($roles as $r): ?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-6 py-4 font-bold"><?= htmlspecialchars($r['nombre']) ?></td>
<td class="px-6 py-4 text-sm text-on-surface-variant"><?= htmlspecialchars($r['descripcion'] ?? '-') ?></td>
<td class="px-6 py-4 text-center"><?= $r['total_usuarios'] ?></td>
<td class="px-6 py-4">
<div class="flex gap-2 justify-center">
<button onclick="editRol(<?= $r['id_rol'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Editar</button>
<?php if ($r['id_rol'] > 3): ?>
<button onclick="deleteRol(<?= $r['id_rol'] ?>)" class="px-3 py-1 bg-red-50 text-red-600 rounded text-xs font-bold hover:bg-red-100">Eliminar</button>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main>

<!-- Modal Nuevo/Editar Rol -->
<div id="modalRol" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalRolTitle" class="font-headline-sm text-headline-sm text-primary">Nuevo Rol</h3>
<button onclick="closeModal('modalRol')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="rolAction" value="create"/>
<input type="hidden" name="id_rol" id="rolId" value=""/>
<div class="grid grid-cols-1 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre del Rol</label>
<input name="nombre" id="rolNombre" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Descripcion</label>
<textarea name="descripcion" id="rolDescripcion" rows="2" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"></textarea>
</div>
</div>

<div class="border-t border-outline-variant pt-4 mt-2">
<h4 class="font-headline-sm text-headline-sm text-primary mb-4">Permisos</h4>
<?php foreach ($permisosPorModulo as $modulo => $modPermisos): ?>
<div class="mb-6">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-3 border-b border-outline-variant pb-2"><?= htmlspecialchars($modulo) ?></p>
<div class="grid grid-cols-2 md:grid-cols-3 gap-3">
<?php foreach ($modPermisos as $p): ?>
<label class="flex items-center gap-2 p-2 rounded hover:bg-surface-container-low cursor-pointer">
<input type="checkbox" name="permisos[]" value="<?= htmlspecialchars($p['codigo']) ?>" class="rounded border-outline-variant w-4 h-4"/>
<span class="text-sm"><?= htmlspecialchars($p['nombre']) ?></span>
</label>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>
</div>

<div class="flex gap-3 pt-4 border-t border-outline-variant">
<button type="button" onclick="closeModal('modalRol')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function resetModalRol() {
document.getElementById('rolAction').value = 'create';
document.getElementById('rolId').value = '';
document.getElementById('rolNombre').value = '';
document.getElementById('rolDescripcion').value = '';
document.querySelectorAll('#modalRol input[type="checkbox"]').forEach(cb => cb.checked = false);
document.getElementById('modalRolTitle').textContent = 'Nuevo Rol';
}

function editRol(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=rol&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.rol) {
alert('Error: No se pudieron obtener los datos del rol');
return;
}
document.getElementById('rolAction').value = 'update';
document.getElementById('rolId').value = data.rol.id_rol;
document.getElementById('rolNombre').value = data.rol.nombre;
document.getElementById('rolDescripcion').value = data.rol.descripcion || '';

// Marcar permisos
document.querySelectorAll('#modalRol input[type="checkbox"]').forEach(cb => {
cb.checked = data.permisos.includes(cb.value);
});

document.getElementById('modalRolTitle').textContent = 'Editar Rol';
openModal('modalRol');
}).catch(err => {
alert('Error al cargar datos: ' + err.message);
});
}

function deleteRol(id) {
showConfirm('¿Eliminar este rol?', function() {
const form = document.createElement('form');
form.method = 'POST';
form.innerHTML = '<input name="action" value="delete"><input name="id_rol" value="' + id + '">';
document.body.appendChild(form);
form.submit();
});
}

document.getElementById('modalRol').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalRol');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
