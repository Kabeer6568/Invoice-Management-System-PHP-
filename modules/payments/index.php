<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/soft_delete_helpers.php';
redirectIfNotLoggedIn();

// ── Soft delete (move to trash) ────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    // Fetch payment before trashing so we can reverse the invoice balance
    $stmt = $db->prepare("SELECT invoice_id, amount FROM payments WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($payment) {
        // Deduct from invoice paid_amount and recalculate status
        $upd = $db->prepare("UPDATE invoices SET paid_amount = GREATEST(0, paid_amount - ?) WHERE id = ?");
        $upd->bind_param("di", $payment['amount'], $payment['invoice_id']);
        $upd->execute();
        $upd->close();
        updateInvoiceStatus($payment['invoice_id']);

        softDelete('payments', $id);
        logActivity($_SESSION['admin_id'], 'SOFT_DELETE_PAYMENT', "Moved payment ID: $id to trash");
        header("Location: index.php?msg=moved_to_trash");
        exit();
    }
}

// ── Pagination ─────────────────────────────────────────────────────
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 20;
$offset = ($page - 1) * $limit;

// ── Filters ────────────────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Always exclude soft-deleted rows
$where_parts = ["p.deleted_at IS NULL"];
$bind_params = [];
$bind_types  = "";

if ($search) {
    $where_parts[] = "(i.invoice_number LIKE ? OR c.name LIKE ?)";
    $bind_params[]  = "%$search%";
    $bind_params[]  = "%$search%";
    $bind_types    .= "ss";
}

$where_clause = "WHERE " . implode(" AND ", $where_parts);

// ── Total count ────────────────────────────────────────────────────
$count_sql  = "SELECT COUNT(*) as total
               FROM   payments p
               JOIN   invoices i ON p.invoice_id = i.id
               JOIN   clients  c ON i.client_id  = c.id
               $where_clause";
$count_stmt = $db->prepare($count_sql);
if ($bind_params) $count_stmt->bind_param($bind_types, ...$bind_params);
$count_stmt->execute();
$total       = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);
$count_stmt->close();

// ── Payments query ─────────────────────────────────────────────────
$sql = "SELECT p.*, i.invoice_number, c.name AS client_name
        FROM   payments p
        JOIN   invoices i ON p.invoice_id = i.id
        JOIN   clients  c ON i.client_id  = c.id
        $where_clause
        ORDER  BY p.id DESC
        LIMIT  $offset, $limit";
$stmt = $db->prepare($sql);
if ($bind_params) $stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$payments = $stmt->get_result();

// ── Summary stats (current month, active payments only) ───────────
$summary = $db->query("
    SELECT SUM(amount)   AS total_received,
           COUNT(*)      AS total_transactions,
           DATE_FORMAT(payment_date, '%Y-%m') AS month
    FROM   payments
    WHERE  deleted_at IS NULL
    GROUP  BY month
    ORDER  BY month DESC
    LIMIT  1
")->fetch_assoc();

// ── Trash count ────────────────────────────────────────────────────
$trash_count = countTrashed('payments');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Tracking</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">

        <div class="page-header">
            <h1>Payment Tracking</h1>
            <div>
                <a href="add.php" class="btn-primary">Record New Payment</a>
                <?php if ($trash_count > 0): ?>
                <a href="../trash/?filter=payments" class="btn-secondary" style="background:#6c757d;">
                    Trash (<?php echo $trash_count; ?>)
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($summary): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($summary['total_received'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Received (This Month)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $summary['total_transactions'] ?? 0; ?></div>
                <div class="stat-label">Total Transactions</div>
            </div>
        </div>
        <?php endif; ?>

        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="Search by invoice # or client..."
                   value="<?php echo escape($search); ?>">
            <button type="submit">Search</button>
            <?php if ($search): ?>
            <a href="index.php" class="btn-secondary">Reset</a>
            <?php endif; ?>
        </form>

        <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">
            <?php
            if ($_GET['msg'] === 'moved_to_trash') echo "Payment moved to trash! You can restore it from the Trash page.";
            if ($_GET['msg'] === 'deleted')         echo "Payment permanently deleted.";
            ?>
        </div>
        <?php endif; ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice #</th>
                    <th>Client</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($payment = $payments->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                    <td>
                        <a href="../invoices/view.php?id=<?php echo $payment['invoice_id']; ?>" class="action-btn invoice">
                            <?php echo escape($payment['invoice_number']); ?>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="2"/>
                                <line x1="8" y1="8" x2="16" y2="8"/>
                                <line x1="8" y1="12" x2="16" y2="12"/>
                                <line x1="8" y1="16" x2="12" y2="16"/>
                            </svg>
                        </a>
                    </td>
                    <td><?php echo escape($payment['client_name']); ?></td>
                    <td>Rs.<?php echo number_format($payment['amount'], 2); ?></td>
                    <td><?php echo escape($payment['payment_method']); ?></td>
                    <td><?php echo escape($payment['reference_number'] ?? '—'); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="?delete=<?php echo $payment['id']; ?>"
                               onclick="return confirm('Move this payment to trash? The invoice balance will be updated. You can restore it later.')"
                               class="action-btn delete">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                    <path d="M10 11v6"/>
                                    <path d="M14 11v6"/>
                                    <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                                </svg>
                                Delete
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>

                <?php if ($total == 0): ?>
                <tr>
                    <td colspan="7" class="text-center">No payments found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
               class="<?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>
</body>
</html>