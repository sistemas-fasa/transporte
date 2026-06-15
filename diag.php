<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
try {
    $pdo = new PDO("mysql:host=localhost;dbname=c0860365_sistema;charset=utf8mb4", "c0860365_sistema", "96gasasoBA", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "Conexion OK\n\n";
    $tablas = ['vehiculos', 'chofer', 'km_recorrido', 'usuarios', 'localidad', 'lugar_de_carga'];
    foreach ($tablas as $t) {
        $stmt = $pdo->query("DESCRIBE $t");
        echo "=== $t ===\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Key']} | {$row['Default']} | {$row['Extra']}\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
