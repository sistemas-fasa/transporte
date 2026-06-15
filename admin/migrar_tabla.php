<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$pageTitle = 'Migrar Tabla';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';

$db = getDB();
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Verificar si existe la tabla km_recorrido VIEJA
        $oldExists = $db->query("SHOW TABLES LIKE 'km_recorrido'")->fetch();
        if ($oldExists) {
            // Verificar si es la vieja (no tiene columna id_hoja)
            $cols = $db->query("SHOW COLUMNS FROM km_recorrido")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('id_hoja', $cols)) {
                $db->exec("DROP TABLE km_recorrido");
                $mensaje .= "Tabla vieja 'km_recorrido' eliminada. ";
            } else {
                $mensaje .= "La tabla 'km_recorrido' ya es la nueva (tiene id_hoja). ";
            }
        }

        // 2. Verificar si existe hoja_ruta y renombrar
        $hrExists = $db->query("SHOW TABLES LIKE 'hoja_ruta'")->fetch();
        if ($hrExists) {
            $db->exec("RENAME TABLE hoja_ruta TO km_recorrido");
            $mensaje .= "Tabla 'hoja_ruta' renombrada a 'km_recorrido'. ";
        } else {
            $mensaje .= "La tabla 'hoja_ruta' no existe (ya fue migrada). ";
        }

        if (empty($mensaje)) $mensaje = "No se realizaron cambios.";
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}
?>
<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-3xl mx-auto">
<div class="mb-8">
<h2 class="font-headline-lg text-headline-lg text-primary">Migrar Tabla</h2>
<p class="font-body-md text-body-md text-on-surface-variant">Renombrar <code>hoja_ruta</code> a <code>km_recorrido</code> y eliminar tabla vieja.</p>
</div>
<?php if ($mensaje): ?><div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="POST" id="formMigrar" class="bg-surface-container-lowest border border-outline-variant p-6 rounded-xl">
<p class="mb-4 text-on-surface-variant">Esta operacion renombrara la tabla <code>hoja_ruta</code> a <code>km_recorrido</code> y eliminara la tabla vieja <code>km_recorrido</code> si existe (del sistema anterior).</p>
<div class="bg-amber-50 border border-amber-200 text-amber-700 px-4 py-3 rounded-lg mb-4 font-bold">Haga una copia de seguridad antes de continuar.</div>
<button type="submit" class="bg-primary text-on-primary px-6 py-3 rounded-lg font-bold hover:opacity-90" onclick="event.preventDefault(); showConfirm('¿Esta seguro? Se eliminara la tabla vieja km_recorrido si existe.', function(){ document.getElementById('formMigrar').submit(); });">Ejecutar Migracion</button>
</form>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
