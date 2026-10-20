<?php
require_once __DIR__ . '/../config/app.php';

require_once __DIR__ . '/cron_monthly_invoices.php';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h3>Demo Company</h3>
        </div>
        <nav>
            <a href="<?php echo app_url('dashboard.php'); ?>">Dashboard</a>
            <a href="<?php echo app_url('modules/clients/'); ?>">Clients</a>
            <a href="<?php echo app_url('modules/projects/'); ?>">Projects</a>
            <a href="<?php echo app_url('modules/invoices/'); ?>">Invoices</a>
            <a href="<?php echo app_url('modules/payments/'); ?>">Payments</a>
            <a href="<?php echo app_url('modules/reports/monthly.php'); ?>">Reports</a>
            <a href="<?php echo app_url('modules/trash/index.php'); ?>">Trash</a>
            <a href="<?php echo app_url('logout.php'); ?>">Logout</a>
        </nav>
    </div>
    <div class="main-content">


    


     