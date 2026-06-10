<?php
session_start();

function isLoggedIn() {
    if (isset($_SESSION['admin_id']) && isset($_SESSION['last_activity'])) {
        // Session timeout after 30 minutes
        if (time() - $_SESSION['last_activity'] > 1800) {
            session_unset();
            session_destroy();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header("Location: http://localhost/invoice-management-system/login.php");
        exit();
    }
}

function logActivity($admin_id, $action, $description) {
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $db->prepare("INSERT INTO activity_logs (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $admin_id, $action, $description, $ip);
    $stmt->execute();
    $stmt->close();
}
?>