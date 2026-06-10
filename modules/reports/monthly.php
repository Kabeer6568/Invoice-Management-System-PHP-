<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$report_type = isset($_GET['type']) ? $_GET['type'] : 'summary';

// Get financial summary
$stmt = $db->prepare("SELECT 
    SUM(i.total) as total_invoiced,
    SUM(i.paid_amount) as total_received,
    SUM(i.remaining_amount) as remaining_balance,
    COUNT(i.id) as invoice_count,
    SUM(CASE WHEN i.payment_status = 'Paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN i.payment_status = 'Partial' THEN 1 ELSE 0 END) as partial_count,
    SUM(CASE WHEN i.payment_status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN i.payment_status = 'Overdue' THEN 1 ELSE 0 END) as overdue_count
    FROM invoices i
    WHERE MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ?");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Client-wise breakdown
$stmt = $db->prepare("SELECT 
    c.id, c.name, c.company,
    COUNT(i.id) as invoice_count,
    SUM(i.total) as total_invoiced,
    SUM(i.paid_amount) as total_paid,
    SUM(i.remaining_amount) as balance
    FROM clients c
    LEFT JOIN invoices i ON c.id = i.client_id 
        AND MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ?
    GROUP BY c.id
    HAVING invoice_count > 0 OR total_invoiced > 0
    ORDER BY total_invoiced DESC");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$client_breakdown = $stmt->get_result();

// Project-wise breakdown
$stmt = $db->prepare("SELECT 
    p.id, p.project_name, p.department,
    COUNT(i.id) as invoice_count,
    SUM(i.total) as total_invoiced,
    SUM(i.paid_amount) as total_paid,
    SUM(i.remaining_amount) as balance
    FROM projects p
    LEFT JOIN invoices i ON p.id = i.project_id 
        AND MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ?
    GROUP BY p.id
    HAVING invoice_count > 0 OR total_invoiced > 0
    ORDER BY total_invoiced DESC
    LIMIT 20");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$project_breakdown = $stmt->get_result();

// Monthly comparison (last 12 months)
$comparison = $db->query("SELECT 
    DATE_FORMAT(invoice_date, '%Y-%m') as month,
    SUM(total) as total_invoiced,
    SUM(paid_amount) as total_received
    FROM invoices
    WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month DESC");

// Department-wise breakdown
$stmt = $db->prepare("SELECT 
    p.department,
    COUNT(i.id) as invoice_count,
    SUM(i.total) as total_invoiced,
    SUM(i.paid_amount) as total_paid
    FROM projects p
    JOIN invoices i ON p.id = i.project_id
    WHERE MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ?
    GROUP BY p.department");
$stmt->bind_param("ii", $selected_month, $selected_year);
$stmt->execute();
$department_breakdown = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Financial Reports</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Financial Reports</h1>
            <a href="export.php?month=<?php echo $selected_month; ?>&year=<?php echo $selected_year; ?>" class="btn-secondary">Export Report</a>
        </div>
        
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <select name="month">
                    <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $selected_month == $m ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
                
                <select name="year">
                    <?php for($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                    <?php endfor; ?>
                </select>
                
                <select name="type">
                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary Report</option>
                    <option value="detailed" <?php echo $report_type == 'detailed' ? 'selected' : ''; ?>>Detailed Report</option>
                </select>
                
                <button type="submit">Generate Report</button>
            </div>
        </form>
        
        <div class="report-header">
            <h2>Financial Report for <?php echo date('F Y', mktime(0,0,0,$selected_month,1,$selected_year)); ?></h2>
        </div>
        
        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($summary['total_invoiced'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Invoiced</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($summary['total_received'] ?? 0, 2); ?></div>
                <div class="stat-label">Total Received</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">Rs.<?php echo number_format($summary['remaining_balance'] ?? 0, 2); ?></div>
                <div class="stat-label">Remaining Balance</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $summary['invoice_count'] ?? 0; ?></div>
                <div class="stat-label">Total Invoices</div>
            </div>
        </div>
        
        <!-- Status Breakdown -->
        <div class="breakdown-section">
            <h3>Payment Status Breakdown</h3>
            <div class="status-breakdown">
                <div class="status-item">
                    <span class="status-label status-paid">Paid:</span>
                    <span class="status-value"><?php echo $summary['paid_count'] ?? 0; ?> invoices</span>
                </div>
                <div class="status-item">
                    <span class="status-label status-partial">Partial:</span>
                    <span class="status-value"><?php echo $summary['partial_count'] ?? 0; ?> invoices</span>
                </div>
                <div class="status-item">
                    <span class="status-label status-pending">Pending:</span>
                    <span class="status-value"><?php echo $summary['pending_count'] ?? 0; ?> invoices</span>
                </div>
                <div class="status-item">
                    <span class="status-label status-overdue">Overdue:</span>
                    <span class="status-value"><?php echo $summary['overdue_count'] ?? 0; ?> invoices</span>
                </div>
            </div>
        </div>
        
        <!-- Client-wise Breakdown -->
        <div class="breakdown-section">
            <h3>Client-wise Breakdown</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Company</th>
                        <th>Invoices</th>
                        <th>Invoiced</th>
                        <th>Paid</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($client_breakdown->num_rows > 0): ?>
                    <?php while($client = $client_breakdown->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo escape($client['name']); ?></td>
                        <td><?php echo escape($client['company']); ?></td>
                        <td><?php echo $client['invoice_count']; ?></td>
                        <td>Rs.<?php echo number_format($client['total_invoiced'] ?? 0, 2); ?></td>
                        <td>Rs.<?php echo number_format($client['total_paid'] ?? 0, 2); ?></td>
                        <td>Rs.<?php echo number_format($client['balance'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center">No invoice data for this period</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Project-wise Breakdown -->
        <div class="breakdown-section">
            <h3>Project-wise Breakdown (Top 20)</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Department</th>
                        <th>Invoices</th>
                        <th>Invoiced</th>
                        <th>Paid</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($project = $project_breakdown->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo escape($project['project_name']); ?></td>
                        <td><?php echo escape($project['department']); ?></td>
                        <td><?php echo $project['invoice_count']; ?></td>
                        <td>Rs.<?php echo number_format($project['total_invoiced'] ?? 0, 2); ?></td>
                        <td>Rs.<?php echo number_format($project['total_paid'] ?? 0, 2); ?></td>
                        <td>Rs.<?php echo number_format($project['balance'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Department-wise Breakdown -->
        <div class="breakdown-section">
            <h3>Department-wise Breakdown</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Invoices</th>
                        <th>Total Invoiced</th>
                        <th>Total Paid</th>
                        <th>Collection Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($dept = $department_breakdown->fetch_assoc()): 
                        $rate = $dept['total_invoiced'] > 0 ? ($dept['total_paid'] / $dept['total_invoiced']) * 100 : 0;
                    ?>
                    <tr>
                        <td><?php echo escape($dept['department']); ?></td>
                        <td><?php echo $dept['invoice_count']; ?></td>
                        <td>Rs.<?php echo number_format($dept['total_invoiced'], 2); ?></td>
                        <td>Rs.<?php echo number_format($dept['total_paid'], 2); ?></td>
                        <td><?php echo number_format($rate, 1); ?>%</td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Monthly Comparison -->
        <div class="breakdown-section">
            <h3>Last 12 Months Comparison</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Total Invoiced</th>
                        <th>Total Received</th>
                        <th>Collection Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($comp = $comparison->fetch_assoc()): 
                        $rate = $comp['total_invoiced'] > 0 ? ($comp['total_received'] / $comp['total_invoiced']) * 100 : 0;
                    ?>
                    <tr>
                        <td><?php echo date('F Y', strtotime($comp['month'] . '-01')); ?></td>
                        <td>Rs.<?php echo number_format($comp['total_invoiced'], 2); ?></td>
                        <td>Rs.<?php echo number_format($comp['total_received'], 2); ?></td>
                        <td><?php echo number_format($rate, 1); ?>%</td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>