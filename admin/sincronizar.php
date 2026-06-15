<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Sincronizar Datos Antiguos';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sincronizar'])) {
    try {
        $db->beginTransaction();

        // 1. Sincronizar Choferes
        $stmtOldChoferes = $db->query("SELECT * FROM chofer");
        $choferesViejos = $stmtOldChoferes->fetchAll();
        
        try {
            $db->exec("ALTER TABLE choferes ADD COLUMN correo VARCHAR(150) NULL");
        } catch (Exception $e) {}

        $insChofer = $db->prepare("INSERT IGNORE INTO choferes (id_chofer, nombre, apellido, dni, telefono, correo) VALUES (?, ?, ?, ?, ?, ?)");
        $insUser = $db->prepare("INSERT IGNORE INTO usuarios (username, password, email, rol, id_chofer, activo) VALUES (?, ?, ?, 'chofer', ?, 1)");
        
        $countChoferes = 0;
        foreach ($choferesViejos as $ch) {
            $nombre = $ch['nombre'] ?? 'Sin Nombre';
            $apellido = $ch['apellido'] ?? '';
            $dni = $ch['dni'] ?? rand(10000000, 99999999);
            $tel = $ch['telefono'] ?? '';
            $correo = $ch['correo'] ?? $ch['email'] ?? '';
            $id = $ch['id'] ?? $ch['id_chofer'] ?? null;
            if ($id) {
                $insChofer->execute([$id, $nombre, $apellido, $dni, $tel, $correo]);
                $countChoferes += $insChofer->rowCount();
                
                if (!empty($correo)) {
                    try {
                        $hash = password_hash($dni, PASSWORD_DEFAULT);
                        $insUser->execute([$correo, $hash, $correo, $id]);
                    } catch (Exception $e) {}
                }
            }
        }

        // 2. Sincronizar Camiones (Vehiculos)
        $countCamiones = 0;
        try {
            $stmtOldVehiculos = $db->query("SELECT * FROM vehiculos");
        } catch (Exception $e) {
            try {
                $stmtOldVehiculos = $db->query("SELECT * FROM camion");
            } catch (Exception $e) {
                $stmtOldVehiculos = null;
            }
        }
        
        if ($stmtOldVehiculos) {
            try {
                $db->exec("ALTER TABLE camiones ADD COLUMN vtv DATE NULL, ADD COLUMN tara DECIMAL(10,2) NULL");
            } catch (Exception $e) {}

            $vehiculosViejos = $stmtOldVehiculos->fetchAll();
            $insCamion = $db->prepare("INSERT IGNORE INTO camiones (id_camion, patente, marca, modelo, anio, vtv, tara) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($vehiculosViejos as $v) {
                $patente = $v['patente'] ?? 'S/P ' . rand(100,999);
                $marca = $v['marca'] ?? 'Desconocida';
                $modelo = $v['modelo'] ?? 'Desconocido';
                $anio = $v['anio'] ?? $v['año'] ?? null;
                $vtv = $v['vtv'] ?? null;
                $tara = $v['tara'] ?? null;
                $id = $v['id'] ?? $v['id_vehiculo'] ?? $v['id_camion'] ?? null;
                if ($id) {
                    $insCamion->execute([$id, $patente, $marca, $modelo, $anio, $vtv, $tara]);
                    $countCamiones += $insCamion->rowCount();
                }
            }
        }

        // 3. Sincronizar Combustible / KM Recorrido
        $countCombustible = 0;
        try {
            $stmtOldKm = $db->query("SELECT * FROM km_recorrido");
            $kmViejos = $stmtOldKm->fetchAll();
            $insCombustible = $db->prepare("INSERT IGNORE INTO combustible (fecha, id_chofer, id_camion, litros, precio_litro, kilometraje_al_cargar) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($kmViejos as $km) {
                $fecha = $km['fecha'] ?? date('Y-m-d H:i:s');
                $idCh = $km['id_chofer'] ?? 1;
                $idCa = $km['id_vehiculo'] ?? $km['id_camion'] ?? 1;
                $litros = $km['litros'] ?? 0;
                $precio = $km['precio'] ?? $km['importe'] ?? 0;
                $kmCarga = $km['km'] ?? $km['kilometraje'] ?? 0;
                if ($litros > 0) {
                    $insCombustible->execute([$fecha, $idCh, $idCa, $litros, $precio, $kmCarga]);
                    $countCombustible += $insCombustible->rowCount();
                }
            }
        } catch (Exception $e) {
            // Tabla km_recorrido quizas no existe o tiene otra estructura
        }

        $db->commit();
        $mensaje = "<div class='bg-green-100 text-green-800 p-4 rounded-lg mb-6'>Sincronización completada: $countChoferes choferes, $countCamiones camiones, $countCombustible registros de combustible importados.</div>";

    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = "<div class='bg-red-100 text-red-800 p-4 rounded-lg mb-6'>Error al sincronizar: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
    <div class="bg-surface-container-lowest border border-outline-variant p-6 rounded-xl max-w-2xl mt-8">
        <h2 class="font-headline-md text-headline-md text-primary mb-2 uppercase tracking-wider">Sincronizar Datos Antiguos</h2>
        <p class="text-on-surface-variant mb-6">Esta herramienta importará los datos de las tablas antiguas (<code>vehiculos</code>, <code>chofer</code>, <code>km_recorrido</code>) a las nuevas tablas del sistema (<code>camiones</code>, <code>choferes</code>, <code>combustible</code>).</p>
        
        <?= $mensaje ?>

        <form method="POST">
            <button type="submit" name="sincronizar" class="bg-primary text-white font-bold py-3 px-6 rounded-lg flex items-center gap-2 hover:opacity-90 transition-opacity">
                <span class="material-symbols-outlined">sync</span>
                Iniciar Sincronización
            </button>
        </form>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
