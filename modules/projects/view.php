<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: index.php");
    exit();
}

// Get project data
$stmt = $db->prepare("SELECT p.*, c.name as client_name, c.company, c.email, c.phone 
                      FROM projects p 
                      JOIN clients c ON p.client_id = c.id 
                      WHERE p.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();

if (!$project) {
    header("Location: index.php");
    exit();
}

// Get invoices for this project
$stmt = $db->prepare("SELECT * FROM invoices WHERE project_id = ? ORDER BY invoice_date DESC");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoices = $stmt->get_result();

// Get total invoiced and paid
$stmt = $db->prepare("SELECT SUM(total) as total_invoiced, SUM(paid_amount) as total_paid FROM invoices WHERE project_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($project['project_name']); ?> - Project Details</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Project: <?php echo escape($project['project_name']); ?></h1>
            <div>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn-primary">Edit Project</a>
                <a href="../invoices/create.php?project=<?php echo $id; ?>" class="btn-secondary">Create Invoice</a>
                <a href="index.php" class="btn-secondary">Back</a>
            </div>
        </div>
        
        <div class="project-info">
            <div class="info-grid">
                <div class="info-card">
                    <h3>Project Details</h3>
                    <p><strong>Client:</strong> <?php echo escape($project['client_name']); ?> (<?php echo escape($project['company']); ?>)</p>
                    <p><strong>Department:</strong> <?php echo escape($project['department']); ?></p>
                    <p><strong>Type:</strong> <?php echo escape($project['project_type']); ?></p>
                    <p><strong>Status:</strong> <span class="status-<?php echo strtolower(str_replace(' ', '-', $project['status'])); ?>"><?php echo $project['status']; ?></span></p>
                    <p><strong>Payment Status:</strong> <span class="status-<?php echo strtolower($project['payment_status']); ?>"><?php echo $project['payment_status']; ?></span></p>
                </div>
                
                <div class="info-card">
                    <h3>Financial Summary</h3>
                    <p><strong>One-time Cost:</strong> Rs.<?php echo number_format($project['cost'], 2); ?></p>
                    <p><strong>Monthly Fee:</strong> Rs.<?php echo number_format($project['monthly_fee'], 2); ?></p>
                    <p><strong>Total Invoiced:</strong> Rs.<?php echo number_format($totals['total_invoiced'] ?? 0, 2); ?></p>
                    <p><strong>Total Paid:</strong> Rs.<?php echo number_format($totals['total_paid'] ?? 0, 2); ?></p>
                    <p><strong>Balance Due:</strong> Rs.<?php echo number_format(($totals['total_invoiced'] ?? 0) - ($totals['total_paid'] ?? 0), 2); ?></p>
                </div>
                
                <?php if($project['start_date'] || $project['end_date']): ?>
                <div class="info-card">
                    <h3>Timeline</h3>
                    <p><strong>Start Date:</strong> <?php echo $project['start_date'] ? date('Y-m-d', strtotime($project['start_date'])) : 'Not set'; ?></p>
                    <p><strong>End Date:</strong> <?php echo $project['end_date'] ? date('Y-m-d', strtotime($project['end_date'])) : 'Not set'; ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if($project['description']): ?>
            <div class="info-card">
                <h3>Description</h3>
                <p><?php echo nl2br(escape($project['description'])); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if($project['notes']): ?>
            <div class="info-card">
                <h3>Notes</h3>
                <p><?php echo nl2br(escape($project['notes'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="invoices-section">
            <h2>Associated Invoices</h2>
            <?php if($invoices->num_rows > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($invoice = $invoices->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo escape($invoice['invoice_number']); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($invoice['due_date'])); ?></td>
                        <td>Rs.<?php echo number_format($invoice['total'], 2); ?></td>
                        <td>Rs.<?php echo number_format($invoice['paid_amount'], 2); ?></td>
                        <td><span class="status-<?php echo strtolower($invoice['payment_status']); ?>"><?php echo $invoice['payment_status']; ?></span></td>
                        <td>
                            <a href="../invoices/view.php?id=<?php echo $invoice['id']; ?>">View</a>
                            <a href="../invoices/pdf.php?id=<?php echo $invoice['id']; ?>">PDF</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="no-data">No invoices found for this project. <a href="../invoices/create.php?project=<?php echo $id; ?>">Create Invoice</a></p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>