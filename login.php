<?php
session_start();
require_once 'config/database.php';
require_once 'includes/csrf.php';

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $stmt = $db->prepare("SELECT id, username, password, full_name FROM admins WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['admin_id'] = $row['id'];
            $_SESSION['admin_name'] = $row['full_name'];
            $_SESSION['last_activity'] = time();
            
            // Update last login
            $update = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
            $update->bind_param("i", $row['id']);
            $update->execute();
            
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid password!";
        }
    } else {
        $error = "Username not found!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Invoice Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <img width="100%" style="filter: brightness(0);" src="https://ozbix.com/wp-content/uploads/2024/07/LOGO-BOLD-copy.webp">
            <h2>Invoice Management System</h2>
            
            <h3>Admin Login</h3>
            <?php if($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <button style="width:100%" type="submit">Login</button>
            </form>
        </div>
    </div>
</body>
</html>