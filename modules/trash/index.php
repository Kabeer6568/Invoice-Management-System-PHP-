<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/soft_delete_helpers.php';
redirectIfNotLoggedIn();

// Handle restore - FIXED: Don't add 's' manually, pass table name directly
if (isset($_GET['restore']) && isset($_GET['type']) && isset($_GET['id'])) {
    $table = $_GET['type']; // 'client', 'project', 'invoice'
    
    // Convert singular to plural table names
    $table_map = [
        'client' => 'clients',
        'project' => 'projects', 
        'invoice' => 'invoices'
    ];
    
    $table_name = $table_map[$table] ?? $table . 's';
    
    if (restoreFromTrash($table_name, $_GET['id'])) {
        header("Location: index.php?msg=restored&type=" . $table);
        exit();
    }
}

// Handle permanent delete - FIXED: Same issue
if (isset($_GET['permanent_delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    $table = $_GET['type'];
    
    $table_map = [
        'client' => 'clients',
        'project' => 'projects', 
        'invoice' => 'invoices'
    ];
    
    $table_name = $table_map[$table] ?? $table . 's';
    
    if (permanentDelete($table_name, $_GET['id'])) {
        header("Location: index.php?msg=deleted_forever&type=" . $table);
        exit();
    }
}

// Get filter type from URL
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Get counts using helpers
$trash_counts = [
    'clients' => countTrashed('clients'),
    'projects' => countTrashed('projects'),
    'invoices' => countTrashed('invoices')
];

// Get trashed items based on filter
$clients = [];
$projects = [];
$invoices = [];

if ($filter_type == 'all' || $filter_type == 'clients') {
    $clients = getTrashed('clients');
}
if ($filter_type == 'all' || $filter_type == 'projects') {
    $projects = getTrashed('projects');
}
if ($filter_type == 'all' || $filter_type == 'invoices') {
    $invoices = getTrashed('invoices');
}

$total_trash = array_sum($trash_counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash - Deleted Items</title>
    <link rel="stylesheet" href="http://localhost/invoice-management-system/assets/css/trash.css">
    <style>
        
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="trash-header">
            <h1>Trash</h1>
            <p>Items deleted from the system are moved here. You can restore them or permanently delete them.</p>
            <div style="margin-top: 15px;">
                <span class="stats-card">Clients: <?php echo $trash_counts['clients']; ?></span>
                <span class="stats-card">Projects: <?php echo $trash_counts['projects']; ?></span>
                <span class="stats-card">Invoices: <?php echo $trash_counts['invoices']; ?></span>
                <span class="stats-card">Total: <?php echo $total_trash; ?></span>
            </div>
        </div>
        
        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success">
                <?php 
                if($_GET['msg'] == 'restored') echo "✓ Item restored successfully!";
                if($_GET['msg'] == 'deleted_forever') echo "✓ Item permanently deleted!";
                ?>
            </div>
        <?php endif; ?>
        
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=all" class="filter-tab <?php echo $filter_type == 'all' ? 'active' : ''; ?>">All (<?php echo $total_trash; ?>)</a>
            <a href="?filter=clients" class="filter-tab <?php echo $filter_type == 'clients' ? 'active' : ''; ?>">Clients (<?php echo $trash_counts['clients']; ?>)</a>
            <a href="?filter=projects" class="filter-tab <?php echo $filter_type == 'projects' ? 'active' : ''; ?>">Projects (<?php echo $trash_counts['projects']; ?>)</a>
            <a href="?filter=invoices" class="filter-tab <?php echo $filter_type == 'invoices' ? 'active' : ''; ?>">Invoices (<?php echo $trash_counts['invoices']; ?>)</a>
        </div>
        
        <!-- Clients Section -->
        <?php if(($filter_type == 'all' || $filter_type == 'clients') && $trash_counts['clients'] > 0): ?>
        <div class="trash-section">
            <div class="section-title">
                <span>Deleted Clients</span>
                <button class="empty-trash-btn" onclick="if(confirm('Are you sure? This will permanently delete ALL clients in trash!')) window.location.href='?empty=clients'">Empty Clients Trash</button>
            </div>
            <?php while($client = $clients->fetch_assoc()): ?>
            <div class="trash-item client">
                <div class="item-info">
                    <div class="item-title"><?php echo htmlspecialchars($client['name']); ?></div>
                    <div class="item-details">
                        <span>Company: <?php echo htmlspecialchars($client['company']); ?></span>
                        <span>Email: <?php echo htmlspecialchars($client['email']); ?></span>
                        <span>Phone: <?php echo htmlspecialchars($client['phone']); ?></span>
                    </div>
                </div>
                <div class="item-meta">
                    <div class="deleted-date">Deleted: <?php echo date('d M Y, h:i A', strtotime($client['deleted_at'])); ?></div>
                </div>
                <div class="action-buttons">
                    <a href="?restore=1&type=client&id=<?php echo $client['id']; ?>" class="btn-restore" onclick="return confirm('Restore this client?')">Restore</a>
                    <a href="?permanent_delete=1&type=client&id=<?php echo $client['id']; ?>" class="btn-permanent" onclick="return confirm('Permanently delete this client? This cannot be undone!')">Delete Forever</a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <!-- Projects Section -->
        <?php if(($filter_type == 'all' || $filter_type == 'projects') && $trash_counts['projects'] > 0): ?>
        <div class="trash-section">
            <div class="section-title">
                <span>Deleted Projects</span>
                <button class="empty-trash-btn" onclick="if(confirm('Are you sure? This will permanently delete ALL projects in trash!')) window.location.href='?empty=projects'">Empty Projects Trash</button>
            </div>
            <?php while($project = $projects->fetch_assoc()): ?>
            <div class="trash-item project">
                <div class="item-info">
                    <div class="item-title"><?php echo htmlspecialchars($project['project_name']); ?></div>
                    <div class="item-details">
                        <span>Department: <?php echo htmlspecialchars($project['department']); ?></span>
                        <span>Amount Remaining: Rs. <?php echo number_format($project['cost'] > 0 ? $project['cost'] : $project['monthly_fee'], 2); ?></span>
                    </div>
                </div>
                <div class="item-meta">
                    <div class="deleted-date">Deleted: <?php echo date('d M Y, h:i A', strtotime($project['deleted_at'])); ?></div>
                </div>
                <div class="action-buttons">
                    <a href="?restore=1&type=project&id=<?php echo $project['id']; ?>" class="btn-restore" onclick="return confirm('Restore this project?')">Restore</a>
                    <a href="?permanent_delete=1&type=project&id=<?php echo $project['id']; ?>" class="btn-permanent" onclick="return confirm('Permanently delete this project? This cannot be undone!')">Delete Forever</a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <!-- Invoices Section -->
        <?php if(($filter_type == 'all' || $filter_type == 'invoices') && $trash_counts['invoices'] > 0): ?>
        <div class="trash-section">
            <div class="section-title">
                <span>Deleted Invoices</span>
                <button class="empty-trash-btn" onclick="if(confirm('Are you sure? This will permanently delete ALL invoices in trash!')) window.location.href='?empty=invoices'">Empty Invoices Trash</button>
            </div>
            <?php while($invoice = $invoices->fetch_assoc()): ?>
            <div class="trash-item invoice">
                <div class="item-info">
                    <div class="item-title">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
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
                    <a href="?restore=1&type=invoice&id=<?php echo $invoice['id']; ?>" class="btn-restore" onclick="return confirm('Restore this invoice?')">Restore</a>
                    <a href="?permanent_delete=1&type=invoice&id=<?php echo $invoice['id']; ?>" class="btn-permanent" onclick="return confirm('Permanently delete this invoice? This cannot be undone!')">Delete Forever</a>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <!-- No Data Message -->
        <?php if($total_trash == 0): ?>
        <div class="no-data">
            Trash is empty. Deleted items will appear here.
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="../clients/" class="btn-secondary" style="padding: 8px 20px;">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>