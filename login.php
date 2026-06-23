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
<title>Iniciar Sesion - Gestion de Vehiculos</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
<meta name="mobile-web-app-capable" content="yes"/>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/Logo/Logo_App.png"/>
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/Logo/Logo_App.png"/>
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json"/>
<script>
if ('serviceWorker' in navigator) {
navigator.serviceWorker.register('<?= BASE_URL ?>/service-worker.js');
}
</script>
<style>
.material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); min-height: 100dvh; }
.login-card { animation: modalFadeIn 0.4s ease; backdrop-filter: blur(20px); }
@keyframes modalFadeIn { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.input-modern { transition: all 0.2s ease; }
.input-modern:focus { border-color: #0f172a; box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.1); }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-[420px]">
<div class="text-center mb-8 animate-[modalFadeIn_0.4s_ease]">
<div class="mx-auto mb-4 w-20 h-20 flex items-center justify-center">
<img src="<?= BASE_URL ?>/Logo/Logo_App.png" alt="Logo" class="max-w-full max-h-full object-contain"/>
</div>
<h1 class="text-[28px] font-bold text-[#0f172a] tracking-tight">Gestion de Vehiculos</h1>
<p class="text-[#64748b] text-sm mt-1">Inicie sesion para continuar</p>
</div>

<div class="login-card bg-white/90 backdrop-blur-xl border border-white/50 shadow-xl shadow-black/5 rounded-2xl p-8">
<?php if ($error): ?>
<div class="bg-red-50/80 border border-red-200 text-red-600 px-4 py-3 rounded-xl mb-6 text-sm flex items-center gap-2">
<span class="material-symbols-outlined text-red-500 text-base">error</span>
<?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<form method="POST" class="space-y-5">
<div>
<label class="text-xs font-bold uppercase text-[#64748b] tracking-wide">Usuario</label>
<div class="relative mt-1.5">
<input name="username" type="text" class="input-modern w-full border border-[#e2e8f0] rounded-xl p-3 bg-white/80 focus:outline-none placeholder:text-[#94a3b8]" placeholder="Ingrese su usuario" required/>
<span class="material-symbols-outlined absolute right-3 top-3 text-[#94a3b8]">person</span>
</div>
</div>

<div>
<label class="text-xs font-bold uppercase text-[#64748b] tracking-wide">Contrasena</label>
<div class="relative mt-1.5">
<input name="password" type="password" class="input-modern w-full border border-[#e2e8f0] rounded-xl p-3 bg-white/80 focus:outline-none placeholder:text-[#94a3b8]" placeholder="Ingrese su contrasena" required/>
<span class="material-symbols-outlined absolute right-3 top-3 text-[#94a3b8]">lock</span>
</div>
</div>

<button type="submit" class="btn-modern w-full bg-[#0f172a] text-white py-3 rounded-xl font-bold flex items-center justify-center gap-2">
<span class="material-symbols-outlined">login</span>
Iniciar Sesion
</button>
</form>
</div>

<p class="text-center text-xs text-[#94a3b8] mt-8">Sistema de Gestion de Vehiculos v2.0</p>
</div>
</body>
</html>
