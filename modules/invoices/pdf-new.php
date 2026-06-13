<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) die("Invalid invoice ID");

// LEFT JOIN project since combined invoices have no project_id
$stmt = $db->prepare("
    SELECT i.*, c.name as client_name, c.company, c.address, c.email, c.phone,
           p.project_name, p.description as project_description
    FROM   invoices i
    JOIN   clients  c ON i.client_id  = c.id
    LEFT JOIN projects p ON i.project_id = p.id
    WHERE  i.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
if (!$invoice) die("Invoice not found");

// Line items
$liStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
$liStmt->bind_param("i", $id);
$liStmt->execute();
$lineItems = $liStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$liStmt->close();

// Payments
$stmt = $db->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
$stmt->bind_param("i", $id);
$stmt->execute();
$payments = $stmt->get_result();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> — Ozbix IT Solutions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/pdf.css">
    <style>
        
    </style>
</head>
<body>

<div class="screen-actions">
    <button class="btn-print" onclick="window.print()">Print Invoice</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="invoice">

    <!-- ════ HEADER ════ -->
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

    <!-- ════ BODY ════ -->
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
                    <div class="contact-line"><span class="contact-dot"></span><?php echo htmlspecialchars($invoice['address']); ?></div>
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

        <!-- ── Line Items ── -->
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
                <?php if (!empty($lineItems)): ?>
                    <?php foreach ($lineItems as $item):
                        $isBalance = ($item['project_id'] === null && strpos($item['description'], 'unpaid') !== false);
                    ?>
                    <tr class="<?php echo $isBalance ? 'item-row-balance' : ''; ?>">
                        <td><div class="item-title"><?php echo htmlspecialchars($item['description']); ?></div></td>
                        <td class="r"><?php echo number_format($item['amount'], 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Fallback for old invoices without line items -->
                    <tr>
                        <td><div class="item-title"><?php echo htmlspecialchars($invoice['project_name'] ?? 'Services'); ?></div></td>
                        <td class="r"><?php echo number_format($invoice['amount'], 0); ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ── Totals ── -->
        <div class="totals-section">
            <div class="totals-box">
                <?php if (count($lineItems) > 1 || $invoice['tax'] > 0 || $invoice['discount'] > 0): ?>
                <div class="total-row">
                    <span class="tr-label">Subtotal</span>
                    <span class="tr-value"><?php echo number_format($invoice['amount'], 0); ?></span>
                </div>
                <?php endif; ?>

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

        <!-- ── Payment History ── -->
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
                        <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                        <td>PKR <?php echo number_format($payment['amount'], 0); ?></td>
                        <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                        <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── Terms ── -->
        <div class="terms-block">
            <div class="terms-icon">§</div>
            <div>
                <div class="terms-title">Terms &amp; Conditions</div>
                <div class="terms-text">Payments are due within 10 days of the invoice date. Kindly pay at your earliest convenience.</div>
            </div>
        </div>

        <!-- ── Footer ── -->
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





<!-- New PDF Start-->

<article class="invoice">

  <!-- ══ HEADER ══════════════════════════════════ -->
  <header class="invoice__header">

    <div class="invoice__logo">
      <img
        class="invoice__logo-img"
        src="https://ozbix.com/wp-content/uploads/2024/07/LOGO-BOLD-copy.webp"
        alt="Ozbix – Empowered by Innovation"
      />
    </div>

    <div class="invoice__meta">
      <p class="invoice__meta-number">
        INVOICE NO:&nbsp;<span class="invoice__meta-number-value"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
      </p>
      <p class="invoice__meta-date">
        <span class="invoice__meta-date-label">Date:&nbsp;</span><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?>
      </p>
    </div>

  </header>

  <!-- ══ BILLING + PAYMENT ═══════════════════════ -->
  <section class="invoice__info-row">

    <div class="invoice__bill-to">
      <p class="invoice__section-label">Invoice to</p>
      <h1 class="invoice__client-name"><?php echo htmlspecialchars($invoice['client_name']); ?></h1>
      <p class="invoice__client-website"><?php echo htmlspecialchars($invoice['project_name'] ?? 'Services'); ?></p>
      <ul class="invoice__contact-list">
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="img/phone.png" alt="">
          </span>
          <?php echo htmlspecialchars($invoice['phone']); ?>
        </li>
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="img/envelop.png" alt="">
          </span>
          <?php echo htmlspecialchars($invoice['email']); ?>
        </li>
      </ul>
    </div>

    <div class="invoice__payment-box">
      <p class="invoice__payment-title">Payment Method</p>
      <div class="invoice__payment-grid">
        <span class="invoice__payment-key">Account No:</span>
        <span class="invoice__payment-val">01790114502628</span>

        <span class="invoice__payment-key">Account Name:</span>
        <span class="invoice__payment-val">Ozbix IT Solutions</span>

        <span class="invoice__payment-key">Bank Name:</span>
        <span class="invoice__payment-val">Meezan Bank</span>

        <span class="invoice__payment-key">Branch Name:</span>
        <span class="invoice__payment-val">Soldier Bazar Branch</span>
      </div>
    </div>

  </section>

  <!-- ══ LINE ITEMS ═══════════════════════════════ -->
  <div class="invoice__table-wrap">
    <table class="invoice__table">
      <thead class="invoice__table-head">
        <tr>
          <th class="invoice__th">Description</th>
          <th class="invoice__th invoice__th--center">Price</th>
          <th class="invoice__th invoice__th--center">QTY</th>
          <th class="invoice__th invoice__th--center">Discount</th>
          <th class="invoice__th invoice__th--right">Total</th>
        </tr>
      </thead>
      <tbody class="invoice__table-body">
        <tr class="invoice__tr">
          <td class="invoice__td">
            <span class="invoice__item-name">Domain Renewal (Amnaahmed.com)</span>
            <span class="invoice__item-validity">Valid till 25 April 2027 (1 year)</span>
          </td>
          <td class="invoice__td invoice__td--center">6,000</td>
          <td class="invoice__td invoice__td--center">1</td>
          <td class="invoice__td invoice__td--center">0</td>
          <td class="invoice__td invoice__td--right">6,000</td>
        </tr>
        <tr class="invoice__tr">
          <td class="invoice__td">
            <span class="invoice__item-name">Domain Renewal (Amnaahmed.pk)</span>
            <span class="invoice__item-validity">Valid till 30 April 2028 (2 year)</span>
          </td>
          <td class="invoice__td invoice__td--center">5,500</td>
          <td class="invoice__td invoice__td--center">1</td>
          <td class="invoice__td invoice__td--center">0</td>
          <td class="invoice__td invoice__td--right">5,500</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- ══ TOTALS ════════════════════════════════════ -->
  <div class="invoice__totals-row">
    <div class="invoice__total-due">
      <p class="invoice__total-due-label">Total Due</p>
      <p class="invoice__total-due-amount">11,500</p>
    </div>
    <div class="invoice__summary">
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">Subtotal</span>
        <span class="invoice__summary-value">11,500</span>
      </div>
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">You Saved</span>
        <span class="invoice__summary-value">0</span>
      </div>
    </div>
  </div>

  <!-- Grand total bar -->
  <div class="invoice__grand-total">
    <span class="invoice__grand-label">Total</span>
    <span class="invoice__grand-value">11,500</span>
  </div>

  <!-- ══ TERMS ═════════════════════════════════════ -->
  <section class="invoice__terms">
    <h2 class="invoice__terms-heading">Terms &amp; Condition</h2>
    <p class="invoice__terms-text">
      Payments are due within 10 days of the invoice date. Kindly pay at your earliest convenience.
    </p>
  </section>

  <!-- ══ FOOTER ════════════════════════════════════ -->
  <footer class="invoice__footer">

    <div class="invoice__footer-block">
      <span class="invoice__footer-micro">Follow us:</span>
      <div class="invoice__footer-social-row">
        <span class="invoice__social-icon">
          <img src="img/x.png" alt="" class="invoice__social-icon">
        </span>
        <span class="invoice__social-icon">in</span>
        <span class="invoice__social-icon">&#128386;</span>
        <span class="invoice__social-icon">f</span>
        <span class="invoice__footer-handle">/ozbixofficial</span>
      </div>
    </div>

    <div class="invoice__footer-block">
      <span class="invoice__footer-micro">Contact us:</span>
      <div class="invoice__footer-info-row">
        <span>
          <img src="img/phone.png" alt="" class="invoice__footer-circle-icon">
        </span>
        +923-111-456-324
      </div>
    </div>

    <div class="invoice__footer-block">
      <span class="invoice__footer-micro">Visit our website:</span>
      <div class="invoice__footer-info-row">
        <span class="invoice__footer-circle-icon">
          <img src="img/website.png" alt="" class="invoice__footer-circle-icon">
        </span>
        www.ozbix.com
      </div>
    </div>

  </footer>

</article>
</body>
</html>