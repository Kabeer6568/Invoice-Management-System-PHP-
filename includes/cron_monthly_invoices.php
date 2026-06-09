<?php
/**
 * Monthly Invoice Auto-Generator — Pseudo-Cron
 * 
 * HOW IT WORKS:
 *   Add this one line anywhere in your includes/header.php:
 *       require_once __DIR__ . '/cron_monthly_invoices.php';
 * 
 *   Every page load checks: "Is it the 5th? Have I already run this month?"
 *   If yes + not yet run → generates invoices silently in the background.
 *   Works on localhost AND shared hosting with zero configuration.
 * 
 * Place this file at: includes/cron_monthly_invoices.php
 */

// ── Only proceed if DB is already available (loaded via header.php) ──────────
if (!isset($db)) return;

// ── Only run on the 5th of the month ─────────────────────────────────────────
if ((int)date('j') !== 5) return;

$currentMonth = date('Y-m');

// ── Check if already ran this month (using a simple lock in DB) ───────────────
// We store a record in a small cron_log table. Create it if it doesn't exist.
$db->query("
    CREATE TABLE IF NOT EXISTS cron_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        job_name    VARCHAR(100) NOT NULL,
        run_month   VARCHAR(7)   NOT NULL,
        ran_at      DATETIME     NOT NULL,
        result      TEXT,
        UNIQUE KEY unique_job_month (job_name, run_month)
    )
");

// Check if this month's run is already done
$check = $db->prepare("SELECT id FROM cron_log WHERE job_name = 'monthly_invoices' AND run_month = ?");
$check->bind_param("s", $currentMonth);
$check->execute();
$alreadyRan = $check->get_result()->fetch_assoc();
$check->close();

if ($alreadyRan) return;   // Already done this month, nothing to do

// ── Lock immediately (prevents duplicate runs on concurrent page loads) ───────
$lock = $db->prepare("INSERT IGNORE INTO cron_log (job_name, run_month, ran_at, result) VALUES ('monthly_invoices', ?, NOW(), 'running')");
$lock->bind_param("s", $currentMonth);
$lock->execute();

// If another request beat us to the insert, affected rows = 0 → bail out
if ($db->affected_rows === 0) return;
$lock->close();

// ── Fetch all active monthly projects ────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        p.id          AS project_id,
        p.project_name,
        p.monthly_fee,
        p.client_id
    FROM projects p
    WHERE p.project_type = 'Monthly'
      AND p.status       NOT IN ('Completed', 'Hold')
      AND p.monthly_fee  > 0
");
$stmt->execute();
$projects = $stmt->get_result();

$invoiceDate = date('Y-m-d');                              // 5th of this month
$dueDate     = date('Y-m-d', strtotime('+30 days'));       // 30 days later
$monthLabel  = date('F Y');                                // e.g. "June 2025"

$created = 0;
$skipped = 0;
$errors  = 0;
$log     = [];

while ($project = $projects->fetch_assoc()) {
    $projectId   = $project['project_id'];
    $projectName = $project['project_name'];
    $clientId    = $project['client_id'];
    $amount      = (float)$project['monthly_fee'];

    // Skip if invoice already exists for this project this month
    // (safety net in case cron_log was cleared manually)
    $dupCheck = $db->prepare("
        SELECT id FROM invoices
        WHERE project_id = ?
          AND DATE_FORMAT(invoice_date, '%Y-%m') = ?
        LIMIT 1
    ");
    $dupCheck->bind_param("is", $projectId, $currentMonth);
    $dupCheck->execute();
    $duplicate = $dupCheck->get_result()->fetch_assoc();
    $dupCheck->close();

    if ($duplicate) {
        $log[] = "SKIP: Project #$projectId \"$projectName\" — invoice already exists.";
        $skipped++;
        continue;
    }

    // Create the invoice
    $invoiceNumber   = generateInvoiceNumber();
    $tax             = 0.00;
    $discount        = 0.00;
    $total           = $amount;
    $remainingAmount = $total;
    $notes           = "Auto-generated monthly invoice for $projectName — $monthLabel.";

    $insert = $db->prepare("
        INSERT INTO invoices
            (invoice_number, client_id, project_id, invoice_date, due_date,
             amount, tax, discount, total, paid_amount, remaining_amount, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
    ");
    $insert->bind_param(
        "siissddddds",
        $invoiceNumber, $clientId, $projectId, $invoiceDate, $dueDate,
        $amount, $tax, $discount, $total, $remainingAmount, $notes
    );

    if ($insert->execute()) {
        $invoiceId = $db->insert_id;
        updateInvoiceStatus($invoiceId);
        $log[] = "OK: Project #$projectId \"$projectName\" → Invoice $invoiceNumber created (Rs $total).";
        $created++;
    } else {
        $log[] = "ERR: Project #$projectId \"$projectName\" → " . $db->error;
        $errors++;
    }

    $insert->close();
}

$stmt->close();

// ── Update the cron_log with the final result ─────────────────────────────────
$summary = "Created: $created | Skipped: $skipped | Errors: $errors\n" . implode("\n", $log);
$update  = $db->prepare("UPDATE cron_log SET result = ?, ran_at = NOW() WHERE job_name = 'monthly_invoices' AND run_month = ?");
$update->bind_param("ss", $summary, $currentMonth);
$update->execute();
$update->close();