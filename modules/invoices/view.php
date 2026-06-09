<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: index.php");
    exit();
}

// Get invoice data with joins
$stmt = $db->prepare("SELECT i.*, c.name as client_name, c.company, c.address, c.email, c.phone,
                      p.project_name, p.description as project_description
                      FROM invoices i 
                      JOIN clients c ON i.client_id = c.id 
                      JOIN projects p ON i.project_id = p.id 
                      WHERE i.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    header("Location: index.php");
    exit();
}

// Get payment history
$stmt = $db->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
$stmt->bind_param("i", $id);
$stmt->execute();
$payments = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo escape($invoice['invoice_number']); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Invoice: <?php echo escape($invoice['invoice_number']); ?></h1>
            <div>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn-primary">Edit Invoice</a>
                <a href="pdf.php?id=<?php echo $id; ?>" class="btn-secondary" target="_blank">Download PDF</a>
                <a href="../payments/add.php?invoice=<?php echo $id; ?>" class="btn-secondary">Record Payment</a>
                <a href="index.php" class="btn-secondary">Back</a>
            </div>
        </div>
        
        <div class="invoice-details">
            <div class="invoice-header">
                <div class="company-info">
                    <h2>Your Company Name</h2>
                    <p>123 Business Street<br>City, State 12345<br>Phone: (555) 123-4567<br>Email: info@company.com</p>
                </div>
                <div class="invoice-info">
                    <h3>INVOICE</h3>
                    <p><strong>Invoice #:</strong> <?php echo escape($invoice['invoice_number']); ?></p>
                    <p><strong>Date:</strong> <?php echo date('F d, Y', strtotime($invoice['invoice_date'])); ?></p>
                    <p><strong>Due Date:</strong> <?php echo date('F d, Y', strtotime($invoice['due_date'])); ?></p>
                    <p><strong>Status:</strong> <span class="status-<?php echo strtolower($invoice['payment_status']); ?>"><?php echo $invoice['payment_status']; ?></span></p>
                </div>
            </div>
            
            <div class="bill-to">
                <h3>Bill To:</h3>
                <p><strong><?php echo escape($invoice['client_name']); ?></strong><br>
                <?php echo escape($invoice['company']); ?><br>
                <?php echo nl2br(escape($invoice['address'])); ?><br>
                Phone: <?php echo escape($invoice['phone']); ?><br>
                Email: <?php echo escape($invoice['email']); ?></p>
            </div>
            
            <div class="project-info">
                <h3>Project Details:</h3>
                <p><strong>Project:</strong> <?php echo escape($invoice['project_name']); ?></p>
                <p><?php echo nl2br(escape($invoice['project_description'])); ?></p>
            </div>
            
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo escape($invoice['project_name']); ?> - Services</td>
                        <td class="text-right">Rs.<?php echo number_format($invoice['amount'], 2); ?></td>
                    </tr>
                    <?php if($invoice['tax'] > 0): ?>
                    <tr>
                        <td>Tax</td>
                        <td class="text-right">Rs.<?php echo number_format($invoice['tax'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if($invoice['discount'] > 0): ?>
                    <tr>
                        <td>Discount</td>
                        <td class="text-right">-Rs.<?php echo number_format($invoice['discount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="text-right"><strong>Rs.<?php echo number_format($invoice['total'], 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Paid Amount</td>
                        <td class="text-right">Rs.<?php echo number_format($invoice['paid_amount'], 2); ?></td>
                    </tr>
                    <tr class="balance-row">
                        <td><strong>Balance Due</strong></td>
                        <td class="text-right"><strong>Rs.<?php echo number_format($invoice['remaining_amount'], 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            
            <?php if($payments->num_rows > 0): ?>
            <div class="payment-history">
                <h3>Payment History</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($payment = $payments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                                                         <td>Rs.<?php echo number_format($payment['amount'], 2); ?></td>
                            <td><?php echo escape($payment['payment_method']); ?></td>
                            <td><?php echo escape($payment['reference_number']); ?></td>
                            <td><?php echo escape($payment['notes']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if($invoice['notes']): ?>
            <div class="invoice-notes">
                <h3>Notes:</h3>
                <p><?php echo nl2br(escape($invoice['notes'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>