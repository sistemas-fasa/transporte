<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'admin/dashboard.php' : 'chofer/panel.php'));
} else {
    header('Location: login.php');
}
exit;
