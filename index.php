<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . getDefaultPage());
} else {
    header('Location: login.php');
}
exit;
