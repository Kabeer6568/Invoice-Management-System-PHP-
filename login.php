<?php
session_start();
require_once 'config/database.php';
require_once 'includes/csrf.php';

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

// ── Brute-force protection ────────────────────────────────
if (!isset($_SESSION['login_attempts']))  $_SESSION['login_attempts']  = 0;
if (!isset($_SESSION['lockout_until']))   $_SESSION['lockout_until']   = 0;

$locked_out   = time() < $_SESSION['lockout_until'];
$lockout_secs = max(0, $_SESSION['lockout_until'] - time());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($locked_out) {
        $error = "Too many failed attempts. Please wait {$lockout_secs} seconds.";

    } else {
        validateCSRFToken($_POST['csrf_token']);

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Basic input length check
        if (strlen($username) > 50 || strlen($password) > 255) {
            $error = "Invalid credentials.";
        } else {
            $stmt = $db->prepare("SELECT id, username, password, full_name FROM admins WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            // Always run password_verify even if user not found
            // to prevent timing attacks that reveal valid usernames
            $hash = $row['password'] ?? '$2y$10$invalidhashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
            $valid = password_verify($password, $hash);

            if ($row && $valid) {
                // ── Successful login ──────────────────────────────
                $_SESSION['login_attempts'] = 0;
                $_SESSION['lockout_until']  = 0;

                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);

                $_SESSION['admin_id']       = $row['id'];
                $_SESSION['admin_name']     = $row['full_name'];
                $_SESSION['last_activity']  = time();

                // Update last login
                $update = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
                $update->bind_param("i", $row['id']);
                $update->execute();

                header("Location: dashboard.php");
                exit();

            } else {
                // ── Failed login ──────────────────────────────────
                $_SESSION['login_attempts']++;

                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['lockout_until']  = time() + 300; // 5 min lockout
                    $_SESSION['login_attempts'] = 0;
                    $error = "Too many failed attempts. Locked out for 5 minutes.";
                } else {
                    $remaining = 5 - $_SESSION['login_attempts'];
                    $error     = "Invalid credentials. {$remaining} attempt(s) remaining.";
                }
            }
        }
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

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($locked_out): ?>
                <div class="alert alert-error">
                    Account locked. Try again in <?php echo $lockout_secs; ?> seconds.
                </div>
            <?php else: ?>
            <form method="POST" autocomplete="off">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required maxlength="50" autocomplete="username">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required maxlength="255" autocomplete="current-password">
                </div>
                <button style="width:100%" type="submit" <?php echo $locked_out ? 'disabled' : ''; ?>>Login</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>