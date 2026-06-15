<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Gestion de Usuarios';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $username = trim($_POST['username'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $id_rol = (int)($_POST['id_rol'] ?? 0);
        $password = $_POST['password'] ?? '';
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($action === 'create') {
            if (empty($username) || empty($password)) {
                $error = 'Usuario y contrasena son obligatorios';
            } else {
                try {
                    // Mapear el rol legacy segun el nuevo sistema de roles
                    $legacyRol = 'chofer';
                    if ($id_rol == 1 || $id_rol == 2) {
                        $legacyRol = 'admin';
                    }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO usuarios (username, nombre, apellido, password, email, telefono, rol, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $nombre, $apellido, $hash, $email, $telefono, $legacyRol, $activo]);
                    $idUsuario = $db->lastInsertId();

                    // Asignar rol
                    if ($id_rol) {
                        $db->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)")->execute([$idUsuario, $id_rol]);
                    }

                    registrarAuditoria(getCurrentUserId(), 'create', 'usuarios', $idUsuario, "Creo usuario $username");
                    registrarAcceso(getCurrentUserId(), 'creacion_usuario', 'Usuarios', $idUsuario, "Creo usuario $username");
                    $mensaje = 'Usuario creado exitosamente';
                } catch (Exception $e) {
                    $error = 'Error al crear: ' . $e->getMessage();
                }
            }
        } else {
            $id = (int)($_POST['id_usuario'] ?? 0);
            try {
                if ($password) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE usuarios SET username=?, nombre=?, apellido=?, email=?, telefono=?, password=?, activo=? WHERE id_usuario=?");
                    $stmt->execute([$username, $nombre, $apellido, $email, $telefono, $hash, $activo, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE usuarios SET username=?, nombre=?, apellido=?, email=?, telefono=?, activo=? WHERE id_usuario=?");
                    $stmt->execute([$username, $nombre, $apellido, $email, $telefono, $activo, $id]);
                }

                // Actualizar rol
                $db->prepare("DELETE FROM usuario_rol WHERE id_usuario = ?")->execute([$id]);
                if ($id_rol) {
                    $db->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)")->execute([$id, $id_rol]);
                }

                registrarAuditoria(getCurrentUserId(), 'update', 'usuarios', $id, "Actualizo usuario $username");
                $mensaje = 'Usuario actualizado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al actualizar: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'desactivar') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE usuarios SET activo = 0 WHERE id_usuario = ?");
            $stmt->execute([$id]);
            registrarAuditoria(getCurrentUserId(), 'desactivar', 'usuarios', $id, "Desactivo usuario ID $id");
            $mensaje = 'Usuario desactivado';
        } catch (Exception $e) {
            $error = 'Error al desactivar';
        }
    } elseif ($action === 'activar') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        try {
            $stmt = $db->prepare("UPDATE usuarios SET activo = 1 WHERE id_usuario = ?");
            $stmt->execute([$id]);
            registrarAuditoria(getCurrentUserId(), 'activar', 'usuarios', $id, "Activo usuario ID $id");
            $mensaje = 'Usuario activado';
        } catch (Exception $e) {
            $error = 'Error al activar';
        }
    } elseif ($action === 'asignar_vehiculo') {
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        $idVehiculo = (int)($_POST['id_vehiculo'] ?? 0);
        if ($idUsuario && $idVehiculo) {
            try {
                $check = $db->prepare("SELECT COUNT(*) FROM vehiculos_usuarios WHERE usuario_id = ? AND vehiculo_id = ?");
                $check->execute([$idUsuario, $idVehiculo]);
                if ($check->fetchColumn() == 0) {
                    $db->prepare("INSERT INTO vehiculos_usuarios (usuario_id, vehiculo_id) VALUES (?, ?)")->execute([$idUsuario, $idVehiculo]);
                    registrarAuditoria(getCurrentUserId(), 'asignar_vehiculo', 'vehiculos_usuarios', $db->lastInsertId(), "Asigno vehiculo $idVehiculo a usuario $idUsuario");
                    $mensaje = 'Vehiculo asignado exitosamente';
                } else {
                    $error = 'El vehiculo ya esta asignado a este usuario';
                }
            } catch (Exception $e) {
                $error = 'Error al asignar vehiculo: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'quitar_vehiculo') {
        $id = (int)($_POST['id_asignacion'] ?? 0);
        try {
            $db->prepare("DELETE FROM vehiculos_usuarios WHERE id = ?")->execute([$id]);
            registrarAuditoria(getCurrentUserId(), 'quitar_vehiculo', 'vehiculos_usuarios', $id, "Quito asignacion vehiculo ID $id");
            $mensaje = 'Asignacion eliminada';
        } catch (Exception $e) {
            $error = 'Error al quitar asignacion';
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        $nueva_pass = trim($_POST['nueva_password'] ?? '');
        if (strlen($nueva_pass) < 6) {
            $error = 'La contrasena debe tener al menos 6 caracteres';
        } else {
            try {
                $hash = password_hash($nueva_pass, PASSWORD_DEFAULT);
                $db->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?")->execute([$hash, $id]);
                registrarAuditoria(getCurrentUserId(), 'reset_password', 'usuarios', $id, "Restablecio contrasena usuario ID $id");
                registrarAcceso(getCurrentUserId(), 'cambio_contrasena', 'Usuarios', $id, "Cambio de contrasena");
                $mensaje = 'Contrasena restablecida exitosamente';
            } catch (Exception $e) {
                $error = 'Error al restablecer contrasena';
            }
        }
    }
}

$buscar = $_GET['buscar'] ?? '';
$sql = "SELECT u.*, GROUP_CONCAT(DISTINCT r.nombre SEPARATOR ', ') as roles_nombre
        FROM usuarios u
        LEFT JOIN usuario_rol ur ON u.id_usuario = ur.id_usuario
        LEFT JOIN roles r ON ur.id_rol = r.id_rol
        WHERE 1=1";
$params = [];
if ($buscar) {
    $sql .= " AND (u.username LIKE ? OR u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ?)";
    $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%"; $params[] = "%$buscar%";
}
$sql .= " GROUP BY u.id_usuario ORDER BY u.created_at DESC";
$usuarios = $db->prepare($sql);
$usuarios->execute($params);
$usuariosList = $usuarios->fetchAll();

$rolesList = $db->query("SELECT id_rol, nombre, descripcion FROM roles ORDER BY nombre")->fetchAll();
$camionesActivos = $db->query("SELECT id_camion, patente, marca, modelo FROM camiones WHERE estado = 'activo' ORDER BY patente")->fetchAll();

// Obtener vehiculos asignados a cada usuario
$vehiculosPorUsuario = [];
$stmtV = $db->query("SELECT vu.*, c.patente, c.marca, c.modelo FROM vehiculos_usuarios vu JOIN camiones c ON vu.vehiculo_id = c.id_camion");
foreach ($stmtV->fetchAll() as $v) {
    $vehiculosPorUsuario[$v['usuario_id']][] = $v;
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestion de Usuarios</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Administracion de accesos al sistema.</p>
</div>
<?php if (hasPermission('usuarios_crear')): ?>
<button onclick="resetModalUsuario(); openModal('modalUsuario')" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">add</span> Nuevo Usuario
</button>
<?php endif; ?>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Search -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-xl flex items-center gap-4 mb-8">
<div class="relative w-full">
<span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
<input onkeyup="filterUsuarios()" id="searchUsuario" class="w-full pl-10 pr-4 py-2 bg-surface-container-low border-none rounded-lg focus:ring-2 focus:ring-primary" placeholder="Buscar por usuario, nombre, email..." type="text"/>
</div>
</div>

<!-- Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-xl table-wrap overflow-x-auto">
<table class="w-full min-w-[600px]">
<thead class="bg-surface-container-high/50">
<tr>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">ID</th>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-left">USUARIO</th>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-left hidden md:table-cell">NOMBRE</th>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-left hidden sm:table-cell">ROL</th>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ESTADO</th>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-left hidden xl:table-cell">ALTA</th>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-left hidden xl:table-cell">ULTIMO ACCESO</th>
<th class="px-2 md:px-6 py-3 font-label-caps text-[10px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="usuariosTableBody">
<?php foreach ($usuariosList as $u): ?>
<tr class="usuario-row hover:bg-surface-container transition-colors" data-search="<?= strtolower(htmlspecialchars($u['username'] . ' ' . $u['nombre'] . ' ' . $u['apellido'] . ' ' . $u['email'])) ?>">
<td class="px-2 md:px-6 py-3 font-data-mono font-bold text-primary text-xs md:text-sm">#<?= $u['id_usuario'] ?></td>
<td class="px-2 md:px-6 py-3">
<span class="font-medium text-xs md:text-sm"><?= htmlspecialchars($u['username']) ?></span>
</td>
<td class="px-2 md:px-6 py-3 hidden md:table-cell text-xs md:text-sm"><?= htmlspecialchars(trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''))) ?: '-' ?></td>
<td class="px-2 md:px-6 py-3 hidden sm:table-cell">
<?php
$rolesStr = $u['roles_nombre'] ?? '';
$rolClass = '';
if (strpos($rolesStr, 'Administrador') !== false) $rolClass = 'bg-purple-100 text-purple-800 border-purple-200';
elseif (strpos($rolesStr, 'Supervisor') !== false) $rolClass = 'bg-blue-100 text-blue-800 border-blue-200';
else $rolClass = 'bg-green-100 text-green-800 border-green-200';
?>
<span class="px-2 py-0.5 rounded-full text-[9px] md:text-[10px] font-bold uppercase border <?= $rolClass ?>"><?= htmlspecialchars($rolesStr ?: 'Sin rol') ?></span>
</td>
<td class="px-2 md:px-6 py-3 text-center">
<span class="px-2 py-0.5 rounded-full text-[9px] md:text-[10px] font-bold uppercase <?= $u['activo'] ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span>
</td>
<td class="px-2 md:px-6 py-3 text-xs hidden xl:table-cell"><?= $u['created_at'] ? date('d/m/Y', strtotime($u['created_at'])) : '-' ?></td>
<td class="px-2 md:px-6 py-3 text-xs hidden xl:table-cell"><?= $u['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : 'Nunca' ?></td>
<td class="px-2 md:px-6 py-3">
<div class="flex gap-1 justify-center flex-nowrap">
<?php if (hasPermission('usuarios_editar')): ?>
<button onclick="editUsuario(<?= $u['id_usuario'] ?>)" class="px-2 py-1 bg-secondary-container text-on-secondary-container rounded text-[10px] font-bold hover:opacity-80 flex items-center gap-0.5" title="Editar">
<span class="material-symbols-outlined text-xs">edit</span>
<span class="hidden lg:inline">Editar</span>
</button>
<?php endif; ?>
<button onclick="openResetPass(<?= $u['id_usuario'] ?>)" class="px-2 py-1 bg-amber-50 text-amber-700 rounded text-[10px] font-bold hover:bg-amber-100 flex items-center gap-0.5" title="Restablecer contraseña">
<span class="material-symbols-outlined text-xs">key</span>
<span class="hidden lg:inline">Pass</span>
</button>
<button onclick="openAsignarVehiculo(<?= $u['id_usuario'] ?>)" class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-[10px] font-bold hover:bg-blue-100 flex items-center gap-0.5" title="Asignar vehículo">
<span class="material-symbols-outlined text-xs">directions_car</span>
<span class="hidden lg:inline">Veh.</span>
</button>
<?php if ($u['activo']): ?>
<button onclick="toggleEstado(<?= $u['id_usuario'] ?>, 'desactivar')" class="px-2 py-1 bg-red-50 text-red-600 rounded text-[10px] font-bold hover:bg-red-100 flex items-center gap-0.5" title="Desactivar">
<span class="material-symbols-outlined text-xs">block</span>
<span class="hidden lg:inline">Desact.</span>
</button>
<?php else: ?>
<button onclick="toggleEstado(<?= $u['id_usuario'] ?>, 'activar')" class="px-2 py-1 bg-green-50 text-green-600 rounded text-[10px] font-bold hover:bg-green-100 flex items-center gap-0.5" title="Activar">
<span class="material-symbols-outlined text-xs">check_circle</span>
<span class="hidden lg:inline">Activar</span>
</button>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</main>

<!-- Modal Nuevo/Editar Usuario -->
<div id="modalUsuario" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalUsuarioTitle" class="font-headline-sm text-headline-sm text-primary">Nuevo Usuario</h3>
<button onclick="closeModal('modalUsuario')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" id="usuarioAction" value="create"/>
<input type="hidden" name="id_usuario" id="usuarioId" value=""/>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Usuario *</label>
<input name="username" id="usUsername" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Contrasena</label>
<input name="password" id="usPassword" type="password" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" placeholder="Min. 6 caracteres"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nombre</label>
<input name="nombre" id="usNombre" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Apellido</label>
<input name="apellido" id="usApellido" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="grid grid-cols-2 gap-4">
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Email</label>
<input name="email" id="usEmail" type="email" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Telefono</label>
<input name="telefono" id="usTelefono" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low"/>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Rol</label>
<select name="id_rol" id="usRol" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccione un rol...</option>
<?php foreach ($rolesList as $r): ?>
<option value="<?= $r['id_rol'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex items-center gap-2">
<input type="checkbox" name="activo" id="usActivo" checked class="w-4 h-4 rounded border-outline-variant"/>
<label for="usActivo" class="font-label-caps text-label-caps text-on-surface-variant uppercase">Usuario Activo</label>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalUsuario')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Reset Password -->
<div id="modalResetPass" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Restablecer Contrasena</h3>
<button onclick="closeModal('modalResetPass')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="reset_password"/>
<input type="hidden" name="id_usuario" id="resetUserId" value=""/>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Nueva Contrasena</label>
<input name="nueva_password" type="password" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required minlength="6" placeholder="Minimo 6 caracteres"/>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalResetPass')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Restablecer</button>
</div>
</form>
</div>
</div>

<!-- Modal Asignar Vehiculo -->
<div id="modalAsignarVehiculo" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 id="modalAsignarTitle" class="font-headline-sm text-headline-sm text-primary">Asignar Vehiculos</h3>
<button onclick="closeModal('modalAsignarVehiculo')"><span class="material-symbols-outlined">close</span></button>
</div>
<div class="p-6 space-y-4">
<form method="POST" class="flex gap-2">
<input type="hidden" name="action" value="asignar_vehiculo"/>
<input type="hidden" name="id_usuario" id="asignarUserId" value=""/>
<select name="id_vehiculo" class="flex-1 border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccionar vehiculo...</option>
<?php foreach ($camionesActivos as $c): ?>
<option value="<?= $c['id_camion'] ?>"><?= htmlspecialchars($c['patente'] . ' - ' . $c['marca'] . ' ' . $c['modelo']) ?></option>
<?php endforeach; ?>
</select>
<button type="submit" class="bg-primary text-on-primary px-4 py-2 rounded-lg font-bold text-sm">Asignar</button>
</form>

<div id="vehiculosAsignados" class="border-t border-outline-variant pt-4 mt-4">
<p class="font-label-caps text-label-caps text-on-surface-variant uppercase mb-3">Vehiculos asignados</p>
<div id="vehiculosList" class="space-y-2">
<p class="text-sm text-on-surface-variant">Seleccione un usuario para ver sus vehiculos</p>
</div>
</div>
</div>
</div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function resetModalUsuario() {
document.getElementById('usuarioAction').value = 'create';
document.getElementById('usuarioId').value = '';
document.getElementById('usUsername').value = '';
document.getElementById('usPassword').value = '';
document.getElementById('usPassword').required = true;
document.getElementById('usNombre').value = '';
document.getElementById('usApellido').value = '';
document.getElementById('usEmail').value = '';
document.getElementById('usTelefono').value = '';
document.getElementById('usRol').value = '';
document.getElementById('usActivo').checked = true;
document.getElementById('modalUsuarioTitle').textContent = 'Nuevo Usuario';
}

function editUsuario(id) {
fetch('<?= BASE_URL ?>/api/get_data.php?action=usuario&id=' + id)
.then(r => r.json()).then(data => {
if (!data || !data.id_usuario) {
alert('Error: No se pudieron obtener los datos del usuario');
return;
}
document.getElementById('usuarioAction').value = 'update';
document.getElementById('usuarioId').value = data.id_usuario;
document.getElementById('usUsername').value = data.username;
document.getElementById('usPassword').value = '';
document.getElementById('usPassword').required = false;
document.getElementById('usNombre').value = data.nombre || '';
document.getElementById('usApellido').value = data.apellido || '';
document.getElementById('usEmail').value = data.email || '';
document.getElementById('usTelefono').value = data.telefono || '';
document.getElementById('usRol').value = data.id_rol || '';
document.getElementById('usActivo').checked = data.activo == 1;
document.getElementById('modalUsuarioTitle').textContent = 'Editar Usuario';
openModal('modalUsuario');
}).catch(err => {
alert('Error al cargar datos: ' + err.message);
});
}

function openResetPass(id) {
document.getElementById('resetUserId').value = id;
openModal('modalResetPass');
}

var vehiculosData = <?= json_encode($vehiculosPorUsuario) ?>;

function openAsignarVehiculo(userId) {
document.getElementById('asignarUserId').value = userId;
var list = document.getElementById('vehiculosList');
var vehiculos = vehiculosData[userId] || [];
var html = '';
if (vehiculos.length === 0) {
html = '<p class="text-sm text-on-surface-variant">Sin vehiculos asignados</p>';
} else {
vehiculos.forEach(function(v) {
html += '<div class="flex items-center justify-between bg-surface-container-low p-3 rounded-lg">' +
'<div>' +
'<p class="font-bold text-sm">' + v.patente + '</p>' +
'<p class="text-xs text-on-surface-variant">' + v.marca + ' ' + v.modelo + '</p>' +
'</div>' +
'<button onclick="quitarVehiculo(' + v.id + ')" class="text-red-600 hover:text-red-800 px-2 py-1 text-xs font-bold">QUITAR</button>' +
'</div>';
});
}
list.innerHTML = html;
openModal('modalAsignarVehiculo');
}

function quitarVehiculo(id) {
showConfirm('¿Quitar este vehiculo?', function() {
const form = document.createElement('form');
form.method = 'POST';
form.innerHTML = '<input name="action" value="quitar_vehiculo"><input name="id_asignacion" value="' + id + '">';
document.body.appendChild(form);
form.submit();
});
}

function toggleEstado(id, accion) {
const msg = accion === 'desactivar' ? '¿Desactivar este usuario?' : '¿Activar este usuario?';
showConfirm(msg, function() {
const form = document.createElement('form');
form.method = 'POST';
form.innerHTML = '<input name="action" value="' + accion + '"><input name="id_usuario" value="' + id + '">';
document.body.appendChild(form);
form.submit();
});
}

function filterUsuarios() {
const search = document.getElementById('searchUsuario').value.toLowerCase();
document.querySelectorAll('.usuario-row').forEach(row => {
row.style.display = row.dataset.search.includes(search) ? '' : 'none';
});
}

document.getElementById('modalUsuario').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalUsuario');
});
document.getElementById('modalResetPass').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalResetPass');
});
document.getElementById('modalAsignarVehiculo').addEventListener('click', function(e) {
if (e.target === this) closeModal('modalAsignarVehiculo');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
