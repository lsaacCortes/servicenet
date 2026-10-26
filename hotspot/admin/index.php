<?php
require_once 'session.php';
admin_session_start();
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;