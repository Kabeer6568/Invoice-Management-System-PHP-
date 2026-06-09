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

// Get invoice data
$stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    header("Location: index.php");
    exit();
}

// Don't allow editing paid invoices
if ($invoice['payment_status'] == 'Paid') {
    header("Location: view.php?id=$id&error=paid");
    exit();
}

// Get clients and projects
$clients = $db->query("SELECT id, name, company FROM clients ORDER BY name");
$projects = $db->query("SELECT id, project_name FROM projects WHERE client_id = {$invoice['client_id']}");

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    $client_id = (int)$_POST['client_id'];
    $project_id = (int)$_POST['project_id'];
    $invoice_date = $_POST['invoice_date'];
    $due_date = $_POST['due_date'];
    $amount = (float)$_POST['amount'];
    $tax = (float)$_POST['tax'];
    $discount = (float)$_POST['discount'];
    $total = $amount + $tax - $discount;
    $notes = trim($_POST['notes']);
    
    $remaining = $total - $invoice['paid_amount'];
    if ($remaining < 0) $remaining = 0;
    
    $status = $invoice['payment_status'];
    if ($invoice['paid_amount'] >= $total) {
        $status = 'Paid';
        $remaining = 0;
    } elseif ($invoice['paid_amount'] > 0) {
        $status = 'Partial';
    }
    
    $stmt = $db->prepare("UPDATE invoices SET client_id=?, project_id=?, invoice_date=?, due_date=?, amount=?, tax=?, discount=?, total=?, remaining_amount=?, payment_status=?, notes=? WHERE id=?");
    $stmt->bind_param("iissddddssi", $client_id, $project_id, $invoice_date, $due_date, $amount, $tax, $discount, $total, $remaining, $status, $notes, $id);
    
    if ($stmt->execute()) {
        logActivity($_SESSION['admin_id'], 'UPDATE_INVOICE', "Updated invoice ID: $id");
        $success = "Invoice updated successfully!";
        // Refresh data
        $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
    } else {
        $error = "Error updating invoice: " . $db->error;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Invoice</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <script>
        function calculateTotal() {
            let amount = parseFloat(document.getElementById('amount').value) || 0;
            let tax = parseFloat(document.getElementById('tax').value) || 0;
            let discount = parseFloat(document.getElementById('discount').value) || 0;
            let total = amount + tax - discount;
            document.getElementById('total_display').innerHTML = '$' + total.toFixed(2);
        }
    </script>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Edit Invoice: <?php echo escape($invoice['invoice_number']); ?></h1>
            <a href="index.php" class="btn-secondary">Back to Invoices</a>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>
        
        <div class="alert alert-info">
            <strong>Note:</strong> Paid amount: Rs.<?php echo number_format($invoice['paid_amount'], 2); ?> cannot be edited here. Use Payment Tracking module.
        </div>
        
        <form method="POST" class="form-container" onchange="calculateTotal()" onkeyup="calculateTotal()">
            <?php echo csrfField(); ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Client *</label>
                    <select name="client_id" required>
                        <?php while($client = $clients->fetch_assoc()): ?>
                        <option value="<?php echo $client['id']; ?>" <?php echo ($invoice['client_id'] == $client['id']) ? 'selected' : ''; ?>>
                            <?php echo escape($client['name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Project *</label>
                    <select name="project_id" required>
                        <?php 
                        $proj_stmt = $db->prepare("SELECT id, project_name FROM projects WHERE client_id = ?");
                        $proj_stmt->bind_param("i", $invoice['client_id']);
                        $proj_stmt->execute();
                        $projs = $proj_stmt->get_result();
                        while($proj = $projs->fetch_assoc()):
                        ?>
                        <option value="<?php echo $proj['id']; ?>" <?php echo ($invoice['project_id'] == $proj['id']) ? 'selected' : ''; ?>>
                            <?php echo escape($proj['project_name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Invoice Date *</label>
                    <input type="date" name="invoice_date" value="<?php echo $invoice['invoice_date']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="date" name="due_date" value="<?php echo $invoice['due_date']; ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Amount *</label>
                    <input type="number" step="0.01" name="amount" id="amount" value="<?php echo $invoice['amount']; ?>" required onchange="calculateTotal()">
                </div>
                
                <div class="form-group">
                    <label>Tax</label>
                    <input type="number" step="0.01" name="tax" id="tax" value="<?php echo $invoice['tax']; ?>" onchange="calculateTotal()">
                </div>
                
                <div class="form-group">
                    <label>Discount</label>
                    <input type="number" step="0.01" name="discount" id="discount" value="<?php echo $invoice['discount']; ?>" onchange="calculateTotal()">
                </div>
            </div>
            
            <div class="form-group">
                <label>Total Amount</label>
                <div id="total_display" class="total-display">Rs.<?php echo number_format($invoice['total'], 2); ?></div>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3"><?php echo escape($invoice['notes']); ?></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Update Invoice</button>
        </form>
    </div>
</body>
</html>