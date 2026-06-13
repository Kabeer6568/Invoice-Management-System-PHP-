<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) die("Invalid invoice ID");

// LEFT JOIN project since combined invoices may have no project_id
$stmt = $db->prepare("
    SELECT i.*, c.name AS client_name, c.company, c.address, c.email, c.phone,
           p.project_name, p.description AS project_description
    FROM   invoices  i
    JOIN   clients   c ON i.client_id  = c.id
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
$pStmt = $db->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC");
$pStmt->bind_param("i", $id);
$pStmt->execute();
$payments = $pStmt->get_result();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?> — Ozbix IT Solutions</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/pdf.css" />
</head>
<body>

<!-- ── Screen-only action bar ──────────────────────── -->
<div class="screen-actions">
  <button class="btn-print" onclick="window.print()">Print Invoice</button>
  <button class="btn-close" onclick="window.close()">Close</button>
</div>

<article class="invoice">

  <!-- ════ HEADER ════ -->
  <header class="invoice__header">

    <div class="invoice__logo">
      <img
        class="invoice__logo-img"
        src="../../assets/img/LOGO.webp"
        alt="Ozbix – Empowered by Innovation"
      />
    </div>

    <div class="invoice__meta">
      <p class="invoice__meta-number">
        INVOICE NO:&nbsp;
        <span class="invoice__meta-number-value">
          <?php echo htmlspecialchars($invoice['invoice_number']); ?>
        </span>
      </p>
      <p class="invoice__meta-date">
        <span class="invoice__meta-date-label">Date:&nbsp;</span>
        <?php echo date('d-F-Y', strtotime($invoice['invoice_date'])); ?>
      </p>
      <p class="invoice__meta-status">
        <span class="status-pill status-<?php echo strtolower(htmlspecialchars($invoice['payment_status'])); ?>">
          <?php echo htmlspecialchars($invoice['payment_status']); ?>
        </span>
      </p>
    </div>

  </header>

  <!-- ════ BILLING + PAYMENT ════ -->
  <section class="invoice__info-row">

    <!-- Billed To -->
    <div class="invoice__bill-to">
      <p class="invoice__section-label">Invoice to</p>
      <h1 class="invoice__client-name"><?php echo htmlspecialchars($invoice['client_name']); ?></h1>

      <?php if (!empty($invoice['company'])): ?>
        <p class="invoice__client-company"><?php echo htmlspecialchars($invoice['company']); ?></p>
      <?php endif; ?>

      <ul class="invoice__contact-list">
        <?php if (!empty($invoice['phone'])): ?>
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="../../assets/img/phone.png" class="invoice__contact-icon">
          </span>
          <?php echo htmlspecialchars($invoice['phone']); ?>
        </li>
        <?php endif; ?>

        <?php if (!empty($invoice['email'])): ?>
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="../../assets/img/envelop.png" class="invoice__contact-icon">
          </span>
          <?php echo htmlspecialchars($invoice['email']); ?>
        </li>
        <?php endif; ?>

        <?php if (!empty($invoice['address'])): ?>
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="../../assets/img/pin.png" class="invoice__contact-circle-icon">
          </span>
          <?php echo htmlspecialchars($invoice['address']); ?>
        </li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Payment Details -->
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

  <!-- ════ LINE ITEMS ════ -->
  <div class="invoice__table-wrap">
    <table class="invoice__table">
      <thead class="invoice__table-head">
        <tr>
          <th class="invoice__th">Description</th>
          <th class="invoice__th invoice__th--center">Price</th>
          <th class="invoice__th invoice__th--center">QTY</th>
          <!-- <th class="invoice__th invoice__th--center">Discount</th> -->
          <th class="invoice__th invoice__th--right">Total</th>
        </tr>
      </thead>
      <tbody class="invoice__table-body">

        <?php if (!empty($lineItems)): ?>
          <?php foreach ($lineItems as $item): ?>
          <tr class="invoice__tr">
            <td class="invoice__td">
              <span class="invoice__item-name">
                <?php echo htmlspecialchars($item['description']); ?>
              </span>
              <?php if (!empty($item['notes'])): ?>
                <span class="invoice__item-sub">
                  <?php echo htmlspecialchars($item['notes']); ?>
                </span>
              <?php endif; ?>
            </td>
            <td class="invoice__td invoice__td--center">
              <?php echo number_format($item['amount'], 0); ?>
            </td>
            <td class="invoice__td invoice__td--center">
              <?php echo (int)($item['quantity'] ?? 1); ?>
            </td>
            <!-- <td class="invoice__td invoice__td--center">
              <?php //echo number_format($item['discount'] ?? 0, 0); ?>
            </td> -->
            <td class="invoice__td invoice__td--right">
              <?php
                $qty      = (int)($item['quantity'] ?? 1);
                $discount = (float)($item['discount'] ?? 0);
                $lineTotal = ($item['amount'] * $qty) - $discount;
                echo number_format($lineTotal, 0);
              ?>
            </td>
          </tr>
          <?php endforeach; ?>

        <?php else: ?>
          <!-- Fallback for legacy invoices without line items -->
          <tr class="invoice__tr">
            <td class="invoice__td">
              <span class="invoice__item-name">
                <?php echo htmlspecialchars($invoice['project_name'] ?? 'Services'); ?>
              </span>
            </td>
            <td class="invoice__td invoice__td--center">
              <?php echo number_format($invoice['amount'], 0); ?>
            </td>
            <td class="invoice__td invoice__td--center">1</td>
            <td class="invoice__td invoice__td--center">0</td>
            <td class="invoice__td invoice__td--right">
              <?php echo number_format($invoice['amount'], 0); ?>
            </td>
          </tr>
        <?php endif; ?>

      </tbody>
    </table>
  </div>

  <!-- ════ TOTALS ════ -->
  <div class="invoice__totals-row">

    <div class="invoice__total-due">
      <p class="invoice__total-due-label">Total Due</p>
      <p class="invoice__total-due-amount">
        <?php echo number_format($invoice['remaining_amount'], 0); ?>
      </p>
    </div>

    <div class="invoice__summary">
      <?php if (count($lineItems) > 1 || $invoice['tax'] > 0 || $invoice['discount'] > 0): ?>
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">Subtotal</span>
        <span class="invoice__summary-value"><?php echo number_format($invoice['amount'], 0); ?></span>
      </div>
      <?php endif; ?>

      <?php if ($invoice['discount'] > 0): ?>
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">You Saved</span>
        <span class="invoice__summary-value">&minus;<?php echo number_format($invoice['discount'], 0); ?></span>
      </div>
      <?php else: ?>
        <div class="invoice__summary-row">
        <span class="invoice__summary-key">Subtotal</span>
        <span class="invoice__summary-value"><?php echo number_format($invoice['amount'], 0); ?></span>
      </div>
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">You Saved</span>
        <span class="invoice__summary-value">0</span>
      </div>
      <?php endif; ?>

      <?php if ($invoice['tax'] > 0): ?>
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">Tax</span>
        <span class="invoice__summary-value"><?php echo number_format($invoice['tax'], 0); ?></span>
      </div>
      <?php endif; ?>

      <?php if (!empty($invoice['paid_amount']) && $invoice['paid_amount'] > 0): ?>
      <div class="invoice__summary-row invoice__summary-row--paid">
        <span class="invoice__summary-key">Paid</span>
        <span class="invoice__summary-value">
          PKR <?php echo number_format($invoice['paid_amount'], 0); ?>
        </span>
      </div>
      <div class="invoice__summary-row invoice__summary-row--balance">
        <span class="invoice__summary-key">Balance Due</span>
        <span class="invoice__summary-value">
          PKR <?php echo number_format($invoice['remaining_amount'], 0); ?>
        </span>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- Grand total bar -->
  <div class="invoice__grand-total">
    <span class="invoice__grand-label">Total</span>
    <span class="invoice__grand-value">
      PKR <?php echo number_format($invoice['remaining_amount'], 0); ?>
    </span>
  </div>



  <!-- ════ TERMS ════ -->
  <section class="invoice__terms">
    <h2 class="invoice__terms-heading">Terms &amp; Condition</h2>
    <p class="invoice__terms-text">
      Payments are due within 10 days of the invoice date. Kindly pay at your earliest convenience.
    </p>
  </section>

  <!-- ════ FOOTER ════ -->
  <footer class="invoice__footer">

    <div class="invoice__footer-block">
      <span class="invoice__footer-micro">Follow us:</span>
      <div class="invoice__footer-social-row">
        <span class="invoice__social-icon">
            <img src="../../assets/img/x.png" class="invoice__footer-circle-icon">
        </span>
        <span class="invoice__social-icon">in</span>
        <span class="invoice__social-icon">
            <img src="../../assets/img/envelop.png" class="invoice__footer-circle-icon">
        </span>
        <span class="invoice__social-icon">f</span>
        <span class="invoice__footer-handle">/ozbixofficial</span>
      </div>
    </div>

    <div class="invoice__footer-block">
      <span class="invoice__footer-micro">Contact us:</span>
      <div class="invoice__footer-info-row">
        <span class="invoice__footer-circle-icon">
            <img src="../../assets/img/phone.png" class="invoice__footer-circle-icon">
        </span>
        +923-111-456-324
      </div>
    </div>

    <div class="invoice__footer-block">
      <span class="invoice__footer-micro">Visit our website:</span>
      <div class="invoice__footer-info-row">
        <span class="invoice__footer-circle-icon">
            <img src="../../assets/img/website.png" class="invoice__footer-circle-icon">
        </span>
        www.ozbix.com
      </div>
    </div>

  </footer>

</article>
</body>
</html>