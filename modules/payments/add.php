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

// Function to generate unique reference number
function generateUniqueReference($invoice_id, $payment_method) {
    global $db;
    
    // Format: PAY-[INV_ID]-[TIMESTAMP]-[RANDOM]
    // Example: PAY-123-20241215-143022-A7B3
    
    $prefix = 'PAY';
    $timestamp = date('Ymd-His');
    // $random = strtoupper(substr(uniqid(), -4));
    $reference = "{$prefix}-{$invoice_id}-{$timestamp}";
    
    // Check if reference already exists (extremely rare but safe)
    $check = $db->prepare("SELECT COUNT(*) FROM payments WHERE reference_number = ?");
    $check->bind_param("s", $reference);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc()['COUNT(*)'] > 0;
    
    if ($exists) {
        // If somehow exists, add more random chars
        $reference .= '-' . strtoupper(substr(md5(uniqid()), 0, 4));
    }
    
    return $reference;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    $invoice_id = (int)$_POST['invoice_id'];
    $amount = (float)$_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $manual_reference = trim($_POST['reference_number']);
    $notes = trim($_POST['notes']);
    
    // Validate amount doesn't exceed remaining
    $stmt = $db->prepare("SELECT remaining_amount, invoice_number FROM invoices WHERE id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $remaining = $invoice['remaining_amount'];
    $invoice_number = $invoice['invoice_number'];
    
    if ($amount <= 0) {
        $error = "Amount must be greater than 0!";
    } elseif ($amount > $remaining) {
        $error = "Payment amount ($amount) exceeds remaining balance ($remaining)!";
    } else {
        // Generate unique reference number
        // Priority: Manual reference > Auto-generated unique reference
        if (!empty($manual_reference)) {
            // Check if manual reference is unique
            $check = $db->prepare("SELECT COUNT(*) FROM payments WHERE reference_number = ?");
            $check->bind_param("s", $manual_reference);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc()['COUNT(*)'] > 0;
            
            if ($exists) {
                $error = "Reference number '$manual_reference' already exists! Please use a different one or leave blank for auto-generation.";
            } else {
                $reference_number = $manual_reference;
            }
        } else {
            // Auto-generate unique reference
            $reference_number = generateUniqueReference($invoice_id, $payment_method);
        }
        
        if (empty($error)) {
            // Insert payment with unique reference
            $stmt = $db->prepare("INSERT INTO payments (invoice_id, amount, payment_date, payment_method, reference_number, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("idssss", $invoice_id, $amount, $payment_date, $payment_method, $reference_number, $notes);
            
            if ($stmt->execute()) {
                // Update invoice paid amount
                $stmt = $db->prepare("UPDATE invoices SET paid_amount = paid_amount + ? WHERE id = ?");
                $stmt->bind_param("di", $amount, $invoice_id);
                $stmt->execute();
                
                // Update invoice status
                updateInvoiceStatus($invoice_id);
                
                // Determine payment type for log message
                $is_full_payment = ($amount == $remaining);
                $payment_type = $is_full_payment ? "FULL PAYMENT" : "PARTIAL PAYMENT";
                
                logActivity($_SESSION['admin_id'], 'ADD_PAYMENT', 
                    "{$payment_type} - Added payment of Rs.{$amount} to invoice #{$invoice_number} (ID: {$invoice_id}) | Reference: {$reference_number}");
                
                $success = "Payment recorded successfully!<br>";
                $success .= "<strong>Reference Number:</strong> {$reference_number}<br>";
                $success .= "<strong>Amount:</strong> Rs." . number_format($amount, 2) . "<br>";
                $success .= "<strong>Type:</strong> " . ($is_full_payment ? "Full Payment" : "Partial Payment");
                
                // Redirect back if from invoice view
                if ($selected_invoice) {
                    header("Location: ../invoices/view.php?id=$selected_invoice&msg=payment_added&ref=" . urlencode($reference_number));
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .reference-info {
            background: #e8f0fe;
            padding: 10px;
            border-radius: 4px;
            margin-top: 5px;
            font-size: 12px;
            color: #0066cc;
        }
        
        .auto-ref-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Record Payment</h1>
            <a href="index.php" class="btn-secondary">Back to Payments</a>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
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
                    <input type="number" step="0.01" name="amount" 
                           value="<?php echo isset($_POST['amount']) ? $_POST['amount'] : ($invoice_data ? $invoice_data['remaining_amount'] : ''); ?>" 
                           required>
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
                        <option value="Cash">Cash</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Credit Card">Credit Card</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Reference Number</label>
                    <input type="text" name="reference_number" 
                           placeholder="Leave blank for auto-generation"
                           value="<?php echo isset($_POST['reference_number']) ? escape($_POST['reference_number']) : ''; ?>">
                    <div class="reference-info">
                        <strong>Auto-Reference Format:</strong> PAY-[INVOICE_ID]-[TIMESTAMP]<br>
                    </div>
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