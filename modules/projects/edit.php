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

// Get project data
$stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    header("Location: index.php");
    exit();
}

// Get clients
$clients = $db->query("SELECT id, name, company FROM clients ORDER BY name");

$error = '';
$success = '';

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
        $stmt = $db->prepare("UPDATE projects SET client_id=?, project_name=?, description=?, department=?, project_type=?, status=?, payment_status=?, start_date=?, end_date=?, cost=?, monthly_fee=?, notes=? WHERE id=?");
        $stmt->bind_param("isssssssdddsi", $client_id, $project_name, $description, $department, $project_type, $status, $payment_status, $start_date, $end_date, $cost, $monthly_fee, $notes, $id);
        
        if ($stmt->execute()) {
            logActivity($_SESSION['admin_id'], 'UPDATE_PROJECT', "Updated project: $project_name (ID: $id)");
            $success = "Project updated successfully!";
            // Refresh data
            $stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $project = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Error updating project: " . $db->error;
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
    <title>Edit Project</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Edit Project</h1>
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
                        <option value="<?php echo $client['id']; ?>" <?php echo ($project['client_id'] == $client['id']) ? 'selected' : ''; ?>>
                            <?php echo escape($client['name']); ?> (<?php echo escape($client['company']); ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Project Name *</label>
                    <input type="text" name="project_name" value="<?php echo escape($project['project_name']); ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Department</label>
                    <select name="department">
                        <option value="Web Dev" <?php echo $project['department'] == 'Web Dev' ? 'selected' : ''; ?>>Web Dev</option>
                        <option value="SEO" <?php echo $project['department'] == 'SEO' ? 'selected' : ''; ?>>SEO</option>
                        <option value="Marketing" <?php echo $project['department'] == 'Marketing' ? 'selected' : ''; ?>>Marketing</option>
                        <option value="Design" <?php echo $project['department'] == 'Design' ? 'selected' : ''; ?>>Design</option>
                        <option value="Social Media" <?php echo $project['department'] == 'Social Media' ? 'selected' : ''; ?>>Social Media</option>
                        <option value="Other" <?php echo $project['department'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Project Type</label>
                    <select name="project_type">
                        <option value="One-time" <?php echo $project['project_type'] == 'One-time' ? 'selected' : ''; ?>>One-time</option>
                        <option value="Monthly" <?php echo $project['project_type'] == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="In Progress" <?php echo $project['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="Hold" <?php echo $project['status'] == 'Hold' ? 'selected' : ''; ?>>Hold</option>
                        <option value="Completed" <?php echo $project['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Payment Status</label>
                    <select name="payment_status">
                        <option value="Pending" <?php echo $project['payment_status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Partial" <?php echo $project['payment_status'] == 'Partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="Paid" <?php echo $project['payment_status'] == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $project['start_date']; ?>">
                </div>
                
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $project['end_date']; ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>One-time Cost</label>
                    <input type="number" step="0.01" name="cost" value="<?php echo $project['cost']; ?>">
                </div>
                
                <div class="form-group">
                    <label>Monthly Fee</label>
                    <input type="number" step="0.01" name="monthly_fee" value="<?php echo $project['monthly_fee']; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3"><?php echo escape($project['description']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2"><?php echo escape($project['notes']); ?></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Update Project</button>
        </form>
    </div>
    <script type="text/javascript" src="../../assets/js/main.js"></script>
</body>
</html>