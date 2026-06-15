<?php
if (session_status() === PHP_SESSION_NONE) session_start();
echo "<h2>Debug Session</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p><a href='login.php'>Volver al login</a></p>";
echo "<p><a href='admin/dashboard.php'>Ir a Dashboard</a></p>";
echo "<p><a href='chofer/panel.php'>Ir a Panel Chofer</a></p>";
?>
