<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/soft_delete_helpers.php';
redirectIfNotLoggedIn();

// Handle soft delete (move to trash) - UPDATED
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Check if has payments - if yes, prevent moving to trash
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM payments WHERE invoice_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $has_payments = $stmt->get_result()->fetch_assoc()['count'] > 0;
    
    if ($has_payments) {
        $error = "Cannot delete invoice with existing payments!";
    } else {
        if (softDelete('invoices', $id)) {
            logActivity($_SESSION['admin_id'], 'SOFT_DELETE_INVOICE', "Moved invoice ID: $id to trash");
            header("Location: index.php?msg=moved_to_trash");
            exit();
        }
    }
}

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['selected_invoices'])) {
    $selected_ids = $_POST['selected_invoices'];
    $bulk_action = $_POST['bulk_action'];
    
    if (count($selected_ids) > 0) {
        $ids_string = implode(',', array_map('intval', $selected_ids));
        
        if ($bulk_action == 'send_whatsapp') {
            header("Location: bulk_whatsapp.php?ids=" . $ids_string);
            exit();
        } elseif ($bulk_action == 'send_reminders') {
            header("Location: bulk_reminders.php?ids=" . $ids_string);
            exit();
        } elseif ($bulk_action == 'download_pdf') {
            header("Location: bulk-pdf.php?ids=" . $ids_string);
            exit();
        } elseif ($bulk_action == 'mark_paid') {
            $stmt = $db->prepare("UPDATE invoices SET payment_status = 'Paid', paid_amount = total, remaining_amount = 0 WHERE id IN ($ids_string) AND deleted_at IS NULL");
            if ($stmt->execute()) {
                header("Location: index.php?msg=marked_paid");
                exit();
            }
        }
    }
}

// Pagination
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 15;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month  = isset($_GET['month'])  ? (int)$_GET['month']  : 0;
$year   = isset($_GET['year'])   ? (int)$_GET['year']   : 0;

$where  = [];
$params = [];
$types  = "";

// Always exclude deleted invoices
$where[] = "i.deleted_at IS NULL";

if ($search) {
    $where[]  = "(i.invoice_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= "ss";
}

if ($status) {
    $where[]  = "i.payment_status = ?";
    $params[] = $status;
    $types   .= "s";
}

if ($month > 0) {
    $where[]  = "MONTH(i.invoice_date) = ?";
    $params[] = $month;
    $types   .= "i";
}

