<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

// Handle deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check if payments exist
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM payments WHERE invoice_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_payments = $result->fetch_assoc()['count'] > 0;
    
    if ($has_payments) {
        $error = "Cannot delete invoice with existing payments!";
    } else {
        $stmt = $db->prepare("DELETE FROM invoices WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            logActivity($_SESSION['admin_id'], 'DELETE_INVOICE', "Deleted invoice ID: $id");
            header("Location: index.php?msg=deleted");
            exit();
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$where = [];
$params = [];
$types = "";

if ($search) {
    $where[] = "(i.invoice_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if ($status) {
    $where[] = "i.payment_status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($month && $month > 0) {
    $where[] = "MONTH(i.invoice_date) = ?";
    $params[] = $month;
    $types .= "i";
}

if ($year && $year > 0) {
    $where[] = "YEAR(i.invoice_date) = ?";
    $params[] = $year;
    $types .= "i";
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get total records
$count_sql = "SELECT COUNT(*) as total FROM invoices i JOIN clients c ON i.client_id = c.id $where_clause";
$stmt = $db->prepare($count_sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get invoices
$sql = "SELECT i.*, c.name as client_name, p.project_name 
        FROM invoices i 
        JOIN clients c ON i.client_id = c.id 
        JOIN projects p ON i.project_id = p.id 
        $where_clause 
        ORDER BY i.invoice_date DESC 
        LIMIT $offset, $limit";
$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$invoices = $stmt->get_result();

// Get years for filter
$years = $db->query("SELECT DISTINCT YEAR(invoice_date) as year FROM invoices ORDER BY year DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Invoices</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Manage Invoices</h1>
            <div>
                <a href="create.php" class="btn-primary">Create Invoice</a>
                <a href="bulk-pdf.php" class="btn-secondary" target="_blank">Bulk PDF Download</a>
            </div>
        </div>
        
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <input type="text" name="search" placeholder="Search by invoice # or client..." value="<?php echo escape($search); ?>">
                
                <select name="status">
                    <option value="">All Status</option>
                    <option value="Paid" <?php echo $status == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="Partial" <?php echo $status == 'Partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="Pending" <?php echo $status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Overdue" <?php echo $status == 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>
                
                <select name="month">
                    <option value="0">All Months</option>
                    <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
                
                <select name="year">
                    <option value="0">All Years</option>
                    <?php while($y = $years->fetch_assoc()): ?>
                    <option value="<?php echo $y['year']; ?>" <?php echo $year == $y['year'] ? 'selected' : ''; ?>>
                        <?php echo $y['year']; ?>
                    </option>
                    <?php endwhile; ?>
                </select>
                
                <button type="submit">Filter</button>
                <a href="index.php" class="btn-secondary">Reset</a>
            </div>
        </form>
        
        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success">Invoice deleted successfully!</div>
        <?php endif; ?>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Client</th>
                    <th>Project</th>
                    <th>Date</th>
                    <th>Due Date</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($invoice = $invoices->fetch_assoc()): ?>
                <tr>
                    <td><?php echo escape($invoice['invoice_number']); ?></td>
                    <td><?php echo escape($invoice['client_name']); ?></td>
                    <td><?php echo escape($invoice['project_name']); ?></td>
                    <td><?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?></td>
                    <td><?php echo date('Y-m-d', strtotime($invoice['due_date'])); ?></td>
                    <td>Rs.<?php echo number_format($invoice['total'], 2); ?></td>
                    <td>Rs.<?php echo number_format($invoice['paid_amount'], 2); ?></td>
                    <td>Rs.<?php echo number_format($invoice['remaining_amount'], 2); ?></td>
                    <td><span class="status-<?php echo strtolower($invoice['payment_status']); ?>"><?php echo $invoice['payment_status']; ?></span></td>
                    <td>
                        <a href="view.php?id=<?php echo $invoice['id']; ?>">View</a>
                        <a href="edit.php?id=<?php echo $invoice['id']; ?>">Edit</a>
                        <a href="pdf.php?id=<?php echo $invoice['id']; ?>" target="_blank">PDF</a>
                        <?php if($invoice['paid_amount'] == 0): ?>
                        <a href="?delete=<?php echo $invoice['id']; ?>" onclick="return confirm('Are you sure?')">Delete</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>