<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('choferes_ver');
$pageTitle = 'Gestion de Choferes';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

try { $db->exec("ALTER TABLE choferes ADD COLUMN empresa_id INT DEFAULT NULL"); } catch (Exception $e) {}
$empresasList = $db->query("SELECT id_empresa, nombre FROM empresas WHERE activo = 1 ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $dni = trim($_POST['dni'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $licencia = trim($_POST['licencia'] ?? '');
        $vencimiento_licencia = $_POST['vencimiento_licencia'] ?? null;
        $estado = $_POST['estado'] ?? 'activo';
        $empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;

        $nuevo_id = trim($_POST['nuevo_id_chofer'] ?? '');

        if ($action === 'create') {
            try {
                if ($nuevo_id) {
                    $stmt = $db->prepare("INSERT INTO choferes (id_chofer, nombre, apellido, dni, telefono, licencia, vencimiento_licencia, estado, empresa_id) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([$nuevo_id, $nombre, $apellido, $dni, $telefono, $licencia, $vencimiento_licencia, $estado, $empresa_id]);
                    $idChofer = $nuevo_id;
                } else {
                    $stmt = $db->prepare("INSERT INTO choferes (nombre, apellido, dni, telefono, licencia, vencimiento_licencia, estado, empresa_id) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([$nombre, $apellido, $dni, $telefono, $licencia, $vencimiento_licencia, $estado, $empresa_id]);
                    $idChofer = $db->lastInsertId();
                }

                // Crear usuario asociado si no existe
                $username = strtolower(substr($nombre, 0, 1) . $apellido);
                $password = password_hash($dni, PASSWORD_DEFAULT);
                $checkUser = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ?");
                $checkUser->execute([$username]);
                if ($checkUser->fetchColumn() == 0) {
                    $db->prepare("INSERT INTO usuarios (username, password, email, rol, id_chofer, activo) VALUES (?,?,?, 'chofer', ?, 1)")
                       ->execute([$username, $password, "$username@reciclarg.com.ar", $idChofer]);
                }

                registrarAuditoria(getCurrentUserId(), 'create', 'choferes', $idChofer, "Creo chofer $nombre $apellido");
                $mensaje = "Chofer creado. Usuario: $username - Clave: $dni";
            } catch (Exception $e) {
                $error = 'Error al crear: ' . $e->getMessage();
            }
        } else {
            $id = (int)($_POST['id_chofer'] ?? 0);
            try {
                if ($nuevo_id && $nuevo_id != $id) {
                    $stmt = $db->prepare("UPDATE choferes SET id_chofer=?, nombre=?, apellido=?, dni=?, telefono=?, licencia=?, vencimiento_licencia=?, estado=?, empresa_id=? WHERE id_chofer=?");
                    $stmt->execute([$nuevo_id, $nombre, $apellido, $dni, $telefono, $licencia, $vencimiento_licencia, $estado, $empresa_id, $id]);
                    $id = $nuevo_id;
                } else {
                    $stmt = $db->prepare("UPDATE choferes SET nombre=?, apellido=?, dni=?, telefono=?, licencia=?, vencimiento_licencia=?, estado=?, empresa_id=? WHERE id_chofer=?");
                    $stmt->execute([$nombre, $apellido, $dni, $telefono, $licencia, $vencimiento_licencia, $estado, $empresa_id, $id]);
                }
                registrarAuditoria(getCurrentUserId(), 'update', 'choferes', $id, "Actualizo chofer $nombre $apellido");
                $mensaje = 'Chofer actualizado';
            } catch (Exception $e) {
                $error = 'Error al actualizar (si cambiaste el ID, verifica que no exista otro igual): ' . $e->getMessage();
            }
        }
    } elseif ($action === 'asociar_usuario') {
        $idChofer = (int)($_POST['id_chofer'] ?? 0);
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        if ($idChofer && $idUsuario) {
            try {
                $db->prepare("UPDATE usuarios SET id_chofer = ? WHERE id_usuario = ?")->execute([$idChofer, $idUsuario]);
                $db->prepare("UPDATE choferes SET usuario_id = ? WHERE id_chofer = ?")->execute([$idUsuario, $idChofer]);
                registrarAuditoria(getCurrentUserId(), 'asociar_usuario', 'choferes', $idChofer, "Asocio usuario ID $idUsuario a chofer ID $idChofer");
                $mensaje = 'Usuario asociado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al asociar usuario: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'desasociar_usuario') {
        $idChofer = (int)($_POST['id_chofer'] ?? 0);
        if ($idChofer) {
            try {
                $stmt = $db->prepare("SELECT id_usuario FROM choferes WHERE id_chofer = ?");
                $stmt->execute([$idChofer]);
                $idUsuario = $stmt->fetchColumn();
                if ($idUsuario) {
                    $db->prepare("UPDATE usuarios SET id_chofer = NULL WHERE id_usuario = ?")->execute([$idUsuario]);
                } else {
                    $db->prepare("UPDATE usuarios SET id_chofer = NULL WHERE id_chofer = ?")->execute([$idChofer]);
                }
                $db->prepare("UPDATE choferes SET usuario_id = NULL WHERE id_chofer = ?")->execute([$idChofer]);
                registrarAuditoria(getCurrentUserId(), 'desasociar_usuario', 'choferes', $idChofer, "Desasocio usuario de chofer ID $idChofer");
                $mensaje = 'Usuario desasociado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al desasociar usuario: ' . $e->getMessage();
            }
        }
    }
}

$buscar = $_GET['buscar'] ?? '';
$sql = "SELECT c.*, e.nombre as empresa_nombre,
        (SELECT COUNT(*) FROM asignaciones WHERE id_chofer = c.id_chofer AND activa = 1) as tiene_camion,
        (SELECT username FROM usuarios WHERE id_chofer = c.id_chofer LIMIT 1) as usuario_asociado_nombre,
        (SELECT id_usuario FROM usuarios WHERE id_chofer = c.id_chofer LIMIT 1) as usuario_asociado_id
        FROM choferes c LEFT JOIN empresas e ON c.empresa_id = e.id_empresa WHERE 1=1";
$params = [];
if ($buscar) {
    $sql .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.dni LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
$sql .= " ORDER BY c.apellido ASC";
$choferes = $db->prepare($sql);
$choferes->execute($params);
$choferesList = $choferes->fetchAll();
$usuariosDisponibles = $db->query("SELECT u.id_usuario, u.username, u.nombre, u.apellido FROM usuarios u WHERE u.id_chofer IS NULL OR u.id_chofer = 0 ORDER BY u.username ASC")->fetchAll();
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Choferes</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Administracion de conductores y licencias.</p>
</div>
<button onclick="openModal('modalChofer')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Chofer
</button>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Search -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex items-center gap-4 mb-8">
<div class="relative w-full">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input onkeyup="filterChoferes()" id="searchChofer" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por nombre, apellido o DNI..." type="text"/>
</div>
</div>

<!-- Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap">
<table class="w-full">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">ID</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">APELLIDO Y NOMBRE</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">DNI</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">LICENCIA</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">VTO LICENCIA</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">TELEFONO</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left">EMPRESA</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-left hidden lg:table-cell">USUARIO</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-6 py-4 font-label-caps text-[10px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="choferesTableBody">
<?php foreach ($choferesList as $ch): ?>
<tr class="chofer-row hover:bg-surface-container transition-colors" data-search="<?= strtolower(htmlspecialchars($ch['id_chofer'] . ' ' . $ch['nombre'] . ' ' . $ch['apellido'] . ' ' . $ch['dni'])) ?>">
<td class="px-6 py-4 font-data-mono font-bold text-primary">#<?= htmlspecialchars($ch['id_chofer']) ?></td>
<td class="px-6 py-4">
<span class="font-medium"><?= htmlspecialchars($ch['apellido']) ?> <?= htmlspecialchars($ch['nombre']) ?></span>
<?php if ($ch['tiene_camion']): ?><span class="ml-2 text-green-600 material-symbols-outlined text-sm" title="Tiene vehiculo asignado">local_shipping</span><?php endif; ?>
</td>
<td class="px-6 py-4 font-data-mono"><?= htmlspecialchars($ch['dni']) ?></td>
<td class="px-6 py-4"><?= htmlspecialchars($ch['licencia'] ?? '-') ?></td>
<td class="px-6 py-4"><?= $ch['vencimiento_licencia'] ? date('d/m/Y', strtotime($ch['vencimiento_licencia'])) : '-' ?></td>
<td class="px-6 py-4"><?= htmlspecialchars($ch['telefono'] ?? '-') ?></td>
<td class="px-6 py-4 text-sm text-on-surface-variant"><?= htmlspecialchars($ch['empresa_nombre'] ?? '-') ?></td>
<td class="px-6 py-4 hidden lg:table-cell">
<?php if ($ch['usuario_asociado_nombre']): ?>
<div class="flex items-center gap-1">
<span class="text-xs font-medium"><?= htmlspecialchars($ch['usuario_asociado_nombre']) ?></span>
<form method="POST" class="inline" onsubmit="return confirm('¿Desasociar usuario?')">
<input type="hidden" name="action" value="desasociar_usuario"/>
<input type="hidden" name="id_chofer" value="<?= $ch['id_chofer'] ?>"/>
<button type="submit" class="text-red-500 hover:text-red-700 text-xs" title="Desasociar usuario">&times;</button>
</form>
</div>
<?php else: ?>
<button onclick="openAsociarUsuario(<?= $ch['id_chofer'] ?>)" class="text-xs text-primary underline hover:opacity-80">+ Asociar</button>
<?php endif; ?>
</td>
<td class="px-6 py-4 text-center">
<span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase <?= $ch['estado'] === 'activo' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200' ?>"><?= $ch['estado'] ?></span>
</td>
<td class="px-6 py-4">
<div class="flex gap-2 justify-center">
<button onclick="editChofer(<?= $ch['id_chofer'] ?>)" class="px-3 py-1 bg-secondary-container text-on-secondary-container rounded text-xs font-bold hover:opacity-80">Editar</button>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main>

<!-- Modal Chofer -->
<div id="modalChofer" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalChoferTitle" class="font-headline-sm text-headline-sm text-primary">Nuevo Chofer</h3>
<button onclick="closeModal('modalChofer')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="choferAction" value="create"/>
<input type="hidden" name="id_chofer" id="choferId" value=""/>
<div class="grid grid-cols-1 mb-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">ID / Legajo (Opcional)</label><input name="nuevo_id_chofer" id="chnuevo_id" type="number" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Dejar en blanco para auto-generar"/></div>
</div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Apellido</label><input name="apellido" id="chapellido" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre</label><input name="nombre" id="chnombre" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/></div>
</div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">DNI</label><input name="dni" id="chdni" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Telefono</label><input name="telefono" id="chtelefono" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
</div>
<div class="grid grid-cols-2 gap-4">
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Licencia</label><input name="licencia" id="chlicencia" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
<div><label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Vto. Licencia</label><input name="vencimiento_licencia" id="chvencimiento" type="date" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/></div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Empresa</label>
<select name="empresa_id" id="chempresa" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="">Sin empresa</option>
<?php foreach ($empresasList as $emp): ?>
<option value="<?= $emp['id_empresa'] ?>"><?= htmlspecialchars($emp['nombre']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Estado</label>
<select name="estado" id="chestado" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low">
<option value="activo">Activo</option>
<option value="inactivo">Inactivo</option>
</select>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalChofer')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Asociar Usuario -->
<div id="modalAsociarUsuario" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Asociar Usuario</h3>
<button onclick="closeModal('modalAsociarUsuario')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="asociar_usuario"/>
<input type="hidden" name="id_chofer" id="asociarUsuarioChoferId" value=""/>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Seleccionar Usuario</label>
<select name="id_usuario" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccione un usuario...</option>
<?php foreach ($usuariosDisponibles as $u): ?>
<option value="<?= $u['id_usuario'] ?>"><?= htmlspecialchars($u['username'] . ' - ' . trim($u['nombre'] . ' ' . $u['apellido'])) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalAsociarUsuario')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Asociar</button>
</div>
</form>
</div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function closeModalandReset() { closeModal('modalChofer'); document.getElementById('choferAction').value = 'create'; document.getElementById('choferId').value = ''; document.getElementById('chnuevo_id').value = ''; }

function openAsociarUsuario(choferId) {
document.getElementById('asociarUsuarioChoferId').value = choferId;
openModal('modalAsociarUsuario');
}

function editChofer(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=chofer&id=' + id).then(r => r.json()).then(data => {
document.getElementById('choferAction').value = 'update';
document.getElementById('choferId').value = data.id_chofer;
document.getElementById('chnuevo_id').value = data.id_chofer;
document.getElementById('chapellido').value = data.apellido;
document.getElementById('chnombre').value = data.nombre;
document.getElementById('chdni').value = data.dni;
document.getElementById('chtelefono').value = data.telefono || '';
document.getElementById('chlicencia').value = data.licencia || '';
document.getElementById('chvencimiento').value = data.vencimiento_licencia || '';
document.getElementById('chempresa').value = data.empresa_id || '';
document.getElementById('chestado').value = data.estado;
document.getElementById('modalChoferTitle').textContent = 'Editar Chofer';
openModal('modalChofer');
});
}



function filterChoferes() {
const search = document.getElementById('searchChofer').value.toLowerCase();
document.querySelectorAll('.chofer-row').forEach(row => {
row.style.display = row.dataset.search.includes(search) ? '' : 'none';
});
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