if ($year > 0) {
    $where[]  = "YEAR(i.invoice_date) = ?";
    $params[] = $year;
    $types   .= "i";
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Total count
$count_sql = "SELECT COUNT(*) as total FROM invoices i JOIN clients c ON i.client_id = c.id $where_clause";
$stmt = $db->prepare($count_sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get invoices
$sql = "SELECT i.*, c.name as client_name, c.phone as client_phone,
               COALESCE(p.project_name, '— Multiple Projects —') as project_name
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        LEFT JOIN projects p ON i.project_id = p.id
        $where_clause
        ORDER BY i.invoice_date DESC, i.id DESC
        LIMIT $offset, $limit";
$stmt = $db->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$invoices = $stmt->get_result();

// Years for filter
$years = $db->query("SELECT DISTINCT YEAR(invoice_date) as year FROM invoices WHERE deleted_at IS NULL ORDER BY year DESC");

// Get trash count for display
$trash_count = countTrashed('invoices');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Invoices</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .bulk-actions-bar {
            background: #f8f9fa;
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            border: 1px solid #e0e0e0;
        }
        
        .bulk-actions-bar .select-info {
            font-size: 13px;
            color: #666;
        }
        
        .bulk-actions-bar select {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .bulk-actions-bar button {
            background: #A81E2A;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .checkbox-col {
            width: 30px;
            text-align: center;
        }
        
        .select-all-checkbox {
            cursor: pointer;
        }
        
        .invoice-checkbox {
            cursor: pointer;
        }
        
        .selected-count {
            background: #A81E2A;
            color: white;
            padding: 4px 12px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Manage Invoices</h1>
            <div>
                <a href="create.php" class="btn-primary">Create Invoice</a>
                <a href="bulk-pdf.php" class="btn-secondary" target="_blank">Bulk PDF Download</a>
                <?php if($trash_count > 0): ?>
                <a href="../trash/?filter=invoices" class="btn-secondary" style="background: #6c757d;">
                    🗑️ Trash (<?php echo $trash_count; ?>)
                </a>
                <?php endif; ?>
            </div>
        </div>

        <form method="GET" class="filter-form">
            <div class="filter-row">
                <input type="text" name="search" placeholder="Search by invoice # or client..."
                       value="<?php echo escape($search); ?>">

                <select name="status">
                    <option value="">All Status</option>
                    <option value="Paid"    <?php echo $status == 'Paid'    ? 'selected' : ''; ?>>Paid</option>
                    <option value="Partial" <?php echo $status == 'Partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="Pending" <?php echo $status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="Overdue" <?php echo $status == 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                </select>

                <select name="month">
                    <option value="0">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>

                <select name="year">
                    <option value="0">All Years</option>
                    <?php while ($y = $years->fetch_assoc()): ?>
                    <option value="<?php echo $y['year']; ?>" <?php echo $year == $y['year'] ? 'selected' : ''; ?>>
                        <?php echo $y['year']; ?>
                    </option>
                    <?php endwhile; ?>
                </select>

                <button type="submit">Filter</button>
                <a href="index.php" class="btn-secondary">Reset</a>
            </div>
        </form>

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success">
                <?php 
                if ($_GET['msg'] == 'moved_to_trash') echo "Invoice moved to trash! You can restore it from the Trash page.";
                if ($_GET['msg'] == 'deleted') echo "Invoice deleted successfully!";
                if ($_GET['msg'] == 'marked_paid') echo "Invoices marked as paid successfully!";
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>

        <!-- Bulk Actions Bar -->
        <form method="POST" id="bulkActionForm">
            <div class="bulk-actions-bar">
                <div class="select-info">
                    <strong>Bulk Actions:</strong>
                </div>
                <select name="bulk_action" id="bulk_action" required>
                    <option value="">Select Action</option>
                    <option value="send_whatsapp">Send via WhatsApp</option>
                    <option value="send_reminders">Send Payment Reminders</option>
                    <option value="download_pdf">Download PDFs</option>
                    <option value="mark_paid">Mark as Paid</option>
                </select>
                <button type="submit" onclick="return confirmBulkAction()">Apply to Selected</button>
                <div class="select-info">
                    <span id="selectedCountDisplay" class="selected-count">0</span> invoice(s) selected
                </div>
                <button type="button" onclick="selectAll()" class="btn-secondary" style="background: #6c757d;">Select All</button>
                <button type="button" onclick="deselectAll()" class="btn-secondary" style="background: #6c757d;">Deselect All</button>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th class="checkbox-col">
                            <input type="checkbox" id="selectAllCheckbox" class="select-all-checkbox" onclick="toggleSelectAll()">
                        </th>
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
                    <?php 
                    while ($invoice = $invoices->fetch_assoc()): 
                    ?>
                    <tr>
                        <td class="checkbox-col">
                            <input type="checkbox" name="selected_invoices[]" value="<?php echo $invoice['id']; ?>" 
                                   class="invoice-checkbox" onclick="updateSelectedCount()">
                        </d>
                        <td><?php echo escape($invoice['invoice_number']); ?></d>
                        <td><?php echo escape($invoice['client_name']); ?></d>
                        <td><?php echo escape($invoice['project_name']); ?></d>
                        <td><?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?></d>
                        <td><?php echo date('Y-m-d', strtotime($invoice['due_date'])); ?></d>
                        <td>Rs.<?php echo number_format($invoice['total'], 2); ?></d>
                        <td>Rs.<?php echo number_format($invoice['paid_amount'], 2); ?></d>
                        <td>Rs.<?php echo number_format($invoice['remaining_amount'], 2); ?></d>
                        <td><span class="status-<?php echo strtolower($invoice['payment_status']); ?>"><?php echo escape($invoice['payment_status']); ?></span></d>
                        <td>
                            <a href="view.php?id=<?php echo $invoice['id']; ?>">View</a>
                            <a href="edit.php?id=<?php echo $invoice['id']; ?>">Edit</a>
                            <a href="pdf.php?id=<?php echo $invoice['id']; ?>" target="_blank">PDF</a>
                            <a href="send_whatsapp.php?id=<?php echo $invoice['id']; ?>" style="color:#25D366;">Send</a>
                            <?php if ($invoice['paid_amount'] == 0): ?>
                            <a href="?delete=<?php echo $invoice['id']; ?>"
                               onclick="return confirm('Move this invoice to trash? You can restore it later.')">Delete</a>
                            <?php endif; ?>
                        </d>
                    </tr>
                    <?php endwhile; ?>
                    
                    <?php if ($invoices->num_rows == 0): ?>
                    <tr>
                        <td colspan="12" class="text-center">No invoices found</d>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&month=<?php echo $month; ?>&year=<?php echo $year; ?>"
               class="<?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>

    <script>
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.invoice-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCountDisplay').innerText = count;
            
            const allCheckboxes = document.querySelectorAll('.invoice-checkbox');
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            if (allCheckboxes.length === count && count > 0) {
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.checked = false;
            }
        }
        
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const checkboxes = document.querySelectorAll('.invoice-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateSelectedCount();
        }
        
        function selectAll() {
            const checkboxes = document.querySelectorAll('.invoice-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectedCount();
            document.getElementById('selectAllCheckbox').checked = true;
        }
        
        function deselectAll() {
            const checkboxes = document.querySelectorAll('.invoice-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectedCount();
            document.getElementById('selectAllCheckbox').checked = false;
        }
        
        function confirmBulkAction() {
            const selectedCount = document.querySelectorAll('.invoice-checkbox:checked').length;
            const action = document.getElementById('bulk_action').value;
            
            if (selectedCount === 0) {
                alert('Please select at least one invoice.');
                return false;
            }
            
            if (!action) {
                alert('Please select an action.');
                return false;
            }
            
            let message = '';
            if (action === 'send_whatsapp') {
                message = `Send ${selectedCount} invoice(s) via WhatsApp? This will open WhatsApp for each invoice.`;
            } else if (action === 'send_reminders') {
                message = `Send payment reminders for ${selectedCount} invoice(s)?`;
            } else if (action === 'download_pdf') {
                message = `Download PDFs for ${selectedCount} invoice(s)?`;
            } else if (action === 'mark_paid') {
                message = `Mark ${selectedCount} invoice(s) as paid? This action cannot be undone.`;
            }
            
            return confirm(message);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });
    </script>
</body>
</html>