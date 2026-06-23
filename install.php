<?php
// Database installer - Run once to setup the system
$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? 'localhost';
    $db_name = $_POST['db_name'] ?? 'gestion_flota';
    $db_user = $_POST['db_user'] ?? 'root';
    $db_pass = $_POST['db_pass'] ?? '';
    $admin_user = $_POST['admin_user'] ?? 'admin';
    $admin_pass = $_POST['admin_pass'] ?? '';
    $base_url = $_POST['base_url'] ?? 'https://reciclarg.com.ar/sistema';

    try {
        // First connect without database to create it
        $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$db_name`");

        // Read and execute schema
        $schema = file_get_contents(__DIR__ . '/database/schema.sql');
        // Remove CREATE DATABASE and USE statements
        $schema = preg_replace('/CREATE DATABASE.*?;/i', '', $schema);
        $schema = preg_replace('/USE .*?;/i', '', $schema);
        $schema = preg_replace('/INSERT INTO usuarios.*?;/i', '', $schema);

        // Execute schema statements
        $statements = array_filter(array_map('trim', explode(';', $schema)));
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }

        // Create admin user with provided password
        $hash = password_hash($admin_pass ?: 'admin123', PASSWORD_DEFAULT);
        $email = "$admin_user@reciclarg.com.ar";
        $check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE username = ?");
        $check->execute([$admin_user ?: 'admin']);
        if ($check->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO usuarios (username, password, email, rol, activo) VALUES (?, ?, ?, 'admin', 1)");
            $stmt->execute([$admin_user ?: 'admin', $hash, $email]);
        }

        // Write config file
        $configContent = "<?php
define('DB_HOST', '$db_host');
define('DB_NAME', '$db_name');
define('DB_USER', '$db_user');
define('DB_PASS', '$db_pass');
define('BASE_URL', '$base_url');
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
// ... rest of config
";
        // Actually, let's update the existing config file
        $configPath = __DIR__ . '/config/database.php';
        $configContent = "<?php
define('DB_HOST', '$db_host');
define('DB_NAME', '$db_name');
define('DB_USER', '$db_user');
define('DB_PASS', '$db_pass');

define('BASE_URL', '$base_url');
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');

function getDB(): PDO {
    static \$pdo = null;
    if (\$pdo === null) {
        try {
            \$pdo = new PDO(
                \"mysql:host=\" . DB_HOST . \";dbname=\" . DB_NAME . \";charset=utf8mb4\",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException \$e) {
            die(\"Error de conexion: \" . \$e->getMessage());
        }
    }
    return \$pdo;
}

function registrarAuditoria(int \$id_usuario, string \$accion, ?string \$tabla = null, ?int \$id_registro = null, ?string \$detalle = null): void {
    \$db = getDB();
    \$ip = \$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    \$stmt = \$db->prepare(\"INSERT INTO auditoria (id_usuario, accion, tabla, id_registro, detalle, ip_address) VALUES (?, ?, ?, ?, ?, ?)\");
    \$stmt->execute([\$id_usuario, \$accion, \$tabla, \$id_registro, \$detalle, \$ip]);
}
";
        file_put_contents($configPath, $configContent);

        $success = 'Sistema instalado exitosamente!';
        $success .= '<br>Usuario: <strong>' . htmlspecialchars($admin_user ?: 'admin') . '</strong>';
        $success .= '<br>Contrasena: <strong>' . htmlspecialchars($admin_pass ?: 'admin123') . '</strong>';
        $success .= '<br><br><a href="login.php" style="display:inline-block;padding:12px 24px;background:#091426;color:white;text-decoration:none;border-radius:8px;font-weight:bold;">IR AL LOGIN</a>';
        $step = 3;

    } catch (Exception $e) {
        $error = 'Error de instalacion: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Instalacion - Gestion de Vehiculos</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="icon" type="image/png" href="/sistema/Logo/Logo_App.png"/>
<link rel="apple-touch-icon" href="/sistema/Logo/Logo_App.png"/>
<link rel="manifest" href="/sistema/manifest.json"/>
<style>
body { font-family: 'Inter', sans-serif; background: #f7f9fb; }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="bg-white border border-gray-200 rounded-xl p-8 w-full max-w-lg">
<div class="text-center mb-6">
<div class="w-16 h-16 bg-[#091426] rounded-full flex items-center justify-center mx-auto mb-4">
<span class="material-symbols-outlined text-white text-3xl" style="font-family:'Material Symbols Outlined'">local_shipping</span>
</div>
<h1 class="text-2xl font-bold text-[#091426]">Instalacion del Sistema</h1>
<p class="text-gray-500 text-sm mt-1">Gestion de Vehiculos de Camiones</p>
</div>

<?php if ($success): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg"><?= $success ?></div>
<?php else: ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($step == 1): ?>
<div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg mb-4">
<strong>Paso 1:</strong> Complete los datos de conexion a MySQL
</div>
<form method="POST" class="space-y-4">
<input type="hidden" name="step" value="2"/>
<div>
<label class="text-xs font-bold uppercase text-gray-500">Host MySQL</label>
<input name="db_host" value="localhost" class="w-full border border-gray-300 rounded p-3 text-sm bg-gray-50" required/>
</div>
<div>
<label class="text-xs font-bold uppercase text-gray-500">Base de Datos</label>
<input name="db_name" value="gestion_flota" class="w-full border border-gray-300 rounded p-3 text-sm bg-gray-50" required/>
</div>
<div>
<label class="text-xs font-bold uppercase text-gray-500">Usuario MySQL</label>
<input name="db_user" value="root" class="w-full border border-gray-300 rounded p-3 text-sm bg-gray-50" required/>
</div>
<div>
<label class="text-xs font-bold uppercase text-gray-500">Contrasena MySQL</label>
<input name="db_pass" type="password" class="w-full border border-gray-300 rounded p-3 text-sm bg-gray-50"/>
</div>
<hr class="border-gray-200"/>
<div>
<label class="text-xs font-bold uppercase text-gray-500">Usuario Admin</label>
<input name="admin_user" value="admin" class="w-full border border-gray-300 rounded p-3 text-sm bg-gray-50"/>
</div>
<div>
<label class="text-xs font-bold uppercase text-gray-500">Contrasena Admin</label>
<input name="admin_pass" type="text" value="admin123" class="w-full border border-gray-300 rounded p-3 text-sm bg-gray-50"/>
</div>
<div>
<label class="text-xs font-bold uppercase text-gray-500">URL Base</label>
<input name="base_url" value="https://reciclarg.com.ar/sistema" class="w-full border border-gray-300 rounded p-3 text-sm bg-gray-50"/>
</div>
<button type="submit" class="w-full bg-[#091426] text-white py-3 rounded-lg font-bold hover:opacity-90">Instalar Sistema</button>
</form>

<?php elseif ($step == 3): ?>
<p class="text-center text-gray-500">Ya puede comenzar a usar el sistema.</p>
<?php endif; ?>
<?php endif; ?>

<p class="text-center text-xs text-gray-400 mt-6">Sistema de Gestion de Vehiculos v1.0</p>
</div>
</body>
</html>
