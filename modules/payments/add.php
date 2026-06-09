<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$error = '';
$success = '';

$selected_invoice = isset($_GET['invoice']) ? (int)$_GET['invoice'] : 0;
$invoice_data = null;

if ($selected_invoice) {
    $stmt = $db->prepare("SELECT i.*, c.name as client_name, c.company 
                          FROM invoices i 
                          JOIN clients c ON i.client_id = c.id 
                          WHERE i.id = ?");
    $stmt->bind_param("i", $selected_invoice);
    $stmt->execute();
    $invoice_data = $stmt->get_result()->fetch_assoc();
}

// Get invoices with pending balance
$invoices = $db->query("SELECT i.id, i.invoice_number, i.remaining_amount, c.name as client_name 
                        FROM invoices i 
                        JOIN clients c ON i.client_id = c.id 
                        WHERE i.remaining_amount > 0 
                        ORDER BY i.due_date ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    $invoice_id = (int)$_POST['invoice_id'];
    $amount = (float)$_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $reference_number = trim($_POST['reference_number']);
    $notes = trim($_POST['notes']);
    
    // Validate amount doesn't exceed remaining
    $stmt = $db->prepare("SELECT remaining_amount FROM invoices WHERE id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $remaining = $stmt->get_result()->fetch_assoc()['remaining_amount'];
    
    if ($amount <= 0) {
        $error = "Amount must be greater than 0!";
    } elseif ($amount > $remaining) {
        $error = "Payment amount ($amount) exceeds remaining balance ($remaining)!";
    } else {
        // Insert payment
        $stmt = $db->prepare("INSERT INTO payments (invoice_id, amount, payment_date, payment_method, reference_number, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("idssss", $invoice_id, $amount, $payment_date, $payment_method, $reference_number, $notes);
        
        if ($stmt->execute()) {
            // Update invoice paid amount
            $stmt = $db->prepare("UPDATE invoices SET paid_amount = paid_amount + ? WHERE id = ?");
            $stmt->bind_param("di", $amount, $invoice_id);
            $stmt->execute();
            
            // Update invoice status
            updateInvoiceStatus($invoice_id);
            
            logActivity($_SESSION['admin_id'], 'ADD_PAYMENT', "Added payment of $$amount to invoice ID: $invoice_id");
            $success = "Payment recorded successfully!";
            
            // Redirect back if from invoice view
            if ($selected_invoice) {
                header("Location: ../invoices/view.php?id=$selected_invoice&msg=payment_added");
                exit();
            }
            
            // Clear form
            $_POST = array();
        } else {
            $error = "Error recording payment: " . $db->error;
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
    <title>Record Payment</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Record Payment</h1>
            <a href="index.php" class="btn-secondary">Back to Payments</a>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>
        
        <?php if($invoice_data): ?>
        <div class="alert alert-info">
            <strong>Invoice: <?php echo escape($invoice_data['invoice_number']); ?></strong><br>
            Client: <?php echo escape($invoice_data['client_name']); ?><br>
            Total: Rs.<?php echo number_format($invoice_data['total'], 2); ?> | 
            Paid: Rs.<?php echo number_format($invoice_data['paid_amount'], 2); ?> | 
            Remaining: Rs.<?php echo number_format($invoice_data['remaining_amount'], 2); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="form-container">
            <?php echo csrfField(); ?>
            
            <div class="form-group">
                <label>Select Invoice *</label>
                <select name="invoice_id" required <?php echo $selected_invoice ? 'disabled' : ''; ?>>
                    <option value="">Select Invoice</option>
                    <?php while($inv = $invoices->fetch_assoc()): ?>
                    <option value="<?php echo $inv['id']; ?>" <?php echo ($selected_invoice == $inv['id']) ? 'selected' : ''; ?>>
                        <?php echo escape($inv['invoice_number']); ?> - <?php echo escape($inv['client_name']); ?> (Balance: Rs.<?php echo number_format($inv['remaining_amount'], 2); ?>)
                    </option>
                    <?php endwhile; ?>
                </select>
                <?php if($selected_invoice): ?>
                <input type="hidden" name="invoice_id" value="<?php echo $selected_invoice; ?>">
                <?php endif; ?>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Payment Amount *</label>
                    <input type="number" step="0.01" name="amount" value="<?php echo isset($_POST['amount']) ? $_POST['amount'] : ($invoice_data ? $invoice_data['remaining_amount'] : ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Payment Date *</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="Bank Transfer">Bank Transfer</option>
                        
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Reference Number</label>
                    <input type="text" name="reference_number" placeholder="Check #, Transaction ID, etc.">
                </div>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Additional payment notes"></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Record Payment</button>
        </form>
    </div>
</body>
</html>