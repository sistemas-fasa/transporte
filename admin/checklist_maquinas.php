<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!isAdmin() && !hasPermission('checklist_ver')) {
    header('Location: ' . getDefaultPage());
    exit;
}
$pageTitle = 'CHECK LIST DIARIO AUTOELEVADORES - RES. SRT Nº 960/15';

require_once __DIR__ . '/../includes/checklist_helper.php';
$db = getDB();
initChecklistDatabase($db);

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
$userIdActual = getCurrentUserId();
$choferIdActual = getChoferIdFromUser();
if (!$choferIdActual && $userIdActual) {
    try {
        $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE usuario_id = ? LIMIT 1");
        $stmtCh->execute([$userIdActual]);
        $choferIdActual = $stmtCh->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// Nombre del usuario logueado
$nombreOperadorActual = '';
try {
    $stmtUserAct = $db->prepare("SELECT username, nombre, apellido FROM usuarios WHERE id_usuario = ?");
    $stmtUserAct->execute([$userIdActual]);
    $uAct = $stmtUserAct->fetch();
    if ($uAct) {
        $nombreCompleto = trim(($uAct['nombre'] ?? '') . ' ' . ($uAct['apellido'] ?? ''));
        $nombreOperadorActual = $nombreCompleto ?: $uAct['username'];
    }
} catch (Exception $e) {}
if (empty($nombreOperadorActual)) {
    $nombreOperadorActual = getCurrentUserName();
}

// -------------------------------------------------------------
// PROCESAMIENTO DE ACCIONES POST
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ACCIÓN: APROBAR Y FIRMAR INSPECCIÓN POR EL INSPECTOR
    if ($action === 'aprobar_inspeccion') {
        if (!puedeAprobarChecklist() && !isAdmin()) {
            $_SESSION['flash_err'] = 'No tiene permisos para aprobar o firmar inspecciones. Solo Inspectores o Administradores autorizados.';
            header('Location: checklist_maquinas.php?tab=historial');
            exit;
        }

        $id_inspeccion = (int)($_POST['id_inspeccion'] ?? 0);
        $id_inspector = !empty($_POST['id_inspector']) ? (int)$_POST['id_inspector'] : $_SESSION['user_id'];
        $inspector_nombre = trim($_POST['inspector_nombre'] ?? '');
        $decision = $_POST['decision'] ?? 'aprobado';
        $observacion_inspector = trim($_POST['observacion_inspector'] ?? '');
        $firma_base64 = $_POST['firma_inspector_base64'] ?? '';
        $firma_guardada_post = trim($_POST['firma_inspector_guardada'] ?? '');

        if (!$id_inspeccion) {
            $_SESSION['flash_err'] = 'ID de inspección no válido.';
            header('Location: checklist_maquinas.php?tab=historial');
            exit;
        }

        if (empty($inspector_nombre)) {
            $stmtU = $db->prepare("SELECT username, nombre, apellido FROM usuarios WHERE id_usuario = ?");
            $stmtU->execute([$id_inspector]);
            $uRow = $stmtU->fetch();
            if ($uRow) {
                $inspector_nombre = trim(($uRow['nombre'] ?? '') . ' ' . ($uRow['apellido'] ?? '')) ?: $uRow['username'];
            } else {
                $inspector_nombre = getCurrentUserName();
            }
        }

        // Guardar firma digital del inspector (soporta archivo subido, base64 o firma precargada de perfil)
        $firmaInspectorPath = null;
        if (isset($_FILES['firma_inspector_archivo']) && $_FILES['firma_inspector_archivo']['error'] === UPLOAD_ERR_OK) {
            $firmasDir = __DIR__ . '/../assets/uploads/checklist/firmas/';
            if (!is_dir($firmasDir)) @mkdir($firmasDir, 0777, true);
            $fn = 'firma_insp_' . $id_inspeccion . '_' . time() . '.png';
            if (move_uploaded_file($_FILES['firma_inspector_archivo']['tmp_name'], $firmasDir . $fn)) {
                $firmaInspectorPath = 'assets/uploads/checklist/firmas/' . $fn;
            }
        } elseif (!empty($firma_base64)) {
            $firmaInspectorPath = guardarFirmaDigital($firma_base64, $id_inspeccion);
        } elseif (!empty($firma_guardada_post)) {
            $firmaInspectorPath = $firma_guardada_post;
        } else {
            // Buscar si el usuario inspector tiene una firma precargada en su perfil
            try {
                $stmtInspFirma = $db->prepare("SELECT firma_digital FROM usuarios WHERE id_usuario = ?");
                $stmtInspFirma->execute([$id_inspector]);
                $userFirma = $stmtInspFirma->fetchColumn();
                if (!empty($userFirma)) {
                    $firmaInspectorPath = $userFirma;
                }
            } catch (Exception $e) {}
        }

        // Estado según fallas
        $stmtCurr = $db->prepare("SELECT total_fallas FROM checklist_inspecciones WHERE id_inspeccion = ?");
        $stmtCurr->execute([$id_inspeccion]);
        $curr = $stmtCurr->fetch();
        $totalFallas = (int)($curr['total_fallas'] ?? 0);

        $nuevoEstado = ($decision === 'rechazado') ? 'rechazado' : (($totalFallas > 0) ? 'con_observaciones' : 'aprobado');
        $estadoAprobacion = ($decision === 'rechazado') ? 'rechazado' : 'aprobado';

        try {
            $updSql = "UPDATE checklist_inspecciones SET 
                id_inspector = ?, 
                inspector_nombre = ?, 
                fecha_aprobacion = NOW(), 
                observacion_inspector = ?, 
                estado_aprobacion = ?, 
                estado = ?";
            $updParams = [$id_inspector, $inspector_nombre, $observacion_inspector ?: null, $estadoAprobacion, $nuevoEstado];
            
            if ($firmaInspectorPath) {
                $updSql .= ", firma_inspector = ?";
                $updParams[] = $firmaInspectorPath;
            }
            $updSql .= " WHERE id_inspeccion = ?";
            $updParams[] = $id_inspeccion;

            $db->prepare($updSql)->execute($updParams);

            registrarAuditoria($_SESSION['user_id'], 'update', 'checklist_inspecciones', $id_inspeccion, "Inspector $inspector_nombre aprobó/firmó inspección #$id_inspeccion (Dictamen: $estadoAprobacion)");
            $_SESSION['flash_msg'] = '¡Inspección #' . $id_inspeccion . ' aprobada y firmada exitosamente por el Inspector!';
        } catch (Exception $e) {
            $_SESSION['flash_err'] = 'Error al aprobar inspección: ' . $e->getMessage();
        }

        header('Location: checklist_maquinas.php?tab=historial&ver=' . $id_inspeccion);
        exit;
    }

    // 1. REGISTRAR NUEVA INSPECCIÓN
    if ($action === 'guardar_inspeccion') {
        $id_camion = (int)($_POST['id_camion'] ?? 0);
        $id_chofer = !empty($_POST['id_chofer']) ? (int)$_POST['id_chofer'] : $choferIdActual;
        $horas_maquina = !empty($_POST['horas_maquina']) ? (float)$_POST['horas_maquina'] : null;
        $fecha = !empty($_POST['fecha']) ? $_POST['fecha'] : date('Y-m-d H:i:s');
        $observacion_general = trim($_POST['observacion_general'] ?? '');
        $firmado_por = trim($_POST['firmado_por'] ?? $nombreOperadorActual);
        $respuestasPost = $_POST['respuestas'] ?? [];
        $observacionesPost = $_POST['observaciones'] ?? [];

        $id_usuario_inspeccion = $userIdActual;

        if (!$id_camion) {
            $_SESSION['flash_err'] = 'Debe seleccionar un autoelevador o máquina.';
            header('Location: checklist_maquinas.php?tab=nueva');
            exit;
        }

        if (empty($respuestasPost)) {
            $_SESSION['flash_err'] = 'No se recibieron respuestas para el checklist.';
            header('Location: checklist_maquinas.php?tab=nueva');
            exit;
        }

        // Prevención de doble registro (idempotencia en 10s)
        try {
            $stmtDup = $db->prepare("SELECT id_inspeccion FROM checklist_inspecciones 
                WHERE id_camion = ? AND id_usuario = ? AND fecha >= DATE_SUB(NOW(), INTERVAL 10 SECOND) 
                ORDER BY id_inspeccion DESC LIMIT 1");
            $stmtDup->execute([$id_camion, $id_usuario_inspeccion]);
            $dupId = $stmtDup->fetchColumn();
            if ($dupId) {
                $_SESSION['flash_msg'] = "Inspección registrada exitosamente.";
                header("Location: checklist_maquinas.php?tab=historial&ver=" . $dupId);
                exit;
            }
        } catch (Exception $e) {}

        try {
            $db->beginTransaction();

            // Obtener tipo de máquina
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

            // Insertar encabezado de inspección
            $insStmt = $db->prepare("INSERT INTO checklist_inspecciones 
                (id_camion, id_usuario, id_chofer, fecha, tipo_maquina, horas_maquina, estado, total_preguntas, total_ok, total_fallas, observacion_general, firmado_por) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insStmt->execute([
                $id_camion,
                $id_usuario_inspeccion,
                $id_chofer,
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

            // Obtener info de las preguntas
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

            // Insertar detalle de respuestas y procesar fotos
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

                // Subir foto si existe
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

            // Actualizar horas del autoelevador si se ingresaron horas mayores
            if ($horas_maquina && $horas_maquina > ($vehInfo['horas_actuales'] ?? 0)) {
                $db->prepare("UPDATE camiones SET horas_actuales = ? WHERE id_camion = ?")->execute([$horas_maquina, $id_camion]);
            }

            // Guardar Firma Digital (archivo subido, base64 o firma precargada del usuario)
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
            } else {
                // Fallback a firma del usuario si existe
                try {
                    $stmtUsFirma = $db->prepare("SELECT firma_digital FROM usuarios WHERE id_usuario = ?");
                    $stmtUsFirma->execute([$userIdActual]);
                    $firmaGuardadaUs = $stmtUsFirma->fetchColumn();
                    if (!empty($firmaGuardadaUs)) {
                        $firmaPath = $firmaGuardadaUs;
                    }
                } catch (Exception $e) {}
            }

            if ($firmaPath) {
                $db->prepare("UPDATE checklist_inspecciones SET firma_digital = ? WHERE id_inspeccion = ?")->execute([$firmaPath, $idInspeccion]);
            }

            $db->commit();
            registrarAuditoria($_SESSION['user_id'], 'create', 'checklist_inspecciones', $idInspeccion, "Registro checklist para camion ID $id_camion (Estado: $estadoGeneral, OK: $total_ok, Fallas: $total_fallas)");
            $_SESSION['flash_msg'] = "Inspección #" . $idInspeccion . " registrada exitosamente. Estado: " . ($estadoGeneral === 'aprobado' ? 'Aprobado sin observaciones' : 'Registrado con ' . $total_fallas . ' observación(es)');
            header("Location: checklist_maquinas.php?tab=historial&ver=" . $idInspeccion);
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_err'] = 'Error al registrar la inspección: ' . $e->getMessage();
            header('Location: checklist_maquinas.php?tab=nueva');
            exit;
        }
    }

    // 2. CREAR NUEVA PREGUNTA
    if ($action === 'crear_pregunta') {
        requirePermission('checklist_config');
        $categoria = trim($_POST['categoria'] ?? '');
        $categoria_nueva = trim($_POST['categoria_nueva'] ?? '');
        if ($categoria === '__nueva__' && !empty($categoria_nueva)) {
            $categoria = $categoria_nueva;
        }
        $pregunta = trim($_POST['pregunta'] ?? '');
        $descripcion_ayuda = trim($_POST['descripcion_ayuda'] ?? '');
        $tipo_maquina = trim($_POST['tipo_maquina'] ?? 'autoelevador');
        $orden = (int)($_POST['orden'] ?? 0);

        if (empty($categoria) || empty($pregunta)) {
            $_SESSION['flash_err'] = 'La categoría y el texto de la pregunta son obligatorios.';
        } else {
            try {
                $db->prepare("INSERT INTO checklist_preguntas (categoria, pregunta, descripcion_ayuda, tipo_maquina, orden, activo) VALUES (?, ?, ?, ?, ?, 1)")
                   ->execute([$categoria, $pregunta, $descripcion_ayuda ?: null, $tipo_maquina, $orden]);
                $idPregNueva = (int)$db->lastInsertId();
                registrarAuditoria($_SESSION['user_id'], 'create', 'checklist_preguntas', $idPregNueva, "Creo pregunta de checklist: $pregunta ($categoria)");
                $_SESSION['flash_msg'] = 'Pregunta creada correctamente en el banco de inspección.';
            } catch (Exception $e) {
                $_SESSION['flash_err'] = 'Error al crear pregunta: ' . $e->getMessage();
            }
        }
        header('Location: checklist_maquinas.php?tab=preguntas');
        exit;
    }

    // 3. EDITAR PREGUNTA
    if ($action === 'editar_pregunta') {
        requirePermission('checklist_config');
        $id_pregunta = (int)($_POST['id_pregunta'] ?? 0);
        $categoria = trim($_POST['categoria'] ?? '');
        $categoria_nueva = trim($_POST['categoria_nueva'] ?? '');
        if ($categoria === '__nueva__' && !empty($categoria_nueva)) {
            $categoria = $categoria_nueva;
        }
        $pregunta = trim($_POST['pregunta'] ?? '');
        $descripcion_ayuda = trim($_POST['descripcion_ayuda'] ?? '');
        $tipo_maquina = trim($_POST['tipo_maquina'] ?? 'autoelevador');
        $orden = (int)($_POST['orden'] ?? 0);
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($id_pregunta <= 0 || empty($categoria) || empty($pregunta)) {
            $_SESSION['flash_err'] = 'Datos inválidos para editar la pregunta.';
        } else {
            try {
                $db->prepare("UPDATE checklist_preguntas SET categoria=?, pregunta=?, descripcion_ayuda=?, tipo_maquina=?, orden=?, activo=? WHERE id_pregunta=?")
                   ->execute([$categoria, $pregunta, $descripcion_ayuda ?: null, $tipo_maquina, $orden, $activo, $id_pregunta]);
                registrarAuditoria($_SESSION['user_id'], 'update', 'checklist_preguntas', $id_pregunta, "Actualizo pregunta de checklist ID $id_pregunta");
                $_SESSION['flash_msg'] = 'Pregunta actualizada exitosamente.';
            } catch (Exception $e) {
                $_SESSION['flash_err'] = 'Error al actualizar pregunta: ' . $e->getMessage();
            }
        }
        header('Location: checklist_maquinas.php?tab=preguntas');
        exit;
    }

    // 4. CAMBIAR ESTADO ACTIVO/INACTIVO
    if ($action === 'toggle_pregunta') {
        requirePermission('checklist_config');
        $id_pregunta = (int)($_POST['id_pregunta'] ?? 0);
        try {
            $db->prepare("UPDATE checklist_preguntas SET activo = IF(activo = 1, 0, 1) WHERE id_pregunta = ?")->execute([$id_pregunta]);
            $_SESSION['flash_msg'] = 'Estado de la pregunta actualizado.';
        } catch (Exception $e) {
            $_SESSION['flash_err'] = 'Error: ' . $e->getMessage();
        }
        header('Location: checklist_maquinas.php?tab=preguntas');
        exit;
    }

    // 5. ELIMINAR PREGUNTA
    if ($action === 'eliminar_pregunta') {
        requirePermission('checklist_config');
        $id_pregunta = (int)($_POST['id_pregunta'] ?? 0);
        try {
            $db->prepare("DELETE FROM checklist_preguntas WHERE id_pregunta = ?")->execute([$id_pregunta]);
            $_SESSION['flash_msg'] = 'Pregunta eliminada del catálogo.';
        } catch (Exception $e) {
            $_SESSION['flash_err'] = 'Error al eliminar pregunta: ' . $e->getMessage();
        }
        header('Location: checklist_maquinas.php?tab=preguntas');
        exit;
    }

    // 6. GUARDAR ORDEN MANUAL DE PREGUNTAS (GUARDADO MASIVO)
    if ($action === 'guardar_orden_preguntas') {
        requirePermission('checklist_config');
        $ordenes = $_POST['ordenes'] ?? [];
        if (is_array($ordenes) && !empty($ordenes)) {
            try {
                $db->beginTransaction();
                $stmtUpd = $db->prepare("UPDATE checklist_preguntas SET orden = ? WHERE id_pregunta = ?");
                foreach ($ordenes as $idPreg => $ordVal) {
                    $stmtUpd->execute([(int)$ordVal, (int)$idPreg]);
                }
                $db->commit();
                registrarAuditoria($_SESSION['user_id'], 'update', 'checklist_preguntas', 0, "Actualizo orden personalizado de " . count($ordenes) . " preguntas de checklist");
                $_SESSION['flash_msg'] = '¡El orden de las preguntas ha sido guardado correctamente!';
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $_SESSION['flash_err'] = 'Error al guardar el nuevo orden: ' . $e->getMessage();
            }
        }
        header('Location: checklist_maquinas.php?tab=preguntas');
        exit;
    }

    // 7. MOVER PREGUNTA ARRIBA / ABAJO (1 CLIC)
    if ($action === 'mover_pregunta') {
        requirePermission('checklist_config');
        $id_pregunta = (int)($_POST['id_pregunta'] ?? 0);
        $direccion = $_POST['direccion'] ?? 'up';

        if ($id_pregunta > 0) {
            try {
                // Obtener todas las preguntas en su orden actual
                $stmtList = $db->query("SELECT id_pregunta, orden FROM checklist_preguntas ORDER BY orden ASC, id_pregunta ASC");
                $allList = $stmtList->fetchAll();

                $curIndex = -1;
                foreach ($allList as $idx => $row) {
                    if ((int)$row['id_pregunta'] === $id_pregunta) {
                        $curIndex = $idx;
                        break;
                    }
                }

                if ($curIndex !== -1) {
                    $targetIndex = ($direccion === 'up') ? $curIndex - 1 : $curIndex + 1;
                    if ($targetIndex >= 0 && $targetIndex < count($allList)) {
                        // Intercambiar posición
                        $temp = $allList[$curIndex];
                        $allList[$curIndex] = $allList[$targetIndex];
                        $allList[$targetIndex] = $temp;

                        $db->beginTransaction();
                        $stmtSet = $db->prepare("UPDATE checklist_preguntas SET orden = ? WHERE id_pregunta = ?");
                        $newOrder = 10;
                        foreach ($allList as $item) {
                            $stmtSet->execute([$newOrder, (int)$item['id_pregunta']]);
                            $newOrder += 10;
                        }
                        $db->commit();
                        $_SESSION['flash_msg'] = 'Pregunta movida ' . ($direccion === 'up' ? 'hacia arriba ▲' : 'hacia abajo ▼') . ' exitosamente.';
                    }
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $_SESSION['flash_err'] = 'Error al mover pregunta: ' . $e->getMessage();
            }
        }
        header('Location: checklist_maquinas.php?tab=preguntas');
        exit;
    }

    // 8. RENUMERAR TODAS LAS PREGUNTAS EN DECENAS (10, 20, 30...)
    if ($action === 'renumerar_orden_automatico') {
        requirePermission('checklist_config');
        try {
            $stmtList = $db->query("SELECT id_pregunta FROM checklist_preguntas ORDER BY orden ASC, id_pregunta ASC");
            $allIds = $stmtList->fetchAll(PDO::FETCH_COLUMN);
            $db->beginTransaction();
            $stmtSet = $db->prepare("UPDATE checklist_preguntas SET orden = ? WHERE id_pregunta = ?");
            $newOrder = 10;
            foreach ($allIds as $pId) {
                $stmtSet->execute([$newOrder, (int)$pId]);
                $newOrder += 10;
            }
            $db->commit();
            $_SESSION['flash_msg'] = 'Preguntas renumeradas automáticamente en intervalos de 10.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_err'] = 'Error al renumerar: ' . $e->getMessage();
        }
        header('Location: checklist_maquinas.php?tab=preguntas');
        exit;
    }

    // 9. ELIMINAR INSPECCIÓN
    if ($action === 'eliminar_inspeccion') {
        requirePermission('checklist_eliminar');
        $id_inspeccion = (int)($_POST['id_inspeccion'] ?? 0);
        try {
            // Eliminar fotos asociadas
            $stmtF = $db->prepare("SELECT foto_path FROM checklist_respuestas WHERE id_inspeccion = ? AND foto_path IS NOT NULL");
            $stmtF->execute([$id_inspeccion]);
            while ($f = $stmtF->fetch()) {
                $fullPath = __DIR__ . '/../' . $f['foto_path'];
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
            $db->prepare("DELETE FROM checklist_inspecciones WHERE id_inspeccion = ?")->execute([$id_inspeccion]);
            $_SESSION['flash_msg'] = 'Inspección #' . $id_inspeccion . ' eliminada correctamente.';
        } catch (Exception $e) {
            $_SESSION['flash_err'] = 'Error al eliminar inspección: ' . $e->getMessage();
        }
        header('Location: checklist_maquinas.php?tab=historial');
        exit;
    }
}

// -------------------------------------------------------------
// CONSULTAS PARA VISTAS
// -------------------------------------------------------------
$activeTab = $_GET['tab'] ?? 'historial';
if (!in_array($activeTab, ['historial', 'nueva', 'preguntas', 'maquinas'])) {
    $activeTab = 'historial';
}

// Lista de máquinas (autoelevadores y vehículos habilitados para checklist)
$stmtCamiones = $db->query("SELECT id_camion, patente, marca, modelo, tipo, horas_actuales, kilometraje_actual, estado FROM camiones WHERE hace_checklist = 1 OR tipo = 'autoelevador' ORDER BY (tipo = 'autoelevador') DESC, patente ASC");
$listaCamiones = $stmtCamiones->fetchAll();
if (empty($listaCamiones)) {
    $stmtCamiones = $db->query("SELECT id_camion, patente, marca, modelo, tipo, horas_actuales, kilometraje_actual, estado FROM camiones ORDER BY (tipo = 'autoelevador') DESC, patente ASC");
    $listaCamiones = $stmtCamiones->fetchAll();
}

// Lista de choferes/operadores
$stmtChoferes = $db->query("SELECT id_chofer, nombre, apellido, dni FROM choferes WHERE estado = 'activo' ORDER BY apellido, nombre");
$listaChoferes = $stmtChoferes->fetchAll();

// Lista de inspectores (usuarios con rol Inspector)
$sqlInspectores = "
    SELECT DISTINCT u.id_usuario, u.username, u.nombre, u.apellido, u.firma_digital, r.nombre as rol_nombre
    FROM usuarios u
    JOIN usuario_rol ur ON u.id_usuario = ur.id_usuario
    JOIN roles r ON ur.id_rol = r.id_rol
    WHERE LOWER(r.nombre) LIKE '%inspector%' AND u.activo = 1
    ORDER BY u.apellido ASC, u.nombre ASC, u.username ASC
";
$stmtInspectores = $db->query($sqlInspectores);
$listaInspectores = $stmtInspectores->fetchAll();

if (empty($listaInspectores)) {
    // Fallback: buscar usuarios con rol inspector o administrativo
    $sqlInspectores = "
        SELECT DISTINCT u.id_usuario, u.username, u.nombre, u.apellido, u.firma_digital, COALESCE(r.nombre, u.rol) as rol_nombre
        FROM usuarios u
        LEFT JOIN usuario_rol ur ON u.id_usuario = ur.id_usuario
        LEFT JOIN roles r ON ur.id_rol = r.id_rol
        WHERE u.activo = 1 AND (LOWER(COALESCE(r.nombre, u.rol)) IN ('inspector', 'administrador', 'admin', 'supervisor') OR u.rol = 'admin')
        ORDER BY u.apellido ASC, u.nombre ASC, u.username ASC
    ";
    $stmtInspectores = $db->query($sqlInspectores);
    $listaInspectores = $stmtInspectores->fetchAll();
}

if (empty($listaInspectores)) {
    $stmtInspectores = $db->query("SELECT id_usuario, username, nombre, apellido, firma_digital, rol as rol_nombre FROM usuarios WHERE activo = 1 ORDER BY apellido, nombre");
    $listaInspectores = $stmtInspectores->fetchAll();
}

// Preguntas activas agrupadas por categoría para la nueva inspección
$stmtPreguntasActivas = $db->query("SELECT * FROM checklist_preguntas WHERE activo = 1 ORDER BY orden ASC, id_pregunta ASC");
$preguntasActivas = $stmtPreguntasActivas->fetchAll();

$preguntasPorCategoria = [];
foreach ($preguntasActivas as $p) {
    $preguntasPorCategoria[$p['categoria']][] = $p;
}

// Categorías únicas
$stmtCats = $db->query("SELECT DISTINCT categoria FROM checklist_preguntas ORDER BY categoria ASC");
$categoriasExistentes = $stmtCats->fetchAll(PDO::FETCH_COLUMN);

// Preguntas para el tab de configuración (ordenadas exactamente por el orden definido por el usuario)
$stmtTodasPreguntas = $db->query("SELECT * FROM checklist_preguntas ORDER BY orden ASC, id_pregunta ASC");
$todasPreguntas = $stmtTodasPreguntas->fetchAll();

// Filtros para Historial
$filtroCamion = (int)($_GET['filtro_camion'] ?? 0);
$filtroEstado = $_GET['filtro_estado'] ?? '';
$filtroDesde = $_GET['filtro_desde'] ?? '';
$filtroHasta = $_GET['filtro_hasta'] ?? '';
$filtroBuscar = trim($_GET['filtro_buscar'] ?? '');

$whereSql = [];
$paramsSql = [];

if ($filtroCamion > 0) {
    $whereSql[] = "i.id_camion = ?";
    $paramsSql[] = $filtroCamion;
}
if (!empty($filtroEstado)) {
    $whereSql[] = "i.estado = ?";
    $paramsSql[] = $filtroEstado;
}
if (!empty($filtroDesde)) {
    $whereSql[] = "DATE(i.fecha) >= ?";
    $paramsSql[] = $filtroDesde;
}
if (!empty($filtroHasta)) {
    $whereSql[] = "DATE(i.fecha) <= ?";
    $paramsSql[] = $filtroHasta;
}
if (!empty($filtroBuscar)) {
    $whereSql[] = "(c.patente LIKE ? OR c.marca LIKE ? OR c.modelo LIKE ? OR ch.nombre LIKE ? OR ch.apellido LIKE ? OR u.username LIKE ? OR i.firmado_por LIKE ?)";
    $term = "%$filtroBuscar%";
    $paramsSql[] = $term;
    $paramsSql[] = $term;
    $paramsSql[] = $term;
    $paramsSql[] = $term;
    $paramsSql[] = $term;
    $paramsSql[] = $term;
    $paramsSql[] = $term;
}

$whereClause = !empty($whereSql) ? "WHERE " . implode(" AND ", $whereSql) : "";

$sqlHistorial = "SELECT i.*, 
    c.patente, c.marca, c.modelo, c.tipo as tipo_vehiculo, c.foto as foto_vehiculo,
    CONCAT(ch.nombre, ' ', ch.apellido) as chofer_nombre,
    u.username as usuario_nombre
    FROM checklist_inspecciones i
    LEFT JOIN camiones c ON i.id_camion = c.id_camion
    LEFT JOIN choferes ch ON i.id_chofer = ch.id_chofer
    LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario
    $whereClause
    ORDER BY i.fecha DESC, i.id_inspeccion DESC
    LIMIT 100";

$stmtHistorial = $db->prepare($sqlHistorial);
$stmtHistorial->execute($paramsSql);
$inspecciones = $stmtHistorial->fetchAll();

// KPIs generales
$kpiTotal = (int)$db->query("SELECT COUNT(*) FROM checklist_inspecciones")->fetchColumn();
$kpiAprobadas = (int)$db->query("SELECT COUNT(*) FROM checklist_inspecciones WHERE estado = 'aprobado'")->fetchColumn();
$kpiConFallas = (int)$db->query("SELECT COUNT(*) FROM checklist_inspecciones WHERE estado = 'con_observaciones' OR estado = 'rechazado'")->fetchColumn();
$kpiTotalAutoelevadores = (int)$db->query("SELECT COUNT(*) FROM camiones WHERE tipo = 'autoelevador' OR por_hora = 1")->fetchColumn();

// Si se pide ver el detalle de una inspección vía GET ?ver=ID
$verInspeccion = null;
$verRespuestas = [];
if (isset($_GET['ver']) && (int)$_GET['ver'] > 0) {
    $idVer = (int)$_GET['ver'];
    $stmtVer = $db->prepare("SELECT i.*, 
        c.patente, c.marca, c.modelo, c.tipo as tipo_vehiculo, c.horas_actuales,
        CONCAT(ch.nombre, ' ', ch.apellido) as chofer_nombre,
        u.username as usuario_nombre
        FROM checklist_inspecciones i
        LEFT JOIN camiones c ON i.id_camion = c.id_camion
        LEFT JOIN choferes ch ON i.id_chofer = ch.id_chofer
        LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario
        WHERE i.id_inspeccion = ?");
    $stmtVer->execute([$idVer]);
    $verInspeccion = $stmtVer->fetch();

    if ($verInspeccion) {
        $stmtResp = $db->prepare("SELECT * FROM checklist_respuestas WHERE id_inspeccion = ? ORDER BY id_respuesta ASC");
        $stmtResp->execute([$idVer]);
        $verRespuestas = $stmtResp->fetchAll();
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';
?>

<div class="md:ml-64 pt-20 px-4 md:px-8 pb-16 min-h-screen bg-surface">
    <!-- Header de la Página -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-primary/10 rounded-xl text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">fact_check</span>
                </div>
                <div>
                    <h1 class="text-xl md:text-2xl font-black text-on-surface tracking-tight leading-tight">
                        CHECK LIST DIARIO PARA USO DE AUTOELEVADORES, MONTACARGAS SEGÚN RESOLUCIÓN DE LA SRT. Nº 960/15
                    </h1>
                    <p class="text-xs text-on-surface-variant mt-1 font-medium">Control operativo diario, inspección preventiva y registro de novedades con firma digital.</p>
                </div>
            </div>
        </div>

        <!-- Botones de Acción Rápida -->
        <div class="flex items-center gap-2">
            <a href="?tab=nueva" class="flex items-center gap-2 px-4 py-2.5 bg-primary text-white rounded-xl text-sm font-semibold shadow-sm hover:bg-primary/90 transition-all">
                <span class="material-symbols-outlined text-lg">add_circle</span>
                <span>Nueva Inspección</span>
            </a>
            <a href="?tab=preguntas" class="flex items-center gap-2 px-3.5 py-2.5 bg-white border border-outline-variant text-on-surface rounded-xl text-sm font-semibold hover:bg-slate-50 transition-all">
                <span class="material-symbols-outlined text-lg">tune</span>
                <span class="hidden sm:inline">Preguntas</span>
            </a>
        </div>
    </div>

    <!-- Mensajes Flash -->
    <?php if ($mensaje): ?>
    <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center gap-3 shadow-sm animate-fade-in">
        <span class="material-symbols-outlined text-emerald-600">check_circle</span>
        <span class="text-sm font-medium"><?= htmlspecialchars($mensaje) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800 flex items-center gap-3 shadow-sm animate-fade-in">
        <span class="material-symbols-outlined text-red-600">error</span>
        <span class="text-sm font-medium"><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- KPI Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Total Inspecciones</p>
                <h3 class="text-2xl font-bold text-on-surface mt-1"><?= number_format($kpiTotal) ?></h3>
            </div>
            <div class="w-11 h-11 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">assignment</span>
            </div>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Conformes (100% OK)</p>
                <h3 class="text-2xl font-bold text-emerald-600 mt-1"><?= number_format($kpiAprobadas) ?></h3>
            </div>
            <div class="w-11 h-11 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">verified</span>
            </div>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Con Observaciones</p>
                <h3 class="text-2xl font-bold text-amber-600 mt-1"><?= number_format($kpiConFallas) ?></h3>
            </div>
            <div class="w-11 h-11 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">warning</span>
            </div>
        </div>

        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">Unidades con Checklist</p>
                <h3 class="text-2xl font-bold text-primary mt-1"><?= number_format($kpiTotalAutoelevadores) ?></h3>
            </div>
            <div class="w-11 h-11 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">forklift</span>
            </div>
        </div>
    </div>

    <!-- Navegación por Pestañas -->
    <div class="border-b border-outline-variant mb-6">
        <nav class="flex space-x-6 overflow-x-auto no-scrollbar">
            <a href="?tab=historial" class="pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-all whitespace-nowrap <?= $activeTab === 'historial' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>">
                <span class="material-symbols-outlined text-lg">history</span>
                Historial de Inspecciones
                <span class="px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700"><?= count($inspecciones) ?></span>
            </a>

            <a href="?tab=nueva" class="pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-all whitespace-nowrap <?= $activeTab === 'nueva' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>">
                <span class="material-symbols-outlined text-lg">add_task</span>
                Nueva Inspección (Asistente)
            </a>

            <a href="?tab=preguntas" class="pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-all whitespace-nowrap <?= $activeTab === 'preguntas' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>">
                <span class="material-symbols-outlined text-lg">quiz</span>
                Banco de Preguntas
                <span class="px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700"><?= count($todasPreguntas) ?></span>
            </a>

            <a href="?tab=maquinas" class="pb-3 text-sm font-semibold flex items-center gap-2 border-b-2 transition-all whitespace-nowrap <?= $activeTab === 'maquinas' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>">
                <span class="material-symbols-outlined text-lg">forklift</span>
                Estado de Máquinas
            </a>
        </nav>
    </div>

    <!-- ============================================================= -->
    <!-- TAB 1: HISTORIAL DE INSPECCIONES                              -->
    <!-- ============================================================= -->
    <?php if ($activeTab === 'historial'): ?>
    <div class="space-y-4">
        <!-- Filtros de Búsqueda -->
        <div class="bg-white p-4 rounded-2xl border border-outline-variant/60 shadow-sm">
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <input type="hidden" name="tab" value="historial">

                <div>
                    <label class="block text-xs font-semibold text-on-surface-variant mb-1">Buscar</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-sm">search</span>
                        <input type="text" name="filtro_buscar" value="<?= htmlspecialchars($filtroBuscar) ?>" placeholder="Patente, chofer..." class="w-full pl-9 pr-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-on-surface-variant mb-1">Máquina / Vehículo</label>
                    <select name="filtro_camion" class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        <option value="">Todos los equipos</option>
                        <?php foreach ($listaCamiones as $c): ?>
                        <option value="<?= $c['id_camion'] ?>" <?= $filtroCamion === (int)$c['id_camion'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['patente']) ?> - <?= htmlspecialchars($c['marca'] . ' ' . $c['modelo']) ?> (<?= ucfirst($c['tipo']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-on-surface-variant mb-1">Estado</label>
                    <select name="filtro_estado" class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        <option value="">Todos los estados</option>
                        <option value="aprobado" <?= $filtroEstado === 'aprobado' ? 'selected' : '' ?>>Aprobado (100% OK)</option>
                        <option value="con_observaciones" <?= $filtroEstado === 'con_observaciones' ? 'selected' : '' ?>>Con Observaciones / Falla</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-on-surface-variant mb-1">Fecha Desde</label>
                    <input type="date" name="filtro_desde" value="<?= htmlspecialchars($filtroDesde) ?>" class="w-full px-3 py-2 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary">
                </div>

                <div class="flex items-end gap-2">
                    <button type="submit" class="flex-1 py-2 px-4 bg-primary text-white text-sm font-semibold rounded-xl hover:bg-primary/90 transition-all flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-sm">filter_alt</span> Filtrar
                    </button>
                    <a href="checklist_maquinas.php?tab=historial" class="py-2 px-3 bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm font-semibold rounded-xl transition-all" title="Limpiar filtros">
                        <span class="material-symbols-outlined text-sm">refresh</span>
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabla de Inspecciones -->
        <div class="bg-white rounded-2xl border border-outline-variant/60 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-on-surface border-collapse">
                    <thead class="bg-slate-50 text-xs uppercase font-semibold text-on-surface-variant border-b border-outline-variant">
                        <tr>
                            <th class="py-3.5 px-4"># ID</th>
                            <th class="py-3.5 px-4">Fecha y Hora</th>
                            <th class="py-3.5 px-4">Máquina / Autoelevador</th>
                            <th class="py-3.5 px-4">Operador / Inspector</th>
                            <th class="py-3.5 px-4 text-center">Horómetro</th>
                            <th class="py-3.5 px-4 text-center">Resultado</th>
                            <th class="py-3.5 px-4 text-center">Estado</th>
                            <th class="py-3.5 px-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/50">
                        <?php if (empty($inspecciones)): ?>
                        <tr>
                            <td colspan="8" class="py-12 text-center text-on-surface-variant">
                                <div class="flex flex-col items-center justify-center">
                                    <span class="material-symbols-outlined text-5xl text-slate-300 mb-2">assignment_late</span>
                                    <p class="font-medium text-base text-slate-600">No se encontraron inspecciones registradas</p>
                                    <p class="text-xs text-slate-400 mt-1">Realice la primera inspección usando el botón "Nueva Inspección".</p>
                                    <a href="?tab=nueva" class="mt-4 px-4 py-2 bg-primary text-white rounded-xl text-xs font-semibold">Iniciar Checklist Ahora</a>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($inspecciones as $row): ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="py-3.5 px-4 font-mono font-semibold text-slate-500">#<?= $row['id_inspeccion'] ?></td>
                            <td class="py-3.5 px-4">
                                <p class="font-semibold text-on-surface"><?= date('d/m/Y', strtotime($row['fecha'])) ?></p>
                                <p class="text-xs text-on-surface-variant"><?= date('H:i', strtotime($row['fecha'])) ?> hs</p>
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-primary font-bold text-xs shrink-0">
                                        <span class="material-symbols-outlined text-sm"><?= $row['tipo_vehiculo'] === 'autoelevador' ? 'forklift' : 'local_shipping' ?></span>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-on-surface"><?= htmlspecialchars($row['patente'] ?: ('Equipo #' . $row['id_camion'])) ?></p>
                                        <p class="text-xs text-on-surface-variant"><?= htmlspecialchars(trim(($row['marca'] ?? '') . ' ' . ($row['modelo'] ?? '')) ?: ($row['tipo_maquina'] ?: 'Autoelevador')) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-4">
                                <p class="font-medium text-on-surface"><?= htmlspecialchars($row['chofer_nombre'] ?: ($row['firmado_por'] ?: $row['usuario_nombre'])) ?></p>
                                <p class="text-xs text-on-surface-variant">Insp: <?= htmlspecialchars($row['usuario_nombre'] ?: ($row['firmado_por'] ?: 'Sistema')) ?></p>
                            </td>
                            <td class="py-3.5 px-4 text-center font-mono font-medium">
                                <?= $row['horas_maquina'] ? number_format($row['horas_maquina'], 1) . ' hs' : '<span class="text-slate-300">-</span>' ?>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <div class="inline-flex items-center gap-1.5 font-medium text-xs">
                                    <span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 font-bold"><?= $row['total_ok'] ?> OK</span>
                                    <?php if ($row['total_fallas'] > 0): ?>
                                    <span class="px-2 py-0.5 rounded-md bg-amber-50 text-amber-700 font-bold"><?= $row['total_fallas'] ?> Falla(s)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <?php $estAprob = $row['estado_aprobacion'] ?? 'pendiente'; ?>
                                <?php if ($estAprob === 'aprobado' || !empty($row['firma_inspector'])): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800" title="Aprobado por <?= htmlspecialchars($row['inspector_nombre'] ?: 'Inspector') ?>">
                                    <span class="material-symbols-outlined text-sm">verified</span> Aprobado
                                </span>
                                <?php elseif ($estAprob === 'rechazado'): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800" title="Rechazado por <?= htmlspecialchars($row['inspector_nombre'] ?: 'Inspector') ?>">
                                    <span class="material-symbols-outlined text-sm">cancel</span> Rechazado
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800" title="Pendiente de revisión y firma por el Inspector">
                                    <span class="material-symbols-outlined text-sm">pending_actions</span> Pend. Firma
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <?php if (puedeAprobarChecklist() && empty($row['firma_inspector']) && ($row['estado_aprobacion'] ?? 'pendiente') === 'pendiente'): ?>
                                    <a href="?tab=historial&ver=<?= $row['id_inspeccion'] ?>" class="px-2.5 py-1 bg-primary text-white rounded-lg text-xs font-bold hover:bg-primary/90 transition-all inline-flex items-center gap-1 shadow-sm" title="Revisar y Firmar como Inspector">
                                        <span class="material-symbols-outlined text-sm">draw</span> Firmar
                                    </a>
                                    <?php endif; ?>
                                    <a href="?tab=historial&ver=<?= $row['id_inspeccion'] ?>" class="p-1.5 text-blue-600 bg-blue-50/70 hover:bg-blue-100 rounded-lg transition-all inline-flex items-center justify-center" title="Ver Detalle Completo">
                                        <span class="material-symbols-outlined text-lg">visibility</span>
                                    </a>
                                    <?php if (puedeAprobarChecklist() || isAdmin()): ?>
                                    <a href="checklist_imprimir.php?id=<?= $row['id_inspeccion'] ?>" target="_blank" class="p-1.5 text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-all inline-flex items-center justify-center" title="Imprimir Ficha A4">
                                        <span class="material-symbols-outlined text-lg">print</span>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('checklist_eliminar') || isAdmin()): ?>
                                    <button type="button" 
                                            onclick="abrirModalEliminarInspeccion(<?= $row['id_inspeccion'] ?>, '<?= htmlspecialchars(addslashes($row['patente'] ?: ('Equipo #' . $row['id_camion']))) ?>', '<?= htmlspecialchars(addslashes(date('d/m/Y H:i', strtotime($row['fecha'])) . ' hs - ' . ($row['chofer_nombre'] ?: ($row['firmado_por'] ?: $row['usuario_nombre'])))) ?>')"
                                            class="p-1.5 text-red-500 bg-red-50/70 hover:bg-red-100 hover:text-red-700 rounded-lg transition-all inline-flex items-center justify-center cursor-pointer" 
                                            title="Eliminar Inspección">
                                        <span class="material-symbols-outlined text-lg">delete</span>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL DETALLE DE INSPECCIÓN -->
    <?php if ($verInspeccion): ?>
    <div id="modal-detalle" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl max-w-3xl w-full max-h-[90vh] flex flex-col shadow-2xl overflow-hidden animate-scale-in">
            <!-- Header Modal -->
            <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-white/10 rounded-xl">
                        <span class="material-symbols-outlined text-2xl">fact_check</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg">Detalle de Inspección #<?= $verInspeccion['id_inspeccion'] ?></h3>
                        <p class="text-xs text-slate-300"><?= date('d/m/Y H:i', strtotime($verInspeccion['fecha'])) ?> hs - <?= htmlspecialchars($verInspeccion['patente']) ?> (<?= htmlspecialchars($verInspeccion['marca'] . ' ' . $verInspeccion['modelo']) ?>)</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <?php if (puedeAprobarChecklist() || isAdmin()): ?>
                    <a href="checklist_imprimir.php?id=<?= $verInspeccion['id_inspeccion'] ?>" target="_blank" class="px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white text-xs font-semibold rounded-xl flex items-center gap-1 transition-all">
                        <span class="material-symbols-outlined text-sm">print</span> Imprimir
                    </a>
                    <?php endif; ?>
                    <a href="?tab=historial" class="p-1.5 text-white/70 hover:text-white rounded-lg hover:bg-white/10 transition-all">
                        <span class="material-symbols-outlined">close</span>
                    </a>
                </div>
            </div>

            <!-- Body Modal -->
            <div class="p-6 overflow-y-auto space-y-6 flex-1 bg-surface">
                <!-- Tarjeta Resumen -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-white p-4 rounded-2xl border border-outline-variant shadow-sm">
                    <div>
                        <p class="text-[11px] font-semibold uppercase text-on-surface-variant">Equipo</p>
                        <p class="font-bold text-sm text-on-surface"><?= htmlspecialchars($verInspeccion['patente']) ?></p>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars($verInspeccion['marca']) ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase text-on-surface-variant">Operador / Inspector</p>
                        <p class="font-bold text-sm text-on-surface"><?= htmlspecialchars($verInspeccion['chofer_nombre'] ?: ($verInspeccion['firmado_por'] ?: 'No especificado')) ?></p>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars($verInspeccion['usuario_nombre']) ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase text-on-surface-variant">Horómetro</p>
                        <p class="font-bold text-sm text-primary"><?= $verInspeccion['horas_maquina'] ? number_format($verInspeccion['horas_maquina'], 1) . ' hs' : 'N/D' ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase text-on-surface-variant">Dictamen</p>
                        <?php if ($verInspeccion['estado'] === 'aprobado'): ?>
                        <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 bg-emerald-50 px-2 py-1 rounded-lg">
                            <span class="material-symbols-outlined text-sm">check_circle</span> APROBADO
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 text-xs font-bold text-amber-700 bg-amber-50 px-2 py-1 rounded-lg">
                            <span class="material-symbols-outlined text-sm">warning</span> <?= $verInspeccion['total_fallas'] ?> OBSERVACIÓN(ES)
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Observación General si existe -->
                <?php if (!empty($verInspeccion['observacion_general'])): ?>
                <div class="p-4 rounded-2xl bg-amber-50/70 border border-amber-200 text-amber-950">
                    <p class="text-xs font-bold uppercase text-amber-800 flex items-center gap-1 mb-1">
                        <span class="material-symbols-outlined text-sm">notes</span> Observaciones Generales de la Inspección:
                    </p>
                    <p class="text-sm"><?= nl2br(htmlspecialchars($verInspeccion['observacion_general'])) ?></p>
                </div>
                <?php endif; ?>

                <!-- Lista de Preguntas y Respuestas -->
                <div class="bg-white rounded-2xl border border-outline-variant shadow-sm overflow-hidden">
                    <div class="px-5 py-3.5 bg-slate-50 border-b border-outline-variant font-bold text-xs uppercase text-slate-600 flex items-center justify-between">
                        <span>Items Inspeccionados</span>
                        <span><?= count($verRespuestas) ?> items</span>
                    </div>
                    <div class="divide-y divide-outline-variant/60">
                        <?php foreach ($verRespuestas as $idx => $resp): ?>
                        <div class="p-4 <?= $resp['resultado'] === 'NO' ? 'bg-amber-50/40' : '' ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-slate-100 text-slate-600"><?= htmlspecialchars($resp['categoria']) ?></span>
                                    </div>
                                    <p class="text-sm font-semibold text-on-surface"><?= ($idx + 1) ?>. <?= htmlspecialchars($resp['pregunta_texto']) ?></p>

                                    <!-- Observación de Falla -->
                                    <?php if ($resp['resultado'] === 'NO' && !empty($resp['observacion'])): ?>
                                    <div class="mt-2.5 p-3 rounded-xl bg-amber-100/70 border border-amber-300/80 text-amber-900 text-xs">
                                        <p class="font-bold flex items-center gap-1 mb-0.5">
                                            <span class="material-symbols-outlined text-sm text-amber-700">report_problem</span>
                                            Falla / Observación Detectada:
                                        </p>
                                        <p><?= nl2br(htmlspecialchars($resp['observacion'])) ?></p>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Foto de Evidencia -->
                                    <?php if (!empty($resp['foto_path'])): ?>
                                    <div class="mt-3">
                                        <p class="text-xs font-semibold text-slate-500 mb-1 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">photo_camera</span> Foto de Evidencia:
                                        </p>
                                        <a href="../<?= htmlspecialchars($resp['foto_path']) ?>" target="_blank" class="inline-block relative group">
                                            <img src="../<?= htmlspecialchars($resp['foto_path']) ?>" alt="Evidencia" class="w-32 h-24 object-cover rounded-xl border border-slate-200 shadow-sm group-hover:opacity-90 transition-all">
                                            <div class="absolute inset-0 bg-black/30 rounded-xl opacity-0 group-hover:opacity-100 flex items-center justify-center transition-all text-white text-xs font-semibold">
                                                <span class="material-symbols-outlined text-base">zoom_in</span> Ver Foto
                                            </div>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="shrink-0">
                                    <?php if ($resp['resultado'] === 'SI'): ?>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                                        <span class="material-symbols-outlined text-sm">check</span> SÍ (OK)
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                                        <span class="material-symbols-outlined text-sm">close</span> NO (FALLA)
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Firma Digital del Operador si existe -->
                <?php if (!empty($verInspeccion['firma_digital'])): ?>
                <div class="p-4 bg-white rounded-2xl border border-outline-variant shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-bold uppercase text-slate-400">Firma del Operador / Chofer</p>
                        <p class="font-bold text-sm text-slate-900"><?= htmlspecialchars($verInspeccion['firmado_por'] ?: $verInspeccion['usuario_nombre']) ?></p>
                        <p class="text-xs text-slate-500">Registrada al completar la inspección</p>
                    </div>
                    <div class="bg-slate-50 p-2 rounded-xl border border-slate-200">
                        <img src="../<?= htmlspecialchars($verInspeccion['firma_digital']) ?>" alt="Firma Operador" class="h-14 w-auto max-w-[180px] object-contain">
                    </div>
                </div>
                <?php endif; ?>

                <!-- SECCIÓN DE APROBACIÓN POR EL INSPECTOR -->
                <?php if (!empty($verInspeccion['firma_inspector']) || ($verInspeccion['estado_aprobacion'] ?? '') === 'aprobado'): ?>
                <!-- YA APROBADO Y FIRMADO POR INSPECTOR -->
                <div class="p-5 bg-emerald-50/80 border border-emerald-200 rounded-3xl space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="w-9 h-9 rounded-xl bg-emerald-600 text-white flex items-center justify-center font-bold">
                                <span class="material-symbols-outlined text-lg">verified</span>
                            </span>
                            <div>
                                <span class="text-[10px] font-bold uppercase text-emerald-800 tracking-wider">Aprobado y Firmado por Inspector</span>
                                <p class="font-bold text-sm text-emerald-950"><?= htmlspecialchars($verInspeccion['inspector_nombre'] ?: 'Inspector Responsable') ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase bg-emerald-200 text-emerald-900">
                                <?= ($verInspeccion['estado_aprobacion'] ?? '') === 'rechazado' ? 'Rechazado' : 'Aprobado' ?>
                            </span>
                            <p class="text-[11px] text-emerald-700 mt-0.5"><?= !empty($verInspeccion['fecha_aprobacion']) ? date('d/m/Y H:i', strtotime($verInspeccion['fecha_aprobacion'])) . ' hs' : '' ?></p>
                        </div>
                    </div>
                    <?php if (!empty($verInspeccion['observacion_inspector'])): ?>
                    <div class="p-3 bg-white/80 rounded-xl text-xs text-emerald-900 border border-emerald-200/60">
                        <span class="font-bold block text-[10px] uppercase text-emerald-700">Observación del Inspector:</span>
                        <?= nl2br(htmlspecialchars($verInspeccion['observacion_inspector'])) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($verInspeccion['firma_inspector'])): ?>
                    <div class="bg-white p-2 rounded-2xl border border-emerald-200/80 flex items-center justify-between">
                        <span class="text-xs text-slate-500 font-semibold pl-2">Firma Digital del Inspector</span>
                        <img src="../<?= htmlspecialchars($verInspeccion['firma_inspector']) ?>" alt="Firma Inspector" class="h-14 w-auto max-w-[180px] object-contain">
                    </div>
                    <?php endif; ?>
                </div>
                <?php elseif (puedeAprobarChecklist()): ?>
                <!-- FORMULARIO PARA QUE EL INSPECTOR REVISE Y FIRME -->
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-primary/30 rounded-3xl p-6 shadow-sm space-y-4">
                    <div class="flex items-center gap-3 border-b border-primary/20 pb-3">
                        <div class="w-10 h-10 rounded-2xl bg-primary text-white flex items-center justify-center font-bold text-lg shadow-sm">
                            <span class="material-symbols-outlined">verified_user</span>
                        </div>
                        <div>
                            <h4 class="font-bold text-base text-slate-900">Aprobación y Firma del Inspector Responsable</h4>
                            <p class="text-xs text-slate-600">Revisión técnica de conformidad y firma digital de cierre.</p>
                        </div>
                    </div>

                    <form method="POST" action="checklist_maquinas.php" enctype="multipart/form-data" id="form-aprobacion-inspector" class="space-y-4">
                        <input type="hidden" name="action" value="aprobar_inspeccion">
                        <input type="hidden" name="id_inspeccion" value="<?= $verInspeccion['id_inspeccion'] ?>">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                    Inspector que Aprueba <span class="text-red-500">*</span>
                                </label>
                                <select name="id_inspector" id="select-inspector-aprobacion" required class="w-full px-3.5 py-2.5 text-sm bg-white border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary font-medium" onchange="actualizarNombreInspector(this)">
                                    <option value="">-- Seleccionar Inspector --</option>
                                    <?php foreach ($listaInspectores as $insp): 
                                        $nomComp = trim(($insp['nombre'] ?? '') . ' ' . ($insp['apellido'] ?? ''));
                                        $valName = $nomComp ?: $insp['username'];
                                        $displayLabel = $nomComp ? $nomComp . ' (@' . $insp['username'] . ')' : $insp['username'];
                                        $isSelected = ($insp['id_usuario'] == ($_SESSION['user_id'] ?? 0)) ? 'selected' : '';
                                        $firmaInsp = $insp['firma_digital'] ?? '';
                                    ?>
                                    <option value="<?= $insp['id_usuario'] ?>" data-nombre="<?= htmlspecialchars($valName) ?>" data-firma="<?= htmlspecialchars($firmaInsp) ?>" <?= $isSelected ?>>
                                        <?= htmlspecialchars($displayLabel) ?><?= !empty($firmaInsp) ? ' ✍️ [Firma precargada]' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="inspector_nombre" id="input-inspector-nombre" value="<?= htmlspecialchars($nombreOperadorActual) ?>">
                            </div>

                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                    Dictamen del Inspector <span class="text-red-500">*</span>
                                </label>
                                <select name="decision" required class="w-full px-3.5 py-2.5 text-sm bg-white border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary font-bold">
                                    <option value="aprobado" class="text-emerald-700 font-bold">✅ APROBADO / CONFORME</option>
                                    <option value="rechazado" class="text-red-700 font-bold">❌ RECHAZADO / FUERA DE SERVICIO</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">Observaciones del Inspector</label>
                            <textarea name="observacion_inspector" rows="2" placeholder="Indicaciones, observaciones de seguridad o detalles de conformidad..." class="w-full p-3 text-sm bg-white border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                        </div>

                        <!-- SECCIÓN DE FIRMA DIGITAL DEL INSPECTOR -->
                        <div>
                            <!-- Banner / Preview de Firma Precargada en el Perfil de Usuario -->
                            <div id="box-firma-inspector-guardada" class="hidden p-4 rounded-2xl bg-emerald-50 border border-emerald-300 text-emerald-950 mb-3 shadow-sm">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-emerald-600 text-white rounded-xl flex items-center justify-center shrink-0">
                                            <span class="material-symbols-outlined text-2xl">verified</span>
                                        </div>
                                        <div>
                                            <p class="font-bold text-sm text-emerald-900 flex items-center gap-1.5">
                                                <span>Firma Digital Precargada</span>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-emerald-200 text-emerald-800">Automática</span>
                                            </p>
                                            <p class="text-xs text-emerald-700">Se aplicará automáticamente su firma de usuario sin necesidad de volver a dibujar.</p>
                                        </div>
                                    </div>
                                    <div class="bg-white p-1.5 rounded-xl border border-emerald-200 shadow-sm shrink-0 self-start sm:self-center">
                                        <img id="img-firma-inspector-guardada" src="" alt="Firma Inspector" class="h-12 w-auto max-w-[160px] object-contain">
                                    </div>
                                </div>
                                <div class="mt-3 pt-2.5 border-t border-emerald-200/80 flex items-center justify-between">
                                    <span class="text-[11px] text-emerald-800 italic">✅ Lista para firmar con un solo clic</span>
                                    <button type="button" onclick="toggleDibujoFirmaInspector()" id="btn-toggle-dibujo-insp" class="text-xs text-emerald-800 hover:text-emerald-950 font-semibold underline flex items-center gap-1">
                                        ✏️ Dibujar otra firma manual para esta ocasión (opcional)
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" name="firma_inspector_guardada" id="input-firma-inspector-guardada" value="">

                            <!-- Canvas de Firma Manual (Se muestra si no hay firma precargada o si decide dibujar una manual) -->
                            <div id="box-firma-inspector-manual">
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                                        Firma Digital del Inspector <span class="text-red-500">*</span> <span class="text-slate-400 font-normal">(Dibuje con mouse o dedo)</span>
                                    </label>
                                    <button type="button" onclick="limpiarCanvasFirmaInspector()" class="text-xs text-red-600 hover:text-red-700 font-bold flex items-center gap-1 transition-all">
                                        <span class="material-symbols-outlined text-sm">cleaning_services</span> Limpiar
                                    </button>
                                </div>
                                <div class="border-2 border-dashed border-primary/40 rounded-2xl bg-white p-2 relative">
                                    <canvas id="canvas-firma-inspector" width="600" height="140" class="w-full h-32 bg-white rounded-xl touch-none cursor-crosshair border border-slate-200 shadow-inner"></canvas>
                                    <input type="file" name="firma_inspector_archivo" id="input-firma-inspector-file" class="hidden" accept="image/png">
                                    <input type="hidden" name="firma_inspector_base64" id="input-firma-inspector-base64" value="">
                                    <div id="hint-firma-inspector" class="absolute inset-0 flex items-center justify-center pointer-events-none text-slate-300 text-sm font-semibold select-none">
                                        ✍️ Firme aquí como Inspector
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" id="btn-submit-aprobar" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm rounded-xl shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-lg">verified</span>
                            <span>Aprobar y Firmar Checklist</span>
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <!-- AVISO INFORMATIVO PARA OPERADORES (SIN PERMISOS DE FIRMA) -->
                <div class="p-5 bg-amber-50 border border-amber-200 rounded-3xl flex items-center gap-3.5">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center font-bold text-lg shrink-0">
                        <span class="material-symbols-outlined">pending_actions</span>
                    </div>
                    <div>
                        <h4 class="font-bold text-sm text-amber-950">Pendiente de Aprobación por Inspector</h4>
                        <p class="text-xs text-amber-800">Esta inspección aún no ha sido revisada ni firmada por el Inspector o Administrador autorizado.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer Modal -->
            <div class="px-6 py-4 bg-white border-t border-outline-variant flex items-center justify-end gap-3">
                <?php if (puedeAprobarChecklist() || isAdmin()): ?>
                <a href="checklist_imprimir.php?id=<?= $verInspeccion['id_inspeccion'] ?>" target="_blank" class="px-4 py-2 bg-slate-900 text-white rounded-xl text-xs font-semibold flex items-center gap-1.5 hover:bg-slate-800 transition-all">
                    <span class="material-symbols-outlined text-sm">print</span> Imprimir Reporte
                </a>
                <?php endif; ?>
                <a href="?tab=historial" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl text-xs font-semibold hover:bg-slate-200 transition-all">
                    Cerrar
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- TAB 2: NUEVA INSPECCIÓN (ASISTENTE INTERACTIVO SÍ / NO)       -->
    <!-- ============================================================= -->
    <?php if ($activeTab === 'nueva'): ?>
    <div class="max-w-4xl mx-auto space-y-6">
        <form method="POST" action="checklist_maquinas.php" enctype="multipart/form-data" id="form-inspeccion" class="space-y-6">
            <input type="hidden" name="action" value="guardar_inspeccion">

            <!-- Paso 1: Datos de Encabezado -->
            <div class="bg-white p-6 rounded-3xl border border-outline-variant/70 shadow-sm space-y-4">
                <div class="flex items-center gap-3 border-b border-outline-variant/60 pb-3">
                    <div class="w-9 h-9 rounded-xl bg-primary text-white flex items-center justify-center font-bold text-sm">1</div>
                    <div>
                        <h2 class="font-bold text-base text-on-surface">Datos del Equipo y Operador</h2>
                        <p class="text-xs text-on-surface-variant">Seleccione el autoelevador a inspeccionar y los datos de control.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Selector de Máquina -->
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                            Máquina / Autoelevador <span class="text-red-500">*</span>
                        </label>
                        <select name="id_camion" id="select-maquina" required class="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary font-medium" onchange="actualizarInfoMaquina(this)">
                            <option value="">-- Seleccionar máquina --</option>
                            <?php foreach ($listaCamiones as $c): ?>
                            <option value="<?= $c['id_camion'] ?>" data-tipo="<?= htmlspecialchars($c['tipo']) ?>" data-horas="<?= $c['horas_actuales'] ?>">
                                <?= $c['tipo'] === 'autoelevador' ? '🚜' : '🚛' ?> <?= htmlspecialchars($c['patente']) ?> - <?= htmlspecialchars($c['marca'] . ' ' . $c['modelo']) ?> (<?= ucfirst($c['tipo']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Operador / Chofer automático del usuario logueado -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Operador / Conductor</label>
                        <div class="w-full px-3.5 py-2.5 text-sm bg-slate-100 border border-outline-variant rounded-xl text-slate-800 font-bold flex items-center gap-2">
                            <span class="material-symbols-outlined text-base text-primary">person</span>
                            <span><?= htmlspecialchars($nombreOperadorActual) ?></span>
                        </div>
                        <input type="hidden" name="id_chofer" value="<?= $choferIdActual ?: '' ?>">
                        <input type="hidden" name="firmado_por" value="<?= htmlspecialchars($nombreOperadorActual) ?>">
                    </div>

                    <!-- Horómetro -->
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Horómetro Actual (hs)</label>
                        <input type="number" step="0.1" name="horas_maquina" id="input-horas" placeholder="Ej: 1450.5" class="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    </div>
                </div>

                <div class="pt-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Fecha y Hora de Inspección</label>
                    <input type="datetime-local" name="fecha" value="<?= date('Y-m-d\TH:i') ?>" class="w-full sm:w-72 px-3.5 py-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary">
                </div>
            </div>

            <!-- Paso 2: Barra Flotante de Progreso -->
            <div class="sticky top-16 z-30 bg-slate-900 text-white p-4 rounded-2xl shadow-xl flex flex-col sm:flex-row items-center justify-between gap-3 border border-white/10">
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <span class="material-symbols-outlined text-amber-400">check_box</span>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-300">Progreso Checklist</span>
                            <span id="txt-progreso" class="text-xs font-bold px-2 py-0.5 rounded-full bg-white/10">0 / <?= count($preguntasActivas) ?></span>
                        </div>
                        <div class="w-48 sm:w-64 bg-white/20 h-2 rounded-full overflow-hidden mt-1.5">
                            <div id="barra-progreso" class="bg-emerald-400 h-full rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                    <span id="badge-ok" class="px-3 py-1 rounded-xl bg-emerald-500/20 text-emerald-300 font-bold text-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">check</span> 0 OK
                    </span>
                    <span id="badge-falla" class="px-3 py-1 rounded-xl bg-amber-500/20 text-amber-300 font-bold text-xs flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">warning</span> 0 Obs
                    </span>
                    <button type="button" onclick="marcarTodosSi()" class="px-3 py-1 bg-white/10 hover:bg-white/20 text-white rounded-xl text-xs font-semibold transition-all">
                        Marcar Todo SÍ
                    </button>
                </div>
            </div>

            <!-- Paso 3: Preguntas por Categoría -->
            <div class="space-y-6">
                <?php $globalIdx = 0; ?>
                <?php foreach ($preguntasPorCategoria as $categoria => $preguntasCat): ?>
                <div class="bg-white rounded-3xl border border-outline-variant/70 shadow-sm overflow-hidden">
                    <!-- Encabezado de Categoría -->
                    <div class="px-6 py-3.5 bg-slate-50 border-b border-outline-variant flex items-center gap-2.5">
                        <span class="material-symbols-outlined text-primary text-xl">category</span>
                        <h3 class="font-bold text-sm text-slate-800 uppercase tracking-wide"><?= htmlspecialchars($categoria) ?></h3>
                        <span class="text-xs text-slate-400 font-semibold ml-auto"><?= count($preguntasCat) ?> preguntas</span>
                    </div>

                    <!-- Lista de Preguntas -->
                    <div class="divide-y divide-outline-variant/60">
                        <?php foreach ($preguntasCat as $p): ?>
                        <?php $globalIdx++; ?>
                        <div class="p-5 transition-colors card-pregunta" id="card-preg-<?= $p['id_pregunta'] ?>">
                            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-start gap-3">
                                        <span class="w-7 h-7 rounded-xl bg-slate-100 text-slate-700 font-black text-xs flex items-center justify-center shrink-0 mt-0.5 border border-slate-200"><?= $globalIdx ?></span>
                                        <div class="space-y-1.5 flex-1">
                                            <p class="text-sm md:text-base font-bold text-slate-900 leading-snug">
                                                <?= htmlspecialchars($p['pregunta']) ?>
                                            </p>
                                            <?php if (!empty($p['descripcion_ayuda'])): ?>
                                            <div class="bg-blue-50/80 border border-blue-200 rounded-xl p-2.5 flex items-start gap-2 text-blue-950">
                                                <span class="material-symbols-outlined text-sm text-blue-600 shrink-0 mt-0.5">info</span>
                                                <p class="text-xs text-blue-900 font-medium leading-relaxed">
                                                    <strong class="text-blue-950 font-bold">¿Qué verificar?:</strong> <?= htmlspecialchars($p['descripcion_ayuda']) ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Botones Interactivos SÍ / NO -->
                                <div class="flex items-center gap-2 shrink-0 self-end md:self-center">
                                    <label class="btn-toggle-si relative cursor-pointer select-none">
                                        <input type="radio" name="respuestas[<?= $p['id_pregunta'] ?>]" value="SI" class="sr-only peer" onchange="seleccionarOpcion(<?= $p['id_pregunta'] ?>, 'SI')" required>
                                        <div class="px-5 py-2.5 rounded-2xl border-2 border-slate-200 text-slate-700 font-bold text-xs flex items-center gap-1.5 transition-all peer-checked:bg-emerald-500 peer-checked:border-emerald-500 peer-checked:text-white peer-checked:shadow-md hover:border-emerald-300">
                                            <span class="material-symbols-outlined text-sm">check_circle</span> SÍ (OK)
                                        </div>
                                    </label>

                                    <label class="btn-toggle-no relative cursor-pointer select-none">
                                        <input type="radio" name="respuestas[<?= $p['id_pregunta'] ?>]" value="NO" class="sr-only peer" onchange="seleccionarOpcion(<?= $p['id_pregunta'] ?>, 'NO')">
                                        <div class="px-5 py-2.5 rounded-2xl border-2 border-slate-200 text-slate-700 font-bold text-xs flex items-center gap-1.5 transition-all peer-checked:bg-amber-500 peer-checked:border-amber-500 peer-checked:text-white peer-checked:shadow-md hover:border-amber-300">
                                            <span class="material-symbols-outlined text-sm">error</span> NO (FALLA)
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Panel Desplegable para NO (Observación + Foto) -->
                            <div id="panel-obs-<?= $p['id_pregunta'] ?>" class="hidden mt-4 pt-4 border-t border-amber-200/80 bg-amber-50/50 p-4 rounded-2xl animate-fade-in space-y-3">
                                <div class="flex items-center gap-2 text-amber-900 font-bold text-xs uppercase tracking-wide">
                                    <span class="material-symbols-outlined text-sm text-amber-600">report</span>
                                    <span>Registrar Observación / Falla encontrada</span>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">
                                        ¿Cuál es el problema u observación? <span class="text-red-500">*</span>
                                    </label>
                                    <textarea name="observaciones[<?= $p['id_pregunta'] ?>]" id="obs-<?= $p['id_pregunta'] ?>" rows="2" placeholder="Ej: Neumático con corte lateral y presión en 20 PSI..." class="w-full p-3 text-sm bg-white border border-amber-300 rounded-xl focus:ring-2 focus:ring-amber-400 focus:border-amber-500"></textarea>
                                </div>

                                <!-- Carga de Fotografía -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1 flex items-center justify-between">
                                        <span>Fotografía de Evidencia (Opcional)</span>
                                        <span class="text-[11px] text-slate-400">Cámara o galería</span>
                                    </label>
                                    <div class="flex items-center gap-3">
                                        <label class="flex-1 cursor-pointer flex items-center justify-center gap-2 px-4 py-3 bg-white border-2 border-dashed border-amber-300 rounded-xl hover:bg-amber-50 transition-all text-amber-800 text-xs font-semibold">
                                            <span class="material-symbols-outlined text-lg">add_a_photo</span>
                                            <span>Tomar Foto / Seleccionar Imagen</span>
                                            <input type="file" name="fotos[<?= $p['id_pregunta'] ?>]" accept="image/*" capture="environment" class="hidden" onchange="previsualizarFoto(this, <?= $p['id_pregunta'] ?>)">
                                        </label>

                                        <!-- Vista Previa de Imagen -->
                                        <div id="preview-box-<?= $p['id_pregunta'] ?>" class="hidden relative">
                                            <img id="preview-img-<?= $p['id_pregunta'] ?>" class="w-14 h-14 object-cover rounded-xl border-2 border-amber-400 shadow-sm" src="" alt="Preview">
                                            <button type="button" onclick="quitarFoto(<?= $p['id_pregunta'] ?>)" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white rounded-full flex items-center justify-center text-xs shadow-md">✕</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Finalizar y Firma -->
            <div class="bg-white p-6 rounded-3xl border border-outline-variant/70 shadow-sm space-y-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Observación General (Opcional)</label>
                    <textarea name="observacion_general" rows="2" placeholder="Comentarios generales sobre la limpieza, estado general o requerimiento de service preventivo..." class="w-full p-3.5 text-sm bg-slate-50 border border-outline-variant rounded-xl focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                </div>

                <!-- Firma Digital con Canvas Táctil -->
                <div class="border-t border-outline-variant/60 pt-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">
                            Firma Digital del Inspector / Operador <span class="text-slate-400 font-normal">(Dibuje con dedo o mouse)</span>
                        </label>
                        <button type="button" onclick="limpiarCanvasFirma()" class="text-xs text-red-600 hover:text-red-700 font-bold flex items-center gap-1 transition-all">
                            <span class="material-symbols-outlined text-sm">cleaning_services</span> Limpiar Firma
                        </button>
                    </div>
                    <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-slate-50 p-2 relative">
                        <canvas id="canvas-firma" width="600" height="150" class="w-full h-36 bg-white rounded-xl touch-none cursor-crosshair border border-slate-200 shadow-inner"></canvas>
                        <input type="file" name="firma_archivo" id="input-firma-file" class="hidden" accept="image/png">
                        <input type="hidden" name="firma_digital_base64" id="input-firma-base64" value="">
                        <div id="hint-firma" class="absolute inset-0 flex items-center justify-center pointer-events-none text-slate-300 text-sm font-semibold select-none">
                            ✍️ Firme aquí con el dedo o mouse
                        </div>
                    </div>
                </div>

                <div class="pt-2 flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-outline-variant/60">
                    <div class="text-xs text-slate-500">
                        Al finalizar, la inspección quedará registrada según la Resolución SRT 960/15 con fecha, hora y firma digital.
                    </div>
                    <button type="submit" class="w-full sm:w-auto px-8 py-3.5 bg-primary hover:bg-primary/90 text-white font-bold text-sm rounded-2xl shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <span>Finalizar y Guardar Inspección</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- TAB 3: BANCO DE PREGUNTAS (ORDEN PERSONALIZADO & CONFIGURACIÓN) -->
    <!-- ============================================================= -->
    <?php if ($activeTab === 'preguntas'): ?>
    <div class="space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-3xl border border-outline-variant shadow-sm">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="p-2 rounded-xl bg-primary/10 text-primary font-bold">
                        <span class="material-symbols-outlined text-xl">low_priority</span>
                    </span>
                    <h2 class="font-bold text-lg text-on-surface">Banco de Preguntas para Inspección</h2>
                </div>
                <p class="text-xs text-on-surface-variant">
                    Ordene las preguntas a su gusto: <strong>arrastre con el mouse</strong> con el ícono <span class="material-symbols-outlined text-sm inline-block align-middle text-slate-500">drag_indicator</span>, use las flechas <strong>▲ / ▼</strong> o escriba los números de orden y haga clic en <strong>Guardar Nuevo Orden</strong>.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <form method="POST" action="checklist_maquinas.php?tab=preguntas" class="inline" onsubmit="return confirm('¿Desea renumerar automáticamente todas las preguntas en intervalos de 10 (10, 20, 30...)?');">
                    <input type="hidden" name="action" value="renumerar_orden_automatico">
                    <button type="submit" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition-all flex items-center gap-1.5" title="Espaciar en decenas para facilitar reordenamientos">
                        <span class="material-symbols-outlined text-sm">format_list_numbered</span> Renumerar (10, 20...)
                    </button>
                </form>
                <button type="button" onclick="document.getElementById('form-orden-preguntas').submit();" id="btn-guardar-orden-top" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 shadow-sm">
                    <span class="material-symbols-outlined text-sm">save</span> Guardar Orden
                </button>
                <button type="button" onclick="abrirModalPregunta()" class="px-4 py-2 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition-all flex items-center gap-1.5 shadow-sm">
                    <span class="material-symbols-outlined text-sm">add</span> Nueva Pregunta
                </button>
            </div>
        </div>

        <!-- Formulario para Guardar Orden Masivo -->
        <form method="POST" action="checklist_maquinas.php?tab=preguntas" id="form-orden-preguntas">
            <input type="hidden" name="action" value="guardar_orden_preguntas">

            <div class="bg-white rounded-3xl border border-outline-variant shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 bg-slate-50 border-b border-outline-variant/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-2 text-slate-600 font-semibold">
                        <span class="material-symbols-outlined text-base text-primary">touch_app</span>
                        <span>Arrastre cualquier fila para moverla arriba o abajo. Los números se ajustarán en tiempo real.</span>
                    </div>
                    <div id="badge-cambios-orden" class="hidden text-amber-700 bg-amber-50 border border-amber-300 font-bold px-3 py-1 rounded-full text-xs flex items-center gap-1.5 animate-pulse">
                        <span class="material-symbols-outlined text-sm">info</span> Hay cambios de orden sin guardar
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-on-surface border-collapse" id="tabla-preguntas-orden">
                        <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-700 border-b border-outline-variant">
                            <tr>
                                <th class="py-3.5 px-4 w-44 text-center">⇅ Posición / Orden</th>
                                <th class="py-3.5 px-4">Categoría</th>
                                <th class="py-3.5 px-4">Pregunta / Ítem a Controlar</th>
                                <th class="py-3.5 px-4">Tipo Máquina</th>
                                <th class="py-3.5 px-4 text-center">Estado</th>
                                <th class="py-3.5 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/60" id="tbody-preguntas">
                            <?php if (empty($todasPreguntas)): ?>
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-500">No hay preguntas cargadas en el banco.</td>
                            </tr>
                            <?php else: ?>
                            <?php $totalP = count($todasPreguntas); foreach ($todasPreguntas as $idx => $p): ?>
                            <tr draggable="true" 
                                class="fila-pregunta hover:bg-blue-50/50 transition-colors cursor-move <?= $p['activo'] ? '' : 'opacity-60 bg-slate-50/50' ?>" 
                                data-id="<?= $p['id_pregunta'] ?>"
                                data-index="<?= $idx ?>">
                                <!-- Columna de Orden y Flechas -->
                                <td class="py-3 px-4 text-center whitespace-nowrap bg-slate-50/50">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <span class="cursor-grab active:cursor-grabbing text-slate-400 hover:text-primary transition-colors p-1" title="Arrastrar fila para reordenar">
                                            <span class="material-symbols-outlined text-lg">drag_indicator</span>
                                        </span>
                                        
                                        <!-- Flecha Arriba -->
                                        <button type="button" 
                                            onclick="moverPreguntaDirecto(<?= $p['id_pregunta'] ?>, 'up')" 
                                            class="w-7 h-7 rounded-lg border border-slate-200 bg-white hover:bg-primary hover:text-white text-slate-600 flex items-center justify-center transition-all <?= ($idx === 0) ? 'opacity-30 cursor-not-allowed pointer-events-none' : '' ?>" 
                                            title="Subir 1 posición">
                                            <span class="material-symbols-outlined text-sm font-bold">arrow_upward</span>
                                        </button>

                                        <!-- Flecha Abajo -->
                                        <button type="button" 
                                            onclick="moverPreguntaDirecto(<?= $p['id_pregunta'] ?>, 'down')" 
                                            class="w-7 h-7 rounded-lg border border-slate-200 bg-white hover:bg-primary hover:text-white text-slate-600 flex items-center justify-center transition-all <?= ($idx === $totalP - 1) ? 'opacity-30 cursor-not-allowed pointer-events-none' : '' ?>" 
                                            title="Bajar 1 posición">
                                            <span class="material-symbols-outlined text-sm font-bold">arrow_downward</span>
                                        </button>

                                        <!-- Input numérico directo -->
                                        <input type="number" 
                                            name="ordenes[<?= $p['id_pregunta'] ?>]" 
                                            value="<?= $p['orden'] ?>" 
                                            class="input-orden-num w-16 text-center font-mono font-bold text-xs bg-white border border-slate-300 rounded-lg py-1 px-1.5 focus:ring-2 focus:ring-primary/40 focus:border-primary shadow-inner" 
                                            oninput="notificarCambioOrden()"
                                            title="Escriba el número de orden deseado">
                                    </div>
                                </td>

                                <td class="py-3 px-4">
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 inline-block border border-slate-200">
                                        <?= htmlspecialchars($p['categoria']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <p class="font-semibold text-on-surface"><?= htmlspecialchars($p['pregunta']) ?></p>
                                    <?php if (!empty($p['descripcion_ayuda'])): ?>
                                    <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($p['descripcion_ayuda']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="text-xs font-semibold text-slate-600 uppercase bg-slate-100 px-2 py-0.5 rounded"><?= htmlspecialchars($p['tipo_maquina']) ?></span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <button type="button" onclick="toggleEstadoPregunta(<?= $p['id_pregunta'] ?>)" class="px-2.5 py-0.5 rounded-full text-xs font-bold transition-all <?= $p['activo'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' ?>">
                                        <?= $p['activo'] ? 'Activo' : 'Inactivo' ?>
                                    </button>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" onclick='editarPregunta(<?= json_encode($p) ?>)' class="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition-all" title="Editar">
                                            <span class="material-symbols-outlined text-base">edit</span>
                                        </button>
                                        <button type="button" onclick="eliminarPreguntaDirecto(<?= $p['id_pregunta'] ?>)" class="p-1.5 text-red-500 hover:bg-red-50 rounded-lg transition-all" title="Eliminar">
                                            <span class="material-symbols-outlined text-base">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Barra inferior para Guardar Cambios -->
                <div class="p-4 bg-slate-50 border-t border-outline-variant flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="text-xs text-slate-500">
                        Total de preguntas en catálogo: <strong class="text-slate-800"><?= count($todasPreguntas) ?></strong>
                    </div>
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow hover:shadow-md transition-all flex items-center justify-center gap-1.5">
                        <span class="material-symbols-outlined text-base">save</span>
                        <span>Guardar Orden Personalizado</span>
                    </button>
                </div>
            </div>
        </form>

        <!-- Formulario Oculto Auxiliar para Acciones Individuales de Preguntas -->
        <form method="POST" action="checklist_maquinas.php?tab=preguntas" id="form-aux-pregunta" class="hidden">
            <input type="hidden" name="action" id="aux-action" value="">
            <input type="hidden" name="id_pregunta" id="aux-id-pregunta" value="">
            <input type="hidden" name="direccion" id="aux-direccion" value="">
        </form>
    </div>

    <!-- MODAL CREAR / EDITAR PREGUNTA -->
    <div id="modal-pregunta" class="fixed inset-0 z-50 bg-black/60 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl max-w-lg w-full shadow-2xl overflow-hidden animate-scale-in">
            <form method="POST" id="form-modal-pregunta">
                <input type="hidden" name="action" id="modal-action" value="crear_pregunta">
                <input type="hidden" name="id_pregunta" id="modal-id-pregunta" value="">

                <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between">
                    <h3 class="font-bold text-base" id="modal-titulo">Nueva Pregunta de Inspección</h3>
                    <button type="button" onclick="cerrarModalPregunta()" class="text-white/70 hover:text-white">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <!-- Categoría -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Categoría</label>
                        <select name="categoria" id="modal-categoria" class="w-full p-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl" onchange="toggleNuevaCat(this.value)">
                            <?php foreach ($categoriasExistentes as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                            <option value="__nueva__">+ Crear Nueva Categoría...</option>
                        </select>
                        <input type="text" name="categoria_nueva" id="modal-categoria-nueva" placeholder="Nombre de la nueva categoría" class="w-full mt-2 p-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl hidden">
                    </div>

                    <!-- Pregunta -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Texto de la Pregunta / Control <span class="text-red-500">*</span></label>
                        <textarea name="pregunta" id="modal-texto-pregunta" required rows="2" placeholder="Ej: Ruedas: banda de rodaje, presión, desgaste..." class="w-full p-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl"></textarea>
                    </div>

                    <!-- Ayuda -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Guía / Ayuda para el Operador (Opcional)</label>
                        <input type="text" name="descripcion_ayuda" id="modal-ayuda" placeholder="Ej: Verificar que no tenga cortes laterales ni clavos..." class="w-full p-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Tipo Máquina</label>
                            <select name="tipo_maquina" id="modal-tipo" class="w-full p-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl">
                                <option value="autoelevador">Autoelevador</option>
                                <option value="camion">Camión</option>
                                <option value="maquinaria">Maquinaria General</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-700 mb-1">Orden</label>
                            <input type="number" name="orden" id="modal-orden" value="10" class="w-full p-2.5 text-sm bg-slate-50 border border-outline-variant rounded-xl">
                        </div>
                    </div>

                    <div id="box-activo" class="hidden">
                        <label class="flex items-center gap-2 text-sm font-semibold text-slate-700 cursor-pointer">
                            <input type="checkbox" name="activo" id="modal-activo" value="1" checked class="rounded text-primary focus:ring-primary">
                            <span>Pregunta Activa en Checklist</span>
                        </label>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 border-t border-outline-variant flex items-center justify-end gap-3">
                    <button type="button" onclick="cerrarModalPregunta()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-200 rounded-xl transition-all">Cancelar</button>
                    <button type="submit" class="px-5 py-2 text-xs font-bold text-white bg-primary hover:bg-primary/90 rounded-xl transition-all">Guardar Pregunta</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- TAB 4: ESTADO DE MÁQUINAS / AUTOELEVADORES                    -->
    <!-- ============================================================= -->
    <?php if ($activeTab === 'maquinas'): ?>
    <div class="space-y-6">
        <div class="bg-white p-6 rounded-3xl border border-outline-variant shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="font-bold text-lg text-on-surface">Monitoreo de Autoelevadores y Unidades con Checklist</h2>
                <p class="text-xs text-on-surface-variant">Estado actual de la flota y última inspección realizada.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?= BASE_URL ?>/admin/camiones.php" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl flex items-center gap-1.5 transition-all">
                    <span class="material-symbols-outlined text-sm">tune</span> Configurar Unidades
                </a>
                <a href="?tab=nueva" class="px-4 py-2 bg-primary text-white text-xs font-bold rounded-xl flex items-center gap-1.5 hover:bg-primary/90 transition-all">
                    <span class="material-symbols-outlined text-sm">add_task</span> Nueva Inspección
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($listaCamiones as $m): ?>
            <?php
            // Obtener última inspección de esta máquina
            $stmtUlt = $db->prepare("SELECT i.*, u.username FROM checklist_inspecciones i LEFT JOIN usuarios u ON i.id_usuario = u.id_usuario WHERE i.id_camion = ? ORDER BY i.fecha DESC LIMIT 1");
            $stmtUlt->execute([$m['id_camion']]);
            $ultInsp = $stmtUlt->fetch();
            ?>
            <div class="bg-white p-5 rounded-3xl border border-outline-variant shadow-sm hover:shadow-md transition-all flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl bg-slate-100 flex items-center justify-center text-primary">
                                <span class="material-symbols-outlined text-2xl"><?= $m['tipo'] === 'autoelevador' ? 'forklift' : 'local_shipping' ?></span>
                            </div>
                            <div>
                                <h3 class="font-bold text-base text-on-surface"><?= htmlspecialchars($m['patente']) ?></h3>
                                <p class="text-xs text-on-surface-variant"><?= htmlspecialchars($m['marca'] . ' ' . $m['modelo']) ?></p>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase <?= $m['estado'] === 'activo' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
                            <?= htmlspecialchars($m['estado']) ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 my-3 p-3 bg-slate-50 rounded-2xl text-xs">
                        <div>
                            <span class="text-slate-400 block text-[10px] uppercase font-bold">Horómetro / Odómetro</span>
                            <span class="font-bold text-slate-800"><?= $m['horas_actuales'] ? number_format($m['horas_actuales'], 1) . ' hs' : number_format($m['kilometraje_actual'], 0) . ' km' ?></span>
                        </div>
                        <div>
                            <span class="text-slate-400 block text-[10px] uppercase font-bold">Tipo Equipo</span>
                            <span class="font-bold text-slate-800 uppercase"><?= htmlspecialchars($m['tipo']) ?></span>
                        </div>
                    </div>

                    <!-- Último Checklist -->
                    <div class="text-xs border-t border-outline-variant/60 pt-3 mt-2">
                        <?php if ($ultInsp): ?>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-slate-500">Última Inspección:</span>
                            <span class="font-semibold text-slate-800"><?= date('d/m/Y', strtotime($ultInsp['fecha'])) ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Resultado:</span>
                            <?php if ($ultInsp['estado'] === 'aprobado'): ?>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">100% CONFORME</span>
                            <?php else: ?>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800"><?= $ultInsp['total_fallas'] ?> OBSERVACIÓN(ES)</span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-slate-400 italic">Sin inspecciones registradas aún.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pt-4 mt-3 border-t border-outline-variant/60 flex items-center justify-between">
                    <?php if ($ultInsp): ?>
                    <a href="?tab=historial&ver=<?= $ultInsp['id_inspeccion'] ?>" class="text-xs font-bold text-blue-600 hover:underline">Ver Último</a>
                    <?php else: ?>
                    <span></span>
                    <?php endif; ?>
                    <a href="?tab=nueva" class="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">add</span> Inspeccionar
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================= -->
<!-- MODAL MODERNO DE CONFIRMACIÓN DE ELIMINACIÓN                 -->
<!-- ============================================================= -->
<div id="modalConfirmarEliminar" class="fixed inset-0 z-[100] bg-slate-950/70 backdrop-blur-md hidden items-center justify-center p-4 transition-all duration-300">
    <div class="bg-white rounded-[2rem] max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-100 animate-scale-in text-center relative overflow-hidden">
        <!-- Decoración de fondo sutil -->
        <div class="absolute -top-16 -right-16 w-36 h-36 bg-red-100 rounded-full blur-2xl pointer-events-none opacity-60"></div>
        <div class="absolute -bottom-16 -left-16 w-36 h-36 bg-amber-100 rounded-full blur-2xl pointer-events-none opacity-40"></div>

        <!-- Botón cerrar X -->
        <button type="button" onclick="cerrarModalEliminarInspeccion()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-2 rounded-xl hover:bg-slate-100/80 transition-all cursor-pointer">
            <span class="material-symbols-outlined text-lg">close</span>
        </button>

        <!-- Icono con halo animado -->
        <div class="w-18 h-18 rounded-3xl bg-gradient-to-br from-red-50 to-red-100 text-red-600 flex items-center justify-center mx-auto mb-4 ring-8 ring-red-50/70 shadow-sm">
            <span class="material-symbols-outlined text-3xl animate-bounce" style="animation-duration: 2s;">delete_forever</span>
        </div>
        
        <h3 class="text-2xl font-black text-slate-900 mb-1 tracking-tight">¿Eliminar Inspección?</h3>
        <p class="text-xs text-slate-500 mb-5 leading-relaxed">Esta acción borrará permanentemente este registro, respuestas técnicas, firmas y fotos adjuntas.</p>
        
        <!-- Ficha Resumen de la Inspección a Eliminar -->
        <div class="bg-slate-50/90 rounded-2xl p-4 border border-slate-200/80 text-left mb-4 space-y-2.5 shadow-inner">
            <div class="flex items-center justify-between text-xs">
                <span class="text-slate-400 font-bold uppercase text-[10px] tracking-wider">Nº Registro:</span>
                <span id="delModalIdBadge" class="font-mono font-bold text-red-600 bg-red-50 border border-red-200/80 px-2.5 py-0.5 rounded-lg text-xs">#0</span>
            </div>
            <div class="flex items-center justify-between text-xs">
                <span class="text-slate-400 font-bold uppercase text-[10px] tracking-wider">Equipo / Máquina:</span>
                <span id="delModalEquipoBadge" class="font-bold text-slate-800 text-xs truncate max-w-[200px]">-</span>
            </div>
            <div class="flex items-center justify-between text-xs">
                <span class="text-slate-400 font-bold uppercase text-[10px] tracking-wider">Fecha y Operador:</span>
                <span id="delModalInfoBadge" class="text-slate-600 font-medium text-xs truncate max-w-[200px]">-</span>
            </div>
        </div>

        <div class="p-3 bg-amber-50/90 border border-amber-200 rounded-xl mb-6 flex items-center gap-2.5 text-left">
            <span class="material-symbols-outlined text-amber-600 text-lg shrink-0">warning</span>
            <p class="text-[11px] text-amber-900 font-semibold leading-tight">Acción irreversible. No se podrá recuperar.</p>
        </div>

        <!-- Formulario POST -->
        <form method="POST" id="formEliminarInspeccionModal" class="m-0 p-0">
            <input type="hidden" name="action" value="eliminar_inspeccion">
            <input type="hidden" name="id_inspeccion" id="delModalIdInput" value="">
            
            <div class="flex items-center gap-3">
                <button type="button" onclick="cerrarModalEliminarInspeccion()" class="flex-1 py-3.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-2xl transition-all cursor-pointer">
                    Cancelar
                </button>
                <button type="submit" class="flex-1 py-3.5 px-4 bg-gradient-to-r from-red-600 to-rose-600 hover:from-red-700 hover:to-rose-700 text-white font-bold text-xs rounded-2xl transition-all shadow-lg shadow-red-500/25 flex items-center justify-center gap-2 cursor-pointer active:scale-95">
                    <span class="material-symbols-outlined text-sm">delete</span>
                    <span>Sí, Eliminar</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================= -->
<!-- JAVASCRIPT LOGIC FOR INTERACTIVE CHECKLIST                   -->
<!-- ============================================================= -->
<script>
let totalPreguntas = <?= count($preguntasActivas) ?>;
let respuestas = {};

function actualizarInfoMaquina(select) {
    const opt = select.options[select.selectedIndex];
    const horas = opt.getAttribute('data-horas');
    const inputHoras = document.getElementById('input-horas');
    if (horas && horas > 0) {
        inputHoras.value = parseFloat(horas);
    }
}

function seleccionarOpcion(idPregunta, valor) {
    respuestas[idPregunta] = valor;

    const panelObs = document.getElementById('panel-obs-' + idPregunta);
    const textareaObs = document.getElementById('obs-' + idPregunta);

    if (valor === 'NO') {
        panelObs.classList.remove('hidden');
        if (textareaObs) {
            textareaObs.required = true;
            textareaObs.focus();
        }
    } else {
        panelObs.classList.add('hidden');
        if (textareaObs) {
            textareaObs.required = false;
        }
    }

    actualizarProgreso();
}

function actualizarProgreso() {
    let contestadas = Object.keys(respuestas).length;
    let countOk = 0;
    let countFalla = 0;

    for (let id in respuestas) {
        if (respuestas[id] === 'SI') countOk++;
        else if (respuestas[id] === 'NO') countFalla++;
    }

    let pct = totalPreguntas > 0 ? Math.round((contestadas / totalPreguntas) * 100) : 0;

    const barra = document.getElementById('barra-progreso');
    const txtProgreso = document.getElementById('txt-progreso');
    const badgeOk = document.getElementById('badge-ok');
    const badgeFalla = document.getElementById('badge-falla');

    if (barra) barra.style.width = pct + '%';
    if (txtProgreso) txtProgreso.textContent = contestadas + ' / ' + totalPreguntas + ' (' + pct + '%)';
    if (badgeOk) badgeOk.innerHTML = '<span class="material-symbols-outlined text-sm">check</span> ' + countOk + ' OK';
    if (badgeFalla) badgeFalla.innerHTML = '<span class="material-symbols-outlined text-sm">warning</span> ' + countFalla + ' Obs';
}

function marcarTodosSi() {
    document.querySelectorAll('.btn-toggle-si input[type="radio"]').forEach(radio => {
        radio.checked = true;
        const id = radio.name.match(/\[(\d+)\]/)[1];
        seleccionarOpcion(id, 'SI');
    });
}

function previsualizarFoto(input, idPregunta) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('preview-img-' + idPregunta).src = e.target.result;
            document.getElementById('preview-box-' + idPregunta).classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function quitarFoto(idPregunta) {
    const input = document.querySelector(`input[name="fotos[${idPregunta}]"]`);
    if (input) input.value = '';
    document.getElementById('preview-box-' + idPregunta).classList.add('hidden');
}

// Modal Pregunta Logic
function abrirModalPregunta() {
    document.getElementById('form-modal-pregunta').reset();
    document.getElementById('modal-action').value = 'crear_pregunta';
    document.getElementById('modal-id-pregunta').value = '';
    document.getElementById('modal-titulo').textContent = 'Nueva Pregunta de Inspección';
    document.getElementById('box-activo').classList.add('hidden');
    document.getElementById('modal-categoria-nueva').classList.add('hidden');
    document.getElementById('modal-pregunta').classList.remove('hidden');
}

function editarPregunta(p) {
    document.getElementById('modal-action').value = 'editar_pregunta';
    document.getElementById('modal-id-pregunta').value = p.id_pregunta;
    document.getElementById('modal-titulo').textContent = 'Editar Pregunta #' + p.id_pregunta;
    document.getElementById('modal-categoria').value = p.categoria;
    document.getElementById('modal-texto-pregunta').value = p.pregunta;
    document.getElementById('modal-ayuda').value = p.descripcion_ayuda || '';
    document.getElementById('modal-tipo').value = p.tipo_maquina || 'autoelevador';
    document.getElementById('modal-orden').value = p.orden || 10;
    document.getElementById('modal-activo').checked = (p.activo == 1);
    document.getElementById('box-activo').classList.remove('hidden');
    document.getElementById('modal-categoria-nueva').classList.add('hidden');
    document.getElementById('modal-pregunta').classList.remove('hidden');
}

function cerrarModalPregunta() {
    document.getElementById('modal-pregunta').classList.add('hidden');
}

function toggleNuevaCat(val) {
    const inputNueva = document.getElementById('modal-categoria-nueva');
    if (val === '__nueva__') {
        inputNueva.classList.remove('hidden');
        inputNueva.focus();
        inputNueva.required = true;
    } else {
        inputNueva.classList.add('hidden');
        inputNueva.required = false;
    }
}

// -------------------------------------------------------------
// PAD DE FIRMA DIGITAL (CANVAS TÁCTIL Y MOUSE)
// -------------------------------------------------------------
let canvasFirma = document.getElementById('canvas-firma');
let ctxFirma = canvasFirma ? canvasFirma.getContext('2d') : null;
let dibujando = false;
let firmaHaSidoDibujada = false;

if (canvasFirma && ctxFirma) {
    ctxFirma.strokeStyle = '#0f172a';
    ctxFirma.lineWidth = 2.5;
    ctxFirma.lineCap = 'round';
    ctxFirma.lineJoin = 'round';

    function getCanvasPos(e) {
        let rect = canvasFirma.getBoundingClientRect();
        let clientX = e.clientX;
        let clientY = e.clientY;
        if (e.touches && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        }
        let scaleX = canvasFirma.width / rect.width;
        let scaleY = canvasFirma.height / rect.height;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function empezarDibujo(e) {
        dibujando = true;
        firmaHaSidoDibujada = true;
        let hint = document.getElementById('hint-firma');
        if (hint) hint.classList.add('hidden');
        let pos = getCanvasPos(e);
        ctxFirma.beginPath();
        ctxFirma.moveTo(pos.x, pos.y);
        e.preventDefault();
    }

    function moverDibujo(e) {
        if (!dibujando) return;
        let pos = getCanvasPos(e);
        ctxFirma.lineTo(pos.x, pos.y);
        ctxFirma.stroke();
        e.preventDefault();
    }

    function pararDibujo() {
        if (dibujando) {
            dibujando = false;
            guardarFirmaEnInput();
        }
    }

    // Mouse events
    canvasFirma.addEventListener('mousedown', empezarDibujo);
    canvasFirma.addEventListener('mousemove', moverDibujo);
    canvasFirma.addEventListener('mouseup', pararDibujo);
    canvasFirma.addEventListener('mouseleave', pararDibujo);

    // Touch events
    canvasFirma.addEventListener('touchstart', empezarDibujo, { passive: false });
    canvasFirma.addEventListener('touchmove', moverDibujo, { passive: false });
    canvasFirma.addEventListener('touchend', pararDibujo);
}

function guardarFirmaEnInput(callback) {
    if (canvasFirma && firmaHaSidoDibujada) {
        if (canvasFirma.toBlob) {
            canvasFirma.toBlob(function(blob) {
                if (blob) {
                    try {
                        let file = new File([blob], "firma_" + Date.now() + ".png", { type: "image/png" });
                        let container = new DataTransfer();
                        container.items.add(file);
                        let fileInput = document.getElementById('input-firma-file');
                        if (fileInput) fileInput.files = container.files;
                        document.getElementById('input-firma-base64').value = '';
                    } catch(e) {
                        let raw = canvasFirma.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
                        document.getElementById('input-firma-base64').value = raw;
                    }
                }
                if (callback) callback();
            }, 'image/png');
            return;
        } else {
            let raw = canvasFirma.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
            document.getElementById('input-firma-base64').value = raw;
        }
    }
    if (callback) callback();
}

function limpiarCanvasFirma() {
    if (canvasFirma && ctxFirma) {
        ctxFirma.clearRect(0, 0, canvasFirma.width, canvasFirma.height);
        firmaHaSidoDibujada = false;
        let fileInput = document.getElementById('input-firma-file');
        if (fileInput) fileInput.value = '';
        document.getElementById('input-firma-base64').value = '';
        let hint = document.getElementById('hint-firma');
        if (hint) hint.classList.remove('hidden');
    }
}

// Asegurar que la firma se pase antes de enviar y mostrar spinner
const formInspeccion = document.getElementById('form-inspeccion');
if (formInspeccion) {
    let submittingFormInsp = false;
    formInspeccion.addEventListener('submit', function(e) {
        if (submittingFormInsp) {
            e.preventDefault();
            return false;
        }
        submittingFormInsp = true;
        const btnSubmit = formInspeccion.querySelector('button[type="submit"]');
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.classList.add('opacity-75', 'cursor-not-allowed');
            btnSubmit.innerHTML = '<span class="material-symbols-outlined animate-spin text-lg">progress_activity</span> <span>Guardando...</span>';
        }
        if (firmaHaSidoDibujada) {
            e.preventDefault();
            guardarFirmaEnInput(function() {
                formInspeccion.submit();
            });
        }
    });
}
// Pad de Firma del Inspector en Modal Detalle
let canvasFirmaInsp = document.getElementById('canvas-firma-inspector');
let ctxFirmaInsp = canvasFirmaInsp ? canvasFirmaInsp.getContext('2d') : null;
let dibujandoInsp = false;
let firmaInspDibujada = false;

if (canvasFirmaInsp && ctxFirmaInsp) {
    ctxFirmaInsp.strokeStyle = '#047857';
    ctxFirmaInsp.lineWidth = 2.5;
    ctxFirmaInsp.lineCap = 'round';
    ctxFirmaInsp.lineJoin = 'round';

    function getCanvasInspPos(e) {
        let rect = canvasFirmaInsp.getBoundingClientRect();
        let clientX = e.clientX;
        let clientY = e.clientY;
        if (e.touches && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        }
        let scaleX = canvasFirmaInsp.width / rect.width;
        let scaleY = canvasFirmaInsp.height / rect.height;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function empezarDibujoInsp(e) {
        dibujandoInsp = true;
        firmaInspDibujada = true;
        let hint = document.getElementById('hint-firma-inspector');
        if (hint) hint.classList.add('hidden');
        let pos = getCanvasInspPos(e);
        ctxFirmaInsp.beginPath();
        ctxFirmaInsp.moveTo(pos.x, pos.y);
        e.preventDefault();
    }

    function moverDibujoInsp(e) {
        if (!dibujandoInsp) return;
        let pos = getCanvasInspPos(e);
        ctxFirmaInsp.lineTo(pos.x, pos.y);
        ctxFirmaInsp.stroke();
        e.preventDefault();
    }

    function pararDibujoInsp() {
        if (dibujandoInsp) {
            dibujandoInsp = false;
            document.getElementById('input-firma-inspector-base64').value = canvasFirmaInsp.toDataURL('image/png');
        }
    }

    canvasFirmaInsp.addEventListener('mousedown', empezarDibujoInsp);
    canvasFirmaInsp.addEventListener('mousemove', moverDibujoInsp);
    canvasFirmaInsp.addEventListener('mouseup', pararDibujoInsp);
    canvasFirmaInsp.addEventListener('mouseleave', pararDibujoInsp);

    canvasFirmaInsp.addEventListener('touchstart', empezarDibujoInsp, { passive: false });
    canvasFirmaInsp.addEventListener('touchmove', moverDibujoInsp, { passive: false });
    canvasFirmaInsp.addEventListener('touchend', pararDibujoInsp);
}

function limpiarCanvasFirmaInspector() {
    if (canvasFirmaInsp && ctxFirmaInsp) {
        ctxFirmaInsp.clearRect(0, 0, canvasFirmaInsp.width, canvasFirmaInsp.height);
        firmaInspDibujada = false;
        document.getElementById('input-firma-inspector-base64').value = '';
        let hint = document.getElementById('hint-firma-inspector');
        if (hint) hint.classList.remove('hidden');
    }
}

function moverPreguntaDirecto(idPregunta, direccion) {
    document.getElementById('aux-action').value = 'mover_pregunta';
    document.getElementById('aux-id-pregunta').value = idPregunta;
    document.getElementById('aux-direccion').value = direccion;
    document.getElementById('form-aux-pregunta').submit();
}

function toggleEstadoPregunta(idPregunta) {
    document.getElementById('aux-action').value = 'toggle_pregunta';
    document.getElementById('aux-id-pregunta').value = idPregunta;
    document.getElementById('form-aux-pregunta').submit();
}

function eliminarPreguntaDirecto(idPregunta) {
    if (confirm('¿Está seguro de eliminar esta pregunta del checklist?')) {
        document.getElementById('aux-action').value = 'eliminar_pregunta';
        document.getElementById('aux-id-pregunta').value = idPregunta;
        document.getElementById('form-aux-pregunta').submit();
    }
}

function notificarCambioOrden() {
    const badge = document.getElementById('badge-cambios-orden');
    if (badge) badge.classList.remove('hidden');
    const btnTop = document.getElementById('btn-guardar-orden-top');
    if (btnTop) {
        btnTop.classList.remove('bg-emerald-600');
        btnTop.classList.add('bg-emerald-700', 'ring-4', 'ring-emerald-300', 'scale-105');
    }
}

// Drag and Drop para reordenar preguntas en la tabla
let filaArrastrada = null;
const tbodyPreguntas = document.getElementById('tbody-preguntas');
if (tbodyPreguntas) {
    const filas = tbodyPreguntas.querySelectorAll('.fila-pregunta');
    filas.forEach(fila => {
        fila.addEventListener('dragstart', function(e) {
            filaArrastrada = this;
            this.classList.add('bg-blue-100', 'opacity-70');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.innerHTML);
        });

        fila.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const filaObjetivo = e.target.closest('.fila-pregunta');
            if (filaObjetivo && filaObjetivo !== filaArrastrada) {
                const rect = filaObjetivo.getBoundingClientRect();
                const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
                tbodyPreguntas.insertBefore(filaArrastrada, next && filaObjetivo.nextSibling || filaObjetivo);
            }
        });

        fila.addEventListener('dragend', function() {
            this.classList.remove('bg-blue-100', 'opacity-70');
            filaArrastrada = null;
            recalcularOrdenInputs();
        });
    });
}

function recalcularOrdenInputs() {
    const filas = document.querySelectorAll('#tbody-preguntas .fila-pregunta');
    let nuevoOrden = 10;
    filas.forEach((fila, idx) => {
        const input = fila.querySelector('.input-orden-num');
        if (input) {
            input.value = nuevoOrden;
            nuevoOrden += 10;
        }
        fila.setAttribute('data-index', idx);
    });
    notificarCambioOrden();
}

function actualizarNombreInspector(selectEl) {
    if (!selectEl) return;
    let opt = selectEl.options[selectEl.selectedIndex];
    if (opt && opt.dataset.nombre) {
        document.getElementById('input-inspector-nombre').value = opt.dataset.nombre;
    }
    
    let firmaGuardada = opt ? (opt.dataset.firma || '') : '';
    let boxGuardada = document.getElementById('box-firma-inspector-guardada');
    let imgGuardada = document.getElementById('img-firma-inspector-guardada');
    let inputGuardada = document.getElementById('input-firma-inspector-guardada');
    let boxManual = document.getElementById('box-firma-inspector-manual');
    let btnToggle = document.getElementById('btn-toggle-dibujo-insp');

    if (firmaGuardada) {
        if (boxGuardada) boxGuardada.classList.remove('hidden');
        if (imgGuardada) imgGuardada.src = '<?= BASE_URL ?>/' + firmaGuardada;
        if (inputGuardada) inputGuardada.value = firmaGuardada;
        if (boxManual) boxManual.classList.add('hidden');
        if (btnToggle) btnToggle.textContent = '✏️ Dibujar otra firma manual para esta ocasión (opcional)';
    } else {
        if (boxGuardada) boxGuardada.classList.add('hidden');
        if (imgGuardada) imgGuardada.src = '';
        if (inputGuardada) inputGuardada.value = '';
        if (boxManual) boxManual.classList.remove('hidden');
    }
}

function toggleDibujoFirmaInspector() {
    let boxManual = document.getElementById('box-firma-inspector-manual');
    let btnToggle = document.getElementById('btn-toggle-dibujo-insp');
    if (boxManual) {
        if (boxManual.classList.contains('hidden')) {
            boxManual.classList.remove('hidden');
            if (btnToggle) btnToggle.textContent = '⚡ Usar firma guardada automática';
        } else {
            boxManual.classList.add('hidden');
            limpiarCanvasFirmaInspector();
            if (btnToggle) btnToggle.textContent = '✏️ Dibujar otra firma manual para esta ocasión (opcional)';
        }
    }
}

function guardarFirmaInspectorEnInput(callback) {
    if (canvasFirmaInsp && firmaInspDibujada) {
        if (canvasFirmaInsp.toBlob) {
            canvasFirmaInsp.toBlob(function(blob) {
                if (blob) {
                    try {
                        let file = new File([blob], "firma_insp_" + Date.now() + ".png", { type: "image/png" });
                        let container = new DataTransfer();
                        container.items.add(file);
                        let fileInput = document.getElementById('input-firma-inspector-file');
                        if (fileInput) fileInput.files = container.files;
                        // Limpiar input base64 para evitar bloqueos 403 por data URI en POST
                        document.getElementById('input-firma-inspector-base64').value = '';
                    } catch(e) {
                        let raw = canvasFirmaInsp.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
                        document.getElementById('input-firma-inspector-base64').value = raw;
                    }
                }
                if (callback) callback();
            }, 'image/png');
            return;
        } else {
            let raw = canvasFirmaInsp.toDataURL('image/png').replace(/^data:image\/[a-z]+;base64,/, '');
            document.getElementById('input-firma-inspector-base64').value = raw;
        }
    }
    if (callback) callback();
}

const formAprobacion = document.getElementById('form-aprobacion-inspector');
if (formAprobacion) {
    let submittingInsp = false;
    formAprobacion.addEventListener('submit', function(e) {
        if (submittingInsp) return;

        let sel = document.getElementById('select-inspector-aprobacion');
        let firmaGuardada = sel && sel.options[sel.selectedIndex] ? (sel.options[sel.selectedIndex].dataset.firma || '') : '';
        let firmaManualBase64 = document.getElementById('input-firma-inspector-base64') ? document.getElementById('input-firma-inspector-base64').value : '';

        if (!firmaGuardada && !firmaManualBase64 && !firmaInspDibujada) {
            e.preventDefault();
            alert('Por favor, seleccione un inspector con firma precargada o dibuje su firma digital en el recuadro antes de aprobar.');
            return;
        }

        if (firmaInspDibujada) {
            e.preventDefault();
            submittingInsp = true;
            guardarFirmaInspectorEnInput(function() {
                formAprobacion.submit();
            });
        }
    });
}

// Inicializar estado del inspector al cargar si el modal está abierto
document.addEventListener('DOMContentLoaded', function() {
    let sel = document.getElementById('select-inspector-aprobacion');
    if (sel) {
        actualizarNombreInspector(sel);
    }
});

// -------------------------------------------------------------
// MODAL MODERNO DE CONFIRMACIÓN DE ELIMINACIÓN
// -------------------------------------------------------------
function abrirModalEliminarInspeccion(id, equipo, info) {
    const inputId = document.getElementById('delModalIdInput');
    const badgeId = document.getElementById('delModalIdBadge');
    const badgeEquipo = document.getElementById('delModalEquipoBadge');
    const badgeInfo = document.getElementById('delModalInfoBadge');
    const modal = document.getElementById('modalConfirmarEliminar');

    if (inputId) inputId.value = id;
    if (badgeId) badgeId.textContent = '#' + id;
    if (badgeEquipo) badgeEquipo.textContent = equipo || 'Autoelevador / Máquina';
    if (badgeInfo) badgeInfo.textContent = info || '-';
    
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}
window.abrirModalEliminarInspeccion = abrirModalEliminarInspeccion;

function cerrarModalEliminarInspeccion() {
    const modal = document.getElementById('modalConfirmarEliminar');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
window.cerrarModalEliminarInspeccion = cerrarModalEliminarInspeccion;

// Cerrar con tecla Escape o clic fuera
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalEliminarInspeccion();
    }
});

const modalDel = document.getElementById('modalConfirmarEliminar');
if (modalDel) {
    modalDel.addEventListener('click', function(e) {
        if (e.target === modalDel) {
            cerrarModalEliminarInspeccion();
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
