<?php
require_once __DIR__ . '/config/database.php';
echo "<pre>";
$tablas = ['vehiculos', 'chofer', 'km_recorrido', 'usuarios', 'localidad', 'lugar_de_carga'];
$db = getDB();
foreach ($tablas as $t) {
    try {
        $stmt = $db->query("DESCRIBE $t");
        echo "<strong>Tabla: $t</strong>\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['Field']} ({$row['Type']})\n";
        }
        echo "\n";
    } catch (Exception $e) {
        echo "<strong>Tabla: $t</strong> - ERROR: {$e->getMessage()}\n\n";
    }
}
echo "</pre>";
