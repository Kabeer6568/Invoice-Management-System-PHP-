<?php
/**
 * Monthly Invoice Auto-Generator — Pseudo-Cron
 * 
 * Add this one line to your includes/header.php:
 *     require_once __DIR__ . '/cron_monthly_invoices.php';
 * 
 * Runs silently on the 5th of each month.
 * Creates one combined invoice per client covering all their active
 * monthly projects + any unpaid balance from previous invoices.
 * 
 * Place at: includes/cron_monthly_invoices.php
 */

if (!isset($db)) return;

// Only run on the 5th
if ((int)date('j') !== 5) return;

$currentMonth = date('Y-m');

// Create cron_log table if it doesn't exist
$db->query("
    CREATE TABLE IF NOT EXISTS cron_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        job_name   VARCHAR(100) NOT NULL,
        run_month  VARCHAR(7)   NOT NULL,
        ran_at     DATETIME     NOT NULL,
        result     TEXT,
        UNIQUE KEY unique_job_month (job_name, run_month)
    )
");

// Check if already ran this month
$check = $db->prepare("SELECT id FROM cron_log WHERE job_name = 'monthly_invoices' AND run_month = ?");
$check->bind_param('s', $currentMonth);
$check->execute();
$alreadyRan = $check->get_result()->fetch_assoc();
$check->close();

if ($alreadyRan) return;

// Lock immediately to prevent concurrent runs
$lock = $db->prepare("INSERT IGNORE INTO cron_log (job_name, run_month, ran_at, result) VALUES ('monthly_invoices', ?, NOW(), 'running')");
$lock->bind_param('s', $currentMonth);
$lock->execute();

if ($db->affected_rows === 0) return;
$lock->close();

// Load the helper if not already loaded
if (!function_exists('createClientInvoice')) {
    require_once __DIR__ . '/createClientInvoice.php';
}

// ── Get all distinct clients who have active monthly projects ─────────────────
$stmt = $db->prepare("
    SELECT DISTINCT client_id
    FROM   projects
    WHERE  project_type = 'Monthly'
      AND  status       NOT IN ('Completed', 'Hold')
      AND  monthly_fee  > 0
");
$stmt->execute();
$clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$created = 0;
$skipped = 0;
$errors  = 0;
$log     = [];

foreach ($clients as $row) {
    $clientId = (int)$row['client_id'];

    // Check if a combined invoice already exists for this client this month
    $dupCheck = $db->prepare("
        SELECT id FROM invoices
        WHERE  client_id = ?
          AND  DATE_FORMAT(invoice_date, '%Y-%m') = ?
        LIMIT 1
    ");
    $dupCheck->bind_param('is', $clientId, $currentMonth);
    $dupCheck->execute();
    $duplicate = $dupCheck->get_result()->fetch_assoc();
    $dupCheck->close();

    if ($duplicate) {
        $log[] = "SKIP: Client #$clientId — invoice already exists for $currentMonth.";
        $skipped++;
        continue;
    }

    // Create combined invoice for all their active monthly projects
    $result = createClientInvoice($db, $clientId);

    if ($result['success']) {
        $log[] = "OK: Client #$clientId — " . $result['message'];
        $created++;
    } else {
        $log[] = "ERR: Client #$clientId — " . $result['message'];
        $errors++;
    }
}

// Save result to cron_log
$summary = "Created: $created | Skipped: $skipped | Errors: $errors\n" . implode("\n", $log);
$update  = $db->prepare("UPDATE cron_log SET result = ?, ran_at = NOW() WHERE job_name = 'monthly_invoices' AND run_month = ?");
$update->bind_param('ss', $summary, $currentMonth);
$update->execute();
$update->close();