<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/soft_delete_helpers.php';
redirectIfNotLoggedIn();

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
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

if (isset($_POST['bulk_action']) && isset($_POST['selected_invoices'])) {
    $selected_ids = $_POST['selected_invoices'];
    $bulk_action  = $_POST['bulk_action'];
 
    if (count($selected_ids) > 0) {
        $ids_string = implode(',', array_map('intval', $selected_ids));
 
        if ($bulk_action == 'send_whatsapp') {
            header("Location: bulk_whatsapp.php?ids=" . $ids_string); exit();
 
        } elseif ($bulk_action == 'send_reminders') {
            header("Location: bulk_reminders.php?ids=" . $ids_string); exit();
 
        } elseif ($bulk_action == 'download_pdf') {
            header("Location: bulk-pdf.php?ids=" . $ids_string); exit();
 
        } elseif ($bulk_action == 'mark_paid') {
 
            // ── STEP 1: fetch invoices that are not already fully paid ──────
            $fetch = $db->query(
                "SELECT id, total, paid_amount, remaining_amount
                 FROM   invoices
                 WHERE  id IN ($ids_string)
                   AND  deleted_at IS NULL
                   AND  payment_status != 'Paid'"
            );
 
            $marked = 0;
 
            while ($inv = $fetch->fetch_assoc()) {
                $invoice_id       = (int)$inv['id'];
                $remaining        = (float)$inv['remaining_amount'];
 
                // Skip if nothing actually owed (safety guard)
                if ($remaining <= 0) continue;
 
                // ── STEP 2: insert a payment record so it appears on
                //            the payments page, just like a manual payment ──
                $today  = date('Y-m-d');
                $method = 'Bank Transfer';   // sensible default; change if needed
                $ref    = 'INV-PAID-' . strtoupper(date('Ymd'));
 
                $ins = $db->prepare(
                    "INSERT INTO payments
                        (invoice_id, amount, payment_date, payment_method, reference_number)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $ins->bind_param('idsss', $invoice_id, $remaining, $today, $method, $ref);
                $ins->execute();
                $ins->close();
 
                // ── STEP 3: update the invoice totals & status ─────────────
                $upd = $db->prepare(
                    "UPDATE invoices
                     SET    paid_amount      = total,
                            remaining_amount = 0,
                            payment_status   = 'Paid'
                     WHERE  id = ?"
                );
                $upd->bind_param('i', $invoice_id);
                $upd->execute();
                $upd->close();
 
                // If you have a helper that recalculates status, call it:
                // updateInvoiceStatus($invoice_id);
 
                logActivity(
                    $_SESSION['admin_id'],
                    'BULK_MARK_PAID',
                    "Marked invoice ID $invoice_id as paid (bulk action); payment record inserted."
                );
 
                $marked++;
            }
 
            header("Location: index.php?msg=marked_paid&count=$marked");
            exit();
        }
    }
}

$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 20;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$month  = isset($_GET['month'])  ? (int)$_GET['month']  : 0;
$year   = isset($_GET['year'])   ? (int)$_GET['year']   : 0;

$where  = [];
$params = [];
$types  = "";

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

$count_sql = "SELECT COUNT(*) as total FROM invoices i JOIN clients c ON i.client_id = c.id $where_clause";
$stmt = $db->prepare($count_sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

$sql = "SELECT i.*, c.name as client_name, c.phone as client_phone,
               COALESCE(p.project_name, '— Not Selected —') as project_name
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

$years       = $db->query("SELECT DISTINCT YEAR(invoice_date) as year FROM invoices WHERE deleted_at IS NULL ORDER BY year DESC");
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
        
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Manage Invoices</h1>
            <div>
                <a href="create.php" class="btn-primary">Create Invoice</a>
                
                <?php if($trash_count > 0): ?>
                <a href="../trash/?filter=invoices" class="btn-secondary" style="background: #6c757d;">
                    Trash (<?php echo $trash_count; ?>)
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
                if ($_GET['msg'] == 'deleted')        echo "Invoice deleted successfully!";
                if ($_GET['msg'] == 'marked_paid')    echo "Invoices marked as paid successfully!";
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>

        <form method="POST" id="bulkActionForm">
            <div class="bulk-actions-bar">
                <div class="select-info"><strong>Bulk Actions:</strong></div>
                <select name="bulk_action" id="bulk_action" required>
                    <option value="">Select Action</option>
                    <option value="send_whatsapp">Send via WhatsApp</option>
                    <option value="download_pdf">Download PDFs</option>
                    <option value="mark_paid">Mark as Paid</option>
                </select>
                <button type="submit" onclick="return confirmBulkAction()">Apply to Selected</button>
                <div class="select-info">
                    <span id="selectedCountDisplay" class="selected-count">0</span> invoice(s) selected
                </div>
                <button type="button" onclick="selectAll()"   class="btn-secondary" style="background:#6c757d;">Select All</button>
                <button type="button" onclick="deselectAll()" class="btn-secondary" style="background:#6c757d;">Deselect All</button>
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
                    <?php while ($invoice = $invoices->fetch_assoc()): ?>
                    <tr>
                        <td class="checkbox-col">
                            <input type="checkbox" name="selected_invoices[]" value="<?php echo $invoice['id']; ?>"
                                   class="invoice-checkbox" onclick="updateSelectedCount()">
                        </td>
                        <td>
                            <a href="view.php?id=<?php echo $invoice['id']; ?>" class="action-btn invoice">
                            <?php echo escape($invoice['invoice_number']); ?>
                        </a>
                            
                        </td>
                        <td><?php echo escape($invoice['client_name']); ?></td>
                        <td><?php echo escape($invoice['project_name']); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($invoice['due_date'])); ?></td>
                        <td>Rs.<?php echo number_format($invoice['total'], 2); ?></td>
                        <td>Rs.<?php echo number_format($invoice['paid_amount'], 2); ?></td>
                        <td>Rs.<?php echo number_format($invoice['remaining_amount'], 2); ?></td>
                        <td><span class="status-<?php echo strtolower($invoice['payment_status']); ?>"><?php echo escape($invoice['payment_status']); ?></span></td>
                        <td>
                            <div class="action-buttons">
                                <a href="view.php?id=<?php echo $invoice['id']; ?>" class="action-btn view">
                                    <svg  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    View
                                </a>
                                <a href="edit.php?id=<?php echo $invoice['id']; ?>" class="action-btn edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </a>
                                <a href="pdf.php?id=<?php echo $invoice['id']; ?>&token=<?php echo $invoice['view_token'] ?>" target="_blank" class="action-btn pdf">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                    PDF
                                </a>
                                <a href="send_whatsapp.php?id=<?php echo $invoice['id']; ?>" class="action-btn send">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    Send
                                </a>
                                <?php if ($invoice['paid_amount'] == 0): ?>
                                <a href="?delete=<?php echo $invoice['id']; ?>" class="action-btn delete"
                                   onclick="return confirm('Move this invoice to trash? You can restore it later.')">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                    Delete
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>

                    <?php if ($invoices->num_rows == 0): ?>
                    <tr>
                        <td colspan="11" class="text-center">No invoices found</td>
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
            selectAllCheckbox.checked = allCheckboxes.length === count && count > 0;
        }
        function toggleSelectAll() {
            const checked = document.getElementById('selectAllCheckbox').checked;
            document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.checked = checked);
            updateSelectedCount();
        }
        function selectAll() {
            document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.checked = true);
            document.getElementById('selectAllCheckbox').checked = true;
            updateSelectedCount();
        }
        function deselectAll() {
            document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
            updateSelectedCount();
        }
        function confirmBulkAction() {
            const selectedCount = document.querySelectorAll('.invoice-checkbox:checked').length;
            const action = document.getElementById('bulk_action').value;
            if (selectedCount === 0) { alert('Please select at least one invoice.'); return false; }
            if (!action)             { alert('Please select an action.'); return false; }
            const messages = {
                send_whatsapp: `Send ${selectedCount} invoice(s) via WhatsApp?`,
                send_reminders: `Send payment reminders for ${selectedCount} invoice(s)?`,
                download_pdf: `Download PDFs for ${selectedCount} invoice(s)?`,
                mark_paid: `Mark ${selectedCount} invoice(s) as paid? This cannot be undone.`
            };
            return confirm(messages[action] || 'Proceed?');
        }
        document.addEventListener('DOMContentLoaded', updateSelectedCount);
    </script>
</body>
</html>