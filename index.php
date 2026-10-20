<?php
require_once __DIR__ . '/config/app.php';
session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: ' . app_url('dashboard.php'));
    exit;
}

header('Location: ' . app_url('login.php'));
exit;