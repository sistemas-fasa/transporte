<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('empresas_ver');
$pageTitle = 'Gestion de Empresas';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$mensaje = '';
$error = '';

// Crear tabla si no existe
try {
    $db->exec("CREATE TABLE IF NOT EXISTS empresas (
        id_empresa INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL UNIQUE,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $nombre = trim($_POST['nombre'] ?? '');
        $activo = (int)($_POST['activo'] ?? 1);

        if ($nombre === '') {
            $error = 'El nombre es obligatorio';
        } else {
            if ($action === 'create') {
                try {
                    $db->prepare("INSERT INTO empresas (nombre, activo) VALUES (?, ?)")->execute([$nombre, $activo]);
                    $mensaje = 'Empresa creada exitosamente';
                } catch (Exception $e) {
                    $error = $e->errorInfo[1] == 1062 ? 'Ya existe una empresa con ese nombre' : 'Error: ' . $e->getMessage();
                }
            } else {
                $id = (int)($_POST['id_empresa'] ?? 0);
                try {
                    $db->prepare("UPDATE empresas SET nombre = ?, activo = ? WHERE id_empresa = ?")->execute([$nombre, $activo, $id]);
                    $mensaje = 'Empresa actualizada';
                } catch (Exception $e) {
                    $error = 'Error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id_empresa'] ?? 0);
        try {
            $db->prepare("DELETE FROM empresas WHERE id_empresa = ?")->execute([$id]);
            $mensaje = 'Empresa eliminada';
        } catch (Exception $e) {
            $error = 'No se puede eliminar, tiene vehiculos o choferes asociados';
        }
    }
}

$empresas = $db->query("SELECT * FROM empresas ORDER BY nombre ASC")->fetchAll();
$empresasActivas = $db->query("SELECT id_empresa, nombre FROM empresas WHERE activo = 1 ORDER BY nombre")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Empresas</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Administracion de empresas para filtrar reportes.</p>
</div>
<button onclick="document.getElementById('modalEmpresa').classList.remove('hidden')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nueva Empresa
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">NOMBRE</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-center">CREACION</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant">
<?php if (empty($empresas)): ?>
<tr><td colspan="4" class="px-6 py-12 text-center text-on-surface-variant">No hay empresas registradas. Cree una para comenzar.</td></tr>
<?php else: ?>
<?php foreach ($empresas as $e): ?>
<tr class="hover:bg-surface-container transition-colors">
<td class="px-6 py-4 font-bold"><?= htmlspecialchars($e['nombre']) ?></td>
<td class="px-6 py-4 text-center">
<?php if ($e['activo']): ?>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase bg-green-50 text-green-700 border border-green-200">Activo</span>
<?php else: ?>
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase bg-red-50 text-red-700 border border-red-200">Inactivo</span>
<?php endif; ?>
</td>
<td class="px-6 py-4 text-center text-sm text-on-surface-variant"><?= date('d/m/Y', strtotime($e['created_at'])) ?></td>
<td class="px-6 py-4">
<div class="flex gap-2 justify-center">
<button onclick="editEmpresa(<?= $e['id_empresa'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Editar</button>
<form method="POST" class="inline" onsubmit="return confirm('¿Eliminar esta empresa?')">
<input type="hidden" name="action" value="delete"/>
<input type="hidden" name="id_empresa" value="<?= $e['id_empresa'] ?>"/>
<button type="submit" class="px-3 py-1 bg-red-50 text-red-600 rounded text-xs font-bold hover:bg-red-100">Eliminar</button>
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
<div id="modalEmpresa" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalEmpresaTitle" class="font-headline-sm text-headline-sm text-primary">Nueva Empresa</h3>
<button onclick="closeModalEmpresa()"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="empresaAction" value="create"/>
<input type="hidden" name="id_empresa" id="empresaId" value=""/>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre</label>
<input name="nombre" id="empresaNombre" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Estado</label>
<select name="activo" id="empresaActivo" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="1">Activo</option>
<option value="0">Inactivo</option>
</select>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModalEmpresa()" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<script>
function closeModalEmpresa() {
document.getElementById('modalEmpresa').classList.add('hidden');
}



function editEmpresa(id) {
var empresas = <?= json_encode($empresas) ?>;
var e = empresas.find(function(item) { return item.id_empresa == id; });
if (!e) return;
document.getElementById('empresaAction').value = 'update';
document.getElementById('empresaId').value = e.id_empresa;
document.getElementById('empresaNombre').value = e.nombre;
document.getElementById('empresaActivo').value = e.activo;
document.getElementById('modalEmpresaTitle').textContent = 'Editar Empresa';
document.getElementById('modalEmpresa').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
