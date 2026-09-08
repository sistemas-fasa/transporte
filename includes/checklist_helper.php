<?php
// includes/checklist_helper.php
require_once __DIR__ . '/../config/database.php';

function initChecklistDatabase(PDO $db): void {
    static $initialized = false;
    if ($initialized) return;

    try {
        // Tabla de Preguntas
        $db->exec("CREATE TABLE IF NOT EXISTS checklist_preguntas (
            id_pregunta INT AUTO_INCREMENT PRIMARY KEY,
            categoria VARCHAR(100) NOT NULL,
            pregunta VARCHAR(255) NOT NULL,
            descripcion_ayuda TEXT DEFAULT NULL,
            tipo_maquina VARCHAR(50) NOT NULL DEFAULT 'autoelevador',
            orden INT DEFAULT 0,
            requiere_foto_en_no TINYINT(1) DEFAULT 0,
            activo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Tabla de Inspecciones
        $db->exec("CREATE TABLE IF NOT EXISTS checklist_inspecciones (
            id_inspeccion INT AUTO_INCREMENT PRIMARY KEY,
            id_camion INT NOT NULL,
            id_usuario INT NOT NULL,
            id_chofer INT DEFAULT NULL,
            fecha DATETIME NOT NULL,
            tipo_maquina VARCHAR(50) DEFAULT 'autoelevador',
            horas_maquina DECIMAL(10,2) DEFAULT NULL,
            estado ENUM('aprobado', 'con_observaciones', 'rechazado') DEFAULT 'aprobado',
            total_preguntas INT DEFAULT 0,
            total_ok INT DEFAULT 0,
            total_fallas INT DEFAULT 0,
            observacion_general TEXT DEFAULT NULL,
            firmado_por VARCHAR(150) DEFAULT NULL,
            firma_digital LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_camion (id_camion),
            INDEX idx_fecha (fecha),
            INDEX idx_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Agregar columnas de firma e inspector si la tabla ya existía
        try {
            $colsExist = [];
            $qCols = $db->query("SHOW COLUMNS FROM checklist_inspecciones");
            while ($cRow = $qCols->fetch()) {
                $colsExist[] = $cRow['Field'];
            }
            if (!in_array('firma_digital', $colsExist)) {
                $db->exec("ALTER TABLE checklist_inspecciones ADD COLUMN firma_digital LONGTEXT DEFAULT NULL AFTER firmado_por");
            }
            if (!in_array('id_inspector', $colsExist)) {
                $db->exec("ALTER TABLE checklist_inspecciones ADD COLUMN id_inspector INT DEFAULT NULL AFTER firma_digital");
            }
            if (!in_array('inspector_nombre', $colsExist)) {
                $db->exec("ALTER TABLE checklist_inspecciones ADD COLUMN inspector_nombre VARCHAR(150) DEFAULT NULL AFTER id_inspector");
            }
            if (!in_array('firma_inspector', $colsExist)) {
                $db->exec("ALTER TABLE checklist_inspecciones ADD COLUMN firma_inspector LONGTEXT DEFAULT NULL AFTER inspector_nombre");
            }
            if (!in_array('fecha_aprobacion', $colsExist)) {
                $db->exec("ALTER TABLE checklist_inspecciones ADD COLUMN fecha_aprobacion DATETIME DEFAULT NULL AFTER firma_inspector");
            }
            if (!in_array('observacion_inspector', $colsExist)) {
                $db->exec("ALTER TABLE checklist_inspecciones ADD COLUMN observacion_inspector TEXT DEFAULT NULL AFTER fecha_aprobacion");
            }
            if (!in_array('estado_aprobacion', $colsExist)) {
                $db->exec("ALTER TABLE checklist_inspecciones ADD COLUMN estado_aprobacion ENUM('pendiente', 'aprobado', 'rechazado') DEFAULT 'pendiente' AFTER observacion_inspector");
            }
        } catch (Exception $e) {}

        // Asegurar columna hace_checklist en tabla camiones
        try {
            $colsCam = $db->query("SHOW COLUMNS FROM camiones LIKE 'hace_checklist'")->fetchAll();
            if (empty($colsCam)) {
                $db->exec("ALTER TABLE camiones ADD COLUMN hace_checklist TINYINT(1) NOT NULL DEFAULT 0 AFTER control_neumaticos");
                // Habilitar por defecto los autoelevadores existentes
                $db->exec("UPDATE camiones SET hace_checklist = 1 WHERE tipo = 'autoelevador'");
            }
        } catch (Exception $e) {}

        // Asegurar columna firma_digital en tabla usuarios
        try {
            $colsUser = $db->query("SHOW COLUMNS FROM usuarios LIKE 'firma_digital'")->fetchAll();
            if (empty($colsUser)) {
                $db->exec("ALTER TABLE usuarios ADD COLUMN firma_digital VARCHAR(255) DEFAULT NULL AFTER activo");
            }
        } catch (Exception $e) {}

        // Tabla de Respuestas
        $db->exec("CREATE TABLE IF NOT EXISTS checklist_respuestas (
            id_respuesta INT AUTO_INCREMENT PRIMARY KEY,
            id_inspeccion INT NOT NULL,
            id_pregunta INT NOT NULL,
            categoria VARCHAR(100) NOT NULL,
            pregunta_texto VARCHAR(255) NOT NULL,
            resultado ENUM('SI', 'NO') NOT NULL,
            observacion TEXT DEFAULT NULL,
            foto_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_inspeccion (id_inspeccion),
            INDEX idx_resultado (resultado),
            FOREIGN KEY (id_inspeccion) REFERENCES checklist_inspecciones(id_inspeccion) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed default questions if empty
        $stmtCount = $db->query("SELECT COUNT(*) FROM checklist_preguntas");
        if ($stmtCount->fetchColumn() == 0) {
            $defaultPreguntas = [
                // Ruedas y Neumáticos
                ['categoria' => 'Ruedas y Rodaje', 'pregunta' => 'Ruedas y neumáticos: banda de rodaje, presión adecuada y sin cortes o desgaste excesivo', 'ayuda' => 'Verificar que no tenga clavos, deformaciones o cortes laterales y presión correcta.', 'orden' => 10],
                ['categoria' => 'Ruedas y Rodaje', 'pregunta' => 'Bulones y tuercas de ruedas: fijación correcta y sin faltantes', 'ayuda' => 'Comprobar que todas las tuercas de las ruedas estén ajustadas.', 'orden' => 20],

                // Niveles y Fluidos
                ['categoria' => 'Niveles y Fluidos', 'pregunta' => 'Nivel de aceite de motor: nivel correcto y sin pérdidas visibles', 'ayuda' => 'Medir con la varilla en frío o reposo. Nivel entre MIN y MAX.', 'orden' => 30],
                ['categoria' => 'Niveles y Fluidos', 'pregunta' => 'Líquido refrigerante / radiador: nivel adecuado y tapa asegurada', 'ayuda' => 'Verificar nivel en el depósito de expansión y limpieza de aletas.', 'orden' => 40],
                ['categoria' => 'Niveles y Fluidos', 'pregunta' => 'Aceite hidráulico: nivel en depósito correcto y visor limpio', 'ayuda' => 'Comprobar nivel con mástil retraído en posición baja.', 'orden' => 50],
                ['categoria' => 'Niveles y Fluidos', 'pregunta' => 'Líquido de frenos: nivel en depósito y sin fugas visibles', 'ayuda' => 'Verificar depósito de frenos.', 'orden' => 60],
                ['categoria' => 'Niveles y Fluidos', 'pregunta' => 'Batería o Garrafa de Gas: sujeción firme, bornes limpios o precintos en regla', 'ayuda' => 'En gas: verificar estanqueidad con agua jabonosa o que no haya olor a gas. En eléctricas: bornes limpios.', 'orden' => 70],

                // Sistema Hidráulico y Horquillas
                ['categoria' => 'Sistema Hidráulico y Mástil', 'pregunta' => 'Horquillas / uñas: sin fisuras, desgaste en talón ni deformaciones', 'ayuda' => 'Verificar seguro de traba de posición de uñas.', 'orden' => 80],
                ['categoria' => 'Sistema Hidráulico y Mástil', 'pregunta' => 'Cadenas y mástil: tensión pareja, lubricación y libre de trabas', 'ayuda' => 'Comprobar sincronismo de las cadenas elevadoras.', 'orden' => 90],
                ['categoria' => 'Sistema Hidráulico y Mástil', 'pregunta' => 'Mangueras y cilindros hidráulicos: sin pérdidas de aceite ni fisuras', 'ayuda' => 'Revisar cilindros de elevación e inclinación.', 'orden' => 100],

                // Seguridad y Luces
                ['categoria' => 'Seguridad y Señalización', 'pregunta' => 'Bocina y alarma sonora de marcha atrás: audibles y operativas', 'ayuda' => 'Probar bocina y accionar reversa para verificar alarma.', 'orden' => 110],
                ['categoria' => 'Seguridad y Señalización', 'pregunta' => 'Luces delanteras, de trabajo y baliza estroboscópica: funcionando', 'ayuda' => 'Encender luces de posición, faros de trabajo y destellador.', 'orden' => 120],
                ['categoria' => 'Seguridad y Señalización', 'pregunta' => 'Cinturón de seguridad y butaca: fijación y traba en perfecto estado', 'ayuda' => 'Trabar y tirar fuertemente del cinturón.', 'orden' => 130],
                ['categoria' => 'Seguridad y Señalización', 'pregunta' => 'Matafuegos: cargado, con manómetro en verde y soporte asegurado', 'ayuda' => 'Verificar fecha de vigencia y precinto de seguridad.', 'orden' => 140],
                ['categoria' => 'Seguridad y Señalización', 'pregunta' => 'Espejos retrovisores: limpios, fijados y sin roturas', 'ayuda' => 'Verificar visibilidad hacia atrás.', 'orden' => 150],

                // Mandos, Frenos y Dirección
                ['categoria' => 'Mandos y Frenos', 'pregunta' => 'Freno de servicio (pedal): respuesta inmediata y firme', 'ayuda' => 'Probar frenado a baja velocidad.', 'orden' => 160],
                ['categoria' => 'Mandos y Frenos', 'pregunta' => 'Freno de estacionamiento (mano): retención firme del equipo', 'ayuda' => 'Accionar palanca y verificar traba.', 'orden' => 170],
                ['categoria' => 'Mandos y Frenos', 'pregunta' => 'Dirección y volante: giro suave sin juego excesivo', 'ayuda' => 'Girar el volante de tope a tope.', 'orden' => 180],
                ['categoria' => 'Mandos y Frenos', 'pregunta' => 'Palancas de comando hidráulico: movimiento libre y retorno a neutro', 'ayuda' => 'Elevar, inclinar y desplazar verificando suavidad.', 'orden' => 190],
            ];

            $insertStmt = $db->prepare("INSERT INTO checklist_preguntas (categoria, pregunta, descripcion_ayuda, tipo_maquina, orden, activo) VALUES (?, ?, ?, 'autoelevador', ?, 1)");
            foreach ($defaultPreguntas as $p) {
                $insertStmt->execute([$p['categoria'], $p['pregunta'], $p['ayuda'], $p['orden']]);
            }
        }

        // Garantizar permisos en la base de datos para todos los roles
        try {
            $chkPerms = [
                ['checklist_ver', 'Ver checklist máquinas', 'Checklist'],
                ['checklist_cargar', 'Cargar checklist máquinas', 'Checklist'],
                ['checklist_config', 'Configurar preguntas checklist', 'Checklist'],
                ['checklist_eliminar', 'Eliminar inspecciones checklist', 'Checklist'],
            ];
            foreach ($chkPerms as $p) {
                $stmtP = $db->prepare("SELECT id_permiso FROM permisos WHERE codigo = ?");
                $stmtP->execute([$p[0]]);
                $idPermiso = $stmtP->fetchColumn();
                if (!$idPermiso) {
                    $db->prepare("INSERT INTO permisos (codigo, nombre, modulo) VALUES (?, ?, ?)")->execute($p);
                    $idPermiso = (int)$db->lastInsertId();
                }
                // Asignar a rol 1 (Admin) y rol 2 (Supervisor)
                $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (1, ?)")->execute([$idPermiso]);
                $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (2, ?)")->execute([$idPermiso]);
                if (in_array($p[0], ['checklist_ver', 'checklist_cargar'])) {
                    $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (3, ?)")->execute([$idPermiso]);
                }
                // Asignar también a cualquier rol de Inspector o Supervisor creado
                try {
                    $stmtExtraRoles = $db->query("SELECT id_rol FROM roles WHERE LOWER(nombre) LIKE '%inspector%' OR LOWER(nombre) LIKE '%supervisor%' OR LOWER(nombre) LIKE '%admin%'");
                    while ($rId = $stmtExtraRoles->fetchColumn()) {
                        $db->prepare("INSERT IGNORE INTO rol_permiso (id_rol, id_permiso) VALUES (?, ?)")->execute([$rId, $idPermiso]);
                    }
                } catch (Exception $re) {}
            }
        } catch (Exception $e) {}

        // Crear directorios de subida si no existen
        $uploadDir = __DIR__ . '/../assets/uploads/checklist';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        $firmasDir = __DIR__ . '/../assets/uploads/checklist/firmas';
        if (!is_dir($firmasDir)) {
            @mkdir($firmasDir, 0777, true);
        }

        $initialized = true;
    } catch (Exception $e) {
        error_log("Error init checklist DB: " . $e->getMessage());
    }
}

/**
 * Guarda una foto subida por el formulario de inspección
 */
function guardarFotoChecklist(array $file, int $preguntaId, int $idx = 0): ?string {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $uploadDir = __DIR__ . '/../assets/uploads/checklist/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'bmp'];
    if (!in_array($ext, $allowed)) {
        $ext = 'jpg';
    }

    $filename = 'chk_' . $preguntaId . '_' . time() . '_' . $idx . '.webp';
    $destPath = $uploadDir . $filename;

    // Redimensionar y guardar como WebP para optimizar peso
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $data = file_get_contents($file['tmp_name']);
        $img = @imagecreatefromstring($data);
        if ($img !== false) {
            $w = imagesx($img);
            $h = imagesy($img);
            $maxDim = 1600;
            if ($w > $maxDim || $h > $maxDim) {
                if ($w > $h) {
                    $newW = $maxDim;
                    $newH = (int)($h * ($maxDim / $w));
                } else {
                    $newH = $maxDim;
                    $newW = (int)($w * ($maxDim / $h));
                }
                $resized = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagewebp($resized, $destPath, 82);
                imagedestroy($resized);
            } else {
                imagewebp($img, $destPath, 82);
            }
            imagedestroy($img);
            return 'assets/uploads/checklist/' . $filename;
        }
    }

    // Fallback estándar
    $rawFilename = 'chk_' . $preguntaId . '_' . time() . '_' . $idx . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $rawFilename)) {
        return 'assets/uploads/checklist/' . $rawFilename;
    }

    return null;
}

