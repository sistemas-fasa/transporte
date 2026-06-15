<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<h2>Test de Session PHP</h2>
<?php
echo "<p>session_id(): " . session_id() . "</p>";
echo "<p>session_save_path(): " . session_save_path() . "</p>";
echo "<p>session_status(): " . session_status() . "</p>";

if (isset($_GET['set'])) {
    $_SESSION['test'] = 'valor_' . time();
    $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
    session_write_close();
    echo "<p style='color:green'>Session SETEADA: test=" . $_SESSION['test'] . "</p>";
    echo "<p><a href='?check=1'>Verificar session</a></p>";
} elseif (isset($_GET['check'])) {
    echo "<p>Valor en session: " . (isset($_SESSION['test']) ? $_SESSION['test'] : 'NO HAY VALOR') . "</p>";
    echo "<p>IP: " . ($_SESSION['ip'] ?? 'NO HAY IP') . "</p>";
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
    echo "<p><a href='?'>Volver</a></p>";
} else {
    echo "<p><a href='?set=1'>Click para setear session y verificar</a></p>";
}
echo "<hr><p><a href='/sistema/login.php'>Volver al login</a></p>";
