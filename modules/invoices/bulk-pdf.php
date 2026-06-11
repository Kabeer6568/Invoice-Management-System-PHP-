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
        LEFT JOIN projects p ON i.project_id = p.id 
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
    <title>Bulk Invoices — Ozbix IT Solutions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/pdf.css">
    <style>
        
    </style>
</head>
<body>

<div class="screen-actions">
    <button class="btn-print" onclick="window.print()">Print All Invoices</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<?php 
$count = 0;
$total_invoices = count($invoice_ids);
while($invoice = $invoices->fetch_assoc()): 
    $count++;
    
    // Get payment history for this invoice
    $pay_stmt = $db->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
    $pay_stmt->bind_param("i", $invoice['id']);
    $pay_stmt->execute();
    $payments = $pay_stmt->get_result();
?>

<div class="invoice-wrapper">
    <div class="bulk-counter">
        Invoice <?php echo $count; ?> of <?php echo $total_invoices; ?>
    </div>
    
    <div class="invoice">
        <!-- HEADER -->
        <div class="inv-header">
            <div class="header-top">
                <div class="brand-block">
                    <div class="brand-wordmark">OZ<span>Bi</span>X</div>
                    <div class="brand-sub">Empowered by Innovation</div>
                </div>
                <div class="inv-label-block">
                    <div class="inv-word">Invoice</div>
                    <div class="inv-number-line">
                        <div class="inv-number-label">Invoice No.</div>
                        <div class="inv-number-val"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                    </div>
                </div>
            </div>

            <div class="header-date-strip">
                <div class="hds-item">
                    <div class="hds-label">Issue Date</div>
                    <div class="hds-val"><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></div>
                </div>
                <div class="hds-sep"></div>
                <div class="hds-item">
                    <div class="hds-label">Due Date</div>
                    <div class="hds-val"><?php echo date('d M Y', strtotime($invoice['due_date'])); ?></div>
                </div>
                <div class="hds-status">
                    <span class="status-pill status-<?php echo strtolower($invoice['payment_status']); ?>">
                        <?php echo htmlspecialchars($invoice['payment_status']); ?>
                    </span>
                </div>
            </div>

            <div class="header-arc"></div>
        </div>

        <!-- BODY -->
        <div class="inv-body">
            <!-- Client + Bank Details -->
            <div class="info-grid">
                <div>
                    <div class="section-eyebrow">Billed To</div>
                    <div class="client-name"><?php echo htmlspecialchars($invoice['client_name']); ?></div>
                    <div class="client-company"><?php echo htmlspecialchars($invoice['company']); ?></div>
                    <div>
                        <?php if ($invoice['phone']): ?>
                        <div class="contact-line"><span class="contact-dot"></span><?php echo htmlspecialchars($invoice['phone']); ?></div>
                        <?php endif; ?>
                        <?php if ($invoice['email']): ?>
                        <div class="contact-line"><span class="contact-dot"></span><?php echo htmlspecialchars($invoice['email']); ?></div>
                        <?php endif; ?>
                        <?php if ($invoice['address']): ?>
                        <div class="contact-line"><span class="contact-dot"></span><?php echo nl2br(htmlspecialchars($invoice['address'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="section-eyebrow">Payment Details</div>
                    <div class="payment-card">
                        <div class="payment-row"><span class="pr-label">Bank</span><span class="pr-value">Meezan Bank</span></div>
                        <div class="payment-row"><span class="pr-label">Branch</span><span class="pr-value">Soldier Bazar Branch</span></div>
                        <div class="payment-row"><span class="pr-label">Account Name</span><span class="pr-value">Ozbix IT Solutions</span></div>
                        <div class="payment-row"><span class="pr-label">Account No.</span><span class="pr-value mono">01790114502628</span></div>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="items-section">
                <div class="section-eyebrow">Services</div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width:75%">Description</th>
                            <th class="r">Amount (PKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><div class="item-title"><?php echo htmlspecialchars($invoice['project_name'] ?? 'Services'); ?></div></td>
                            <td class="r"><?php echo number_format($invoice['amount'], 0); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Totals -->
            <div class="totals-section">
                <div class="totals-box">
                    <div class="total-row">
                        <span class="tr-label">Subtotal</span>
                        <span class="tr-value"><?php echo number_format($invoice['amount'], 0); ?></span>
                    </div>

                    <?php if ($invoice['tax'] > 0): ?>
                    <div class="total-row">
                        <span class="tr-label">Tax</span>
                        <span class="tr-value"><?php echo number_format($invoice['tax'], 0); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($invoice['discount'] > 0): ?>
                    <div class="total-row">
                        <span class="tr-label">Discount</span>
                        <span class="tr-value">&minus;<?php echo number_format($invoice['discount'], 0); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="grand-total">
                        <span class="gt-label">Total Due</span>
                        <div class="gt-amount-wrap">
                            <span class="gt-currency">PKR</span>
                            <span class="gt-amount"><?php echo number_format($invoice['total'], 0); ?></span>
                        </div>
                    </div>

                    <?php if ($invoice['paid_amount'] > 0): ?>
                    <div class="balance-due-row">
                        <span class="bd-label">Paid: PKR <?php echo number_format($invoice['paid_amount'], 0); ?> &nbsp;|&nbsp; Balance Due</span>
                        <span class="bd-value">PKR <?php echo number_format($invoice['remaining_amount'], 0); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment History -->
            <?php if ($payments->num_rows > 0): ?>
            <div class="history-section">
                <div class="section-eyebrow">Payment History</div>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($payment = $payments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></d>
                            <td>PKR <?php echo number_format($payment['amount'], 0); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></d>
                            <td><?php echo htmlspecialchars($payment['reference_number']); ?></d>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Terms -->
            <div class="terms-block">
                <div class="terms-icon">§</div>
                <div>
                    <div class="terms-title">Terms &amp; Conditions</div>
                    <div class="terms-text">Payments are due within 10 days of the invoice date. Kindly pay at your earliest convenience.</div>
                </div>
            </div>

            <!-- Footer -->
            <div class="inv-footer">
                <div class="footer-contact">
                    <div class="fc-item"><span class="fc-dot"></span>billing@ozbix.com</div>
                    <div class="fc-item"><span class="fc-dot"></span>+923-111-456-324</div>
                    <div class="fc-item"><span class="fc-dot"></span>www.ozbix.com</div>
                </div>
                <div class="footer-tagline">Thank you for your business.</div>
            </div>
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