/**
 * Guarda la firma digital dibujada en base64 como PNG transparente
 */
function guardarFirmaDigital(?string $base64Data, int $idInspeccion): ?string {
    if (empty($base64Data)) {
        return null;
    }

    $uploadDir = __DIR__ . '/../assets/uploads/checklist/firmas/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    // Extraer datos base64 con o sin prefijo data:image
    if (strpos($base64Data, ',') !== false) {
        $parts = explode(',', $base64Data);
        $base64Data = $parts[1] ?? '';
    }
    
    $decoded = base64_decode($base64Data);
    if ($decoded === false || empty($decoded)) return null;

    $filename = 'firma_' . $idInspeccion . '_' . time() . '.png';
    $filepath = $uploadDir . $filename;

    if (file_put_contents($filepath, $decoded)) {
        return 'assets/uploads/checklist/firmas/' . $filename;
    }

    return null;
}

/**
 * Guarda la firma digital de un usuario (inspector/operador)
 * Acepta array $_FILES o string base64
 */
function guardarFirmaUsuario($fileOrBase64, int $idUsuario): ?string {
    $uploadDir = __DIR__ . '/../assets/uploads/firmas_usuarios/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    // Caso 1: Archivo subido ($_FILES item)
    if (is_array($fileOrBase64) && !empty($fileOrBase64['tmp_name']) && $fileOrBase64['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($fileOrBase64['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
            $ext = 'png';
        }
        $filename = 'firma_user_' . $idUsuario . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $filename;
        if (move_uploaded_file($fileOrBase64['tmp_name'], $destPath)) {
            return 'assets/uploads/firmas_usuarios/' . $filename;
        }
    }

    // Caso 2: Cadena Base64 desde Canvas táctil
    if (is_string($fileOrBase64) && !empty($fileOrBase64)) {
        $raw = $fileOrBase64;
        if (strpos($raw, ',') !== false) {
            $parts = explode(',', $raw);
            $raw = $parts[1] ?? '';
        }
        $decoded = base64_decode($raw);
        if ($decoded !== false && strlen($decoded) > 50) {
            $filename = 'firma_user_' . $idUsuario . '_' . time() . '.png';
            $destPath = $uploadDir . $filename;
            if (file_put_contents($destPath, $decoded)) {
                return 'assets/uploads/firmas_usuarios/' . $filename;
            }
        }
    }

    return null;
}

