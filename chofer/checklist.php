<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'CHECK LIST DIARIO AUTOELEVADORES - RES. SRT Nº 960/15';

require_once __DIR__ . '/../includes/checklist_helper.php';
$db = getDB();
initChecklistDatabase($db);

$userId = getCurrentUserId();
$idChofer = getChoferIdFromUser();

// Si el usuario no tiene id_chofer vinculado, buscarlo en choferes por usuario_id
if (!$idChofer && $userId) {
    try {
        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
        $stmtCh->execute([$userId]);
        $idChofer = $stmtCh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

$usuarioActual = null;
$firmaGuardadaUsuario = null;
if ($userId) {
    try {
        $stmtUs = $db->prepare("SELECT id_usuario, username, nombre, apellido, firma_digital FROM usuarios WHERE id_usuario = ?");
        $stmtUs->execute([$userId]);
        $usuarioActual = $stmtUs->fetch();
        $firmaGuardadaUsuario = $usuarioActual['firma_digital'] ?? null;
    } catch (Exception $e) {}
}

$mensaje = '';
$error = '';

if (isset($_SESSION['flash_msg'])) {
    $mensaje = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}
if (isset($_SESSION['flash_err'])) {
    $error = $_SESSION['flash_err'];
    unset($_SESSION['flash_err']);
}

// Handler: Guardar o actualizar la firma del operador en su perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_mi_firma_perfil') {
    $firmaBase64 = $_POST['mi_firma_base64'] ?? '';
    if (!empty($firmaBase64) || (isset($_FILES['mi_firma_archivo']) && $_FILES['mi_firma_archivo']['error'] === UPLOAD_ERR_OK)) {
        $archivoFirma = (isset($_FILES['mi_firma_archivo']) && $_FILES['mi_firma_archivo']['error'] === UPLOAD_ERR_OK) ? $_FILES['mi_firma_archivo'] : $firmaBase64;
        $ruta = guardarFirmaUsuario($archivoFirma, $userId);
        if ($ruta) {
            $db->prepare("UPDATE usuarios SET firma_digital = ? WHERE id_usuario = ?")->execute([$ruta, $userId]);
            $_SESSION['flash_msg'] = '¡Firma digital guardada con éxito en su perfil! Se aplicará automáticamente en todos sus checklists.';
        } else {
            $_SESSION['flash_err'] = 'No se pudo procesar la firma digital.';
        }
    } else {
        $_SESSION['flash_err'] = 'Por favor, dibuje o adjunte su firma digital antes de guardar.';
    }
    header('Location: checklist.php');
    exit;
}

// Handler: Eliminar firma guardada del perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'eliminar_mi_firma_perfil') {
    $db->prepare("UPDATE usuarios SET firma_digital = NULL WHERE id_usuario = ?")->execute([$userId]);
    $_SESSION['flash_msg'] = 'Firma digital eliminada de su perfil. Podrá dibujar una nueva cuando lo desee.';
    header('Location: checklist.php');
    exit;
}

