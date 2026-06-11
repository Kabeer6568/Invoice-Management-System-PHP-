<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    $name = trim($_POST['name']);
    $company = trim($_POST['company']);
    $contact_person = trim($_POST['contact_person']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $notes = trim($_POST['notes']);
    
    // Validation
    if (empty($name)) {
        $error = "Client name is required!";
    } else {
        $stmt = $db->prepare("INSERT INTO clients (name, company, contact_person, phone, email, address, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $name, $company, $contact_person, $phone, $email, $address, $notes);
        
        if ($stmt->execute()) {
            $client_id = $db->insert_id;
            logActivity($_SESSION['admin_id'], 'CREATE_CLIENT', "Created new client: $name (ID: $client_id)");
            $success = "Client created successfully!";
            // Clear form
            $_POST = array();
        } else {
            $error = "Error creating client: " . $db->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Client</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Add New Client</h1>
            <a href="index.php" class="btn-secondary">Back to Clients</a>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <?php echo escape($success); ?>
                <a href="../projects/create.php?client=<?php echo $client_id; ?>" class="btn-add-project">
                    Create Project
                </a>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="form-container">
            <?php echo csrfField(); ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Client Name *</label>
                    <input type="text" name="name" value="<?php echo isset($_POST['name']) ? escape($_POST['name']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Company</label>
                    <input type="text" name="company" value="<?php echo isset($_POST['company']) ? escape($_POST['company']) : ''; ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" value="<?php echo isset($_POST['contact_person']) ? escape($_POST['contact_person']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?php echo isset($_POST['phone']) ? escape($_POST['phone']) : ''; ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo isset($_POST['email']) ? escape($_POST['email']) : ''; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" rows="3"><?php echo isset($_POST['address']) ? escape($_POST['address']) : ''; ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3"><?php echo isset($_POST['notes']) ? escape($_POST['notes']) : ''; ?></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Save Client</button>
        </form>
    </div>
</body>
</html>