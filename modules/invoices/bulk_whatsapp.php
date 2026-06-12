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
    die("No invoices selected for bulk WhatsApp sending");
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

// Create temp directory for PDFs if needed
$temp_dir = '../../temp_invoices/';
if (!file_exists($temp_dir)) {
    mkdir($temp_dir, 0777, true);
}

// Get base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . $host . '/invoice-management-system';

// Process each invoice and generate WhatsApp links
$whatsapp_links = [];
$errors = [];

while ($invoice = $invoices->fetch_assoc()) {
    // Clean phone number
    $phone = preg_replace('/[^0-9]/', '', $invoice['phone']);
    if (substr($phone, 0, 2) != '92' && strlen($phone) == 10) {
        $phone = '92' . $phone;
    }
    
    if (empty($phone) || strlen($phone) < 10) {
        $errors[] = "Invalid phone number for Invoice #" . $invoice['invoice_number'] . " - Client: " . $invoice['client_name'];
        continue;
    }
    
    // Generate PDF download link
    $pdf_download_link = $base_url . "/modules/invoices/pdf.php?id=" . $invoice['id'] . "&download=1";
    
    $today = strtotime(date('Y-m-d'));
    $dueDate = strtotime($invoice['due_date']);

    // Custom message based on payment status
    if ($invoice['remaining_amount'] > 0 && $dueDate < $today) {
        $custom_message = "Dear " . $invoice['client_name'] . ",

This is a friendly reminder that payment for the following invoice is still outstanding.

 Invoice #: " . $invoice['invoice_number'] . "
 Total Amount: Rs. " . number_format($invoice['total'], 2) . "
 Paid Amount: Rs. " . number_format($invoice['paid_amount'], 2) . "
 Remaining Balance: Rs. " . number_format($invoice['remaining_amount'], 2) . "
 Due Date: " . date('d M Y', strtotime($invoice['due_date'])) . "

 You can view and download your invoice here:

" . $pdf_download_link . "

We kindly request you to arrange payment at your earliest convenience. If payment has already been made, please disregard this reminder and accept our thanks.

For any questions, please contact us at info@ozbix.com

Best Regards,
Ozbix IT Solutions
+92 213 2226060";

    }
    elseif ($invoice['remaining_amount'] > 0 && strtotime($invoice['invoice_date']) <= strtotime('-5 days')) {
        $custom_message = "Dear " . $invoice['client_name'] . ",

This is a friendly reminder that payment for the following invoice is still outstanding.:

You can view and download your invoice here:

" . $pdf_download_link . "

 Invoice #: " . $invoice['invoice_number'] . "
 Total Amount: Rs. " . number_format($invoice['total'], 2) . "
 Paid Amount: Rs. " . number_format($invoice['paid_amount'], 2) . "
 Remaining Balance: Rs. " . number_format($invoice['remaining_amount'], 2) . "
 Due Date: " . date('d M Y', strtotime($invoice['due_date'])) . "

We kindly request you to arrange payment at your earliest convenience. If payment has already been made, please disregard this reminder and accept our thanks.

For any questions, please contact us at info@ozbix.com

Best Regards,
Ozbix IT Solutions
+92 213 2226060";

    }
    elseif ($invoice['remaining_amount'] > 0) {
        $custom_message = "Dear " . $invoice['client_name'] . ",

Thank you for your business! Please find your invoice attached or download it here:

" . $pdf_download_link . "

 Invoice #: " . $invoice['invoice_number'] . "
 Total Amount: Rs. " . number_format($invoice['total'], 2) . "
 Paid Amount: Rs. " . number_format($invoice['paid_amount'], 2) . "
 Remaining Balance: Rs. " . number_format($invoice['remaining_amount'], 2) . "
 Due Date: " . date('d M Y', strtotime($invoice['due_date'])) . "

Please make payment before the due date to avoid late fees.

For any questions, please contact us at info@ozbix.com

Best Regards,
Ozbix IT Solutions
+92 213 2226060";
}
    else {
        $custom_message = "Dear " . $invoice['client_name'] . ",

Thank you for your business! Please find your invoice attached or download it here:

" . $pdf_download_link . "

 Invoice #: " . $invoice['invoice_number'] . "
 Total Amount: Rs. " . number_format($invoice['total'], 2) . "
 Paid Amount: Rs. " . number_format($invoice['paid_amount'], 2) . "
 Date: " . date('d M Y', strtotime($invoice['invoice_date'])) . "

This invoice has been fully paid. Thank you for your prompt payment!

For any questions, please contact us at info@ozbix.com

Best Regards,
Ozbix IT Solutions
+92 213 2226060";
    }
    
    // Encode message for URL
    $encoded_message = urlencode($custom_message);
    
    // WhatsApp links
    $whatsapp_links[] = [
        'invoice_id' => $invoice['id'],
        'invoice_number' => $invoice['invoice_number'],
        'client_name' => $invoice['client_name'],
        'phone' => $invoice['phone'],
        'payment_status'  => $invoice['payment_status'],   // add this
        'total'           => $invoice['total'],             // add this
        'paid_amount'     => $invoice['paid_amount'],       // add this
        'remaining_amount'=> $invoice['remaining_amount'],  // add this
        'whatsapp_url' => "https://wa.me/" . $phone . "?text=" . $encoded_message,
        'whatsapp_web_url' => "https://web.whatsapp.com/send?phone=" . $phone . "&text=" . $encoded_message,
        'message' => $custom_message,
        'pdf_link' => $pdf_download_link
    ];
}

