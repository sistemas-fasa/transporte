<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('viajes_importar');
$pageTitle = 'Importar Viajes';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();

// Migrar columnas si no existen (mismo patron que viajes.php)
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN nro_hoja_ruta VARCHAR(50) DEFAULT NULL AFTER id_hoja"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN ayudante_id INT DEFAULT NULL AFTER nro_hoja_ruta"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN tara DECIMAL(10,2) DEFAULT NULL AFTER ayudante_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN peso_carga DECIMAL(10,2) DEFAULT NULL AFTER tara"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN aprobado_por INT DEFAULT NULL AFTER estado"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN fecha_cierre DATETIME DEFAULT NULL AFTER aprobado_por"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN estado ENUM('abierto','cerrado','aprobado') DEFAULT 'abierto' AFTER observaciones"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN cachape_id INT DEFAULT NULL AFTER peso_carga"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE km_recorrido ADD COLUMN peso_total DECIMAL(12,2) DEFAULT NULL AFTER cachape_id"); } catch (Exception $e) {}

$mensaje = '';
$error = '';
$resultado = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importar'])) {
    $raw = trim($_POST['datos'] ?? '');
    if ($raw === '') {
        $error = 'Ingrese los datos a importar';
    } else {
        $lineas = explode("\n", $raw);
        $importados = 0;
        $omitidos = 0;
        $errores = [];

        foreach ($lineas as $num => $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;

            $cols = explode("\t", $linea);
            if (count($cols) < 8) {
                $errores[] = "Linea " . ($num + 1) . ": columnas insuficientes (" . count($cols) . ")";
                continue;
            }

            $nro_hoja = trim($cols[0]);
            $nombreCompleto = trim($cols[1]);
            $patente = strtoupper(trim($cols[2]));
            $origen = trim($cols[3]);
            $fechaHora = trim($cols[4]);
            $destino = trim($cols[5]);
            $kmSalida = (float)str_replace(',', '', trim($cols[6]));
            $kmLlegada = (float)str_replace(',', '', trim($cols[7]));
            $tara = isset($cols[8]) ? (float)str_replace(',', '', trim($cols[8])) : 0;
            $pesoCarga = isset($cols[9]) && trim($cols[9]) !== '' ? (float)str_replace(',', '', trim($cols[9])) : null;
            $ayudanteNombre = isset($cols[10]) ? trim($cols[10]) : '';

            // Extraer solo la fecha (YYYY-MM-DD) del campo fechaHora
            $fecha = date('Y-m-d', strtotime($fechaHora));

            try {
                // Resolver o crear chofer
                $parts = explode(' ', $nombreCompleto);
                $apellido = array_shift($parts);
                $nombre = implode(' ', $parts);
                $primerNombre = $parts[0] ?? '';

                $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE CONCAT(apellido, ' ', nombre) = ? OR CONCAT(nombre, ' ', apellido) = ? LIMIT 1");
                $stmtCh->execute([$nombreCompleto, $nombreCompleto]);
                $idChofer = $stmtCh->fetchColumn();

                if (!$idChofer && $primerNombre) {
                    $stmtCh2 = $db->prepare("SELECT id_chofer FROM choferes WHERE apellido = ? AND (nombre = ? OR nombre LIKE ?) LIMIT 1");
                    $stmtCh2->execute([$apellido, $primerNombre, $primerNombre . ' %']);
                    $idChofer = $stmtCh2->fetchColumn();
                }

                if (!$idChofer) {
                    $dni = 'IMP' . $nro_hoja;
                    $stmtIns = $db->prepare("INSERT INTO choferes (nombre, apellido, dni, estado) VALUES (?, ?, ?, 'activo')");
                    $stmtIns->execute([$nombre, $apellido, $dni]);
                    $idChofer = $db->lastInsertId();
                    // Crear usuario asociado
                    $username = strtolower(substr($nombre, 0, 1) . $apellido);
                    $checkUser = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ?");
                    $checkUser->execute([$username]);
                    if ($checkUser->fetchColumn() == 0) {
                        $db->prepare("INSERT INTO usuarios (username, password, email, rol, id_chofer, activo) VALUES (?,?,?, 'chofer', ?, 1)")
                           ->execute([$username, password_hash($dni, PASSWORD_DEFAULT), "$username@importado.com", $idChofer]);
                    }
                }

                // Resolver o crear camion
                $stmtCa = $db->prepare("SELECT id_camion, tara FROM camiones WHERE patente = ? LIMIT 1");
                $stmtCa->execute([$patente]);
                $camion = $stmtCa->fetch();

                if ($camion) {
                    $idCamion = $camion['id_camion'];
                    // Actualizar tara si difiere
                    if ($tara > 0 && (!$camion['tara'] || (float)$camion['tara'] !== $tara)) {
                        $db->prepare("UPDATE camiones SET tara = ? WHERE id_camion = ?")->execute([$tara, $idCamion]);
                    }
                } else {
                    $marcaModelo = 'Importado';
                    $stmtIns = $db->prepare("INSERT INTO camiones (patente, marca, modelo, tara, estado, tipo) VALUES (?, ?, ?, ?, 'activo', 'camion')");
                    $stmtIns->execute([$patente, $marcaModelo, $marcaModelo, $tara ?: null]);
                    $idCamion = $db->lastInsertId();
                }

                // Resolver ayudante si se proporciono
                $idAyudante = null;
                if ($ayudanteNombre !== '') {
                    $partsA = explode(' ', $ayudanteNombre);
                    $apellidoA = array_shift($partsA);
                    $nombreA = implode(' ', $partsA);
                    $primerNombreA = $partsA[0] ?? '';

                    $stmtAy = $db->prepare("SELECT id_chofer FROM choferes WHERE CONCAT(apellido, ' ', nombre) = ? OR CONCAT(nombre, ' ', apellido) = ? LIMIT 1");
                    $stmtAy->execute([$ayudanteNombre, $ayudanteNombre]);
                    $idAyudante = $stmtAy->fetchColumn();

                    if (!$idAyudante && $primerNombreA) {
                        $stmtAy2 = $db->prepare("SELECT id_chofer FROM choferes WHERE apellido = ? AND (nombre = ? OR nombre LIKE ?) LIMIT 1");
                        $stmtAy2->execute([$apellidoA, $primerNombreA, $primerNombreA . ' %']);
                        $idAyudante = $stmtAy2->fetchColumn();
                    }

                    if (!$idAyudante) {
                        $dniA = 'IMP-A' . $nro_hoja;
                        $stmtInsA = $db->prepare("INSERT INTO choferes (nombre, apellido, dni, estado) VALUES (?, ?, ?, 'activo')");
                        $stmtInsA->execute([$nombreA, $apellidoA, $dniA]);
                        $idAyudante = $db->lastInsertId();
                    }
                }

                // Verificar si ya existe por nro_hoja_ruta
                $stmtEx = $db->prepare("SELECT id_hoja FROM km_recorrido WHERE nro_hoja_ruta = ? LIMIT 1");
                $stmtEx->execute([$nro_hoja]);
                if ($stmtEx->fetch()) {
                    $omitidos++;
                    continue;
                }

                // Insertar viaje
                $pesoTotal = $tara + ($pesoCarga ?? 0);
                $stmtIns = $db->prepare("INSERT INTO km_recorrido (nro_hoja_ruta, fecha, id_chofer, id_camion, km_salida, km_llegada, origen, destino, tara, peso_carga, peso_total, ayudante_id, estado, fecha_cierre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'cerrado', NOW())");
                $stmtIns->execute([$nro_hoja, $fecha, $idChofer, $idCamion, $kmSalida, $kmLlegada, $origen, $destino, $tara ?: null, $pesoCarga, $pesoTotal ?: null, $idAyudante]);
                $importados++;
            } catch (Exception $e) {
                $errores[] = "Linea " . ($num + 1) . ": " . $e->getMessage();
            }
        }

        $resultado = [
            'importados' => $importados,
            'omitidos' => $omitidos,
            'errores' => $errores,
        ];
        $mensaje = "Importacion completada: $importados importados, $omitidos omitidos (ya existentes).";
    }
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Importar Viajes</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Cargue viajes historicos desde datos tabulados.</p>
</div>
</div>

