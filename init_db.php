<?php
$error = '';
$success = '';

try {
    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", "c0860365_sistema", "96gasasoBA", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `c0860365_sistema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `c0860365_sistema`");

    // Drop and recreate all tables to ensure correct structure
    $pdo->exec("DROP TABLE IF EXISTS auditoria");
    $pdo->exec("DROP TABLE IF EXISTS alertas");
    $pdo->exec("DROP TABLE IF EXISTS mantenimientos");
    $pdo->exec("DROP TABLE IF EXISTS combustible");
    $pdo->exec("DROP TABLE IF EXISTS km_recorrido");
    $pdo->exec("DROP TABLE IF EXISTS asignaciones");
    $pdo->exec("DROP TABLE IF EXISTS seguros");
    $pdo->exec("DROP TABLE IF EXISTS vtv");
    $pdo->exec("DROP TABLE IF EXISTS camiones");
    $pdo->exec("DROP TABLE IF EXISTS choferes");
    $pdo->exec("DROP TABLE IF EXISTS usuarios");

    // Read and execute fresh schema
    $schema = file_get_contents(__DIR__ . '/database/schema.sql');
    $schema = preg_replace('/CREATE DATABASE.*?;/is', '', $schema);
    $schema = preg_replace('/USE .*?;/i', '', $schema);
    $schema = preg_replace('/INSERT INTO usuarios.*?;/is', '', $schema);

    $statements = array_filter(array_map('trim', explode(';', $schema)));
    $count = 0;
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
            $count++;
        }
    }

    // Create/replace admin user
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ?");
    $stmt->execute(['admin']);
    if ($stmt->fetchColumn() > 0) {
        $pdo->prepare("UPDATE usuarios SET password = ?, rol = 'admin', activo = 1 WHERE username = ?")->execute([$hash, 'admin']);
        $success = 'Base actualizada. Admin actualizado: admin / admin123';
    } else {
        $pdo->prepare("INSERT INTO usuarios (username, password, email, rol, activo) VALUES (?, ?, 'admin@reciclarg.com.ar', 'admin', 1)")->execute(['admin', $hash]);
        $success = 'Base inicializada. Admin creado: admin / admin123';
    }

} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Inicializar BD</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>body{font-family:Inter,sans-serif;background:#f7f9fb;}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="bg-white border border-gray-200 rounded-xl p-8 w-full max-w-lg text-center">
<h1 class="text-2xl font-bold text-[#091426] mb-4">Inicializar Base de Datos</h1>
<?php if ($error): ?>
<div class="bg-red-50 text-red-700 p-4 rounded-lg"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
<div class="bg-green-50 text-green-700 p-4 rounded-lg"><?= $success ?></div>
<p class="mt-4"><a href="login.php" class="text-[#091426] font-bold underline">Ir al Login</a></p>
<?php endif; ?>
</div>
</body>
</html>
