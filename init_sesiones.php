<?php
require_once __DIR__ . '/includes/session_db.php';

$error = '';

try {
    $pdo = new PDO("mysql:host=localhost;charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $pdo->exec("USE `" . DB_NAME . "`");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sesiones (
        id_sesion INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expira DATETIME NOT NULL,
        ultimo_acceso DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
        INDEX (token),
        INDEX (expira)
    ) ENGINE=InnoDB");
    $success = 'Tabla sesiones creada correctamente.';
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Inicializar Sesiones</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:Inter,sans-serif;background:#f7f9fb;}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="bg-white border border-gray-200 rounded-xl p-8 w-full max-w-lg text-center">
<h1 class="text-2xl font-bold mb-4">Inicializar Sesiones DB</h1>
<?php if ($error): ?>
<div class="bg-red-50 text-red-700 p-4 rounded-lg"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
<div class="bg-green-50 text-green-700 p-4 rounded-lg"><?= $success ?></div>
<p class="mt-4"><a href="login.php" class="text-[#091426] font-bold underline">Ir al Login</a></p>
<?php endif; ?>
</div>
</body>
</html>