<?php if ($mensaje): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div>
<?php if (!empty($resultado['errores'])): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
<strong>Errores:</strong>
<ul class="list-disc list-inside text-sm mt-1"><?php foreach ($resultado['errores'] as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-6">
<form method="POST">
<div class="flex flex-col gap-1 mb-4">
<label class="font-label-caps text-label-caps text-on-surface-variant uppercase">Datos a importar</label>
<p class="text-sm text-on-surface-variant mb-2">Pegue los datos separados por tabulaciones. Formato esperado por linea:</p>
<pre class="bg-surface-container-high p-3 rounded-lg text-xs font-mono mb-3">NRO &lt;TAB&gt; CHOFER &lt;TAB&gt; PATENTE &lt;TAB&gt; ORIGEN &lt;TAB&gt; FECHA_HORA &lt;TAB&gt; DESTINO &lt;TAB&gt; KM_SALIDA &lt;TAB&gt; KM_LLEGADA &lt;TAB&gt; TARA &lt;TAB&gt; PESO_CARGA &lt;TAB&gt; AYUDANTE (opcional)</pre>
<textarea name="datos" rows="15" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low font-mono text-sm" placeholder="Pegue los datos aqui..."><?= htmlspecialchars($_POST['datos'] ?? '') ?></textarea>
</div>
<button type="submit" name="importar" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold hover:opacity-90 transition-opacity flex items-center gap-2">
<span class="material-symbols-outlined">upload</span> Importar Viajes
</button>
</form>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
