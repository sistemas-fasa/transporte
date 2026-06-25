<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
requirePermission('combustible_importar');
$pageTitle = 'Importar Combustible';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
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
        $idUsuario = getCurrentUserId();

        foreach ($lineas as $num => $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;

            $cols = explode("\t", $linea);
            if (count($cols) < 6) {
                $errores[] = "Linea " . ($num + 1) . ": columnas insuficientes (" . count($cols) . ")";
                continue;
            }

            $fecha = trim($cols[0]);
            $choferRaw = trim($cols[1]);
            $patente = strtoupper(trim($cols[2]));
            $estacion = trim($cols[3]);
            $litros = (float)str_replace(',', '.', trim($cols[4]));
            $precioLitro = (float)str_replace(',', '.', trim($cols[5]));
            $kmAlCargar = isset($cols[6]) ? (float)str_replace(',', '.', trim($cols[6])) : null;

            try {
                // Convertir fecha (soporta d/m/Y H:i y Y-m-d H:i)
                $fechaDt = DateTime::createFromFormat('d/m/Y H:i', $fecha);
                if (!$fechaDt) {
                    $fechaDt = DateTime::createFromFormat('d/m/Y', $fecha);
                }
                if (!$fechaDt) {
                    $fechaDt = new DateTime($fecha);
                }
                $fechaDt = $fechaDt->format('Y-m-d H:i:s');

                // Resolver chofer por ID o por nombre
                if (is_numeric($choferRaw)) {
                    $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE id_chofer = ?");
                    $stmtCh->execute([(int)$choferRaw]);
                    $idChofer = $stmtCh->fetchColumn();
                    if (!$idChofer) {
                        $errores[] = "Linea " . ($num + 1) . ": chofer ID $choferRaw no encontrado";
                        continue;
                    }
                } else {
                    $parts = explode(' ', $choferRaw);
                    $apellido = array_shift($parts);
                    $nombre = implode(' ', $parts);
                    $primerNombre = $parts[0] ?? '';

                    $stmtCh = $db->prepare("SELECT id_chofer FROM choferes WHERE CONCAT(apellido, ' ', nombre) = ? OR CONCAT(nombre, ' ', apellido) = ? LIMIT 1");
                    $stmtCh->execute([$choferRaw, $choferRaw]);
                    $idChofer = $stmtCh->fetchColumn();

                    if (!$idChofer && $primerNombre) {
                        $stmtCh2 = $db->prepare("SELECT id_chofer FROM choferes WHERE apellido = ? AND (nombre = ? OR nombre LIKE ?) LIMIT 1");
                        $stmtCh2->execute([$apellido, $primerNombre, $primerNombre . ' %']);
                        $idChofer = $stmtCh2->fetchColumn();
                    }

                    if (!$idChofer) {
                        $dni = 'IMP-C-' . uniqid();
                        $stmtIns = $db->prepare("INSERT INTO choferes (nombre, apellido, dni, estado) VALUES (?, ?, ?, 'activo')");
                        $stmtIns->execute([$nombre, $apellido, $dni]);
                        $idChofer = $db->lastInsertId();
                        $username = strtolower(substr($nombre, 0, 1) . $apellido);
                        $checkUser = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ?");
                        $checkUser->execute([$username]);
                        if ($checkUser->fetchColumn() == 0) {
                            $db->prepare("INSERT INTO usuarios (username, password, email, rol, id_chofer, activo) VALUES (?,?,?, 'chofer', ?, 1)")
                               ->execute([$username, password_hash($dni, PASSWORD_DEFAULT), "$username@importado.com", $idChofer]);
                        }
                    }
                }

                // Resolver camion
                $stmtCa = $db->prepare("SELECT id_camion FROM camiones WHERE patente = ? LIMIT 1");
                $stmtCa->execute([$patente]);
                $idCamion = $stmtCa->fetchColumn();

                if (!$idCamion) {
                    $stmtIns = $db->prepare("INSERT INTO camiones (patente, marca, modelo, estado, tipo) VALUES (?, 'Importado', 'Importado', 'activo', 'camion')");
                    $stmtIns->execute([$patente]);
                    $idCamion = $db->lastInsertId();
                }

                // Insertar combustible
                $stmtIns = $db->prepare("INSERT INTO combustible (fecha, id_chofer, id_camion, estacion_servicio, litros, precio_litro, kilometraje_al_cargar, id_usuario_registra) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$fechaDt, $idChofer, $idCamion, $estacion, $litros, $precioLitro, $kmAlCargar, $idUsuario]);
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
        $mensaje = "Importacion completada: $importados importados.";
    }
}
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
<div class="flex flex-col md:flex-row md:items-center justify-between mb-8 gap-4">
<div>
<h2 class="font-headline-lg text-headline-lg text-primary">Importar Combustible</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Cargue cargas de combustible historicas desde datos tabulados.</p>
</div>
<a href="<?= BASE_URL ?>/admin/combustible.php" class="bg-secondary-container text-on-secondary-container px-6 py-3 rounded-lg font-bold flex items-center gap-2 hover:opacity-90 transition-opacity">
<span class="material-symbols-outlined">local_gas_station</span> Gestionar Combustible
</a>
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
<pre class="bg-surface-container-high p-3 rounded-lg text-xs font-mono mb-3">FECHA &lt;TAB&gt; CHOFER(ID o Nombre) &lt;TAB&gt; PATENTE &lt;TAB&gt; ESTACION &lt;TAB&gt; LITROS &lt;TAB&gt; PRECIO_LITRO &lt;TAB&gt; KM_AL_CARGAR (opcional)</pre>
<p class="text-xs text-on-surface-variant mb-3">Ejemplo: <code class="bg-surface-container-high px-1 rounded">1/6/2026 07:24	93	AE480GN	YPF	166,43	2317	184377</code></p>
<textarea name="datos" rows="15" class="w-full border border-outline-variant rounded p-3 bg-surface-container-low font-mono text-sm" placeholder="Pegue los datos aqui..."><?= htmlspecialchars($_POST['datos'] ?? '') ?></textarea>
</div>
<button type="submit" name="importar" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold hover:opacity-90 transition-opacity flex items-center gap-2">
<span class="material-symbols-outlined">upload</span> Importar Combustible
</button>
</form>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
