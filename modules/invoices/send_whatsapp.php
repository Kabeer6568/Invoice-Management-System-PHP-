<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    die("Invalid invoice ID");
}

// Get invoice data with client info
$stmt = $db->prepare("SELECT i.*, c.name as client_name, c.phone, c.email 
                      FROM invoices i 
                      JOIN clients c ON i.client_id = c.id 
                      WHERE i.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    die("Invoice not found");
}

$error = '';
$success = '';

// Clean phone number (remove spaces, dashes, plus signs)
$phone = preg_replace('/[^0-9]/', '', $invoice['phone']);
// Add Pakistan country code if not present (92)
if (substr($phone, 0, 2) != '92' && strlen($phone) == 10) {
    $phone = '92' . $phone;
}

// Generate PDF file path
$pdf_dir = '../../temp_invoices/';
if (!file_exists($pdf_dir)) {
    mkdir($pdf_dir, 0777, true);
}

$pdf_filename = 'Invoice_' . $invoice['invoice_number'] . '.pdf';
$pdf_path = $pdf_dir . $pdf_filename;

// Generate PDF if not exists
if (!file_exists($pdf_path)) {
    // Include PDF generation function
    ob_start();
    include 'generate_pdf.php';
    $pdf_html = ob_get_clean();
    
    // Use HTML2PDF or similar to generate PDF
    // For now, we'll use the PDF generation from your existing pdf.php
    $pdf_url = "http://" . $_SERVER['HTTP_HOST'] . "/invoice-management-system/modules/invoices/pdf.php?id=" . $id . "&download=1";
    
    // Download the PDF to temp folder
    $pdf_content = file_get_contents($pdf_url);
    file_put_contents($pdf_path, $pdf_content);
}

// Generate PDF download link
$pdf_download_link = "http://" . $_SERVER['HTTP_HOST'] . "/invoice-management-system/modules/invoices/pdf.php?id=" . $id . "&download=1";

// Custom message
$custom_message = "Dear " . $invoice['client_name'] . ",

Thank you for your business! Please find your invoice attached or download it here:

" . $pdf_download_link . "

Invoice #: " . $invoice['invoice_number'] . "
Total Amount: Rs. " . number_format($invoice['total'], 2) . "
Paid Amount: Rs. " . number_format($invoice['paid_amount'], 2) . "
Remaining Amount: Rs. " . number_format($invoice['remaining_amount'], 2) . "
Due Date: " . date('d M Y', strtotime($invoice['due_date'])) . "

For any questions, please contact us at info@ozbix.com

Best Regards,
Ozbix IT Solutions
+92 213 2226060";

// Encode message for URL
$encoded_message = urlencode($custom_message);

// WhatsApp API URLs ()
$whatsapp_urls = [
    'direct' => "https://api.whatsapp.com/send?phone=" . $phone . "&text=" . $encoded_message,
    'web' => "https://web.whatsapp.com/send?phone=" . $phone . "&text=" . $encoded_message,
    'api' => "https://wa.me/" . $phone . "?text=" . $encoded_message
];


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Invoice via WhatsApp</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .whatsapp-container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .whatsapp-header {
            background: #25D366;
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .whatsapp-header h2 {
            margin: 0;
            font-size: 24px;
        }
        
        .whatsapp-body {
            padding: 30px;
        }
        
        .client-info-card {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .message-preview {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-line;
            border-left: 4px solid #25D366;
        }
        
        .whatsapp-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            flex-wrap: wrap;
        }
        
        .btn-whatsapp {
            background: #25D366;
            color: white;
            flex: 1;
            text-align: center;
            text-decoration: none;
        }
        
        .btn-whatsapp:hover {
            background: #128C7E;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            text-align: center;
        }
        
        .qr-code {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .note {
            background: #fff3cd;
            padding: 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 20px;
        }
        
        @media (max-width: 600px) {
            .whatsapp-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="whatsapp-container">
        <div class="whatsapp-header">
            <h2>Send Invoice via WhatsApp</h2>
        </div>
        
        <div class="whatsapp-body">
            <div class="client-info-card">
                <h3>Client Information</h3>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($invoice['client_name']); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($invoice['phone']); ?></p>
                <p><strong>Invoice #:</strong> <?php echo $invoice['invoice_number']; ?></p>
                <p><strong>Total Amount:</strong> <?php echo $invoice['total']; ?></p>
                <p><strong>Paid Amout:</strong> <?php echo $invoice['paid_amount']; ?></p>
                <p><strong>Remaining Amount:</strong> Rs. <?php echo number_format($invoice['remaining_amount'], 2); ?></p>
            </div>
            
            <h3>Message Preview</h3>
            <div class="message-preview">
                <?php echo nl2br(htmlspecialchars($custom_message)); ?>
            </div>
            
            <div class="whatsapp-buttons">
                <a href="<?php echo $whatsapp_urls['api']; ?>" target="_blank" class="btn-primary btn-whatsapp" style="display: inline-block; padding: 12px;">
                    Send via WhatsApp Mobile
                </a>
                <a href="<?php echo $whatsapp_urls['web']; ?>" target="_blank" class="btn-primary btn-whatsapp" style="display: inline-block; padding: 12px;">
                    Send via WhatsApp Web
                </a>
            </div>
            
            <div class="qr-code">
                <h3>Scan to Open on Mobile</h3>
                <?php
                // Generate QR code using Google Chart API
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($whatsapp_urls['api']);
                ?>
                <img src="<?php echo $qr_url; ?>" alt="WhatsApp QR Code">
                <p style="margin-top: 10px; font-size: 12px; color: #666;">
                    Scan with your phone camera to open WhatsApp
                </p>
            </div>
            
            <div class="note">
                <strong> Note:</strong> 
                After clicking the button, you will need to manually attach the PDF file. 
                <a href="pdf.php?id=<?php echo $id; ?>&download=1" target="_blank">Click here to download the invoice PDF</a>
                to attach it to the WhatsApp message.
            </div>
            
            <div style="margin-top: 20px; text-align: center;">
                <a href="view.php?id=<?php echo $id; ?>" class="btn-secondary" style="padding: 10px 20px;">← Back to Invoice</a>
            </div>
        </div>
    </div>
</body>
</html>