<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: index.php");
    exit();
}

// Get client data
$stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$client = $result->fetch_assoc();

if (!$client) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    $name = trim($_POST['name']);
    $company = trim($_POST['company']);
    $contact_person = trim($_POST['contact_person']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $client_type = $_POST['client_type'];
    $address = trim($_POST['address']);
    $notes = trim($_POST['notes']);
    
    if (empty($name)) {
        $error = "Client name is required!";
    } else {
        $stmt = $db->prepare("UPDATE clients SET name=?, company=?, contact_person=?, phone=?, email=?, client_type=?, address=?, notes=? WHERE id=?");
        $stmt->bind_param("ssssssssi", $name, $company, $contact_person, $phone, $email, $client_type, $address, $notes, $id);
        
        
        if ($stmt->execute()) {
            logActivity($_SESSION['admin_id'], 'UPDATE_CLIENT', "Updated client: $name (ID: $id)");
            $success = "Client updated successfully!";
            // Refresh data
            $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $client = $result->fetch_assoc();
        } else {
            $error = "Error updating client: " . $db->error;
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
    <title>Edit Client</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Edit Client</h1>
            <a href="index.php" class="btn-secondary">Back to Clients</a>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" class="form-container">
            <?php echo csrfField(); ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Client Name *</label>
                    <input type="text" name="name" value="<?php echo escape($client['name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Company</label>
                    <input type="text" name="company" value="<?php echo escape($client['company']); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" value="<?php echo escape($client['contact_person']); ?>">
                </div>
                
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?php echo escape($client['phone']); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo escape($client['email']); ?>">
                </div>
                <div class="form-group">
                    
                    <label>Client Type</label>
                    <select name="client_type" id="project_type">
                        <option value="before" <?php echo $client['client_type'] == 'before' ? 'selected' : ''; ?>>Before</option>
                        <option value="after"  <?php echo $client['client_type'] == 'after'  ? 'selected' : ''; ?>>After</option>
                    </select>
                
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" rows="3"><?php echo escape($client['address']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3"><?php echo escape($client['notes']); ?></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Update Client</button>
        </form>
    </div>
    <script type="text/javascript" src="../../assets/js/main.js"></script>
</body>
</html>