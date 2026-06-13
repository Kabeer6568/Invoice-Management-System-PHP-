<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/soft_delete_helpers.php';
redirectIfNotLoggedIn();

// ── Table map (singular type → plural table name) ──────────────────
$table_map = [
    'client'  => 'clients',
    'project' => 'projects',
    'invoice' => 'invoices',
    'payment' => 'payments',
];

// ── Restore ────────────────────────────────────────────────────────
if (isset($_GET['restore'], $_GET['type'], $_GET['id'])) {
    $table_name = $table_map[$_GET['type']] ?? null;

    if ($table_name) {
        // If restoring a payment, add back the amount to the invoice balance
        if ($table_name === 'payments') {
            $stmt = $db->prepare("SELECT invoice_id, amount FROM payments WHERE id = ? AND deleted_at IS NOT NULL");
            $stmt->bind_param("i", $_GET['id']);
            $stmt->execute();
            $pmt = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($pmt) {
                $upd = $db->prepare("UPDATE invoices SET paid_amount = paid_amount + ? WHERE id = ?");
                $upd->bind_param("di", $pmt['amount'], $pmt['invoice_id']);
                $upd->execute();
                $upd->close();
                updateInvoiceStatus($pmt['invoice_id']);
            }
        }

        restoreFromTrash($table_name, $_GET['id']);
        header("Location: index.php?msg=restored&type=" . $_GET['type']);
        exit();
    }
}

// ── Permanent delete ───────────────────────────────────────────────
if (isset($_GET['permanent_delete'], $_GET['type'], $_GET['id'])) {
    $table_name = $table_map[$_GET['type']] ?? null;
    if ($table_name) {
        permanentDelete($table_name, $_GET['id']);
        header("Location: index.php?msg=deleted_forever&type=" . $_GET['type']);
        exit();
    }
}

// ── Empty section ──────────────────────────────────────────────────
if (isset($_GET['empty'])) {
    $empty_map = [
        'clients'  => 'clients',
        'projects' => 'projects',
        'invoices' => 'invoices',
        'payments' => 'payments',
    ];
    $empty_table = $empty_map[$_GET['empty']] ?? null;
    if ($empty_table) {
        $db->query("DELETE FROM $empty_table WHERE deleted_at IS NOT NULL");
        header("Location: index.php?msg=emptied&type=" . $_GET['empty']);
        exit();
    }
}

// ── Filter ─────────────────────────────────────────────────────────
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// ── Counts ─────────────────────────────────────────────────────────
$trash_counts = [
    'clients'  => countTrashed('clients'),
    'projects' => countTrashed('projects'),
    'invoices' => countTrashed('invoices'),
    'payments' => countTrashed('payments'),
];

// invoices + payments combined count for the tab label
$invoices_tab_count = $trash_counts['invoices'] + $trash_counts['payments'];
$total_trash        = array_sum($trash_counts);

// ── Fetch trashed records ──────────────────────────────────────────
$clients  = null;
$projects = null;
$invoices = null;
$payments = null;

