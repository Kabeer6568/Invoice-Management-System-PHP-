<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$error = '';
$success = '';

// Get clients for dropdown
$clients = $db->query("SELECT id, name, company FROM clients ORDER BY name");
$selected_client = isset($_GET['client']) ? (int)$_GET['client'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    $client_id = (int)$_POST['client_id'];
    $project_name = trim($_POST['project_name']);
    $description = trim($_POST['description']);
    $department = $_POST['department'];
    $project_type = $_POST['project_type'];
    $status = $_POST['status'];
    $payment_status = $_POST['payment_status'];
    $start_date = $_POST['start_date'] ?: null;
    $end_date = $_POST['end_date'] ?: null;
    $cost = (float)$_POST['cost'];
    $monthly_fee = (float)$_POST['monthly_fee'];
    $notes = trim($_POST['notes']);
    
    if (empty($project_name) || $client_id == 0) {
        $error = "Project name and client are required!";
    } else {
        $stmt = $db->prepare("INSERT INTO projects (client_id, project_name, description, department, project_type, status, payment_status, start_date, end_date, cost, monthly_fee, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssddds", $client_id, $project_name, $description, $department, $project_type, $status, $payment_status, $start_date, $end_date, $cost, $monthly_fee, $notes);
        
        if ($stmt->execute()) {
            $project_id = $db->insert_id;
            logActivity($_SESSION['admin_id'], 'CREATE_PROJECT', "Created project: $project_name (ID: $project_id)");
            $success = "Project created successfully!";
            
            // Auto-create invoice for one-time projects
            if ($project_type == 'One-time' && $cost > 0) {
                header("Location: ../invoices/create.php?project=$project_id&auto=1");
                exit();
            }
            
            // Clear form
            $_POST = array();
        } else {
            $error = "Error creating project: " . $db->error;
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
    <title>Add New Project</title>
    <link rel="stylesheet" href="http://localhost/invoice-management-system/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Add New Project</h1>
            <a href="index.php" class="btn-secondary">Back to Projects</a>
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
                    <label>Client *</label>
                    <select name="client_id" required>
                        <option value="">Select Client</option>
                        <?php while($client = $clients->fetch_assoc()): ?>
                        <option value="<?php echo $client['id']; ?>" <?php echo ($selected_client == $client['id']) ? 'selected' : ''; ?>>
                            <?php echo escape($client['name']); ?> (<?php echo escape($client['company']); ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Project Name *</label>
                    <input type="text" name="project_name" value="<?php echo isset($_POST['project_name']) ? escape($_POST['project_name']) : ''; ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Department</label>
                    <select name="department">
                        <option value="Web Dev">Web Dev</option>
                        <option value="SEO">SEO</option>
                        <option value="Marketing">Marketing</option>
                        <option value="Design">Design</option>
                        <option value="Social Media">Social Media</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Project Type</label>
                    <select name="project_type" id="project_type">
                        <option value="One-time">One-time</option>
                        <option value="Monthly">Monthly</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="In Progress">In Progress</option>
                        <option value="Hold">Hold</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Payment Status</label>
                    <select name="payment_status">
                        <option value="Pending">Pending</option>
                        <option value="Partial">Partial</option>
                        <option value="Paid">Paid</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date">
                </div>
                
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>One-time Cost</label>
                    <input type="number" step="0.01" name="cost" value="0.00">
                </div>
                
                <div class="form-group">
                    <label>Monthly Fee</label>
                    <input type="number" step="0.01" name="monthly_fee" value="0.00">
                </div>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3"><?php echo isset($_POST['description']) ? escape($_POST['description']) : ''; ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2"><?php echo isset($_POST['notes']) ? escape($_POST['notes']) : ''; ?></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Create Project</button>
        </form>
    </div>
    
    <script>
        // Auto update invoice creation suggestion
        document.getElementById('project_type').addEventListener('change', function() {
            if(this.value === 'One-time') {
                // Show one-time cost field
            }
        });
    </script>
</body>
</html>