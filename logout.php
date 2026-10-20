<?php
require_once __DIR__ . '/config/app.php';
session_start();

session_unset();
session_destroy();

header('Location: ' . app_url('login.php'));
exit;
?>