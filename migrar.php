<?php
require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Migración de Base de Datos</title>
    <style>
        body { font-family: sans-serif; margin: 40px; line-height: 1.6; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        .card { border: 1px solid #ccc; padding: 20px; border-radius: 8px; max-width: 600px; }
    </style>
</head>
<body>
    <div class='card'>
        <h2>Ejecutando Migraciones en la Nube...</h2>";

try {
    $db = getDB();
    echo "<p class='info'>Conectado a la base de datos: <strong>" . DB_NAME . "</strong></p>";
    
    // 1. Column horas_al_cargar in combustible table
    $stmt = $db->query("DESCRIBE combustible");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('horas_al_cargar', $cols)) {
        $db->exec("ALTER TABLE combustible ADD COLUMN horas_al_cargar DECIMAL(12,2) DEFAULT NULL AFTER kilometraje_al_cargar");
        echo "<p class='success'>&check; Columna 'horas_al_cargar' agregada a la tabla 'combustible'.</p>";
    } else {
        echo "<p class='info'>&bull; La columna 'horas_al_cargar' ya existe en 'combustible'.</p>";
    }
    
    // 2. Column horas_actuales in camiones table
    $stmt2 = $db->query("DESCRIBE camiones");
    $cols2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('horas_actuales', $cols2)) {
        $db->exec("ALTER TABLE camiones ADD COLUMN horas_actuales DECIMAL(12,2) DEFAULT 0 AFTER kilometraje_actual");
        echo "<p class='success'>&check; Columna 'horas_actuales' agregada a la tabla 'camiones'.</p>";
    } else {
        echo "<p class='info'>&bull; La columna 'horas_actuales' ya existe en 'camiones'.</p>";
    }

    // 3. New calculation columns in combustible table
    $newCols = [
        'km_recorridos' => 'DECIMAL(12,2) DEFAULT NULL AFTER horas_al_cargar',
        'km_por_litro' => 'DECIMAL(12,2) DEFAULT NULL AFTER km_recorridos',
        'litros_cada_100km' => 'DECIMAL(12,2) DEFAULT NULL AFTER km_por_litro',
        'costo_por_km' => 'DECIMAL(12,2) DEFAULT NULL AFTER litros_cada_100km',
        'hs_recorridas' => 'DECIMAL(12,2) DEFAULT NULL AFTER costo_por_km',
        'litros_por_hora' => 'DECIMAL(12,2) DEFAULT NULL AFTER hs_recorridas',
        'costo_por_hora' => 'DECIMAL(12,2) DEFAULT NULL AFTER litros_por_hora',
        'error_consumo' => 'VARCHAR(255) DEFAULT NULL AFTER costo_por_hora'
    ];

    foreach ($newCols as $colName => $colDef) {
        if (!in_array($colName, $cols)) {
            $db->exec("ALTER TABLE combustible ADD COLUMN $colName $colDef");
            echo "<p class='success'>&check; Columna '$colName' agregada a la tabla 'combustible'.</p>";
        } else {
            echo "<p class='info'>&bull; La columna '$colName' ya existe en 'combustible'.</p>";
        }
    }

    // 4. Indexes for efficient queries
    // Check indexes on combustible table
    $stmtIdx = $db->query("SHOW INDEX FROM combustible");
    $indexes = $stmtIdx->fetchAll(PDO::FETCH_COLUMN, 2); // Key_name is index 2

    if (!in_array('idx_combustible_fecha', $indexes)) {
        $db->exec("ALTER TABLE combustible ADD INDEX idx_combustible_fecha (fecha)");
        echo "<p class='success'>&check; Índice 'idx_combustible_fecha' agregado a 'combustible'.</p>";
    } else {
        echo "<p class='info'>&bull; El índice 'idx_combustible_fecha' ya existe.</p>";
    }

    if (!in_array('idx_combustible_km', $indexes)) {
        $db->exec("ALTER TABLE combustible ADD INDEX idx_combustible_km (kilometraje_al_cargar)");
        echo "<p class='success'>&check; Índice 'idx_combustible_km' agregado a 'combustible'.</p>";
    } else {
        echo "<p class='info'>&bull; El índice 'idx_combustible_km' ya existe.</p>";
    }
    // 5. Recalcular todas las cargas existentes
    try {
        $stmtC = $db->query("SELECT id_camion FROM camiones");
        $camionesIds = $stmtC->fetchAll(PDO::FETCH_COLUMN);
        $recalcCount = 0;
        foreach ($camionesIds as $idCam) {
            recalcularCombustibleCamion($idCam);
            $recalcCount++;
        }
        echo "<p class='success'>&check; Se recalcularon las cargas de combustible de $recalcCount camiones históricos.</p>";
    } catch (Exception $e) {
        echo "<p class='error'>Error al recalcular cargas existentes: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "<h3 class='success'>¡Migración exitosa!</h3>";
    echo "<p><strong>IMPORTANTE:</strong> Por seguridad, elimina el archivo <code>migrar.php</code> de tu servidor después de ejecutarlo.</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>Error al ejecutar la migración: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "  </div>
</body>
</html>";
