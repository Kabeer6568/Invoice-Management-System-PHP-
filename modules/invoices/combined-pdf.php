<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

// Accept either a client_id (all unpaid) or specific invoice IDs
$client_id  = isset($_GET['client_id'])  ? (int)$_GET['client_id']  : 0;
$primary_id = isset($_GET['primary_id']) ? (int)$_GET['primary_id'] : 0;

if (!$client_id) die("Invalid request.");

// Fetch all unpaid/partial/overdue invoices for this client
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
$allLineItems   = [];
$grandTotal     = 0.00;
$grandPaid      = 0.00;

foreach ($invoices as $inv) {
    $liStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
    $liStmt->bind_param('i', $inv['id']);
    $liStmt->execute();
    $items = $liStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $liStmt->close();

    if (empty($items)) {
        $items = [[
            'description' => 'Services — Invoice #' . $inv['invoice_number'],
            'amount'      => $inv['amount'],
            'quantity'    => 1,
            'discount'    => 0,
            'notes'       => null,
            'project_id'  => null,
        ]];
    } else {
        foreach ($items as &$item) {
            $item['_inv_number'] = $inv['invoice_number'];
        }
        unset($item);
    }

    $allLineItems = array_merge($allLineItems, $items);
    $grandTotal  += $inv['total'];
    $grandPaid   += $inv['paid_amount'];
}

