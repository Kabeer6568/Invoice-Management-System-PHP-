<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

// Accept either a client_id (all unpaid) or specific invoice IDs
$client_id  = isset($_GET['client_id'])  ? (int)$_GET['client_id']  : 0;
$primary_id = isset($_GET['primary_id']) ? (int)$_GET['primary_id'] : 0; // newest invoice shown first

if (!$client_id) die("Invalid request.");

// Fetch all unpaid/partial/overdue invoices for this client
// Primary invoice (newest) first, then the rest ordered by date
$stmt = $db->prepare("
    SELECT i.*,
           c.name    AS client_name,
           c.company, c.address, c.email, c.phone
    FROM   invoices i
    JOIN   clients  c ON i.client_id = c.id
    WHERE  i.client_id      = ?
      AND  i.payment_status != 'Paid'
    ORDER  BY CASE WHEN i.id = ? THEN 0 ELSE 1 END ASC,
              i.invoice_date DESC
");
$stmt->bind_param('ii', $client_id, $primary_id);
$stmt->execute();
$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($invoices)) die("No unpaid invoices found for this client.");

// Gather all line items for all invoices
$allLineItems = [];
$grandTotal   = 0.00;
$grandPaid    = 0.00;

foreach ($invoices as $inv) {
    $liStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
    $liStmt->bind_param('i', $inv['id']);
    $liStmt->execute();
    $items = $liStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $liStmt->close();

    if (empty($items)) {
        // Fallback for old invoices without line items
        $items = [[
            'description' => 'Services — Invoice #' . $inv['invoice_number'],
            'amount'      => $inv['amount'],
            'project_id'  => null,
        ]];
    } else {
        // Tag each item with its invoice number for clarity
        foreach ($items as &$item) {
            $item['description'] = $item['description'] . ' [Inv #' . $inv['invoice_number'] . ']';
        }
        unset($item);
    }

    $allLineItems = array_merge($allLineItems, $items);
    $grandTotal  += $inv['total'];
    $grandPaid   += $inv['paid_amount'];
}

$grandRemaining = $grandTotal - $grandPaid;
$client         = $invoices[0]; // client info from first row
$newestInvoice  = $invoices[0]; // for header dates