$total_invoices = count($whatsapp_links);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk WhatsApp - Send Invoices</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/bulk_whatsapp.css">
    <style>
        
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1>Bulk WhatsApp Sender</h1>
            <p>Send invoices to clients via WhatsApp with personalized messages</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="error-box">
            <h4>Issues Found</h4>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="summary-card">
            <h3>Summary</h3>
            <div class="summary-stats">
                <div class="stat">
                    <span class="stat-label">Total Selected</span>
                    <span class="stat-value"><?php echo $total_invoices; ?> Invoice(s)</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Ready to Send</span>
                    <span class="stat-value"><?php echo count($whatsapp_links); ?> Client(s)</span>
                </div>
            </div>
        </div>

        <div class="invoices-grid">
            <?php foreach ($whatsapp_links as $index => $invoice_data): ?>
            <div class="invoice-card">
                <div class="card-header">
                    <span class="invoice-number"># <?php echo htmlspecialchars($invoice_data['invoice_number']); ?></span>
                    <span class="status-pill status-<?php echo strtolower($invoice_data['payment_status'] ?? 'pending'); ?>">
                        <?php echo $invoice_data['payment_status'] ?? 'Pending'; ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="client-info">
                        <div class="client-name"><?php echo htmlspecialchars($invoice_data['client_name']); ?></div>
                        <div class="client-phone"><?php echo htmlspecialchars($invoice_data['phone']); ?></div>
                    </div>
                    
                    <div class="amount-info">
                        <div class="amount-item">
                            <div class="amount-label">Total Amount</div>
                            <div class="amount-value">Rs. <?php echo number_format($invoice_data['total'] ?? 0, 2); ?></div>
                        </div>
                        <div class="amount-item">
                            <div class="amount-label">Paid Amount</div>
                                <div class="amount-value">Rs. <?php echo number_format($invoice_data['paid_amount'] ?? 0, 2); ?></div>                        </div>
                        <div class="amount-item">
                            <div class="amount-label">Remaining</div>
                                <div class="amount-value">Rs. <?php echo number_format($invoice_data['remaining_amount'] ?? 0, 2); ?></div>
                        </div>
                    </div>

                    <div class="message-preview">
                        <?php echo nl2br(htmlspecialchars($invoice_data['message'])); ?>
                    </div>

                    <div class="whatsapp-buttons">
                        <a href="<?php echo $invoice_data['whatsapp_url']; ?>" target="_blank" class="btn-whatsapp">
                            Send via WhatsApp Mobile
                        </a>
                        <a href="<?php echo $invoice_data['whatsapp_web_url']; ?>" target="_blank" class="btn-whatsapp btn-whatsapp-web">
                            Send via WhatsApp Web
                        </a>
                    </div>
                    
                    <div class="pdf-link">
                        <a href="<?php echo $invoice_data['pdf_link']; ?>" target="_blank">Download Invoice PDF</a> (Attach this to the WhatsApp message)
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($whatsapp_links) && empty($errors)): ?>
        <div class="error-box">
            <p>No valid invoices found to send. Please make sure invoices have valid phone numbers.</p>
        </div>
        <?php endif; ?>

        <div class="back-link">
            <a href="index.php" class="btn-secondary">← Back to Invoices</a>
        </div>
    </div>

    <script>
        // Add confirmation before opening WhatsApp links
        document.querySelectorAll('.btn-whatsapp, .btn-whatsapp-web').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const invoiceNumber = this.closest('.invoice-card')?.querySelector('.invoice-number')?.innerText || 'this invoice';
                if (!confirm(`You are about to send invoice ${invoiceNumber} via WhatsApp.\n\nMake sure you have downloaded and attached the PDF file.\n\nContinue?`)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>