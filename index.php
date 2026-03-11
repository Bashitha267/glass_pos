<?php
require_once 'auth.php';

if (isLoggedIn()) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: sale/dashboard.php');
    }
    exit;
} else {
    header('Location: login.php');
    exit;
}
?>
