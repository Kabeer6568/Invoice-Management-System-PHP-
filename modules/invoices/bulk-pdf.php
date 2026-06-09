<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

$ids = isset($_GET['ids']) ? $_GET['ids'] : '';
$invoice_ids = [];

if ($ids) {
    $invoice_ids = explode(',', $ids);
} elseif (isset($_POST['invoice_ids'])) {
    $invoice_ids = $_POST['invoice_ids'];
}

if (empty($invoice_ids)) {
    die("No invoices selected for bulk download");
}

// Sanitize IDs
$invoice_ids = array_map('intval', $invoice_ids);
$placeholders = implode(',', array_fill(0, count($invoice_ids), '?'));

$sql = "SELECT i.*, c.name as client_name, c.company, c.address, c.email, c.phone,
        p.project_name, p.description as project_description
        FROM invoices i 
        JOIN clients c ON i.client_id = c.id 
        JOIN projects p ON i.project_id = p.id 
        WHERE i.id IN ($placeholders)
        ORDER BY i.invoice_date DESC";

$stmt = $db->prepare($sql);
$types = str_repeat('i', count($invoice_ids));
$stmt->bind_param($types, ...$invoice_ids);
$stmt->execute();
$invoices = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bulk Invoices Export</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            padding: 20px;
        }
        .invoice-wrapper {
            margin-bottom: 40px;
            page-break-after: always;
        }
        .invoice-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #667eea;
        }
        .company-info h2 {
            color: #667eea;
            margin-bottom: 10px;
        }
        .invoice-info {
            text-align: right;
        }
        .invoice-info h3 {
            color: #667eea;
            margin-bottom: 10px;
        }
        .bill-to, .project-info {
            margin-bottom: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        th {
            background: #f5f5f5;
        }
        .text-right {
            text-align: right;
        }
        .total-row {
            background: #f5f5f5;
            font-weight: bold;
        }
        .balance-row {
            background: #e8f4f8;
            font-weight: bold;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        @media print {
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <?php 
    $count = 0;
    while($invoice = $invoices->fetch_assoc()): 
        $count++;
        // Get payment history for this invoice
        $pay_stmt = $db->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
        $pay_stmt->bind_param("i", $invoice['id']);
        $pay_stmt->execute();
        $payments = $pay_stmt->get_result();
    ?>
    <div class="invoice-wrapper">
        <div class="invoice-container">
            <div class="header">
                <div class="company-info">
                    <h2>YOUR COMPANY NAME</h2>
                    <p>123 Business Street<br>
                    City, State 12345<br>
                    Phone: (555) 123-4567<br>
                    Email: info@company.com</p>
                </div>
                <div class="invoice-info">
                    <h3>INVOICE <?php echo $count; ?> of <?php echo count($invoice_ids); ?></h3>
                    <p><strong>Invoice #:</strong> <?php echo $invoice['invoice_number']; ?><br>
                    <strong>Date:</strong> <?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?><br>
                    <strong>Due Date:</strong> <?php echo date('Y-m-d', strtotime($invoice['due_date'])); ?><br>
                    <strong>Status:</strong> <?php echo $invoice['payment_status']; ?></p>
                </div>
            </div>
            
            <div class="bill-to">
                <h3>Bill To:</h3>
                <p><strong><?php echo htmlspecialchars($invoice['client_name']); ?></strong><br>
                <?php echo htmlspecialchars($invoice['company']); ?><br>
                <?php echo nl2br(htmlspecialchars($invoice['address'])); ?></p>
            </div>
            
            <div class="project-info">
                <h3>Project:</h3>
                <p><?php echo htmlspecialchars($invoice['project_name']); ?></p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo htmlspecialchars($invoice['project_name']); ?> - Services</td>
                        <td class="text-right">$<?php echo number_format($invoice['amount'], 2); ?></td>
                    </tr>
                    <?php if($invoice['tax'] > 0): ?>
                    <tr>
                        <td>Tax</td>
                        <td class="text-right">$<?php echo number_format($invoice['tax'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if($invoice['discount'] > 0): ?>
                    <tr>
                        <td>Discount</td>
                        <td class="text-right">-$<?php echo number_format($invoice['discount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td class="text-right"><strong>$<?php echo number_format($invoice['total'], 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Paid Amount</td>
                        <td class="text-right">$<?php echo number_format($invoice['paid_amount'], 2); ?></td>
                    </tr>
                    <tr class="balance-row">
                        <td><strong>Balance Due</strong></td>
                        <td class="text-right"><strong>$<?php echo number_format($invoice['remaining_amount'], 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            
            <div class="footer">
                <p>Thank you for your business!</p>
            </div>
        </div>
    </div>
    <?php 
    endwhile; 
    ?>
    <script>
        window.print();
    </script>
</body>
</html>