if ($filter_type === 'all' || $filter_type === 'clients') {
    $clients = getTrashed('clients');
}
if ($filter_type === 'all' || $filter_type === 'projects') {
    $projects = getTrashed('projects');
}
if ($filter_type === 'all' || $filter_type === 'invoices') {
    $invoices = getTrashed('invoices');
    $payments = getTrashed('payments');   // payments live in the invoices tab
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash - Deleted Items</title>
    <link rel="stylesheet" href="../../assets/css/trash.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">

        <div class="trash-header">
            <h1>Trash</h1>
            <p>Items deleted from the system are moved here. You can restore them or permanently delete them.</p>
            <div style="margin-top:15px;">
                <span class="stats-card">Clients: <?php echo $trash_counts['clients']; ?></span>
                <span class="stats-card">Projects: <?php echo $trash_counts['projects']; ?></span>
                <span class="stats-card">Invoices: <?php echo $trash_counts['invoices']; ?></span>
                <span class="stats-card">Payments: <?php echo $trash_counts['payments']; ?></span>
                <span class="stats-card">Total: <?php echo $total_trash; ?></span>
            </div>
        </div>

        <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">
            <?php
            if ($_GET['msg'] === 'restored')       echo "✓ Item restored successfully!";
            if ($_GET['msg'] === 'deleted_forever') echo "✓ Item permanently deleted!";
            if ($_GET['msg'] === 'emptied')         echo "✓ Section emptied successfully!";
            ?>
        </div>
        <?php endif; ?>

        <!-- ── Filter Tabs ──────────────────────────────────────── -->
        <div class="filter-tabs">
            <a href="?filter=all"
               class="filter-tab <?php echo $filter_type === 'all'      ? 'active' : ''; ?>">
               All (<?php echo $total_trash; ?>)
            </a>
            <a href="?filter=clients"
               class="filter-tab <?php echo $filter_type === 'clients'  ? 'active' : ''; ?>">
               Clients (<?php echo $trash_counts['clients']; ?>)
            </a>
            <a href="?filter=projects"
               class="filter-tab <?php echo $filter_type === 'projects' ? 'active' : ''; ?>">
               Projects (<?php echo $trash_counts['projects']; ?>)
            </a>
            <a href="?filter=invoices"
               class="filter-tab <?php echo $filter_type === 'invoices' ? 'active' : ''; ?>">
               Invoices &amp; Payments (<?php echo $invoices_tab_count; ?>)
            </a>
        </div>

        <!-- ══ CLIENTS ══════════════════════════════════════════════ -->
        <?php if (($filter_type === 'all' || $filter_type === 'clients') && $trash_counts['clients'] > 0): ?>
        <div class="trash-section">
            <div class="section-title">
                <span>Deleted Clients</span>
            </div>
            <div class="trash-sub-heading">
                Clients (<?php echo $trash_counts['clients']; ?>)
                <a href="?empty=clients"
                   class="empty-trash-link"
                   onclick="return confirm('Permanently delete ALL invoices in trash?')">Empty Clients Only</a>
            </div>
            <?php while ($client = $clients->fetch_assoc()): ?>
            <div class="trash-item client">
                <div class="item-info">
    <div class="item-title">
        <?php echo htmlspecialchars($client['name']); ?>
        <span class="trash-item-badge trash-item-badge--client">Client</span>
    </div>

    <div class="item-details">
        <?php if(!empty($client['company'])): ?>
            <span><strong>Company:</strong> <?php echo htmlspecialchars($client['company']); ?></span>
        <?php endif; ?>

        <?php if(!empty($client['email'])): ?>
            <span><strong>Email:</strong> <?php echo htmlspecialchars($client['email']); ?></span>
        <?php endif; ?>

        <?php if(!empty($client['phone'])): ?>
            <span><strong>Phone:</strong> <?php echo htmlspecialchars($client['phone']); ?></span>
        <?php endif; ?>

        <?php if(!empty($client['client_type'])): ?>
            <span><strong>Type:</strong> <?php echo ucfirst($client['client_type']); ?></span>
        <?php endif; ?>
    </div>
</div>
                <div class="item-meta">
                    <div class="deleted-date">Deleted: <?php echo date('d M Y, h:i A', strtotime($client['deleted_at'])); ?></div>
                </div>
                <div class="action-buttons">
                    <a href="?restore=1&type=client&id=<?php echo $client['id']; ?>"
                       class="btn-restore"
                       onclick="return confirm('Restore this client?')">Restore</a>
                    <a href="?permanent_delete=1&type=client&id=<?php echo $client['id']; ?>"
                       class="btn-permanent"
                       onclick="return confirm('Permanently delete this client? This cannot be undone!')">Delete Forever</a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>

        <!-- ══ PROJECTS ═════════════════════════════════════════════ -->
        <?php if (($filter_type === 'all' || $filter_type === 'projects') && $trash_counts['projects'] > 0): ?>
        <div class="trash-section">
            <div class="section-title">
                <span>Deleted Projects</span>
            </div>

            <div class="trash-sub-heading">
                projects (<?php echo $trash_counts['projects']; ?>)
                <a href="?empty=projects"
                   class="empty-trash-link"
                   onclick="return confirm('Permanently delete ALL invoices in trash?')">Empty Projects Only</a>
            </div>

            <?php while ($project = $projects->fetch_assoc()): ?>
            <div class="trash-item project">
                <div class="item-info">
    <div class="item-title">
        <?php echo htmlspecialchars($project['project_name']); ?>
        <span class="trash-item-badge trash-item-badge--project">Project</span>
    </div>

    <div class="item-details">

        <?php if(!empty($project['department'])): ?>
            <span>
                <strong>Department:</strong>
                <?php echo htmlspecialchars($project['department']); ?>
            </span>
        <?php endif; ?>

        <span>
            <strong>Amount:</strong>
            Rs. <?php echo number_format($project['cost'] > 0 ? $project['cost'] : $project['monthly_fee'], 2); ?>
        </span>

        <?php if(!empty($project['status'])): ?>
            <span>
                <strong>Status:</strong>
                <?php echo htmlspecialchars($project['status']); ?>
            </span>
        <?php endif; ?>

    </div>
</div>
                <div class="item-meta">
                    <div class="deleted-date">Deleted: <?php echo date('d M Y, h:i A', strtotime($project['deleted_at'])); ?></div>
                </div>
                <div class="action-buttons">
                    <a href="?restore=1&type=project&id=<?php echo $project['id']; ?>"
                       class="btn-restore"
                       onclick="return confirm('Restore this project?')">Restore</a>
                    <a href="?permanent_delete=1&type=project&id=<?php echo $project['id']; ?>"
                       class="btn-permanent"
                       onclick="return confirm('Permanently delete this project? This cannot be undone!')">Delete Forever</a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>

        <!-- ══ INVOICES + PAYMENTS (same tab) ═══════════════════════ -->
        <?php if (($filter_type === 'all' || $filter_type === 'invoices') && $invoices_tab_count > 0): ?>
        <div class="trash-section">
            <div class="section-title">
                <span>Deleted Invoices &amp; Payments</span>
                <!-- Empty both at once -->
                <button class="empty-trash-btn"
                        onclick="if(confirm('Permanently delete ALL invoices AND payments in trash?')) window.location.href='?empty=invoices&also=payments'">
                    Empty All
                </button>
            </div>

            <!-- ── Invoices ─────────────────────────────────────── -->
            <?php if ($trash_counts['invoices'] > 0): ?>

            <div class="trash-sub-heading">
                Invoices (<?php echo $trash_counts['invoices']; ?>)
                <a href="?empty=invoices"
                   class="empty-trash-link"
                   onclick="return confirm('Permanently delete ALL invoices in trash?')">Empty Invoices Only</a>
            </div>

            <?php while ($invoice = $invoices->fetch_assoc()): ?>
            <div class="trash-item invoice">
                <div class="item-info">
                    <div class="item-title">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?>
                        <span class="trash-item-badge trash-item-badge--invoice">Invoice</span>
                    </div>
                    <div class="item-details">
                        <span>Client ID: <?php echo $invoice['client_id']; ?></span>
                        <span>Total: Rs. <?php echo number_format($invoice['total'], 2); ?></span>
                        <span>Due: <?php echo date('d M Y', strtotime($invoice['due_date'])); ?></span>
                    </div>
                </div>
                <div class="item-meta">
                    <div class="deleted-date">Deleted: <?php echo date('d M Y, h:i A', strtotime($invoice['deleted_at'])); ?></div>
                </div>
                <div class="action-buttons">
                    <a href="?restore=1&type=invoice&id=<?php echo $invoice['id']; ?>"
                       class="btn-restore"
                       onclick="return confirm('Restore this invoice?')">Restore</a>
                    <a href="?permanent_delete=1&type=invoice&id=<?php echo $invoice['id']; ?>"
                       class="btn-permanent"
                       onclick="return confirm('Permanently delete this invoice? This cannot be undone!')">Delete Forever</a>
                </div>
            </div>
            <?php endwhile; ?>
            <?php endif; ?>

            <!-- ── Payments ─────────────────────────────────────── -->
            <?php if ($trash_counts['payments'] > 0): ?>

            <div class="trash-sub-heading">
                Payments (<?php echo $trash_counts['payments']; ?>)
                <a href="?empty=payments"
                   class="empty-trash-link"
                   onclick="return confirm('Permanently delete ALL payments in trash?')">Empty Payments Only</a>
            </div>

            <?php
            // Re-fetch payments with invoice number and client name via JOIN
            $pmt_result = $db->query("
                SELECT p.*, i.invoice_number, c.name AS client_name
                FROM   payments p
                JOIN   invoices i ON p.invoice_id = i.id
                JOIN   clients  c ON i.client_id  = c.id
                WHERE  p.deleted_at IS NOT NULL
                ORDER  BY p.deleted_at DESC
            ");
            while ($pmt = $pmt_result->fetch_assoc()):
            ?>
            <div class="trash-item invoice">
                <div class="item-info">
                    <div class="item-title">
                        Payment — Invoice #<?php echo htmlspecialchars($pmt['invoice_number']); ?>
                        <span class="trash-item-badge trash-item-badge--payment">Payment</span>
                    </div>
                    <div class="item-details">
                        <span>Client: <?php echo htmlspecialchars($pmt['client_name']); ?></span>
                        <span>Amount: Rs. <?php echo number_format($pmt['amount'], 2); ?></span>
                        <span>Method: <?php echo htmlspecialchars($pmt['payment_method']); ?></span>
                        <span>Paid on: <?php echo date('d M Y', strtotime($pmt['payment_date'])); ?></span>
                    </div>
                </div>
                <div class="item-meta">
                    <div class="deleted-date">Deleted: <?php echo date('d M Y, h:i A', strtotime($pmt['deleted_at'])); ?></div>
                </div>
                <div class="action-buttons">
                    <a href="?restore=1&type=payment&id=<?php echo $pmt['id']; ?>"
                       class="btn-restore"
                       onclick="return confirm('Restore this payment? The invoice balance will be updated.')">Restore</a>
                    <a href="?permanent_delete=1&type=payment&id=<?php echo $pmt['id']; ?>"
                       class="btn-permanent"
                       onclick="return confirm('Permanently delete this payment? This cannot be undone!')">Delete Forever</a>
                </div>
            </div>
            <?php endwhile; ?>
            <?php endif; ?>

        </div><!-- /.trash-section -->
        <?php endif; ?>

        <!-- ── Empty state ──────────────────────────────────────── -->
        <?php if ($total_trash == 0): ?>
        <div class="no-data">
            Trash is empty. Deleted items will appear here.
        </div>
        <?php endif; ?>

        <div style="margin-top:20px; text-align:center;">
            <a href="../clients/" class="btn-secondary" style="padding:8px 20px;">← Back to Dashboard</a>
        </div>
    </div>

    <style>

    </style>
</body>
</html>