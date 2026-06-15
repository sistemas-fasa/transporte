<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$error = '';

define('DB_HOST', 'localhost');
define('DB_NAME', 'c0860365_sistema');
define('DB_USER', 'c0860365_sistema');
define('DB_PASS', '96gasasoBA');
define('BASE_URL', '/sistema');

require_once __DIR__ . '/includes/session_db.php';
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya esta logueado via DB session, redirigir
if (dbSessionStart()) {
    $destino = $_SESSION['user_rol'] === 'admin' ? BASE_URL . '/admin/dashboard.php' : BASE_URL . '/chofer/panel.php';
    header('Location: ' . $destino);
    exit;
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Complete todos los campos';
    } elseif (usuarioBloqueado($username)) {
        $error = 'Cuenta bloqueada temporalmente por muchos intentos fallidos. Intente en 15 minutos.';
    } else {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = ? AND activo = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                resetearIntentos($username);
                $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id_usuario = ?")->execute([$user['id_usuario']]);
                dbSessionLogin($user['id_usuario'], $user['rol'], $user['username'], $user['id_chofer']);
                registrarAcceso($user['id_usuario'], 'inicio_sesion', 'Login', null, "Inicio de sesion exitoso");
                $destino = $user['rol'] === 'admin' ? BASE_URL . '/admin/dashboard.php' : BASE_URL . '/chofer/panel.php';
                header('Location: ' . $destino);
                exit;
            } else {
                registrarIntentoFallido($username);

                // Verificar si debe bloquearse después de 5 intentos
                $stmt2 = $pdo->prepare("SELECT intentos_fallidos FROM usuarios WHERE username = ?");
                $stmt2->execute([$username]);
                $intentos = (int)($stmt2->fetchColumn() ?: 0);
                if ($intentos >= 5) {
                    bloquearUsuario($username);
                    $error = 'Cuenta bloqueada temporalmente por muchos intentos fallidos. Intente en 15 minutos.';
                } else {
                    $error = 'Usuario o contrasena incorrectos. Intento ' . $intentos . ' de 5.';
                }
            }
        } catch (Exception $e) {
            $error = 'Error de conexion a la base de datos. Contacte al administrador.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Iniciar Sesion - Gestion de Flota</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
.material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
body { font-family: 'Inter', sans-serif; background: #f7f9fb; }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-[420px]">
<div class="text-center mb-8">
<div class="w-16 h-16 bg-[#091426] rounded-full flex items-center justify-center mx-auto mb-4">
<span class="material-symbols-outlined text-white text-3xl">local_shipping</span>
</div>
<h1 class="text-[32px] font-bold text-[#091426]">Gestion de Flota</h1>
<p class="text-gray-500 text-sm mt-1">Inicie sesion para continuar</p>
</div>

<div class="bg-white border border-gray-200 rounded-xl p-8">
<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6 text-sm"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" class="space-y-5">
<div>
<label class="text-xs font-bold uppercase text-gray-500">Usuario</label>
<div class="relative mt-1">
<input name="username" type="text" class="w-full border border-gray-300 rounded-lg p-3 bg-gray-50 focus:ring-2 focus:ring-[#091426] focus:outline-none" placeholder="Ingrese su usuario" required/>
<span class="material-symbols-outlined absolute right-3 top-3 text-gray-400">person</span>
</div>
</div>

<div>
<label class="text-xs font-bold uppercase text-gray-500">Contrasena</label>
<div class="relative mt-1">
<input name="password" type="password" class="w-full border border-gray-300 rounded-lg p-3 bg-gray-50 focus:ring-2 focus:ring-[#091426] focus:outline-none" placeholder="Ingrese su contrasena" required/>
<span class="material-symbols-outlined absolute right-3 top-3 text-gray-400">lock</span>
</div>
</div>

<button type="submit" class="w-full bg-[#091426] text-white py-3 rounded-lg font-bold hover:opacity-90 transition-opacity flex items-center justify-center gap-2">
<span class="material-symbols-outlined">login</span>
Iniciar Sesion
</button>
</form>
</div>

<p class="text-center text-xs text-gray-400 mt-6">Sistema de Gestion de Flota v2.0</p>
</div>
</body>
</html>
