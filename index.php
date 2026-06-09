<?php
session_start();


if (isset($_SESSION['admin_id'])) {
    header("Location: http://localhost/invoice-management-system/dashboard.php");
    exit;
} else {
    header("Location: http://localhost/invoice-management-system/login.php");
    exit;
}
?>