$grandRemaining = $grandTotal - $grandPaid;
$client         = $invoices[0];
$newestInvoice  = $invoices[0];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Combined Invoice — <?php echo htmlspecialchars($client['client_name']); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/pdf.css" />
  <style>
    /* ── Combined-invoice-only extras ───────────────────
       All base styles live in pdf.css.
       Only unique combined-view styles go here.
    ──────────────────────────────────────────────────── */

    /* Top info banner */
    .combined-banner {
      background: #fff5f5;
      border-left: 4px solid var(--red);
      padding: 10px 36px;
      font-size: 12px;
      font-weight: 600;
      color: var(--red);
      letter-spacing: .03em;
    }

    /* Invoice reference table */
    .invoice__refs {
      margin: 0 36px 24px;
      border-radius: 6px;
      overflow: hidden;
      border: 1px solid var(--border);
    }

    .invoice__refs-heading {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--red);
      padding: 10px 14px 8px;
      border-bottom: 1px solid var(--border);
      background: #fdf5f5;
    }

    .invoice__refs-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12.5px;
    }

    .invoice__refs-table th {
      text-align: left;
      font-weight: 600;
      color: var(--text-mid);
      padding: 8px 14px;
      background: var(--bg-grey);
      border-bottom: 1px solid var(--border);
    }

    .invoice__refs-table td {
      padding: 8px 14px;
      color: var(--text-dark);
      border-bottom: 1px solid var(--border);
    }

    .invoice__refs-table tr:last-child td {
      border-bottom: none;
    }

    .invoice__refs-table .refs-due {
      font-weight: 700;
      color: var(--red);
    }

    /* Grand totals override — wider summary */
    .invoice__grand-total--combined {
      background: var(--charcoal);
      color: #fff;
      border-radius: 6px;
      margin: 6px 36px 24px;
      padding: 16px 22px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .invoice__grand-total--combined .invoice__grand-label {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: rgba(255,255,255,.55);
    }

    .invoice__grand-total--combined .invoice__grand-value {
      font-size: 32px;
      font-weight: 800;
      color: #fff;
      line-height: 1;
    }

    .invoice__grand-currency {
      font-size: 13px;
      font-weight: 400;
      color: rgba(255,255,255,.45);
      margin-right: 4px;
    }
  </style>
</head>
<body>

<!-- ── Screen actions ───────────────────────────────── -->
<div class="screen-actions">
  <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
  <button class="btn-close" onclick="window.close()">Close</button>
</div>

<article class="invoice">

  <!-- Combined banner -->
  <div class="combined-banner">
    Combined Statement &mdash;
    <?php echo count($invoices); ?> unpaid invoice<?php echo count($invoices) > 1 ? 's' : ''; ?>
    merged for <?php echo htmlspecialchars($client['client_name']); ?>
  </div>

  <!-- ════ HEADER ════ -->
  <header class="invoice__header">

    <div class="invoice__logo">
      <img
        class="invoice__logo-img"
        src="../../assets/img/logo.webp"
        alt="Ozbix – Empowered by Innovation"
      />
    </div>

    <div class="invoice__meta">
      <p class="invoice__meta-number">
        COMBINED STATEMENT
      </p>
      <p class="invoice__meta-date">
        <span class="invoice__meta-date-label">Generated:&nbsp;</span>
        <?php echo date('d-F-Y'); ?>
      </p>
      <p class="invoice__meta-date" style="font-size:12px; font-weight:500; margin-top:3px;">
        <span class="invoice__meta-date-label">Latest Due:&nbsp;</span>
        <?php echo date('d-F-Y', strtotime($newestInvoice['due_date'])); ?>
      </p>
      <p class="invoice__meta-status">
        <span class="status-pill status-outstanding">Outstanding</span>
      </p>
    </div>

  </header>

  <!-- ════ BILLING + PAYMENT ════ -->
  <section class="invoice__info-row">

    <!-- Billed To -->
    <div class="invoice__bill-to">
      <p class="invoice__section-label">Billed To</p>
      <h1 class="invoice__client-name"><?php echo htmlspecialchars($client['client_name']); ?></h1>

      <?php if (!empty($client['company'])): ?>
        <p class="invoice__client-company"><?php echo htmlspecialchars($client['company']); ?></p>
      <?php endif; ?>

      <ul class="invoice__contact-list">
        <?php if (!empty($client['phone'])): ?>
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="../../assets/img/phone.png" class="invoice__contact-icon">
          </span>
          <?php echo htmlspecialchars($client['phone']); ?>
        </li>
        <?php endif; ?>

        <?php if (!empty($client['email'])): ?>
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="../../assets/img/envelop.png" class="invoice__contact-icon">
          </span>
          <?php echo htmlspecialchars($client['email']); ?>
        </li>
        <?php endif; ?>

        <?php if (!empty($client['address'])): ?>
        <li class="invoice__contact-item">
          <span class="invoice__contact-icon">
            <img src="../../assets/img/pin.png" class="invoice__contact-circle-icon">
          </span>
          <?php echo htmlspecialchars($client['address']); ?>
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

  <!-- ════ INVOICE REFERENCE LIST ════ -->
  <div class="invoice__refs">
    <div class="invoice__refs-heading">Invoices Included in This Statement</div>
    <table class="invoice__refs-table">
      <thead>
        <tr>
          <th>Invoice No.</th>
          <th>Date</th>
          <th>Total</th>
          <th>Paid</th>
          <th>Balance Due</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr>
          <td><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
          <td><?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></td>
          <td>PKR <?php echo number_format($inv['total'], 0); ?></td>
          <td>PKR <?php echo number_format($inv['paid_amount'], 0); ?></td>
          <td class="refs-due">PKR <?php echo number_format($inv['remaining_amount'], 0); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ════ LINE ITEMS ════ -->
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

        <?php foreach ($allLineItems as $item):
          $qty      = (int)($item['quantity'] ?? 1);
          $discount = (float)($item['discount'] ?? 0);
          $lineTotal = ($item['amount'] * $qty) - $discount;
        ?>
        <tr class="invoice__tr">
          <td class="invoice__td">
            <span class="invoice__item-name">
              <?php echo htmlspecialchars($item['description']); ?>
            </span>
            <?php if (!empty($item['_inv_number'])): ?>
              <span class="invoice__item-sub">
                Invoice #<?php echo htmlspecialchars($item['_inv_number']); ?>
              </span>
            <?php endif; ?>
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
            <?php echo $qty; ?>
          </td>
          <td class="invoice__td invoice__td--center">
            <?php echo number_format($discount, 0); ?>
          </td>
          <td class="invoice__td invoice__td--right">
            <?php echo number_format($lineTotal, 0); ?>
          </td>
        </tr>
        <?php endforeach; ?>

      </tbody>
    </table>
  </div>

  <!-- ════ TOTALS ════ -->
  <div class="invoice__totals-row">

    <div class="invoice__total-due">
      <p class="invoice__total-due-label">Total Outstanding</p>
      <p class="invoice__total-due-amount">
        PKR <?php echo number_format($grandRemaining, 0); ?>
      </p>
    </div>

    <div class="invoice__summary">
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">Total Invoiced</span>
        <span class="invoice__summary-value">PKR <?php echo number_format($grandTotal, 0); ?></span>
      </div>
      <div class="invoice__summary-row">
        <span class="invoice__summary-key">Total Paid</span>
        <span class="invoice__summary-value invoice__summary-row--paid">
          PKR <?php echo number_format($grandPaid, 0); ?>
        </span>
      </div>
      <div class="invoice__summary-row invoice__summary-row--balance">
        <span class="invoice__summary-key">Balance Due</span>
        <span class="invoice__summary-value">
          PKR <?php echo number_format($grandRemaining, 0); ?>
        </span>
      </div>
    </div>

  </div>

  <!-- Grand total bar — dark variant for combined -->
  <div class="invoice__grand-total--combined">
    <span class="invoice__grand-label">Total Outstanding</span>
    <div>
      <span class="invoice__grand-currency">PKR</span>
      <span class="invoice__grand-value">
        <?php echo number_format($grandRemaining, 0); ?>
      </span>
    </div>
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