<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="financial_report_' . $year . '_' . $month . '.csv"');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, ['Financial Report for ' . date('F Y', mktime(0,0,0,$month,1,$year))]);
fputcsv($output, []);

// Summary
fputcsv($output, ['SUMMARY']);
$stmt = $db->prepare("SELECT 
    SUM(total) as total_invoiced,
    SUM(paid_amount) as total_received,
    SUM(remaining_amount) as remaining_balance,
    COUNT(id) as invoice_count
    FROM invoices
    WHERE MONTH(invoice_date) = ? AND YEAR(invoice_date) = ?");
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

fputcsv($output, ['Total Invoiced', 'Rs.' . number_format($summary['total_invoiced'] ?? 0, 2)]);
fputcsv($output, ['Total Received', 'Rs.' . number_format($summary['total_received'] ?? 0, 2)]);
fputcsv($output, ['Remaining Balance', 'Rs.' . number_format($summary['remaining_balance'] ?? 0, 2)]);
fputcsv($output, ['Total Invoices', $summary['invoice_count'] ?? 0]);
fputcsv($output, []);

// Client-wise breakdown
fputcsv($output, ['CLIENT-WISE BREAKDOWN']);
fputcsv($output, ['Client', 'Company', 'Invoices', 'Total Invoiced', 'Total Paid', 'Balance']);

$stmt = $db->prepare("SELECT 
    c.name, c.company,
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
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$clients = $stmt->get_result();

while($client = $clients->fetch_assoc()) {
    fputcsv($output, [
        $client['name'],
        $client['company'],
        $client['invoice_count'],
        'Rs.' . number_format($client['total_invoiced'] ?? 0, 2),
        'Rs.' . number_format($client['total_paid'] ?? 0, 2),
        'Rs.' . number_format($client['balance'] ?? 0, 2)
    ]);
}
fputcsv($output, []);

// Project-wise breakdown
fputcsv($output, ['PROJECT-WISE BREAKDOWN']);
fputcsv($output, ['Project', 'Department', 'Invoices', 'Total Invoiced', 'Total Paid', 'Balance']);

$stmt = $db->prepare("SELECT 
    p.project_name, p.department,
    COUNT(i.id) as invoice_count,
    SUM(i.total) as total_invoiced,
    SUM(i.paid_amount) as total_paid,
    SUM(i.remaining_amount) as balance
    FROM projects p
    LEFT JOIN invoices i ON p.id = i.project_id 
        AND MONTH(i.invoice_date) = ? AND YEAR(i.invoice_date) = ?
    GROUP BY p.id
    HAVING invoice_count > 0 OR total_invoiced > 0
    ORDER BY total_invoiced DESC");
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$projects = $stmt->get_result();

while($project = $projects->fetch_assoc()) {
    fputcsv($output, [
        $project['project_name'],
        $project['department'],
        $project['invoice_count'],
        'Rs.' . number_format($project['total_invoiced'] ?? 0, 2),
        'Rs.' . number_format($project['total_paid'] ?? 0, 2),
        'Rs.' . number_format($project['balance'] ?? 0, 2)
    ]);
}

fclose($output);
exit();