<?php
session_start();

// Remove all session data
session_unset();
session_destroy();

// Redirect to login page
header("Location: http://localhost/invoice-management-system/login.php");
exit;
?>