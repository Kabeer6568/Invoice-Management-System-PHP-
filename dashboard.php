<?php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
redirectIfNotLoggedIn();

// Get dashboard statistics
$stats = [];

// Total clients
$result = $db->query("SELECT COUNT(*) as count FROM clients");
$stats['total_clients'] = $result->fetch_assoc()['count'];

// Total projects
$result = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as active
    FROM projects");
$project_stats = $result->fetch_assoc();
$stats['total_projects'] = $project_stats['total'];
$stats['completed_projects'] = $project_stats['completed'];
$stats['active_projects'] = $project_stats['active'];

// Payment statistics
$result = $db->query("SELECT 
    SUM(CASE WHEN payment_status IN ('Pending', 'Partial') THEN 1 ELSE 0 END) as pending_payments,
    SUM(total) as total_revenue,
    SUM(paid_amount) as total_paid
    FROM invoices");
$payment_stats = $result->fetch_assoc();
$stats['pending_payments'] = $payment_stats['pending_payments'] ?? 0;
$stats['total_revenue'] = $payment_stats['total_revenue'] ?? 0;
$stats['total_paid'] = $payment_stats['total_paid'] ?? 0;

// Monthly revenue
$result = $db->query("SELECT 
    SUM(paid_amount) as monthly_revenue 
    FROM invoices 
    WHERE MONTH(invoice_date) = MONTH(CURRENT_DATE) 
    AND YEAR(invoice_date) = YEAR(CURRENT_DATE)");
$monthly = $result->fetch_assoc();
$stats['monthly_revenue'] = $monthly['monthly_revenue'] ?? 0;

// Recent activities
$recent_invoices = $db->query("SELECT i.*, c.name as client_name 
    FROM invoices i 
    JOIN clients c ON i.client_id = c.id 
    ORDER BY i.created_at DESC LIMIT 5");

$recent_projects = $db->query("SELECT p.*, c.name as client_name 
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    ORDER BY p.created_at DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Invoice Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="container">
        <h1>Dashboard</h1>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_clients']; ?></div>
                <div class="stat-label">Total Clients</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_projects']; ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['active_projects']; ?></div>
                <div class="stat-label">Active Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['pending_payments']; ?></div>
                <div class="stat-label">Pending Payments</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($stats['total_revenue'], 2); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($stats['monthly_revenue'], 2); ?></div>
                <div class="stat-label">This Month</div>
            </div>
        </div>
        
        <div class="activity-grid">
            <div class="activity-section">
                <h2>Recent Invoices</h2>
                <table class="data-table">
                    <thead>
                        <tr><th>Invoice #</th><th>Client</th><th>Amount</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php while($invoice = $recent_invoices->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo escape($invoice['invoice_number']); ?></td>
                            <td><?php echo escape($invoice['client_name']); ?></td>
                            <td>Rs.<?php echo number_format($invoice['total'], 2); ?></td>
                            <td><span class="status-<?php echo strtolower($invoice['payment_status']); ?>"><?php echo $invoice['payment_status']; ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="activity-section">
                <h2>Recent Projects</h2>
                <table class="data-table">
                    <thead>
                        <tr><th>Project Name</th><th>Client</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php while($project = $recent_projects->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo escape($project['project_name']); ?></td>
                            <td><?php echo escape($project['client_name']); ?></td>
                            <td><span class="status-<?php echo strtolower(str_replace(' ', '-', $project['status'])); ?>"><?php echo $project['status']; ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>