// Collect invoice numbers for header
$invoiceNumbers = array_column($invoices, 'invoice_number');
$invoiceLabel   = count($invoiceNumbers) > 1
    ? 'Combined: ' . implode(', ', $invoiceNumbers)
    : $invoiceNumbers[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Combined Invoice — <?php echo htmlspecialchars($client['client_name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --red:       #A81E2A;
            --red-deep:  #7A1219;
            --red-light: #C8313F;
            --red-mist:  #FAF0F1;
            --red-pale:  #F3E0E2;
            --ink:       #1A1215;
            --ink-mid:   #4A3538;
            --ink-soft:  #8A7072;
            --rule:      #E8D8DA;
            --white:     #FFFFFF;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Outfit',sans-serif; background:#F0EAEA; padding:40px 20px; }

        .screen-actions { max-width:860px; margin:0 auto 18px; display:flex; gap:10px; justify-content:flex-end; }
        .screen-actions button { font-family:'Outfit',sans-serif; font-size:12px; font-weight:500; letter-spacing:.08em; text-transform:uppercase; border:1.5px solid var(--red); border-radius:3px; padding:7px 18px; cursor:pointer; transition:all .2s; }
        .btn-print { background:var(--red); color:#fff; }
        .btn-print:hover { background:var(--red-deep); }
        .btn-close  { background:transparent; color:var(--red); }
        .btn-close:hover  { background:var(--red-mist); }

        .invoice { max-width:860px; margin:0 auto; background:var(--white); position:relative; overflow:hidden; }
        .invoice::before { content:''; position:absolute; left:0; top:0; bottom:0; width:5px; background:linear-gradient(180deg,var(--red-light),var(--red-deep)); }

        /* Combined banner */
        .combined-banner { background:var(--red-mist); border-left:4px solid var(--red); padding:10px 20px 10px 57px; font-size:12px; color:var(--red-deep); font-weight:500; letter-spacing:.04em; }

        .inv-header { background:var(--ink); padding:42px 52px 0 57px; position:relative; overflow:hidden; }
        .inv-header::before { content:''; position:absolute; right:-60px; top:-60px; width:240px; height:240px; border-radius:50%; border:40px solid rgba(168,30,42,.18); }
        .inv-header::after  { content:''; position:absolute; right:80px; top:-100px; width:180px; height:180px; border-radius:50%; border:28px solid rgba(168,30,42,.09); }

        .header-top { display:flex; justify-content:space-between; align-items:flex-start; position:relative; z-index:1; }
        .brand-wordmark { font-size:42px; font-weight:300; color:#fff; letter-spacing:.12em; line-height:1; }
        .brand-wordmark span { color:var(--red-light); }
        .brand-sub { font-size:10px; letter-spacing:.28em; text-transform:uppercase; color:rgba(255,255,255,.83); margin-top:6px; }
        .inv-label-block { text-align:right; position:relative; z-index:1; }
        .inv-word { font-size:42px; font-weight:300; font-style:italic; color:#fff; letter-spacing:.05em; line-height:1; }
        .inv-number-label { font-size:9px; letter-spacing:.3em; text-transform:uppercase; color:rgba(255,255,255,.88); margin-top:6px; }
        .inv-number-val { font-size:13px; font-weight:500; color:#fff; letter-spacing:.04em; margin-top:2px; }

        .header-date-strip { display:flex; align-items:center; gap:24px; margin-top:32px; padding-top:18px; border-top:1px solid rgba(255,255,255,.08); position:relative; z-index:1; }
        .hds-label { font-size:9px; letter-spacing:.3em; text-transform:uppercase; color:rgba(255,255,255,.3); }
        .hds-val   { font-size:13px; color:rgba(255,255,255,.8); margin-top:3px; }
        .hds-sep   { width:1px; height:30px; background:rgba(255,255,255,.1); }
        .hds-status { margin-left:auto; }
        .status-pill { display:inline-block; font-size:10px; font-weight:600; letter-spacing:.12em; text-transform:uppercase; padding:5px 14px; border-radius:2px; }
        .status-pending { background:rgba(255,193,7,.15); color:#FFC107; border:1px solid rgba(255,193,7,.3); }
        .status-overdue { background:rgba(168,30,42,.2); color:var(--red-light); border:1px solid rgba(168,30,42,.3); }
        .status-partial { background:rgba(23,162,184,.15); color:#17A2B8; border:1px solid rgba(23,162,184,.3); }

        .header-arc { height:38px; background:var(--white); border-radius:50% 50% 0 0/100% 100% 0 0; margin-top:-1px; position:relative; z-index:2; }

        .inv-body { padding:10px 52px 48px 57px; }

        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:28px; margin-bottom:40px; padding-bottom:32px; border-bottom:1px solid var(--rule); }
        .section-eyebrow { font-size:9px; letter-spacing:.32em; text-transform:uppercase; color:var(--red); font-weight:600; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
        .section-eyebrow::after { content:''; flex:1; height:1px; background:var(--rule); }
        .client-name    { font-size:26px; font-weight:600; color:var(--ink); line-height:1.1; margin-bottom:4px; }
        .client-company { font-size:13px; font-weight:500; color:var(--red); margin-bottom:12px; }
        .contact-line   { font-size:12px; color:var(--ink-soft); display:flex; align-items:center; gap:8px; margin-bottom:5px; }
        .contact-dot    { width:4px; height:4px; border-radius:50%; background:var(--red); flex-shrink:0; }

        .payment-card { background:var(--red-mist); border:1px solid var(--red-pale); border-radius:6px; padding:20px 22px; position:relative; overflow:hidden; }
        .payment-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,var(--red),var(--red-light)); }
        .payment-row { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid var(--red-pale); }
        .payment-row:last-child { border-bottom:none; }
        .pr-label { font-size:11px; color:var(--ink-soft); }
        .pr-value { font-size:12px; font-weight:500; color:var(--ink); }
        .pr-value.mono { font-family:monospace; font-size:11px; }

        /* Invoice reference list */
        .inv-refs { margin-bottom:28px; padding:12px 16px; background:#f9f5f5; border-radius:4px; border-left:3px solid var(--red); }
        .inv-refs-title { font-size:10px; letter-spacing:.2em; text-transform:uppercase; color:var(--red); font-weight:600; margin-bottom:8px; }
        .inv-ref-row { display:flex; justify-content:space-between; font-size:12px; color:var(--ink-mid); padding:3px 0; border-bottom:1px solid var(--red-pale); }
        .inv-ref-row:last-child { border-bottom:none; }

        .items-section { margin-bottom:32px; }
        .items-table { width:100%; border-collapse:collapse; }
        .items-table thead tr { border-bottom:1.5px solid var(--ink); }
        .items-table th { font-size:9px; letter-spacing:.28em; text-transform:uppercase; font-weight:600; color:var(--ink-soft); padding:0 0 10px; text-align:left; }
        .items-table th.r { text-align:right; }
        .items-table tbody tr { border-bottom:1px solid var(--rule); }
        .items-table tbody tr:last-child { border-bottom:2px solid var(--ink); }
        .items-table td { padding:12px 0; font-size:13px; color:var(--ink); }
        .items-table td.r { text-align:right; font-weight:500; }
        .item-title { font-weight:500; font-size:13px; }
        .item-inv-tag { font-size:11px; color:var(--ink-soft); margin-top:2px; }

        /* Carried balance row */
        .item-row-balance td { background:var(--red-mist); }

        .totals-section { display:flex; justify-content:flex-end; margin-bottom:36px; }
        .totals-box { min-width:280px; }
        .total-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--rule); }
        .total-row:last-of-type { border-bottom:none; }
        .tr-label { font-size:12px; color:var(--ink-soft); }
        .tr-value { font-size:13px; color:var(--ink-mid); }

        .grand-total { background:var(--ink); padding:14px 18px; display:flex; justify-content:space-between; align-items:center; margin-top:10px; border-radius:4px; }
        .gt-label    { font-size:10px; letter-spacing:.28em; text-transform:uppercase; color:rgba(255,255,255,.45); }
        .gt-currency { font-size:11px; color:rgba(255,255,255,.35); margin-right:4px; }
        .gt-amount   { font-size:32px; font-weight:600; color:#fff; line-height:1; }
        .gt-amount-wrap { display:flex; align-items:baseline; gap:4px; }

        .balance-due-row { background:#fff8f0; border-radius:4px; padding:10px 14px; display:flex; justify-content:space-between; margin-top:8px; border:1px solid #f0d8b0; }
        .bd-label { font-size:11px; color:#a06020; }
        .bd-value { font-size:14px; font-weight:700; color:#a06020; }

        .terms-block { display:flex; gap:16px; align-items:flex-start; padding:16px 20px; background:var(--red-mist); border-left:3px solid var(--red); border-radius:0 4px 4px 0; margin-bottom:36px; }
        .terms-icon  { font-size:18px; color:var(--red); flex-shrink:0; }
        .terms-title { font-size:10px; letter-spacing:.22em; text-transform:uppercase; color:var(--red); font-weight:600; margin-bottom:4px; }
        .terms-text  { font-size:14px; color:#000; line-height:1.6; }

        .inv-footer { border-top:1px solid var(--rule); padding-top:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .footer-contact { display:flex; gap:20px; flex-wrap:wrap; }
        .fc-item { font-size:11px; color:var(--ink-soft); display:flex; align-items:center; gap:6px; }
        .fc-dot  { width:4px; height:4px; border-radius:50%; background:var(--red); }
        .footer-tagline { font-size:14px; font-style:italic; color:var(--ink-soft); }

        @media print {
            body { background:white; padding:0; }
            .screen-actions { display:none; }
            .inv-header, .grand-total, .invoice::before, .payment-card::before { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
    </style>
</head>
<body>

<div class="screen-actions">
    <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="invoice">

    <!-- Combined banner -->
    <div class="combined-banner">
        Combined Invoice — <?php echo count($invoices); ?> unpaid invoice<?php echo count($invoices) > 1 ? 's' : ''; ?> merged for <?php echo htmlspecialchars($client['client_name']); ?>
    </div>

    <!-- Header -->
    <div class="inv-header">
        <div class="header-top">
            <div class="brand-block">
                <div class="brand-wordmark">OZ<span>Bi</span>X</div>
                <div class="brand-sub">Empowered by Innovation</div>
            </div>
            <div class="inv-label-block">
                <div class="inv-word">Invoice</div>
                <div class="inv-number-label">Combined Statement</div>
                <div class="inv-number-val"><?php echo htmlspecialchars($client['client_name']); ?></div>
            </div>
        </div>

        <div class="header-date-strip">
            <div class="hds-item">
                <div class="hds-label">Generated</div>
                <div class="hds-val"><?php echo date('d M Y'); ?></div>
            </div>
            <div class="hds-sep"></div>
            <div class="hds-item">
                <div class="hds-label">Latest Due Date</div>
                <div class="hds-val"><?php echo date('d M Y', strtotime($newestInvoice['due_date'])); ?></div>
            </div>
            <div class="hds-status">
                <span class="status-pill status-overdue">Outstanding</span>
            </div>
        </div>
        <div class="header-arc"></div>
    </div>

    <!-- Body -->
    <div class="inv-body">

        <!-- Client + Bank -->
        <div class="info-grid">
            <div>
                <div class="section-eyebrow">Billed To</div>
                <div class="client-name"><?php echo htmlspecialchars($client['client_name']); ?></div>
                <div class="client-company"><?php echo htmlspecialchars($client['company']); ?></div>
                <?php if ($client['phone']): ?>
                <div class="contact-line"><span class="contact-dot"></span><?php echo htmlspecialchars($client['phone']); ?></div>
                <?php endif; ?>
                <?php if ($client['email']): ?>
                <div class="contact-line"><span class="contact-dot"></span><?php echo htmlspecialchars($client['email']); ?></div>
                <?php endif; ?>
                <?php if ($client['address']): ?>
                <div class="contact-line"><span class="contact-dot"></span><?php echo htmlspecialchars($client['address']); ?></div>
                <?php endif; ?>
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

        <!-- Invoice reference list -->
        <div class="inv-refs">
            <div class="inv-refs-title">Invoices Included in This Statement</div>
            <?php foreach ($invoices as $inv): ?>
            <div class="inv-ref-row">
                <span><?php echo htmlspecialchars($inv['invoice_number']); ?> &nbsp;·&nbsp; <?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></span>
                <span>
                    Total: PKR <?php echo number_format($inv['total'], 0); ?> &nbsp;|&nbsp;
                    Paid: PKR <?php echo number_format($inv['paid_amount'], 0); ?> &nbsp;|&nbsp;
                    Due: PKR <?php echo number_format($inv['remaining_amount'], 0); ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Line items -->
        <div class="items-section">
            <div class="section-eyebrow">Services &amp; Charges</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:75%">Description</th>
                        <th class="r">Amount (PKR)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allLineItems as $item):
                        // Split description and invoice tag if we added it
                        $parts = explode(' [Inv #', $item['description']);
                        $desc  = $parts[0];
                        $tag   = isset($parts[1]) ? '[Inv #' . $parts[1] : '';
                        $isBalance = ($item['project_id'] === null && stripos($desc, 'unpaid') !== false);
                    ?>
                    <tr class="<?php echo $isBalance ? 'item-row-balance' : ''; ?>">
                        <td>
                            <div class="item-title"><?php echo htmlspecialchars($desc); ?></div>
                            <?php if ($tag): ?>
                            <div class="item-inv-tag"><?php echo htmlspecialchars($tag); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="r"><?php echo number_format($item['amount'], 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-box">
                <div class="total-row">
                    <span class="tr-label">Total Invoiced</span>
                    <span class="tr-value">PKR <?php echo number_format($grandTotal, 0); ?></span>
                </div>
                <div class="total-row">
                    <span class="tr-label">Total Paid</span>
                    <span class="tr-value">PKR <?php echo number_format($grandPaid, 0); ?></span>
                </div>
                <div class="grand-total">
                    <span class="gt-label">Total Outstanding</span>
                    <div class="gt-amount-wrap">
                        <span class="gt-currency">PKR</span>
                        <span class="gt-amount"><?php echo number_format($grandRemaining, 0); ?></span>
                    </div>
                </div>
            </div>
        </div>

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

</body>
</html>
