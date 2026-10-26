<?php
require_once 'session.php';
admin_session_start();
admin_no_cache();

$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    admin_session_destroy();
    header('Location: login.php');
    exit;
}

// Default: redirect
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;