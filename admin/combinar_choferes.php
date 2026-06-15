<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Combinar Tabla Chofer';
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['combinar'])) {
    try {
        $db->exec("SET FOREIGN_KEY_CHECKS=0");

        // 1. Obtener estructura de la tabla vieja 'chofer'
        $oldCols = $db->query("DESCRIBE chofer")->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Asegurarnos que la tabla nueva 'choferes' tenga todas las columnas de la vieja
        foreach ($oldCols as $col) {
            $colName = $col['Field'];
            $colType = $col['Type'];
            if (strtolower($colName) === 'id' || strtolower($colName) === 'id_chofer') continue;
            
            try {
                $check = $db->query("SHOW COLUMNS FROM choferes LIKE '$colName'");
                if ($check->rowCount() == 0) {
                    $db->exec("ALTER TABLE choferes ADD COLUMN `$colName` $colType NULL");
                }
            } catch (Exception $e) {}
        }
        
        // 3. Limpiar tabla nueva por si tenía basura
        $db->exec("TRUNCATE TABLE choferes");

        // 4. Migrar los datos exactos mapeando 'id' -> 'id_chofer'
        $filas = $db->query("SELECT * FROM chofer")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as $fila) {
            $cols = [];
            $vals = [];
            $placeholders = [];
            
            foreach ($fila as $k => $v) {
                $targetCol = (strtolower($k) === 'id') ? 'id_chofer' : $k;
                $cols[] = "`$targetCol`";
                $vals[] = $v;
                $placeholders[] = "?";
            }
            
            $sql = "INSERT INTO choferes (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute($vals);
        }

        // 5. Eliminar la tabla vieja ya que los datos fueron migrados perfectamente
        $db->exec("DROP TABLE chofer");

        $db->exec("SET FOREIGN_KEY_CHECKS=1");

        $mensaje = "<div class='bg-green-100 text-green-800 p-4 rounded-lg mb-6'>¡Perfecto! Los datos y columnas se combinaron migrando la información de 'chofer' a la nueva tabla 'choferes' exitosamente.</div>";

    } catch (Exception $e) {
        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        $mensaje = "<div class='bg-red-100 text-red-800 p-4 rounded-lg mb-6'>Error al combinar: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar_admin.php';
?>

<main class="pt-20 pb-24 md:pb-8 md:pl-64 px-margin-mobile md:px-margin-desktop max-w-[1440px] mx-auto">
    <div class="bg-surface-container-lowest border border-outline-variant p-6 rounded-xl max-w-2xl mt-8">
        <h2 class="font-headline-md text-headline-md text-primary mb-2 uppercase tracking-wider">Combinar Tablas de Chofer</h2>
        <p class="text-on-surface-variant mb-6">Esta acción borrará la tabla nueva vacía <code>choferes</code>, tomará tu tabla original <code>chofer</code>, la renombrará a <code>choferes</code> y le agregará cualquier columna faltante (como DNI, estado, etc.) para que todo funcione a la perfección sin perder datos.</p>
        
        <?= $mensaje ?>

        <form method="POST" id="formCombinar">
            <button type="submit" name="combinar" class="bg-primary text-white font-bold py-3 px-6 rounded-lg flex items-center gap-2 hover:opacity-90 transition-opacity" onclick="event.preventDefault(); showConfirm('¿Estás seguro? Se reemplazará la tabla nueva por tu tabla original.', function(){ document.getElementById('formCombinar').submit(); });">
                <span class="material-symbols-outlined">merge</span>
                Combinar y Renombrar
            </button>
        </form>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
