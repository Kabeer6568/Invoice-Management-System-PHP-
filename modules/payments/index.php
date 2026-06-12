<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

// Handle deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Get invoice_id before deleting
    $stmt = $db->prepare("SELECT invoice_id, amount FROM payments WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
    if ($payment) {
        $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            // Update invoice paid amount
            $stmt = $db->prepare("UPDATE invoices SET paid_amount = paid_amount - ? WHERE id = ?");
            $stmt->bind_param("di", $payment['amount'], $payment['invoice_id']);
            $stmt->execute();
            updateInvoiceStatus($payment['invoice_id']);
            
            logActivity($_SESSION['admin_id'], 'DELETE_PAYMENT', "Deleted payment ID: $id");
            header("Location: index.php?msg=deleted");
            exit();
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? $_GET['search'] : '';

$where = "";
if ($search) {
    $where = "WHERE i.invoice_number LIKE '%$search%' OR c.name LIKE '%$search%'";
}

// Get total records
$total_result = $db->query("SELECT COUNT(*) as total FROM payments p 
                            JOIN invoices i ON p.invoice_id = i.id 
                            JOIN clients c ON i.client_id = c.id 
                            $where");
$total = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get payments
$sql = "SELECT p.*, i.invoice_number, c.name as client_name 
        FROM payments p 
        JOIN invoices i ON p.invoice_id = i.id 
        JOIN clients c ON i.client_id = c.id 
        $where 
        ORDER BY p.payment_date DESC 
        LIMIT $offset, $limit";
$payments = $db->query($sql);

// Get summary
$summary = $db->query("SELECT 
    SUM(amount) as total_received,
    COUNT(*) as total_transactions,
    DATE_FORMAT(payment_date, '%Y-%m') as month
    FROM payments 
    GROUP BY month 
    ORDER BY month DESC 
    LIMIT 1")->fetch_assoc();
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
            <a href="add.php" class="btn-primary">Record New Payment</a>
        </div>
        
        <?php if($summary): ?>
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
            <input type="text" name="search" placeholder="Search by invoice # or client..." value="<?php echo escape($search); ?>">
            <button type="submit">Search</button>
        </form>
        
        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success">Payment record deleted successfully!</div>
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
                <?php while($payment = $payments->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                    <td>
                        <a href="../invoices/view.php?id=<?php echo $payment['invoice_id']; ?>"  class="action-btn invoice"><?php echo escape($payment['invoice_number']); ?>
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
                    <td><?php echo escape($payment['reference_number']); ?></td>
                    <td>
                        <div class="action-buttons">
                        <a href="?delete=<?php echo $payment['id']; ?>" onclick="return confirm('Are you sure? This will affect invoice balance!')" class="action-btn delete">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            Delete
                        </a>
                        </div>
                     </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        <tr>
        
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>