// -------------------------------------------------------------
// PROCESAMIENTO DEL CHECKLIST POR EL OPERADOR
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_checklist_chofer') {
    $id_camion = (int)($_POST['id_camion'] ?? 0);
    $horas_maquina = !empty($_POST['horas_maquina']) ? (float)$_POST['horas_maquina'] : null;
    $fecha = date('Y-m-d H:i:s');
    $observacion_general = trim($_POST['observacion_general'] ?? '');
    $firmado_por = trim($_POST['firmado_por'] ?? getCurrentUserName());
    $respuestasPost = $_POST['respuestas'] ?? [];
    $observacionesPost = $_POST['observaciones'] ?? [];

    if (!$id_camion) {
        $_SESSION['flash_err'] = 'Por favor, seleccione el autoelevador o máquina a inspeccionar.';
        header('Location: checklist.php');
        exit;
    }

    if (empty($respuestasPost)) {
        $_SESSION['flash_err'] = 'Debe responder las preguntas del checklist.';
        header('Location: checklist.php');
        exit;
    }

    // Prevención de doble registro (idempotencia en 10s)
    try {
        $stmtDup = $db->prepare("SELECT id_inspeccion FROM checklist_inspecciones 
            WHERE id_camion = ? AND id_usuario = ? AND fecha >= DATE_SUB(NOW(), INTERVAL 10 SECOND) 
            ORDER BY id_inspeccion DESC LIMIT 1");
        $stmtDup->execute([$id_camion, $userId]);
        $dupId = $stmtDup->fetchColumn();
        if ($dupId) {
            $_SESSION['flash_msg'] = '¡Checklist registrado con éxito!';
            header('Location: checklist.php?vista=historial&ver=' . $dupId);
            exit;
        }
    } catch (Exception $e) {}

    try {
        $db->beginTransaction();

        $stmtVeh = $db->prepare("SELECT tipo, horas_actuales FROM camiones WHERE id_camion = ?");
        $stmtVeh->execute([$id_camion]);
        $vehInfo = $stmtVeh->fetch();
        $tipo_maquina = $vehInfo['tipo'] ?? 'autoelevador';

        $total_preguntas = count($respuestasPost);
        $total_ok = 0;
        $total_fallas = 0;

        foreach ($respuestasPost as $idPreg => $res) {
            if (strtoupper($res) === 'SI') {
                $total_ok++;
            } else {
                $total_fallas++;
            }
        }

        $estadoGeneral = ($total_fallas === 0) ? 'aprobado' : 'con_observaciones';

        // Insertar cabecera de inspección
        $insStmt = $db->prepare("INSERT INTO checklist_inspecciones 
            (id_camion, id_usuario, id_chofer, fecha, tipo_maquina, horas_maquina, estado, total_preguntas, total_ok, total_fallas, observacion_general, firmado_por) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insStmt->execute([
            $id_camion,
            $userId,
            $idChofer,
            $fecha,
            $tipo_maquina,
            $horas_maquina,
            $estadoGeneral,
            $total_preguntas,
            $total_ok,
            $total_fallas,
            $observacion_general ?: null,
            $firmado_por ?: null
        ]);
        $idInspeccion = (int)$db->lastInsertId();

        // Obtener nombres de las preguntas
        $pregIds = array_map('intval', array_keys($respuestasPost));
        $pregMap = [];
        if (!empty($pregIds)) {
            $placeholders = implode(',', array_fill(0, count($pregIds), '?'));
            $stmtP = $db->prepare("SELECT id_pregunta, categoria, pregunta FROM checklist_preguntas WHERE id_pregunta IN ($placeholders)");
            $stmtP->execute($pregIds);
            while ($rowP = $stmtP->fetch()) {
                $pregMap[$rowP['id_pregunta']] = $rowP;
            }
        }

        // Insertar respuestas y fotos
        $respStmt = $db->prepare("INSERT INTO checklist_respuestas 
            (id_inspeccion, id_pregunta, categoria, pregunta_texto, resultado, observacion, foto_path) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        $idxFoto = 0;
        foreach ($respuestasPost as $idPreg => $res) {
            $idPreg = (int)$idPreg;
            $resultado = (strtoupper($res) === 'SI') ? 'SI' : 'NO';
            $obs = trim($observacionesPost[$idPreg] ?? '');
            $categoria = $pregMap[$idPreg]['categoria'] ?? 'General';
            $preguntaTexto = $pregMap[$idPreg]['pregunta'] ?? 'Pregunta #' . $idPreg;
            $fotoPath = null;

            // Procesar foto subida
            if (isset($_FILES['fotos']['name'][$idPreg]) && !empty($_FILES['fotos']['tmp_name'][$idPreg])) {
                $fileItem = [
                    'name' => $_FILES['fotos']['name'][$idPreg],
                    'type' => $_FILES['fotos']['type'][$idPreg],
                    'tmp_name' => $_FILES['fotos']['tmp_name'][$idPreg],
                    'error' => $_FILES['fotos']['error'][$idPreg],
                    'size' => $_FILES['fotos']['size'][$idPreg]
                ];
                $fotoPath = guardarFotoChecklist($fileItem, $idPreg, ++$idxFoto);
            }

            $respStmt->execute([
                $idInspeccion,
                $idPreg,
                $categoria,
                $preguntaTexto,
                $resultado,
                $obs ?: null,
                $fotoPath
            ]);
        }

        // Actualizar horas del equipo
        if ($horas_maquina && $horas_maquina > ($vehInfo['horas_actuales'] ?? 0)) {
            $db->prepare("UPDATE camiones SET horas_actuales = ? WHERE id_camion = ?")->execute([$horas_maquina, $id_camion]);
        }

        // Guardar Firma Digital (archivo, base64 o firma guardada del usuario)
        $firmaPath = null;
        if (isset($_FILES['firma_archivo']) && $_FILES['firma_archivo']['error'] === UPLOAD_ERR_OK) {
            $firmasDir = __DIR__ . '/../assets/uploads/checklist/firmas/';
            if (!is_dir($firmasDir)) @mkdir($firmasDir, 0777, true);
            $fn = 'firma_' . $idInspeccion . '_' . time() . '.png';
            if (move_uploaded_file($_FILES['firma_archivo']['tmp_name'], $firmasDir . $fn)) {
                $firmaPath = 'assets/uploads/checklist/firmas/' . $fn;
            }
        } elseif (!empty($_POST['firma_digital_base64'])) {
            $firmaPath = guardarFirmaDigital($_POST['firma_digital_base64'], $idInspeccion);
            // Si el usuario marcó guardar en perfil o si no tenía firma previa guardada
            if (!empty($_POST['guardar_firma_perfil']) || empty($firmaGuardadaUsuario)) {
                try {
                    $rutaUser = guardarFirmaUsuario($_POST['firma_digital_base64'], $userId);
                    if ($rutaUser) {
                        $db->prepare("UPDATE usuarios SET firma_digital = ? WHERE id_usuario = ?")->execute([$rutaUser, $userId]);
                    }
                } catch (Exception $e) {}
            }
        } else {
            // Fallback a firma guardada del usuario si existe
            if (!empty($firmaGuardadaUsuario)) {
                $firmaPath = $firmaGuardadaUsuario;
            }
        }

        if ($firmaPath) {
            $db->prepare("UPDATE checklist_inspecciones SET firma_digital = ? WHERE id_inspeccion = ?")->execute([$firmaPath, $idInspeccion]);
        }

        $db->commit();
        $_SESSION['flash_msg'] = '¡Checklist registrado con éxito! Estado: ' . ($estadoGeneral === 'aprobado' ? 'Aprobado sin novedades' : 'Registrado con ' . $total_fallas . ' observación(es)');
        header('Location: checklist.php?vista=historial&ver=' . $idInspeccion);
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['flash_err'] = 'Error al registrar checklist: ' . $e->getMessage();
        header('Location: checklist.php');
        exit;
    }
}

// Obtener vehículos asignados al chofer/usuario (ambas fuentes: asignaciones y vehiculos_usuarios)
$camionesAsignados = [];
$vistos = [];

if ($idChofer) {
    try {
        $stmtAsig = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.modelo, c.tipo, c.horas_actuales, c.kilometraje_actual 
            FROM asignaciones a 
            JOIN camiones c ON a.id_camion = c.id_camion 
            WHERE a.id_chofer = ? AND a.activa = 1 AND c.estado != 'fuera_de_servicio'");
        $stmtAsig->execute([$idChofer]);
        foreach ($stmtAsig->fetchAll() as $row) {
            $vistos[$row['id_camion']] = true;
            $camionesAsignados[] = $row;
        }
    } catch (Exception $e) {}
}

if ($userId) {
    try {
        $stmtVu = $db->prepare("SELECT c.id_camion, c.patente, c.marca, c.modelo, c.tipo, c.horas_actuales, c.kilometraje_actual 
            FROM vehiculos_usuarios vu 
            JOIN camiones c ON vu.vehiculo_id = c.id_camion 
            WHERE vu.usuario_id = ? AND c.estado != 'fuera_de_servicio'");
        $stmtVu->execute([$userId]);
        foreach ($stmtVu->fetchAll() as $row) {
            if (!isset($vistos[$row['id_camion']])) {
                $vistos[$row['id_camion']] = true;
                $camionesAsignados[] = $row;
            }
        }
    } catch (Exception $e) {}
}

// Si tiene vehículos asignados, traer ÚNICAMENTE sus asignados
$tieneAsignados = !empty($camionesAsignados);
if ($tieneAsignados) {
    $listaCamiones = $camionesAsignados;
} else {
    // Si no tiene asignado directo (ej: administrador testeando), mostrar los habilitados para checklist
    $stmtCamiones = $db->query("SELECT id_camion, patente, marca, modelo, tipo, horas_actuales, kilometraje_actual FROM camiones WHERE estado != 'fuera_de_servicio' AND (hace_checklist = 1 OR tipo = 'autoelevador') ORDER BY (tipo = 'autoelevador') DESC, patente ASC");
    $listaCamiones = $stmtCamiones->fetchAll();
    if (empty($listaCamiones)) {
        $stmtCamiones = $db->query("SELECT id_camion, patente, marca, modelo, tipo, horas_actuales, kilometraje_actual FROM camiones WHERE estado != 'fuera_de_servicio' ORDER BY (tipo = 'autoelevador') DESC, patente ASC");
        $listaCamiones = $stmtCamiones->fetchAll();
    }
}

// Obtener preguntas activas
$stmtPreguntas = $db->query("SELECT * FROM checklist_preguntas WHERE activo = 1 ORDER BY orden ASC, id_pregunta ASC");
$preguntas = $stmtPreguntas->fetchAll();

$preguntasPorCat = [];
foreach ($preguntas as $p) {
    $preguntasPorCat[$p['categoria']][] = $p;
}

$esAdminSupervisorInspector = (isAdmin() || puedeAprobarChecklist() || hasRole('Supervisor') || hasRole('Inspector') || hasRole('Administrador'));

// Consultar inspecciones según rol:
// - Chofer / Operador regular: ve ÚNICAMENTE sus checklists cargados
// - Admin / Supervisor / Inspector: ve TODOS los checklists cargados
if ($esAdminSupervisorInspector) {
    $stmtMis = $db->query("SELECT i.*, c.patente, c.marca, c.modelo,
        CONCAT(ch.nombre, ' ', ch.apellido) as chofer_nombre,
        u.username as usuario_nombre
        FROM checklist_inspecciones i 
        LEFT JOIN camiones c ON i.id_camion = c.id_camion 
        LEFT JOIN choferes ch ON i.id_chofer = ch.id_chofer
        LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario
        ORDER BY i.fecha DESC LIMIT 60");
    $misInspecciones = $stmtMis->fetchAll();
} else {
    $stmtMis = $db->prepare("SELECT i.*, c.patente, c.marca, c.modelo,
        CONCAT(ch.nombre, ' ', ch.apellido) as chofer_nombre,
        u.username as usuario_nombre
        FROM checklist_inspecciones i 
        LEFT JOIN camiones c ON i.id_camion = c.id_camion 
        LEFT JOIN choferes ch ON i.id_chofer = ch.id_chofer
        LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario
        WHERE i.id_usuario = ? OR (i.id_chofer IS NOT NULL AND i.id_chofer = ?) 
        ORDER BY i.fecha DESC LIMIT 30");
    $stmtMis->execute([$userId, $idChofer]);
    $misInspecciones = $stmtMis->fetchAll();
}

// Cargar inspección en detalle si se solicita 'ver'
$verId = (int)($_GET['ver'] ?? 0);
$verInspeccion = null;
$verRespuestas = [];
if ($verId > 0) {
    if ($esAdminSupervisorInspector) {
        $stmtVer = $db->prepare("SELECT i.*, c.patente, c.marca, c.modelo, c.tipo as tipo_vehiculo,
            CONCAT(ch.nombre, ' ', ch.apellido) as chofer_nombre,
            u.username as usuario_nombre
            FROM checklist_inspecciones i 
            LEFT JOIN camiones c ON i.id_camion = c.id_camion 
            LEFT JOIN choferes ch ON i.id_chofer = ch.id_chofer
            LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario
            WHERE i.id_inspeccion = ?");
        $stmtVer->execute([$verId]);
    } else {
        $stmtVer = $db->prepare("SELECT i.*, c.patente, c.marca, c.modelo, c.tipo as tipo_vehiculo,
            CONCAT(ch.nombre, ' ', ch.apellido) as chofer_nombre,
            u.username as usuario_nombre
            FROM checklist_inspecciones i 
            LEFT JOIN camiones c ON i.id_camion = c.id_camion 
            LEFT JOIN choferes ch ON i.id_chofer = ch.id_chofer
            LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario
            WHERE i.id_inspeccion = ? AND (i.id_usuario = ? OR i.id_chofer = ?)");
        $stmtVer->execute([$verId, $userId, $idChofer]);
    }
    $verInspeccion = $stmtVer->fetch();
    if ($verInspeccion) {
        $stmtResp = $db->prepare("SELECT * FROM checklist_respuestas WHERE id_inspeccion = ? ORDER BY id_respuesta ASC");
        $stmtResp->execute([$verId]);
        $verRespuestas = $stmtResp->fetchAll();
    }
}

$vista = $_GET['vista'] ?? ($verInspeccion ? 'historial' : 'formulario');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_chofer.php';
?>

<div class="md:ml-64 pt-20 px-3 md:px-6 pb-20 min-h-screen bg-surface">
    <!-- Header Móvil / Desktop -->
    <div class="max-w-2xl mx-auto mb-5">
        <div class="flex items-center justify-between gap-3 mb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-primary text-white flex items-center justify-center shadow-md shrink-0">
                    <span class="material-symbols-outlined text-2xl">fact_check</span>
                </div>
                <div>
                    <h1 class="text-base sm:text-lg font-black text-on-surface leading-tight">
                        CHECK LIST DIARIO PARA USO DE AUTOELEVADORES, MONTACARGAS SEGÚN RESOLUCIÓN DE LA SRT. Nº 960/15
                    </h1>
                    <p class="text-[11px] text-on-surface-variant font-medium mt-0.5">Control pre-operacional diario obligatorio</p>
                </div>
            </div>

            <div class="flex items-center gap-1.5 flex-wrap sm:flex-nowrap">
                <button type="button" onclick="abrirModalMiFirma()" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer <?= !empty($firmaGuardadaUsuario) ? 'bg-emerald-50 text-emerald-800 border border-emerald-300 hover:bg-emerald-100' : 'bg-amber-50 text-amber-800 border border-amber-300 hover:bg-amber-100' ?>" title="Gestionar mi firma digital guardada">
                    <span class="material-symbols-outlined text-sm">draw</span>
                    <span>Mi Firma</span>
                    <?php if (!empty($firmaGuardadaUsuario)): ?>
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" title="Firma guardada"></span>
                    <?php else: ?>
                    <span class="text-[10px] bg-amber-200 text-amber-900 px-1 rounded font-bold">Cargar</span>
                    <?php endif; ?>
                </button>
                <a href="?vista=formulario" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?= $vista === 'formulario' ? 'bg-primary text-white shadow-sm' : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50' ?>">
                    Inspección
                </a>
                <a href="?vista=historial" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?= $vista === 'historial' ? 'bg-primary text-white shadow-sm' : 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50' ?>">
                    Historial
                </a>
            </div>
        </div>

        <!-- Mensajes Flash -->
        <?php if ($mensaje): ?>
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center gap-3 text-sm shadow-sm mb-4">
            <span class="material-symbols-outlined text-emerald-600">check_circle</span>
            <span class="font-medium"><?= htmlspecialchars($mensaje) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-red-50 border border-red-200 text-red-800 flex items-center gap-3 text-sm shadow-sm mb-4">
            <span class="material-symbols-outlined text-red-600">error</span>
            <span class="font-medium"><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================= -->
    <!-- VISTA 1: FORMULARIO DE CHECKLIST (PASO A PASO 1 POR 1)       -->
    <!-- ============================================================= -->
    <?php if ($vista === 'formulario'): ?>
    <div class="max-w-2xl mx-auto space-y-4">
        <form method="POST" enctype="multipart/form-data" id="form-checklist-chofer" class="space-y-4">
            <input type="hidden" name="action" value="guardar_checklist_chofer">

            <!-- Barra Flotante de Progreso del Wizard -->
            <div id="barra-progreso-top" class="sticky top-16 z-30 bg-slate-900 text-white p-3.5 rounded-2xl shadow-xl flex items-center justify-between gap-2 border border-white/10">
                <div class="flex items-center gap-2.5 flex-1 min-w-0">
                    <span id="icono-progreso-wizard" class="material-symbols-outlined text-amber-400 text-lg shrink-0">fact_check</span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span id="txt-progreso-m" class="text-xs font-bold truncate">Paso 1: Seleccionar Máquina</span>
                            <span id="badge-contador-preg" class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-white/10 text-emerald-300">0 / <?= count($preguntas) ?></span>
                        </div>
                        <div class="w-full bg-white/20 h-1.5 rounded-full overflow-hidden mt-1.5">
                            <div id="barra-progreso-m" class="bg-emerald-400 h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" onclick="marcarTodosSiChofer()" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold whitespace-nowrap flex items-center gap-1 shadow-sm transition-all" title="Marcar todas las preguntas con SÍ (OK) y pasar a la firma">
                        <span class="material-symbols-outlined text-sm">done_all</span> Todo SÍ (OK)
                    </button>
                </div>
            </div>

            <!-- PASO 0: SELECCIÓN DE MÁQUINA Y HORÓMETRO -->
            <div id="paso-maquina" class="paso-wizard bg-white p-6 rounded-3xl border border-outline-variant shadow-sm space-y-4">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <span class="w-7 h-7 rounded-full bg-primary/10 text-primary font-extrabold text-xs flex items-center justify-center">1</span>
                    <div>
                        <h2 class="font-bold text-sm text-slate-800">Seleccionar Máquina / Autoelevador</h2>
                        <p class="text-xs text-slate-400">Paso inicial antes de comenzar las preguntas</p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                        Equipo a Inspeccionar <span class="text-red-500">*</span>
                    </label>
                    <select name="id_camion" id="select-equipo" required class="w-full px-3.5 py-3 text-sm bg-slate-50 border border-outline-variant rounded-2xl focus:ring-2 focus:ring-primary/20 focus:border-primary font-semibold" onchange="cambioEquipo(this)">
                        <?php if (count($listaCamiones) !== 1): ?>
                        <option value="">-- Toque para seleccionar equipo --</option>
                        <?php endif; ?>
                        <?php foreach ($listaCamiones as $idx => $c): 
                            $isSelected = (count($listaCamiones) === 1) ? 'selected' : '';
                        ?>
                        <option value="<?= $c['id_camion'] ?>" data-horas="<?= $c['horas_actuales'] ?>" data-tipo="<?= $c['tipo'] ?>" <?= $isSelected ?>>
                            <?= $c['tipo'] === 'autoelevador' ? '🚜' : '🚛' ?> <?= htmlspecialchars($c['patente']) ?> - <?= htmlspecialchars($c['marca'] . ' ' . $c['modelo']) ?><?= $tieneAsignados ? ' (Asignado)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Horómetro (hs)</label>
                        <input type="number" step="0.1" name="horas_maquina" id="input-horas-chofer" placeholder="Ej: 1250.4" class="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-outline-variant rounded-2xl focus:ring-2 focus:ring-primary/20 focus:border-primary font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Operador</label>
                        <input type="text" name="firmado_por" value="<?= htmlspecialchars(getCurrentUserName()) ?>" class="w-full px-3.5 py-2.5 text-sm bg-slate-100 border border-outline-variant rounded-2xl text-slate-700 font-medium" readonly>
                    </div>
                </div>

                <div class="pt-3">
                    <button type="button" onclick="comenzarPreguntas()" class="w-full py-4 bg-primary text-white font-extrabold text-sm rounded-2xl shadow-lg hover:bg-primary/90 flex items-center justify-center gap-2 transition-all active:scale-[0.99]">
                        <span>Comenzar Inspección (Paso a Paso)</span>
                        <span class="material-symbols-outlined text-lg">arrow_forward</span>
                    </button>
                </div>
            </div>

            <!-- PASOS 1 A N: PREGUNTAS INDIVIDUALES (1 POR 1) -->
            <?php foreach ($preguntas as $pIdx => $p): ?>
            <div id="paso-pregunta-<?= $pIdx ?>" class="paso-wizard paso-pregunta-card bg-white p-6 rounded-3xl border border-outline-variant shadow-sm space-y-5 hidden animate-fade-in">
                <!-- Encabezado de la Pregunta -->
                <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-1 rounded-xl text-[11px] font-extrabold uppercase bg-primary/10 text-primary border border-primary/20 flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs">category</span>
                            <?= htmlspecialchars($p['categoria']) ?>
                        </span>
                    </div>
                    <span class="text-xs font-extrabold text-slate-500 font-mono">Pregunta <?= $pIdx + 1 ?> de <?= count($preguntas) ?></span>
                </div>

                <!-- Texto de la Pregunta y Detalle de Inspección -->
                <div class="space-y-3">
                    <div class="bg-slate-50/80 rounded-2xl p-4 border border-slate-200/80 shadow-inner">
                        <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 block mb-1">Ítem a Inspeccionar</span>
                        <h3 class="text-lg sm:text-xl font-black text-slate-900 leading-snug tracking-tight">
                            <?= htmlspecialchars($p['pregunta']) ?>
                        </h3>
                    </div>

                    <?php if (!empty($p['descripcion_ayuda'])): ?>
                    <div class="bg-blue-50/90 border border-blue-200/90 rounded-2xl p-3.5 flex items-start gap-3 shadow-sm text-blue-950">
                        <div class="w-7 h-7 rounded-xl bg-blue-500 text-white flex items-center justify-center shrink-0 shadow-sm mt-0.5">
                            <span class="material-symbols-outlined text-base">info</span>
                        </div>
                        <div class="flex-1">
                            <span class="text-[10px] font-black uppercase tracking-wider text-blue-700 block mb-0.5">¿Qué debes verificar?</span>
                            <p class="text-xs sm:text-sm font-semibold text-blue-950 leading-relaxed">
                                <?= htmlspecialchars($p['descripcion_ayuda']) ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Inputs Ocultos de Radio -->
                <div class="hidden">
                    <input type="radio" name="respuestas[<?= $p['id_pregunta'] ?>]" id="radio-si-<?= $p['id_pregunta'] ?>" value="SI" onchange="clickRespuesta(<?= $p['id_pregunta'] ?>, 'SI')">
                    <input type="radio" name="respuestas[<?= $p['id_pregunta'] ?>]" id="radio-no-<?= $p['id_pregunta'] ?>" value="NO" onchange="clickRespuesta(<?= $p['id_pregunta'] ?>, 'NO')">
                </div>

                <!-- Botones Táctiles Gigantes SÍ / NO -->
                <div class="grid grid-cols-2 gap-3 pt-1">
                    <button type="button" id="btn-touch-si-<?= $p['id_pregunta'] ?>" onclick="responderPaso(<?= $pIdx ?>, <?= $p['id_pregunta'] ?>, 'SI')" class="btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-emerald-300 bg-emerald-50 hover:bg-emerald-500 hover:text-white text-emerald-950 font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 transition-all shadow-sm active:scale-95">
                        <span class="material-symbols-outlined text-2xl text-emerald-600 group-hover:text-white">check_circle</span>
                        <span>SÍ (OK)</span>
                    </button>

                    <button type="button" id="btn-touch-no-<?= $p['id_pregunta'] ?>" onclick="responderPaso(<?= $pIdx ?>, <?= $p['id_pregunta'] ?>, 'NO')" class="btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-amber-300 bg-amber-50 hover:bg-amber-500 hover:text-white text-amber-950 font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 transition-all shadow-sm active:scale-95">
                        <span class="material-symbols-outlined text-2xl text-amber-600 group-hover:text-white">warning</span>
                        <span>NO (FALLA)</span>
                    </button>
                </div>

                <!-- Panel Desplegable para NO (Motivo y Foto) -->
                <div id="panel-falla-<?= $p['id_pregunta'] ?>" class="hidden p-4 bg-amber-50 border-2 border-amber-300 rounded-2xl space-y-3.5 animate-scale-in">
                    <div>
                        <label class="block text-xs font-black uppercase text-amber-950 mb-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm text-amber-700">edit_note</span>
                            Escriba el motivo / falla <span class="text-red-600">*</span>
                        </label>
                        <textarea name="observaciones[<?= $p['id_pregunta'] ?>]" id="txt-obs-<?= $p['id_pregunta'] ?>" rows="2" placeholder="Describa la observación o falla..." class="w-full p-3 text-xs bg-white border border-amber-400 rounded-xl focus:ring-2 focus:ring-amber-500 font-medium"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-black uppercase text-amber-950 mb-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm text-amber-700">photo_camera</span>
                            Foto de Evidencia (Opcional)
                        </label>
                        <div class="flex items-center gap-2">
                            <label class="flex-1 py-3 px-3 bg-white border-2 border-dashed border-amber-400 rounded-xl flex items-center justify-center gap-2 text-xs font-bold text-amber-900 cursor-pointer hover:bg-amber-100/50 transition-all">
                                <span class="material-symbols-outlined text-lg">photo_camera</span>
                                <span>Tomar o Subir Foto</span>
                                <input type="file" name="fotos[<?= $p['id_pregunta'] ?>]" accept="image/*" capture="environment" class="hidden" onchange="previewFotoChofer(this, <?= $p['id_pregunta'] ?>)">
                            </label>

                            <div id="preview-chofer-box-<?= $p['id_pregunta'] ?>" class="hidden relative shrink-0">
                                <img id="preview-chofer-img-<?= $p['id_pregunta'] ?>" class="w-14 h-14 object-cover rounded-xl border border-amber-500 shadow" src="" alt="Foto">
                                <button type="button" onclick="quitarFotoChofer(<?= $p['id_pregunta'] ?>)" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-600 text-white rounded-full text-xs font-bold flex items-center justify-center shadow">✕</button>
                            </div>
                        </div>
                    </div>

                    <!-- Botón para avanzar tras ingresar motivo -->
                    <button type="button" onclick="guardarMotivoYAvanzar(<?= $pIdx ?>, <?= $p['id_pregunta'] ?>)" class="w-full py-3.5 bg-amber-600 hover:bg-amber-700 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl shadow-md flex items-center justify-center gap-1.5 transition-all">
                        <span>Guardar motivo y pasar a la siguiente</span>
                        <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </button>
                </div>

                <!-- Navegación Inferior de la Tarjeta -->
                <div class="flex items-center justify-between pt-3 border-t border-slate-100">
                    <button type="button" onclick="irPaso(<?= $pIdx - 1 ?>)" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 font-bold text-xs hover:bg-slate-100 flex items-center gap-1 transition-all">
                        <span class="material-symbols-outlined text-sm">arrow_back</span> Anterior
                    </button>

                    <button type="button" onclick="irPaso(<?= $pIdx + 1 ?>)" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-800 hover:bg-slate-200 font-bold text-xs flex items-center gap-1 transition-all">
                        <span>Siguiente</span>
                        <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- PASO FINAL: RESUMEN, OBSERVACIONES GENERALES Y FIRMA DIGITAL -->
            <div id="paso-final" class="paso-wizard bg-white p-6 rounded-3xl border border-outline-variant shadow-sm space-y-5 hidden animate-fade-in">
                <div class="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                    <span class="w-7 h-7 rounded-full bg-emerald-500 text-white font-extrabold text-xs flex items-center justify-center">✓</span>
                    <div>
                        <h2 class="font-bold text-sm text-slate-800">Paso Final: Firma y Cierre del Checklist</h2>
                        <p class="text-xs text-slate-400">Revise sus respuestas y firme con el dedo</p>
                    </div>
                </div>

                <!-- Matriz de Revisión Rápida de Preguntas -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Resumen de Ítems Respondidos (Toque para editar)</label>
                    <div class="grid grid-cols-6 sm:grid-cols-9 gap-1.5 max-h-36 overflow-y-auto p-1 border border-slate-100 rounded-2xl bg-slate-50" id="grid-resumen-pills">
                        <?php foreach ($preguntas as $pIdx => $p): ?>
                        <button type="button" onclick="irPaso(<?= $pIdx ?>)" id="pill-resumen-<?= $p['id_pregunta'] ?>" class="py-1.5 px-1 rounded-xl text-xs font-extrabold border text-center transition-all bg-white text-slate-400 border-slate-200">
                            #<?= $pIdx + 1 ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Observaciones Generales del Equipo (Opcional)</label>
                    <textarea name="observacion_general" rows="2" placeholder="Cualquier aclaración o detalle adicional sobre el autoelevador..." class="w-full p-3 text-xs bg-slate-50 border border-outline-variant rounded-2xl font-medium"></textarea>
                </div>

                <!-- Firma Digital en Celular -->
                <div class="border-t border-slate-100 pt-3">
                    <?php if (!empty($firmaGuardadaUsuario)): ?>
                    <!-- Caso 1: El Operador TIENE una firma guardada en su perfil -->
                    <div class="space-y-3">
                        <div class="bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200/80 rounded-2xl p-4 flex items-center justify-between gap-3 shadow-sm">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-2xl bg-emerald-500 text-white flex items-center justify-center shadow-sm shrink-0">
                                    <span class="material-symbols-outlined text-xl">verified</span>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-900">Firma Precargada Activa</p>
                                    <p class="text-[11px] text-emerald-800 font-medium">Se adjuntará automáticamente tu firma guardada sin necesidad de dibujarla.</p>
                                </div>
                            </div>
                            <img src="<?= BASE_URL ?>/<?= htmlspecialchars($firmaGuardadaUsuario) ?>" alt="Firma Guardada" class="h-11 w-auto max-w-[110px] object-contain bg-white rounded-xl p-1 border border-emerald-200 shadow-sm shrink-0">
                        </div>

                        <!-- Opción para re-dibujar si desea firmar a mano en esta inspección -->
                        <div class="pt-1 flex items-center justify-between">
                            <button type="button" onclick="toggleRedibujarFirma()" class="text-xs text-slate-500 hover:text-primary font-bold flex items-center gap-1 cursor-pointer transition-colors">
                                <span class="material-symbols-outlined text-sm">edit</span>
                                <span id="txt-toggle-firma">¿Deseas firmar a mano para esta inspección?</span>
                            </button>
                        </div>

                        <div id="contenedor-dibujo-firma" class="hidden space-y-2 mt-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600">
                                    Dibujar nueva firma en pantalla:
                                </label>
                                <button type="button" onclick="limpiarFirmaChofer()" class="text-xs text-red-600 hover:text-red-700 font-bold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">cleaning_services</span> Limpiar
                                </button>
                            </div>
                            <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-slate-50 p-2 relative">
                                <canvas id="canvas-firma-chofer" width="500" height="140" class="w-full h-32 bg-white rounded-xl touch-none cursor-crosshair border border-slate-200 shadow-inner"></canvas>
                                <input type="file" name="firma_archivo" id="input-firma-chofer-file" class="hidden" accept="image/png">
                                <input type="hidden" name="firma_digital_base64" id="input-firma-chofer-base64" value="">
                                <div id="hint-firma-chofer" class="absolute inset-0 flex items-center justify-center pointer-events-none text-slate-300 text-sm font-semibold select-none">
                                    ✍️ Dibuje aquí con el dedo
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-slate-700 font-semibold cursor-pointer pt-1">
                                <input type="checkbox" name="guardar_firma_perfil" value="1" class="rounded text-primary focus:ring-primary w-4 h-4">
                                <span>Actualizar también como mi firma predeterminada</span>
                            </label>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Caso 2: El Operador NO TIENE firma guardada aún -->
                    <div class="space-y-2">
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Firma Digital del Operador <span class="text-slate-400 font-normal">(Dibuje con el dedo)</span>
                            </label>
                            <button type="button" onclick="limpiarFirmaChofer()" class="text-xs text-red-600 hover:text-red-700 font-bold flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">cleaning_services</span> Limpiar
                            </button>
                        </div>
                        <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-slate-50 p-2 relative">
                            <canvas id="canvas-firma-chofer" width="500" height="140" class="w-full h-32 bg-white rounded-xl touch-none cursor-crosshair border border-slate-200 shadow-inner"></canvas>
                            <input type="file" name="firma_archivo" id="input-firma-chofer-file" class="hidden" accept="image/png">
                            <input type="hidden" name="firma_digital_base64" id="input-firma-chofer-base64" value="">
                            <div id="hint-firma-chofer" class="absolute inset-0 flex items-center justify-center pointer-events-none text-slate-300 text-sm font-semibold select-none">
                                ✍️ Firme aquí con el dedo
                            </div>
                        </div>
                        <div class="pt-1 bg-amber-50/80 border border-amber-200/80 rounded-xl p-2.5">
                            <label class="flex items-center gap-2 text-xs text-amber-950 font-bold cursor-pointer">
                                <input type="checkbox" name="guardar_firma_perfil" value="1" checked class="rounded text-primary focus:ring-primary w-4 h-4">
                                <span>Guardar esta firma en mi perfil (no tendrás que firmar en futuros checklists)</span>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="flex flex-col gap-2 pt-2">
                    <button type="submit" class="w-full py-4 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-sm rounded-2xl shadow-lg flex items-center justify-center gap-2 transition-all active:scale-[0.99]">
                        <span class="material-symbols-outlined text-lg">check_circle</span>
                        <span>Confirmar y Guardar Checklist</span>
                    </button>

                    <button type="button" onclick="irPaso(<?= count($preguntas) - 1 ?>)" class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl flex items-center justify-center gap-1 transition-all">
                        <span class="material-symbols-outlined text-sm">arrow_back</span> Volver a la última pregunta
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- VISTA 2: HISTORIAL DEL OPERADOR (SOLO LECTURA)               -->
    <!-- ============================================================= -->
    <?php if ($vista === 'historial'): ?>
    <div class="max-w-2xl mx-auto space-y-3">
        <div class="flex items-center justify-between mb-2">
            <h2 class="font-bold text-sm text-slate-800 uppercase tracking-wider">
                <?= $esAdminSupervisorInspector ? 'Historial de Checklists Cargados (Todos)' : 'Mis Últimos Checklists' ?>
            </h2>
            <span class="text-xs text-slate-400 font-medium">
                <?= $esAdminSupervisorInspector ? 'Vista de Control' : 'Solo lectura' ?>
            </span>
        </div>

        <?php if (empty($misInspecciones)): ?>
        <div class="bg-white p-8 rounded-3xl border border-outline-variant text-center text-slate-400">
            <span class="material-symbols-outlined text-4xl mb-1 text-slate-300">history_toggle_off</span>
            <p class="text-sm font-semibold text-slate-600">No se encontraron checklists registrados</p>
        </div>
        <?php else: ?>
        <?php foreach ($misInspecciones as $m): ?>
        <div class="bg-white p-4 rounded-3xl border border-outline-variant shadow-sm space-y-3 hover:border-primary/40 transition-colors">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-bold text-sm text-slate-900"><?= htmlspecialchars($m['patente'] ?: ('Equipo #' . $m['id_camion'])) ?></h3>
                    <p class="text-xs text-slate-500"><?= htmlspecialchars(trim(($m['marca'] ?? '') . ' ' . ($m['modelo'] ?? '')) ?: ($m['tipo_maquina'] ?: 'Autoelevador')) ?></p>
                    <?php if ($esAdminSupervisorInspector): ?>
                    <p class="text-[11px] font-bold text-primary mt-1 flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">person</span>
                        <span>Operador: <?= htmlspecialchars($m['firmado_por'] ?: ($m['chofer_nombre'] ?: $m['usuario_nombre'])) ?></span>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <?php if ($m['estado'] === 'aprobado'): ?>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">✓ Sin Novedades</span>
                    <?php else: ?>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">⚠ <?= $m['total_fallas'] ?> Observación(es)</span>
                    <?php endif; ?>

                    <?php if (!empty($m['firma_inspector']) || ($m['estado_aprobacion'] ?? '') === 'aprobado'): ?>
                    <span class="text-[10px] font-bold text-emerald-700 flex items-center gap-0.5">
                        <span class="material-symbols-outlined text-xs">verified</span> Aprobado por Inspector
                    </span>
                    <?php elseif (($m['estado_aprobacion'] ?? '') === 'rechazado'): ?>
                    <span class="text-[10px] font-bold text-red-700 flex items-center gap-0.5">
                        <span class="material-symbols-outlined text-xs">cancel</span> Rechazado
                    </span>
                    <?php else: ?>
                    <span class="text-[10px] font-semibold text-amber-600 flex items-center gap-0.5">
                        <span class="material-symbols-outlined text-xs">hourglass_empty</span> Pendiente de Firma
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex items-center justify-between text-xs text-slate-500 border-t border-slate-100 pt-2.5">
                <div>
                    <span>📅 <?= date('d/m/Y H:i', strtotime($m['fecha'])) ?> hs</span>
                    <span class="ml-2">⏱ <?= $m['horas_maquina'] ? number_format($m['horas_maquina'], 1) . ' hs' : 'N/D' ?></span>
                </div>
                <a href="?vista=historial&ver=<?= $m['id_inspeccion'] ?>" class="px-3 py-1.5 bg-primary/10 text-primary hover:bg-primary hover:text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">visibility</span> <?= $esAdminSupervisorInspector ? 'Ver Detalle' : 'Ver lo que cargué' ?>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- MODAL DETALLE DE INSPECCIÓN DEL OPERADOR (SOLO LECTURA, SIN IMPRESIÓN NI EDICIÓN) -->
    <?php if ($verInspeccion): ?>
    <div id="modal-detalle-chofer" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl max-w-xl w-full max-h-[92vh] flex flex-col shadow-2xl overflow-hidden animate-scale-in">
            <!-- Header Modal -->
            <div class="px-5 py-4 bg-slate-900 text-white flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="p-2 bg-white/10 rounded-xl">
                        <span class="material-symbols-outlined text-xl text-emerald-400">task_alt</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-base">Checklist Cargado #<?= $verInspeccion['id_inspeccion'] ?></h3>
                        <p class="text-xs text-slate-300"><?= htmlspecialchars($verInspeccion['patente'] ?: ('Equipo #' . $verInspeccion['id_camion'])) ?> - <?= htmlspecialchars(trim(($verInspeccion['marca'] ?? '') . ' ' . ($verInspeccion['modelo'] ?? '')) ?: ($verInspeccion['tipo_maquina'] ?: 'Autoelevador')) ?></p>
                    </div>
                </div>
                <a href="?vista=historial" class="p-1.5 text-white/70 hover:text-white rounded-lg hover:bg-white/10 transition-all">
                    <span class="material-symbols-outlined">close</span>
                </a>
            </div>

            <!-- Body Modal (Read-Only) -->
            <div class="p-5 overflow-y-auto space-y-4 flex-1 bg-surface">
                <!-- Resumen de Datos -->
                <div class="grid grid-cols-2 gap-2 bg-white p-3.5 rounded-2xl border border-outline-variant shadow-sm text-xs">
                    <div>
                        <span class="text-slate-400 font-semibold uppercase text-[10px] block">Fecha y Hora</span>
                        <span class="font-bold text-slate-800"><?= date('d/m/Y H:i', strtotime($verInspeccion['fecha'])) ?> hs</span>
                    </div>
                    <div>
                        <span class="text-slate-400 font-semibold uppercase text-[10px] block">Horómetro</span>
                        <span class="font-bold text-primary"><?= $verInspeccion['horas_maquina'] ? number_format($verInspeccion['horas_maquina'], 1) . ' hs' : 'N/D' ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 font-semibold uppercase text-[10px] block">Operador / Conductor</span>
                        <span class="font-bold text-slate-800"><?= htmlspecialchars($verInspeccion['firmado_por'] ?: getCurrentUserName()) ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 font-semibold uppercase text-[10px] block">Estado del Equipo</span>
                        <?php if ($verInspeccion['estado'] === 'aprobado'): ?>
                        <span class="font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md inline-block">Aprobado</span>
                        <?php else: ?>
                        <span class="font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md inline-block"><?= $verInspeccion['total_fallas'] ?> Observación(es)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Estado de Aprobación por Inspector -->
                <?php if (!empty($verInspeccion['firma_inspector']) || ($verInspeccion['estado_aprobacion'] ?? '') === 'aprobado'): ?>
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-950 space-y-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-emerald-600">verified</span>
                        <span class="font-bold text-xs uppercase tracking-wider text-emerald-900">Revisado y Aprobado por Inspector</span>
                    </div>
                    <p class="text-xs font-semibold text-emerald-800">Inspector: <?= htmlspecialchars($verInspeccion['inspector_nombre'] ?: 'Inspector Responsable') ?></p>
                    <?php if (!empty($verInspeccion['observacion_inspector'])): ?>
                    <p class="text-xs text-slate-700 bg-white/80 p-2.5 rounded-xl border border-emerald-200">
                        <span class="font-bold text-emerald-900 block mb-0.5">Observación del Inspector:</span>
                        <?= nl2br(htmlspecialchars($verInspeccion['observacion_inspector'])) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 flex items-center gap-2.5">
                    <span class="material-symbols-outlined text-amber-600 text-xl">hourglass_top</span>
                    <div>
                        <p class="font-bold text-xs">Pendiente de revisión por Inspector</p>
                        <p class="text-[11px] text-amber-700">El inspector revisará y firmará la conformidad.</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Observaciones Generales -->
                <?php if (!empty($verInspeccion['observacion_general'])): ?>
                <div class="p-3.5 rounded-2xl bg-white border border-outline-variant">
                    <p class="text-[11px] font-bold uppercase text-slate-500 mb-1">Observaciones Generales:</p>
                    <p class="text-xs text-slate-800"><?= nl2br(htmlspecialchars($verInspeccion['observacion_general'])) ?></p>
                </div>
                <?php endif; ?>

                <!-- Respuestas del Checklist (Solo Lectura) -->
                <div class="bg-white rounded-2xl border border-outline-variant overflow-hidden">
                    <div class="px-4 py-2.5 bg-slate-50 border-b border-outline-variant font-bold text-xs uppercase text-slate-700">
                        Respuestas Registradas (<?= count($verRespuestas) ?> items)
                    </div>
                    <div class="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                        <?php foreach ($verRespuestas as $r): ?>
                        <div class="p-3 text-xs <?= $r['resultado'] === 'NO' ? 'bg-amber-50/50' : '' ?>">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1">
                                    <span class="text-[10px] uppercase font-bold text-slate-400 block"><?= htmlspecialchars($r['categoria']) ?></span>
                                    <p class="font-semibold text-slate-900 leading-snug"><?= htmlspecialchars($r['pregunta_texto']) ?></p>
                                    <?php if (!empty($r['observacion'])): ?>
                                    <p class="mt-1 p-2 bg-amber-100/70 text-amber-900 rounded-lg text-xs font-medium border border-amber-200">
                                        ⚠️ <strong>Detalle:</strong> <?= htmlspecialchars($r['observacion']) ?>
                                    </p>
                                    <?php endif; ?>
                                    <?php if (!empty($r['foto_path'])): ?>
                                    <div class="mt-2">
                                        <a href="../<?= htmlspecialchars($r['foto_path']) ?>" target="_blank" class="inline-block">
                                            <img src="../<?= htmlspecialchars($r['foto_path']) ?>" alt="Foto" class="h-16 w-auto rounded-lg border border-slate-200 shadow-sm">
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <span class="px-2 py-0.5 rounded-full font-bold text-[10px] shrink-0 <?= $r['resultado'] === 'SI' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-200 text-amber-900' ?>">
                                    <?= $r['resultado'] === 'SI' ? '✓ OK' : '⚠ NO (FALLA)' ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Firma del Operador -->
                <?php if (!empty($verInspeccion['firma_digital'])): ?>
                <div class="bg-white p-3 rounded-2xl border border-outline-variant flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-bold uppercase text-slate-400 block">Firma Registrada</span>
                        <span class="text-xs font-semibold text-slate-800"><?= htmlspecialchars($verInspeccion['firmado_por'] ?: getCurrentUserName()) ?></span>
                    </div>
                    <img src="../<?= htmlspecialchars($verInspeccion['firma_digital']) ?>" alt="Firma Operador" class="h-10 w-auto max-w-[120px] object-contain bg-slate-50 rounded p-1 border border-slate-200">
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer Modal (Solo botón cerrar, sin imprimir ni editar) -->
            <div class="px-5 py-3.5 bg-white border-t border-outline-variant flex items-center justify-end">
                <a href="?vista=historial" class="w-full sm:w-auto px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold text-center transition-all">
                    Entendido / Cerrar
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- MODAL: GESTIONAR MI FIRMA DIGITAL (OPERADOR)                 -->
    <!-- ============================================================= -->
    <div id="modalGestionarMiFirma" class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md hidden items-center justify-center p-4 transition-all duration-300">
        <div class="bg-white rounded-[2rem] max-w-lg w-full p-6 sm:p-7 shadow-2xl border border-slate-100 animate-scale-in relative overflow-hidden">
            <!-- Botón cerrar X -->
            <button type="button" onclick="cerrarModalMiFirma()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-2 rounded-xl hover:bg-slate-100 transition-all cursor-pointer">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>

            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center shadow-md shadow-emerald-500/20 shrink-0">
                    <span class="material-symbols-outlined text-2xl">draw</span>
                </div>
                <div>
                    <h3 class="text-lg font-black text-slate-900 leading-tight">Mi Firma Digital</h3>
                    <p class="text-xs text-slate-500">Guarda tu firma una sola vez para todos tus checklists</p>
                </div>
            </div>

            <!-- Firma Actual si existe -->
            <?php if (!empty($firmaGuardadaUsuario)): ?>
            <div class="bg-emerald-50/80 border border-emerald-200 rounded-2xl p-4 mb-4 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-900 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-emerald-600">verified</span> Firma Actual Guardada
                    </span>
                    <form method="POST" class="inline m-0 p-0" onsubmit="return confirm('¿Está seguro de eliminar su firma guardada? Tendrá que firmar manualmente hasta que cargue una nueva.');">
                        <input type="hidden" name="action" value="eliminar_mi_firma_perfil">
                        <button type="submit" class="text-[11px] font-bold text-red-600 hover:text-red-800 hover:underline flex items-center gap-0.5">
                            <span class="material-symbols-outlined text-xs">delete</span> Eliminar
                        </button>
                    </form>
                </div>
                <div class="bg-white rounded-xl p-2.5 border border-emerald-200/80 flex items-center justify-center min-h-[70px]">
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($firmaGuardadaUsuario) ?>" alt="Mi Firma" class="h-14 w-auto max-w-[220px] object-contain">
                </div>
                <p class="text-[10px] text-emerald-700">✓ Esta firma se aplica automáticamente en cada checklist que completas.</p>
            </div>
            <?php endif; ?>

            <!-- Formulario para Dibujar y Guardar Nueva Firma -->
            <form method="POST" id="form-guardar-firma-perfil" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="guardar_mi_firma_perfil">
                <input type="hidden" name="mi_firma_base64" id="input-mi-firma-perfil-base64" value="">

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                            <?= !empty($firmaGuardadaUsuario) ? 'Dibujar Nueva Firma (Reemplazar):' : 'Dibuje su firma con el dedo:' ?>
                        </label>
                        <button type="button" onclick="limpiarCanvasMiFirmaPerfil()" class="text-xs text-red-600 hover:text-red-700 font-bold flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">cleaning_services</span> Limpiar
                        </button>
                    </div>
                    <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-slate-50 p-2 relative">
                        <canvas id="canvas-mi-firma-perfil" width="500" height="150" class="w-full h-36 bg-white rounded-xl touch-none cursor-crosshair border border-slate-200 shadow-inner"></canvas>
                        <div id="hint-mi-firma-perfil" class="absolute inset-0 flex items-center justify-center pointer-events-none text-slate-300 text-sm font-semibold select-none">
                            ✍️ Firme aquí con el dedo
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="button" onclick="cerrarModalMiFirma()" class="flex-1 py-3 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition-all cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" class="flex-1 py-3 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl transition-all shadow-md shadow-emerald-600/20 flex items-center justify-center gap-1.5 cursor-pointer active:scale-95">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span>Guardar en mi Perfil</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let totalPreg = <?= count($preguntas) ?>;
let respuestasChofer = {};
let pasoActual = -1; // -1 = máquina, 0..totalPreg-1 = preguntas, totalPreg = paso final

function cambioEquipo(select) {
    const opt = select.options[select.selectedIndex];
    const horas = opt ? opt.getAttribute('data-horas') : 0;
    if (horas && horas > 0) {
        document.getElementById('input-horas-chofer').value = parseFloat(horas);
    }
}

function comenzarPreguntas() {
    const sel = document.getElementById('select-equipo');
    if (!sel || !sel.value) {
        alert('Por favor seleccione la máquina o autoelevador a inspeccionar antes de comenzar.');
        if (sel) sel.focus();
        return;
    }
    irPaso(0);
}

function irPaso(pIdx) {
    const todosPasos = document.querySelectorAll('.paso-wizard');
    todosPasos.forEach(el => el.classList.add('hidden'));

    pasoActual = pIdx;

    if (pIdx < 0) {
        // Volver a selección de máquina
        const pMaq = document.getElementById('paso-maquina');
        if (pMaq) pMaq.classList.remove('hidden');
        document.getElementById('txt-progreso-m').textContent = 'Paso 1: Seleccionar Máquina';
        document.getElementById('icono-progreso-wizard').textContent = 'forklift';
    } else if (pIdx >= totalPreg) {
        // Paso final de firma
        const pFin = document.getElementById('paso-final');
        if (pFin) pFin.classList.remove('hidden');
        document.getElementById('txt-progreso-m').textContent = 'Paso Final: Revisión y Firma';
        document.getElementById('icono-progreso-wizard').textContent = 'draw';
    } else {
        // Pregunta individual pIdx
        const pCard = document.getElementById('paso-pregunta-' + pIdx);
        if (pCard) pCard.classList.remove('hidden');
        document.getElementById('txt-progreso-m').textContent = 'Pregunta ' + (pIdx + 1) + ' de ' + totalPreg;
        document.getElementById('icono-progreso-wizard').textContent = 'quiz';
    }

    actualizarProgresoChofer();

    // Scroll suave hacia la barra superior
    const barra = document.getElementById('barra-progreso-top');
    if (barra) {
        barra.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function responderPaso(pIdx, idPregunta, val) {
    const rSi = document.getElementById('radio-si-' + idPregunta);
    const rNo = document.getElementById('radio-no-' + idPregunta);
    const btnSi = document.getElementById('btn-touch-si-' + idPregunta);
    const btnNo = document.getElementById('btn-touch-no-' + idPregunta);
    const panelFalla = document.getElementById('panel-falla-' + idPregunta);
    const txtObs = document.getElementById('txt-obs-' + idPregunta);

    if (val === 'SI') {
        if (rSi) rSi.checked = true;
        if (btnSi) {
            btnSi.className = 'btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-emerald-500 bg-emerald-600 text-white font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 shadow-md scale-[1.02] transition-all';
        }
        if (btnNo) {
            btnNo.className = 'btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-slate-200 bg-slate-50 text-slate-400 font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 transition-all opacity-60';
        }
        if (panelFalla) panelFalla.classList.add('hidden');
        if (txtObs) txtObs.required = false;

        clickRespuesta(idPregunta, 'SI');

        // Avanzar automáticamente a la siguiente tras un breve feedback visual
        setTimeout(() => {
            irPaso(pIdx + 1);
        }, 180);

    } else {
        if (rNo) rNo.checked = true;
        if (btnNo) {
            btnNo.className = 'btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-amber-500 bg-amber-500 text-white font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 shadow-md scale-[1.02] transition-all';
        }
        if (btnSi) {
            btnSi.className = 'btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-slate-200 bg-slate-50 text-slate-400 font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 transition-all opacity-60';
        }
        if (panelFalla) panelFalla.classList.remove('hidden');
        if (txtObs) {
            txtObs.required = true;
            setTimeout(() => { txtObs.focus(); }, 100);
        }

        clickRespuesta(idPregunta, 'NO');
    }
}

function guardarMotivoYAvanzar(pIdx, idPregunta) {
    const txtObs = document.getElementById('txt-obs-' + idPregunta);
    if (txtObs && !txtObs.value.trim()) {
        alert('Por favor complete el motivo o detalle de la falla antes de continuar.');
        txtObs.focus();
        return;
    }
    irPaso(pIdx + 1);
}

function clickRespuesta(id, val) {
    respuestasChofer[id] = val;

    // Actualizar la pastilla en la matriz de resumen final
    const pill = document.getElementById('pill-resumen-' + id);
    if (pill) {
        if (val === 'SI') {
            pill.className = 'py-1.5 px-1 rounded-xl text-xs font-black border text-center transition-all bg-emerald-500 text-white border-emerald-600 shadow-sm';
        } else {
            pill.className = 'py-1.5 px-1 rounded-xl text-xs font-black border text-center transition-all bg-amber-500 text-white border-amber-600 shadow-sm';
        }
    }

    actualizarProgresoChofer();
}

function actualizarProgresoChofer() {
    let cant = Object.keys(respuestasChofer).length;
    let pct = totalPreg > 0 ? Math.round((cant / totalPreg) * 100) : 0;
    const barra = document.getElementById('barra-progreso-m');
    const badge = document.getElementById('badge-contador-preg');
    if (barra) barra.style.width = pct + '%';
    if (badge) badge.textContent = cant + ' / ' + totalPreg;
}

function marcarTodosSiChofer() {
    const sel = document.getElementById('select-equipo');
    if (!sel || !sel.value) {
        alert('Por favor seleccione primero el equipo o máquina a inspeccionar.');
        if (sel) sel.focus();
        return;
    }

    document.querySelectorAll('#form-checklist-chofer input[type="radio"][value="SI"]').forEach(r => {
        r.checked = true;
        const idMatch = r.name.match(/\[(\d+)\]/);
        if (idMatch) {
            const id = idMatch[1];
            clickRespuesta(id, 'SI');
            const btnSi = document.getElementById('btn-touch-si-' + id);
            const btnNo = document.getElementById('btn-touch-no-' + id);
            if (btnSi) btnSi.className = 'btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-emerald-500 bg-emerald-600 text-white font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 shadow-md';
            if (btnNo) btnNo.className = 'btn-touch-respuesta py-4 px-3 rounded-2xl border-2 border-slate-200 bg-slate-50 text-slate-400 font-black text-sm sm:text-base flex flex-col items-center justify-center gap-1.5 opacity-60';
            const panel = document.getElementById('panel-falla-' + id);
            if (panel) panel.classList.add('hidden');
        }
    });

    // Ir directo al paso de firma final
    irPaso(totalPreg);
}

function previewFotoChofer(input, id) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('preview-chofer-img-' + id).src = e.target.result;
            document.getElementById('preview-chofer-box-' + id).classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function quitarFotoChofer(id) {
    const input = document.querySelector(`input[name="fotos[${id}]"]`);
    if (input) input.value = '';
    document.getElementById('preview-chofer-box-' + id).classList.add('hidden');
}

// -------------------------------------------------------------
// PAD DE FIRMA DIGITAL MÓVIL
// -------------------------------------------------------------
let canvasChofer = document.getElementById('canvas-firma-chofer');
let ctxChofer = canvasChofer ? canvasChofer.getContext('2d') : null;
let dibujandoChofer = false;
let firmaDibujadaChofer = false;

if (canvasChofer && ctxChofer) {
    ctxChofer.strokeStyle = '#0f172a';
    ctxChofer.lineWidth = 2.5;
    ctxChofer.lineCap = 'round';
    ctxChofer.lineJoin = 'round';

    function getPosChofer(e) {
        let rect = canvasChofer.getBoundingClientRect();
        let clientX = e.clientX;
        let clientY = e.clientY;
        if (e.touches && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        }
        let scaleX = canvasChofer.width / rect.width;
        let scaleY = canvasChofer.height / rect.height;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function startDraw(e) {
        dibujandoChofer = true;
        firmaDibujadaChofer = true;
        let hint = document.getElementById('hint-firma-chofer');
        if (hint) hint.classList.add('hidden');
        let pos = getPosChofer(e);
        ctxChofer.beginPath();
        ctxChofer.moveTo(pos.x, pos.y);
        e.preventDefault();
    }

    function moveDraw(e) {
        if (!dibujandoChofer) return;
        let pos = getPosChofer(e);
        ctxChofer.lineTo(pos.x, pos.y);
        ctxChofer.stroke();
        e.preventDefault();
    }

    function endDraw() {
        if (dibujandoChofer) {
            dibujandoChofer = false;
            guardarFirmaChoferInput();
        }
    }

    canvasChofer.addEventListener('mousedown', startDraw);
    canvasChofer.addEventListener('mousemove', moveDraw);
    canvasChofer.addEventListener('mouseup', endDraw);
    canvasChofer.addEventListener('mouseleave', endDraw);

    canvasChofer.addEventListener('touchstart', startDraw, { passive: false });
    canvasChofer.addEventListener('touchmove', moveDraw, { passive: false });
    canvasChofer.addEventListener('touchend', endDraw);
}

function guardarFirmaChoferInput(callback) {
    if (canvasChofer && firmaDibujadaChofer) {
        if (canvasChofer.toBlob) {
            canvasChofer.toBlob(function(blob) {
                if (blob) {
                    try {
                        let file = new File([blob], "firma_" + Date.now() + ".png", { type: "image/png" });
                        let container = new DataTransfer();
                        container.items.add(file);
                        let fileInput = document.getElementById('input-firma-chofer-file');
                        if (fileInput) fileInput.files = container.files;
                        document.getElementById('input-firma-chofer-base64').value = '';
                    } catch(e) {
                        let raw = canvasChofer.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
                        document.getElementById('input-firma-chofer-base64').value = raw;
                    }
                }
                if (callback) callback();
            }, 'image/png');
            return;
        } else {
            let raw = canvasChofer.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
            document.getElementById('input-firma-chofer-base64').value = raw;
        }
    }
    if (callback) callback();
}

function limpiarFirmaChofer() {
    if (canvasChofer && ctxChofer) {
        ctxChofer.clearRect(0, 0, canvasChofer.width, canvasChofer.height);
        firmaDibujadaChofer = false;
        let fileInput = document.getElementById('input-firma-chofer-file');
        if (fileInput) fileInput.value = '';
        document.getElementById('input-firma-chofer-base64').value = '';
        let hint = document.getElementById('hint-firma-chofer');
        if (hint) hint.classList.remove('hidden');
    }
}

function toggleRedibujarFirma() {
    const contenedor = document.getElementById('contenedor-dibujo-firma');
    const txt = document.getElementById('txt-toggle-firma');
    if (contenedor) {
        if (contenedor.classList.contains('hidden')) {
            contenedor.classList.remove('hidden');
            if (txt) txt.textContent = '✕ Cancelar firma manual (usar precargada)';
        } else {
            contenedor.classList.add('hidden');
            limpiarFirmaChofer();
            if (txt) txt.textContent = '✍️ ¿Deseas firmar a mano para esta inspección?';
        }
    }
}

// -------------------------------------------------------------
// PAD DE FIRMA DIGITAL EN MODAL PERFIL (GESTIONAR MI FIRMA)
// -------------------------------------------------------------
let canvasMiPerfil = document.getElementById('canvas-mi-firma-perfil');
let ctxMiPerfil = canvasMiPerfil ? canvasMiPerfil.getContext('2d') : null;
let dibujandoMiPerfil = false;
let firmaDibujadaMiPerfil = false;

if (canvasMiPerfil && ctxMiPerfil) {
    ctxMiPerfil.strokeStyle = '#0f172a';
    ctxMiPerfil.lineWidth = 2.5;
    ctxMiPerfil.lineCap = 'round';
    ctxMiPerfil.lineJoin = 'round';

    function getPosMiPerfil(e) {
        let rect = canvasMiPerfil.getBoundingClientRect();
        let clientX = e.clientX;
        let clientY = e.clientY;
        if (e.touches && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        }
        let scaleX = canvasMiPerfil.width / rect.width;
        let scaleY = canvasMiPerfil.height / rect.height;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function startDrawMiPerfil(e) {
        dibujandoMiPerfil = true;
        firmaDibujadaMiPerfil = true;
        let hint = document.getElementById('hint-mi-firma-perfil');
        if (hint) hint.classList.add('hidden');
        let pos = getPosMiPerfil(e);
        ctxMiPerfil.beginPath();
        ctxMiPerfil.moveTo(pos.x, pos.y);
        e.preventDefault();
    }

    function moveDrawMiPerfil(e) {
        if (!dibujandoMiPerfil) return;
        let pos = getPosMiPerfil(e);
        ctxMiPerfil.lineTo(pos.x, pos.y);
        ctxMiPerfil.stroke();
        e.preventDefault();
    }

    function endDrawMiPerfil() {
        if (dibujandoMiPerfil) {
            dibujandoMiPerfil = false;
        }
    }

    canvasMiPerfil.addEventListener('mousedown', startDrawMiPerfil);
    canvasMiPerfil.addEventListener('mousemove', moveDrawMiPerfil);
    canvasMiPerfil.addEventListener('mouseup', endDrawMiPerfil);
    canvasMiPerfil.addEventListener('mouseleave', endDrawMiPerfil);

    canvasMiPerfil.addEventListener('touchstart', startDrawMiPerfil, { passive: false });
    canvasMiPerfil.addEventListener('touchmove', moveDrawMiPerfil, { passive: false });
    canvasMiPerfil.addEventListener('touchend', endDrawMiPerfil);
}

function limpiarCanvasMiFirmaPerfil() {
    if (canvasMiPerfil && ctxMiPerfil) {
        ctxMiPerfil.clearRect(0, 0, canvasMiPerfil.width, canvasMiPerfil.height);
        firmaDibujadaMiPerfil = false;
        document.getElementById('input-mi-firma-perfil-base64').value = '';
        let hint = document.getElementById('hint-mi-firma-perfil');
        if (hint) hint.classList.remove('hidden');
    }
}

function abrirModalMiFirma() {
    const modal = document.getElementById('modalGestionarMiFirma');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}
window.abrirModalMiFirma = abrirModalMiFirma;

function cerrarModalMiFirma() {
    const modal = document.getElementById('modalGestionarMiFirma');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
window.cerrarModalMiFirma = cerrarModalMiFirma;

const formMiFirmaPerfil = document.getElementById('form-guardar-firma-perfil');
if (formMiFirmaPerfil) {
    formMiFirmaPerfil.addEventListener('submit', function(e) {
        if (!firmaDibujadaMiPerfil) {
            e.preventDefault();
            alert('Por favor, dibuje su firma en el recuadro antes de guardar.');
            return false;
        }
        try {
            let raw = canvasMiPerfil.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
            document.getElementById('input-mi-firma-perfil-base64').value = raw;
        } catch(err) {}
    });
}

const formChofer = document.getElementById('form-checklist-chofer');
if (formChofer) {
    let submittingChofer = false;
    formChofer.addEventListener('submit', function(e) {
        if (submittingChofer) {
            e.preventDefault();
            return false;
        }
        submittingChofer = true;

        if (canvasChofer && firmaDibujadaChofer) {
            try {
                let raw = canvasChofer.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
                document.getElementById('input-firma-chofer-base64').value = raw;
            } catch(err) {}
        }

        const btnSubmit = formChofer.querySelector('button[type="submit"]');
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.classList.add('opacity-75', 'cursor-not-allowed');
            btnSubmit.innerHTML = '<span class="material-symbols-outlined animate-spin text-lg">progress_activity</span> <span>Guardando checklist...</span>';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('select-equipo');
    if (sel && sel.value) {
        cambioEquipo(sel);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
