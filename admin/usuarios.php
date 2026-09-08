<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Gestion de Usuarios';
require_once __DIR__ . '/../includes/checklist_helper.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
initChecklistDatabase($db);

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
        $eliminarFirma = isset($_POST['eliminar_firma']) && $_POST['eliminar_firma'] == '1';
        $firmaBase64 = $_POST['firma_digital_base64'] ?? '';
        $firmaArchivo = $_FILES['firma_archivo'] ?? null;

        if ($action === 'create') {
            if (empty($username) || empty($password)) {
                $error = 'Usuario y contrasena son obligatorios';
            } else {
                try {
                    // Mapear el rol legacy segun el nuevo sistema de roles
                    $legacyRol = ($id_rol == 1) ? 'admin' : '';
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO usuarios (username, nombre, apellido, password, email, telefono, rol, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $nombre, $apellido, $hash, $email, $telefono, $legacyRol, $activo]);
                    $idUsuario = (int)$db->lastInsertId();

                    // Asignar rol
                    if ($id_rol) {
                        $db->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)")->execute([$idUsuario, $id_rol]);
                    }

                    // Guardar firma digital si se proporcionó
                    $rutaFirma = null;
                    if ($firmaArchivo && !empty($firmaArchivo['tmp_name'])) {
                        $rutaFirma = guardarFirmaUsuario($firmaArchivo, $idUsuario);
                    } elseif (!empty($firmaBase64)) {
                        $rutaFirma = guardarFirmaUsuario($firmaBase64, $idUsuario);
                    }
                    if ($rutaFirma) {
                        $db->prepare("UPDATE usuarios SET firma_digital = ? WHERE id_usuario = ?")->execute([$rutaFirma, $idUsuario]);
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
                $legacyRol = ($id_rol == 1) ? 'admin' : '';
                $db->prepare("UPDATE usuarios SET rol = ? WHERE id_usuario = ?")->execute([$legacyRol, $id]);
                $db->prepare("DELETE FROM usuario_rol WHERE id_usuario = ?")->execute([$id]);
                if ($id_rol) {
                    $db->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)")->execute([$id, $id_rol]);
                }

                // Procesar firma digital
                if ($eliminarFirma) {
                    $db->prepare("UPDATE usuarios SET firma_digital = NULL WHERE id_usuario = ?")->execute([$id]);
                } else {
                    $rutaFirma = null;
                    if ($firmaArchivo && !empty($firmaArchivo['tmp_name'])) {
                        $rutaFirma = guardarFirmaUsuario($firmaArchivo, $id);
                    } elseif (!empty($firmaBase64)) {
                        $rutaFirma = guardarFirmaUsuario($firmaBase64, $id);
                    }
                    if ($rutaFirma) {
                        $db->prepare("UPDATE usuarios SET firma_digital = ? WHERE id_usuario = ?")->execute([$rutaFirma, $id]);
                    }
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
    } elseif ($action === 'asociar_chofer') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        $idChofer = (int)($_POST['id_chofer'] ?? 0);
        if ($id && $idChofer) {
            try {
                $db->prepare("UPDATE usuarios SET id_chofer = ? WHERE id_usuario = ?")->execute([$idChofer, $id]);
                $db->prepare("UPDATE choferes SET usuario_id = ? WHERE id_chofer = ?")->execute([$id, $idChofer]);
                registrarAuditoria(getCurrentUserId(), 'asociar_chofer', 'usuarios', $id, "Asocio chofer ID $idChofer a usuario ID $id");
                $mensaje = 'Chofer asociado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al asociar chofer: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'desasociar_chofer') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        if ($id) {
            try {
                $stmt = $db->prepare("SELECT id_chofer FROM usuarios WHERE id_usuario = ?");
                $stmt->execute([$id]);
                $idChofer = $stmt->fetchColumn();
                if ($idChofer) {
                    $db->prepare("UPDATE choferes SET usuario_id = NULL WHERE id_chofer = ?")->execute([$idChofer]);
                }
                $db->prepare("UPDATE usuarios SET id_chofer = NULL WHERE id_usuario = ?")->execute([$id]);
                registrarAuditoria(getCurrentUserId(), 'desasociar_chofer', 'usuarios', $id, "Desasocio chofer de usuario ID $id");
                $mensaje = 'Chofer desasociado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al desasociar chofer: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'eliminar') {
        $id = (int)($_POST['id_usuario'] ?? 0);
        try {
            $db->prepare("DELETE FROM usuarios WHERE id_usuario = ?")->execute([$id]);
            registrarAuditoria(getCurrentUserId(), 'delete', 'usuarios', $id, "Elimino usuario ID $id");
            $mensaje = 'Usuario eliminado permanentemente';
        } catch (Exception $e) {
            $error = 'Error al eliminar: ' . $e->getMessage();
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

$buscar = trim($_GET['buscar'] ?? '');
$filtroRol = (int)($_GET['filtro_rol'] ?? 0);
$filtroEstado = $_GET['filtro_estado'] ?? '';
$orden = $_GET['orden'] ?? 'legajo';
$dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

// Mapeo de ordenamiento
switch ($orden) {
    case 'rol':
        $orderBy = "roles_nombre $dir, u.username ASC";
        break;
    case 'legajo':
        $orderBy = "COALESCE(u.id_chofer, u.id_usuario) $dir, u.id_usuario $dir";
        break;
    case 'nombre':
        $orderBy = "u.apellido $dir, u.nombre $dir, u.username $dir";
        break;
    case 'usuario':
        $orderBy = "u.username $dir";
        break;
    case 'id':
        $orderBy = "u.id_usuario $dir";
        break;
    case 'created_at':
        $orderBy = "u.created_at $dir";
        break;
    default:
        $orderBy = "COALESCE(u.id_chofer, u.id_usuario) ASC, u.id_usuario ASC";
        break;
}

$sql = "SELECT u.*, 
        COALESCE(u.id_chofer, u.id_usuario) as legajo_display,
        GROUP_CONCAT(DISTINCT r.nombre ORDER BY r.nombre SEPARATOR ', ') as roles_nombre,
        (SELECT CONCAT(c.nombre, ' ', c.apellido) FROM choferes c WHERE c.id_chofer = u.id_chofer) as chofer_asociado,
        (SELECT c.dni FROM choferes c WHERE c.id_chofer = u.id_chofer) as chofer_dni
        FROM usuarios u
        LEFT JOIN usuario_rol ur ON u.id_usuario = ur.id_usuario
        LEFT JOIN roles r ON ur.id_rol = r.id_rol
        WHERE 1=1";
$params = [];
if ($buscar !== '') {
    $sql .= " AND (u.username LIKE ? OR u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR CAST(u.id_usuario AS CHAR) LIKE ? OR CAST(u.id_chofer AS CHAR) LIKE ?)";
    $term = "%$buscar%";
    $params = [$term, $term, $term, $term, $term, $term];
}
if ($filtroRol > 0) {
    $sql .= " AND ur.id_rol = ?";
    $params[] = $filtroRol;
}
if ($filtroEstado === '1' || $filtroEstado === '0') {
    $sql .= " AND u.activo = ?";
    $params[] = (int)$filtroEstado;
}

$sql .= " GROUP BY u.id_usuario ORDER BY $orderBy";
$usuarios = $db->prepare($sql);
$usuarios->execute($params);
$usuariosList = $usuarios->fetchAll();

$rolesList = $db->query("SELECT id_rol, nombre, descripcion FROM roles ORDER BY nombre")->fetchAll();
$camionesActivos = $db->query("SELECT id_camion, patente, marca, modelo FROM camiones WHERE estado = 'activo' ORDER BY patente")->fetchAll();
$choferesDisponibles = $db->query("SELECT c.*, CONCAT(c.nombre, ' ', c.apellido) as nombre_completo FROM choferes c ORDER BY c.apellido ASC")->fetchAll();
$choferesAsociados = $db->query("SELECT id_chofer FROM usuarios WHERE id_chofer IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// Obtener vehiculos asignados a cada usuario
$vehiculosPorUsuario = [];
$stmtV = $db->query("SELECT vu.*, c.patente, c.marca, c.modelo FROM vehiculos_usuarios vu JOIN camiones c ON vu.vehiculo_id = c.id_camion");
foreach ($stmtV->fetchAll() as $v) {
    $vehiculosPorUsuario[(int)$v['usuario_id']][] = $v;
}

// Helpers para enlaces de ordenamiento
function buildSortUrl(string $col, string $currOrden, string $currDir, string $buscar, int $filtroRol, string $filtroEstado): string {
    $newDir = ($currOrden === $col && $currDir === 'ASC') ? 'desc' : 'asc';
    $q = ['orden' => $col, 'dir' => $newDir];
    if ($buscar !== '') $q['buscar'] = $buscar;
    if ($filtroRol > 0) $q['filtro_rol'] = $filtroRol;
    if ($filtroEstado !== '') $q['filtro_estado'] = $filtroEstado;
    return '?' . http_build_query($q);
}

function buildSortIcon(string $col, string $currOrden, string $currDir): string {
    if ($currOrden !== $col) {
        return '<span class="material-symbols-outlined text-[14px] text-slate-400 group-hover:text-primary transition-colors inline-block align-middle">unfold_more</span>';
    }
    return $currDir === 'ASC' 
        ? '<span class="material-symbols-outlined text-[14px] text-primary font-bold inline-block align-middle">arrow_upward</span>' 
        : '<span class="material-symbols-outlined text-[14px] text-primary font-bold inline-block align-middle">arrow_downward</span>';
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Gestión de Usuarios</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Administración de accesos, roles, legajos y firmas del sistema.</p>
</div>
<?php if (hasPermission('usuarios_crear')): ?>
<button onclick="resetModalUsuario(); openModal('modalUsuario')" class="bg-primary text-on-primary px-6 py-3 rounded-xl font-bold flex items-center gap-2 hover:opacity-90 transition-opacity shadow-sm">
<span class="material-symbols-outlined">add</span> Nuevo Usuario
</button>
<?php endif; ?>
</div>

<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-green-600">check_circle</span><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4 flex items-center gap-2"><span class="material-symbols-outlined text-red-600">error</span><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Barra de Filtros, Búsqueda y Ordenamiento -->
<div class="bg-surface-container-lowest border border-outline-variant p-4 rounded-2xl mb-6 shadow-sm">
    <form method="GET" action="usuarios.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 items-center">
        <!-- Búsqueda rápida -->
        <div class="lg:col-span-4 relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-lg">search</span>
            <input name="buscar" value="<?= htmlspecialchars($buscar) ?>" onkeyup="filterUsuarios()" id="searchUsuario" class="w-full pl-9 pr-4 py-2 text-sm bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary focus:bg-white transition-all" placeholder="Buscar usuario, nombre, legajo..." type="text"/>
        </div>

        <!-- Filtro por Rol -->
        <div class="lg:col-span-3">
            <select name="filtro_rol" id="filtroRolSelect" onchange="this.form.submit()" class="w-full px-3 py-2 text-xs font-semibold bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary">
                <option value="0">-- Todos los Roles --</option>
                <?php foreach ($rolesList as $r): ?>
                <option value="<?= $r['id_rol'] ?>" <?= ($filtroRol === (int)$r['id_rol']) ? 'selected' : '' ?>>
                    Rol: <?= htmlspecialchars($r['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Ordenar por -->
        <div class="lg:col-span-3">
            <select name="orden_combo" onchange="cambiarOrdenCombo(this.value)" class="w-full px-3 py-2 text-xs font-semibold bg-surface-container-low border border-outline-variant/60 rounded-xl focus:ring-2 focus:ring-primary">
                <option value="legajo_asc" <?= ($orden === 'legajo' && $dir === 'ASC') ? 'selected' : '' ?>>⇅ Ordenar por: Legajo / ID (1 ➔ 99)</option>
                <option value="legajo_desc" <?= ($orden === 'legajo' && $dir === 'DESC') ? 'selected' : '' ?>>⇅ Ordenar por: Legajo / ID (99 ➔ 1)</option>
                <option value="rol_asc" <?= ($orden === 'rol' && $dir === 'ASC') ? 'selected' : '' ?>>⇅ Ordenar por: Rol (A ➔ Z)</option>
                <option value="rol_desc" <?= ($orden === 'rol' && $dir === 'DESC') ? 'selected' : '' ?>>⇅ Ordenar por: Rol (Z ➔ A)</option>
                <option value="nombre_asc" <?= ($orden === 'nombre' && $dir === 'ASC') ? 'selected' : '' ?>>⇅ Ordenar por: Nombre (A ➔ Z)</option>
                <option value="usuario_asc" <?= ($orden === 'usuario' && $dir === 'ASC') ? 'selected' : '' ?>>⇅ Ordenar por: Usuario (A ➔ Z)</option>
                <option value="created_at_desc" <?= ($orden === 'created_at' && $dir === 'DESC') ? 'selected' : '' ?>>⇅ Ordenar por: Más Recientes</option>
            </select>
            <input type="hidden" name="orden" id="inputHiddenOrden" value="<?= htmlspecialchars($orden) ?>">
            <input type="hidden" name="dir" id="inputHiddenDir" value="<?= htmlspecialchars(strtolower($dir)) ?>">
        </div>

        <!-- Botones de Acción -->
        <div class="lg:col-span-2 flex items-center gap-2">
            <button type="submit" class="w-full py-2 px-3 bg-primary text-white text-xs font-bold rounded-xl hover:opacity-90 transition-opacity flex items-center justify-center gap-1 shadow-sm">
                <span class="material-symbols-outlined text-sm">filter_alt</span> Filtrar
            </button>
            <?php if ($buscar !== '' || $filtroRol > 0 || $filtroEstado !== '' || $orden !== 'legajo'): ?>
            <a href="usuarios.php" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-all" title="Limpiar filtros">
                <span class="material-symbols-outlined text-sm">restart_alt</span>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Table -->
<div class="bg-surface-container-lowest border border-outline-variant rounded-2xl table-wrap overflow-x-auto shadow-sm">
<table class="w-full">
<thead class="bg-slate-100/70 border-b border-outline-variant">
<tr>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-left">
    <a href="<?= buildSortUrl('legajo', $orden, $dir, $buscar, $filtroRol, $filtroEstado) ?>" class="group flex items-center gap-1 font-bold hover:text-primary transition-colors" title="Ordenar por Legajo / ID">
        <span>ID / LEGAJO</span> <?= buildSortIcon('legajo', $orden, $dir) ?>
    </a>
</th>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-left">
    <a href="<?= buildSortUrl('usuario', $orden, $dir, $buscar, $filtroRol, $filtroEstado) ?>" class="group flex items-center gap-1 font-bold hover:text-primary transition-colors" title="Ordenar por Usuario">
        <span>USUARIO</span> <?= buildSortIcon('usuario', $orden, $dir) ?>
    </a>
</th>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-left hidden md:table-cell">
    <a href="<?= buildSortUrl('nombre', $orden, $dir, $buscar, $filtroRol, $filtroEstado) ?>" class="group flex items-center gap-1 font-bold hover:text-primary transition-colors" title="Ordenar por Nombre">
        <span>NOMBRE Y APELLIDO</span> <?= buildSortIcon('nombre', $orden, $dir) ?>
    </a>
</th>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-center hidden lg:table-cell">
    <a href="<?= buildSortUrl('rol', $orden, $dir, $buscar, $filtroRol, $filtroEstado) ?>" class="group inline-flex items-center justify-center gap-1 font-bold hover:text-primary transition-colors" title="Ordenar por Rol">
        <span>ROL</span> <?= buildSortIcon('rol', $orden, $dir) ?>
    </a>
</th>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-center">EST</th>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-center">FIRMA</th>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-left hidden xl:table-cell">CHOFER VINCULADO</th>
<th class="px-3 py-3.5 font-label-caps text-[12px] text-on-surface-variant text-center">ACCIONES</th>
</tr>
</thead>
<tbody class="divide-y divide-outline-variant" id="usuariosTableBody">
<?php if (empty($usuariosList)): ?>
<tr>
    <td colspan="8" class="py-8 text-center text-slate-500 text-sm">No se encontraron usuarios con los criterios seleccionados.</td>
</tr>
<?php endif; ?>
<?php foreach ($usuariosList as $u): ?>
<tr class="usuario-row hover:bg-surface-container transition-colors" data-search="<?= strtolower(htmlspecialchars($u['username'] . ' ' . $u['nombre'] . ' ' . $u['apellido'] . ' ' . $u['email'] . ' ' . ($u['id_chofer'] ? 'legajo '.$u['id_chofer'] : ''))) ?>" data-rol="<?= strtolower(htmlspecialchars($u['roles_nombre'] ?? '')) ?>">
<td class="px-3 py-3">
    <div class="flex items-center gap-1.5 flex-wrap">
        <span class="font-data-mono font-bold text-primary text-[13px]">#<?= $u['id_usuario'] ?></span>
        <?php if (!empty($u['id_chofer'])): ?>
        <span class="px-1.5 py-0.5 rounded-md text-[10px] font-bold uppercase bg-blue-50 text-blue-800 border border-blue-200" title="Legajo de Chofer vinculante">
            Legajo #<?= $u['id_chofer'] ?>
        </span>
        <?php endif; ?>
    </div>
</td>
<td class="px-3 py-3">
    <span class="font-bold text-[13px] text-slate-900"><?= htmlspecialchars($u['username']) ?></span>
    <?php if (!empty($u['email'])): ?>
    <p class="text-[11px] text-slate-500"><?= htmlspecialchars($u['email']) ?></p>
    <?php endif; ?>
</td>
<td class="px-3 py-3 hidden md:table-cell text-[13px]">
    <?= htmlspecialchars(trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''))) ?: '-' ?>
</td>
<td class="px-3 py-3 text-center hidden lg:table-cell">
<?php
$rolesStr = $u['roles_nombre'] ?? '';
$rolClass = '';
if (stripos($rolesStr, 'Administrador') !== false || stripos($rolesStr, 'Admin') !== false) $rolClass = 'bg-purple-100 text-purple-800 border-purple-200';
elseif (stripos($rolesStr, 'Inspector') !== false) $rolClass = 'bg-amber-100 text-amber-900 border-amber-300 font-extrabold';
elseif (stripos($rolesStr, 'Supervisor') !== false) $rolClass = 'bg-blue-100 text-blue-800 border-blue-200';
elseif (stripos($rolesStr, 'Chofer') !== false) $rolClass = 'bg-emerald-100 text-emerald-800 border-emerald-200';
else $rolClass = 'bg-slate-100 text-slate-800 border-slate-200';
?>
<span class="px-2.5 py-1 rounded-full text-[11px] font-bold uppercase border <?= $rolClass ?>"><?= htmlspecialchars($rolesStr ?: '-') ?></span>
</td>
<td class="px-3 py-3 text-center">
<span class="px-2 py-0.5 rounded-full text-[11px] font-bold uppercase <?= $u['activo'] ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span>
</td>
<td class="px-3 py-3 text-center">
<?php if (!empty($u['firma_digital'])): ?>
<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200" title="Firma cargada: se aplicará automáticamente en inspecciones">
<span class="material-symbols-outlined text-[13px]">draw</span> Sí
</span>
<?php else: ?>
<span class="text-slate-300 text-xs font-mono">-</span>
<?php endif; ?>
</td>
<td class="px-3 py-3 hidden xl:table-cell">
<?php if ($u['chofer_asociado']): ?>
<div class="flex items-center gap-1.5">
<span class="text-[13px] font-medium text-slate-800"><?= htmlspecialchars($u['chofer_asociado']) ?></span>
<?php if (!empty($u['chofer_dni'])): ?>
<span class="text-[10px] text-slate-500 font-mono">(DNI: <?= $u['chofer_dni'] ?>)</span>
<?php endif; ?>
<form method="POST" class="inline" onsubmit="return confirm('¿Desasociar chofer?')">
<input type="hidden" name="action" value="desasociar_chofer"/>
<input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>"/>
<button type="submit" class="text-red-500 hover:text-red-700 text-sm font-bold ml-1" title="Desasociar chofer">&times;</button>
</form>
</div>
<?php else: ?>
<button onclick="openAsociarChofer(<?= $u['id_usuario'] ?>)" class="text-xs text-primary font-semibold hover:underline flex items-center gap-0.5">
<span class="material-symbols-outlined text-sm">link</span> Vincular chofer
</button>
<?php endif; ?>
</td>
<td class="px-3 py-3">
<div class="flex gap-1 justify-center flex-nowrap">
<?php if (hasPermission('usuarios_editar')): ?>
<button onclick="editUsuario(<?= $u['id_usuario'] ?>)" class="p-1.5 bg-secondary-container text-on-secondary-container rounded-lg hover:opacity-80 transition-opacity" title="Editar">
<span class="material-symbols-outlined text-sm">edit</span>
</button>
<?php endif; ?>
<button onclick="openResetPass(<?= $u['id_usuario'] ?>)" class="p-1.5 bg-amber-50 text-amber-700 rounded-lg hover:bg-amber-100 transition-colors" title="Restablecer contraseña">
<span class="material-symbols-outlined text-sm">key</span>
</button>
<?php if (stripos($u['roles_nombre'] ?? '', 'mantenimiento') === false): ?>
<button onclick="openAsignarVehiculo(<?= $u['id_usuario'] ?>)" class="p-1.5 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors" title="Asignar vehículo">
<span class="material-symbols-outlined text-sm">directions_car</span>
</button>
<?php endif; ?>
            <?php if (hasPermission('usuarios_eliminar')): ?>
            <button onclick="eliminarUsuario(<?= $u['id_usuario'] ?>)" class="p-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors" title="Eliminar">
                <span class="material-symbols-outlined text-sm">delete</span>
            </button>
            <?php endif; ?>
            <?php if ($u['activo']): ?>
            <button onclick="toggleEstado(<?= $u['id_usuario'] ?>, 'desactivar')" class="p-1.5 bg-orange-50 text-orange-600 rounded-lg hover:bg-orange-100 transition-colors" title="Desactivar">
                <span class="material-symbols-outlined text-sm">block</span>
            </button>
            <?php else: ?>
            <button onclick="toggleEstado(<?= $u['id_usuario'] ?>, 'activar')" class="p-1.5 bg-green-50 text-green-600 rounded-lg hover:bg-green-100 transition-colors" title="Activar">
                <span class="material-symbols-outlined text-sm">check_circle</span>
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
<form method="POST" enctype="multipart/form-data" id="formUsuario" class="p-6 space-y-4">
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

<!-- Sección Firma Digital del Usuario / Inspector -->
<div class="border-t border-outline-variant pt-4 mt-2">
    <div class="flex items-center justify-between mb-2">
        <label class="font-label-caps text-label-caps text-on-surface-variant uppercase font-bold flex items-center gap-1">
            <span class="material-symbols-outlined text-sm text-primary">draw</span> Firma Digital del Usuario / Inspector
        </label>
        <span class="text-[11px] text-emerald-700 font-semibold">Autofirma en Checklist</span>
    </div>

    <!-- Vista previa de firma guardada actual -->
    <div id="boxFirmaActual" class="hidden p-3 bg-surface-container-low rounded-xl border border-outline-variant mb-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <img id="imgFirmaActual" src="" alt="Firma Usuario" class="h-12 w-auto max-w-[150px] object-contain bg-white rounded p-1 border border-outline-variant">
            <div>
                <p class="text-xs font-bold text-emerald-700 flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">check_circle</span> Firma guardada
                </p>
                <p class="text-[11px] text-on-surface-variant">Se aplicará automáticamente en inspecciones</p>
            </div>
        </div>
        <button type="button" onclick="eliminarFirmaUsuario()" class="text-xs text-red-600 hover:text-red-800 font-bold px-2.5 py-1 rounded-lg bg-red-50 hover:bg-red-100 flex items-center gap-1 border border-red-200">
            <span class="material-symbols-outlined text-xs">delete</span> Quitar
        </button>
    </div>
    <input type="hidden" name="eliminar_firma" id="inputEliminarFirma" value="0">

    <!-- Tabs para Subir o Dibujar Firma -->
    <div class="space-y-3 bg-surface-container-low/60 p-3 rounded-xl border border-outline-variant/60">
        <div class="flex gap-2">
            <button type="button" id="btnTabSubirFirma" onclick="setFirmaTab('subir')" class="flex-1 py-1.5 px-3 rounded-lg text-xs font-bold bg-primary text-on-primary transition-all">
                📁 Subir Archivo
            </button>
            <button type="button" id="btnTabDibujarFirma" onclick="setFirmaTab('dibujar')" class="flex-1 py-1.5 px-3 rounded-lg text-xs font-bold bg-surface-container text-on-surface hover:bg-surface-container-high transition-all">
                ✍️ Dibujar Firma
            </button>
        </div>

        <!-- Panel Subir Archivo -->
        <div id="panelSubirFirma">
            <label class="block text-xs text-on-surface-variant mb-1">Cargar imagen de firma (PNG, JPG o WebP con fondo blanco o transparente):</label>
            <input type="file" name="firma_archivo" id="usFirmaArchivo" accept="image/png,image/jpeg,image/webp" class="w-full text-xs text-on-surface border border-outline-variant rounded-lg p-2 bg-surface-container-lowest file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-on-primary hover:file:opacity-90"/>
        </div>

        <!-- Panel Dibujar Pad -->
        <div id="panelDibujarFirma" class="hidden">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs text-on-surface-variant">Dibuje con el dedo o mouse:</span>
                <button type="button" onclick="limpiarCanvasUsuario()" class="text-[11px] text-red-600 font-bold hover:underline flex items-center gap-0.5">
                    <span class="material-symbols-outlined text-xs">cleaning_services</span> Limpiar
                </button>
            </div>
            <div class="border border-outline-variant rounded-xl bg-white p-1 relative shadow-inner">
                <canvas id="canvasFirmaUsuario" width="450" height="120" class="w-full h-28 bg-white rounded touch-none cursor-crosshair"></canvas>
                <div id="hintCanvasUsuario" class="absolute inset-0 flex items-center justify-center pointer-events-none text-slate-300 text-xs font-semibold select-none">
                    ✍️ Firme aquí
                </div>
            </div>
            <input type="hidden" name="firma_digital_base64" id="usFirmaBase64" value=""/>
        </div>
    </div>
</div>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalUsuario')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Guardar</button>
</div>
</form>
</div>
</div>

<!-- Modal Asociar Chofer -->
<div id="modalAsociarChofer" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
<div class="bg-surface-container-lowest rounded-xl w-full max-w-md">
<div class="p-6 border-b border-outline-variant flex justify-between items-center">
<h3 class="font-headline-sm text-headline-sm text-primary">Asociar Chofer</h3>
<button onclick="closeModal('modalAsociarChofer')"><span class="material-symbols-outlined">close</span></button>
</div>
<form method="POST" class="p-6 space-y-4">
<input type="hidden" name="action" value="asociar_chofer"/>
<input type="hidden" name="id_usuario" id="asociarChoferUserId" value=""/>
<div class="flex flex-col gap-1">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Seleccionar Chofer</label>
<select name="id_chofer" id="asociarChoferSelect" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low" required>
<option value="">Seleccione un chofer...</option>
<?php foreach ($choferesDisponibles as $ch): ?>
<?php $yaAsociado = in_array($ch['id_chofer'], $choferesAsociados); ?>
<option value="<?= $ch['id_chofer'] ?>" data-asociado="<?= $yaAsociado ? '1' : '0' ?>"><?= htmlspecialchars($ch['nombre_completo']) ?> (<?= htmlspecialchars($ch['dni']) ?>)<?= $yaAsociado ? ' [YA ASOCIADO]' : '' ?></option>
<?php endforeach; ?>
</select>
</div>
<p id="asociarChoferWarning" class="text-xs text-amber-600 hidden">Este chofer ya esta asociado a otro usuario. Al confirmar se reasignara.</p>
<div class="flex gap-3 pt-4">
<button type="button" onclick="closeModal('modalAsociarChofer')" class="flex-1 border border-outline text-primary py-2 rounded-lg font-bold">Cancelar</button>
<button type="submit" class="flex-1 bg-primary text-on-primary py-2 rounded-lg font-bold">Asociar</button>
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

// =============================================================
// FIRMA DIGITAL DE USUARIOS (SUBIDA Y CANVAS)
// =============================================================
let canvasUser = document.getElementById('canvasFirmaUsuario');
let ctxUser = canvasUser ? canvasUser.getContext('2d') : null;
let dibujandoUser = false;
let firmaUserDibujada = false;

if (canvasUser && ctxUser) {
    ctxUser.strokeStyle = '#0f172a';
    ctxUser.lineWidth = 2.5;
    ctxUser.lineCap = 'round';
    ctxUser.lineJoin = 'round';

    function getUserCanvasPos(e) {
        let rect = canvasUser.getBoundingClientRect();
        let clientX = e.clientX;
        let clientY = e.clientY;
        if (e.touches && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        }
        let scaleX = canvasUser.width / rect.width;
        let scaleY = canvasUser.height / rect.height;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function empezarDibujoUser(e) {
        dibujandoUser = true;
        firmaUserDibujada = true;
        let hint = document.getElementById('hintCanvasUsuario');
        if (hint) hint.classList.add('hidden');
        let pos = getUserCanvasPos(e);
        ctxUser.beginPath();
        ctxUser.moveTo(pos.x, pos.y);
        e.preventDefault();
    }

    function moverDibujoUser(e) {
        if (!dibujandoUser) return;
        let pos = getUserCanvasPos(e);
        ctxUser.lineTo(pos.x, pos.y);
        ctxUser.stroke();
        e.preventDefault();
    }

    function pararDibujoUser() {
        if (dibujandoUser) {
            dibujandoUser = false;
            document.getElementById('usFirmaBase64').value = canvasUser.toDataURL('image/png');
        }
    }

    canvasUser.addEventListener('mousedown', empezarDibujoUser);
    canvasUser.addEventListener('mousemove', moverDibujoUser);
    canvasUser.addEventListener('mouseup', pararDibujoUser);
    canvasUser.addEventListener('mouseleave', pararDibujoUser);

    canvasUser.addEventListener('touchstart', empezarDibujoUser, { passive: false });
    canvasUser.addEventListener('touchmove', moverDibujoUser, { passive: false });
    canvasUser.addEventListener('touchend', pararDibujoUser);
}

function setFirmaTab(tab) {
    const btnSubir = document.getElementById('btnTabSubirFirma');
    const btnDibujar = document.getElementById('btnTabDibujarFirma');
    const pSubir = document.getElementById('panelSubirFirma');
    const pDibujar = document.getElementById('panelDibujarFirma');

    if (tab === 'subir') {
        btnSubir.className = 'flex-1 py-1.5 px-3 rounded-lg text-xs font-bold bg-primary text-on-primary transition-all';
        btnDibujar.className = 'flex-1 py-1.5 px-3 rounded-lg text-xs font-bold bg-surface-container text-on-surface hover:bg-surface-container-high transition-all';
        pSubir.classList.remove('hidden');
        pDibujar.classList.add('hidden');
    } else {
        btnDibujar.className = 'flex-1 py-1.5 px-3 rounded-lg text-xs font-bold bg-primary text-on-primary transition-all';
        btnSubir.className = 'flex-1 py-1.5 px-3 rounded-lg text-xs font-bold bg-surface-container text-on-surface hover:bg-surface-container-high transition-all';
        pDibujar.classList.remove('hidden');
        pSubir.classList.add('hidden');
    }
}

function limpiarCanvasUsuario() {
    if (canvasUser && ctxUser) {
        ctxUser.clearRect(0, 0, canvasUser.width, canvasUser.height);
        firmaUserDibujada = false;
        document.getElementById('usFirmaBase64').value = '';
        let hint = document.getElementById('hintCanvasUsuario');
        if (hint) hint.classList.remove('hidden');
    }
}

function eliminarFirmaUsuario() {
    if (confirm('¿Desea quitar la firma digital guardada de este usuario?')) {
        document.getElementById('inputEliminarFirma').value = '1';
        document.getElementById('boxFirmaActual').classList.add('hidden');
    }
}

// Interceptar submit del form para guardar firma dibujada
document.getElementById('formUsuario')?.addEventListener('submit', function() {
    if (canvasUser && firmaUserDibujada) {
        document.getElementById('usFirmaBase64').value = canvasUser.toDataURL('image/png');
    }
});

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
document.getElementById('inputEliminarFirma').value = '0';
document.getElementById('boxFirmaActual').classList.add('hidden');
document.getElementById('imgFirmaActual').src = '';
document.getElementById('usFirmaArchivo').value = '';
limpiarCanvasUsuario();
setFirmaTab('subir');
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
document.getElementById('inputEliminarFirma').value = '0';
document.getElementById('usFirmaArchivo').value = '';
limpiarCanvasUsuario();
setFirmaTab('subir');

if (data.firma_digital) {
    document.getElementById('imgFirmaActual').src = '<?= BASE_URL ?>/' + data.firma_digital;
    document.getElementById('boxFirmaActual').classList.remove('hidden');
} else {
    document.getElementById('boxFirmaActual').classList.add('hidden');
    document.getElementById('imgFirmaActual').src = '';
}

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

function openAsociarChofer(userId) {
document.getElementById('asociarChoferUserId').value = userId;
document.getElementById('asociarChoferSelect').value = '';
document.getElementById('asociarChoferWarning').classList.add('hidden');
openModal('modalAsociarChofer');
}

document.getElementById('asociarChoferSelect')?.addEventListener('change', function() {
var warning = document.getElementById('asociarChoferWarning');
if (this.options[this.selectedIndex]?.dataset.asociado === '1') {
warning.classList.remove('hidden');
} else {
warning.classList.add('hidden');
}
});

var vehiculosData = <?= json_encode($vehiculosPorUsuario) ?>;

function openAsignarVehiculo(userId) {
document.getElementById('asignarUserId').value = userId;
var list = document.getElementById('vehiculosList');
userId = String(userId); // Normalizar a string para coincidir con claves JSON
var vehiculos = vehiculosData[userId] || vehiculosData[parseInt(userId)] || [];
console.log('userId:', userId, 'vehiculos:', vehiculos, 'allData:', vehiculosData);
var html = '';
var canDelete = <?= hasPermission('vehiculos_eliminar') ? 'true' : 'false' ?>;
if (vehiculos.length === 0) {
html = '<p class="text-sm text-on-surface-variant">Sin vehiculos asignados</p>';
} else {
vehiculos.forEach(function(v) {
html += '<div class="flex items-center justify-between bg-surface-container-low p-3 rounded-lg">' +
'<div>' +
'<p class="font-bold text-sm">' + v.patente + '</p>' +
'<p class="text-xs text-on-surface-variant">' + v.marca + ' ' + v.modelo + '</p>' +
'</div>' +
(canDelete ? '<button onclick="quitarVehiculo(' + v.id + ')" class="text-red-600 hover:text-red-800 px-2 py-1 text-xs font-bold">QUITAR</button>' : '') +
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

function eliminarUsuario(id) {
    if (typeof showConfirm === 'function') {
        showConfirm('¿Eliminar este usuario permanentemente? Esta accion no se puede deshacer.', function() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input name="action" value="eliminar"><input name="id_usuario" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        });
    } else {
        if (confirm('¿Eliminar este usuario permanentemente? Esta accion no se puede deshacer.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input name="action" value="eliminar"><input name="id_usuario" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }
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

function cambiarOrdenCombo(val) {
    if (!val) return;
    const parts = val.split('_');
    const dir = parts.pop();
    const orden = parts.join('_');
    const inputOrden = document.getElementById('inputHiddenOrden');
    const inputDir = document.getElementById('inputHiddenDir');
    if (inputOrden && inputDir) {
        inputOrden.value = orden;
        inputDir.value = dir;
    }
    const form = document.getElementById('searchUsuario') ? document.getElementById('searchUsuario').form : null;
    if (form) {
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
