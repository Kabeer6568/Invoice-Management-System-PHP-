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

// Get client data
$stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
    header("Location: index.php");
    exit();
}

$summary = getClientSummary($id);

// Get projects
$stmt = $db->prepare("SELECT * FROM projects WHERE client_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $id);
$stmt->execute();
$projects = $stmt->get_result();

// Get invoices
$stmt = $db->prepare("SELECT * FROM invoices WHERE client_id = ? ORDER BY invoice_date DESC");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoices = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($client['name']); ?> - Client Details</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Client Details: <?php echo escape($client['name']); ?></h1>
            <div>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn-primary">Edit Client</a>
                <a href="../projects/create.php?client=<?php echo $id; ?>" class="btn-secondary">Add Project</a>
                <a href="../invoices/create.php?client=<?php echo $id; ?>" class="btn-secondary">Create Invoice</a>
                <a href="index.php" class="btn-secondary">Back</a>
            </div>
        </div>
        
        <div class="client-info">
            <div class="info-grid">
                <div class="info-card">
                    <h3>Contact Information</h3>
                    <p><strong>Company:</strong> <?php echo escape($client['company']); ?></p>
                    <p><strong>Contact Person:</strong> <?php echo escape($client['contact_person']); ?></p>
                    <p><strong>Phone:</strong> <?php echo escape($client['phone']); ?></p>
                    <p><strong>Email:</strong> <?php echo escape($client['email']); ?></p>
                    <p><strong>Address:</strong> <?php echo nl2br(escape($client['address'])); ?></p>
                </div>
                
                <div class="info-card">
                    <h3>Financial Summary</h3>
                    <p><strong>Total Projects:</strong> <?php echo $summary['total_projects']; ?></p>
                    <p><strong>Total Invoices:</strong> <?php echo $summary['total_invoices']; ?></p>
                    <p><strong>Total Paid:</strong> Rs.<?php echo number_format($summary['total_paid'], 2); ?></p>
                    <p><strong>Pending Balance:</strong> Rs.<?php echo number_format($summary['pending_balance'], 2); ?></p>
                </div>
                
                <?php if($client['notes']): ?>
                <div class="info-card">
                    <h3>Notes</h3>
                    <p><?php echo nl2br(escape($client['notes'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="projects-section">
            <h2>Projects</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th>Department</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Cost</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo escape($project['project_name']); ?></td>
                        <td><?php echo escape($project['department']); ?></td>
                        <td><?php echo escape($project['project_type']); ?></td>
                        <td><span class="status-<?php echo strtolower(str_replace(' ', '-', $project['status'])); ?>"><?php echo $project['status']; ?></span></td>
                        <td>Rs.<?php echo number_format($project['cost'], 2); ?></td>
                        <td>
                            <a href="../projects/view.php?id=<?php echo $project['id']; ?>">View</a>
                            <a href="../projects/edit.php?id=<?php echo $project['id']; ?>">Edit</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <div class="invoices-section">
            <h2>Invoices</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Project</th>
                        <th>Date</th>
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
                        <td><?php 
                            $proj = $db->query("SELECT project_name FROM projects WHERE id = {$invoice['project_id']}");
                            echo escape($proj->fetch_assoc()['project_name']);
                        ?></td>
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
        </div>
    </div>
</body>
</html>