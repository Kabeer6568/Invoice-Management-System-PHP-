<?php
/**
 * Pseudo-Cron — runs silently on every page load via header.php
 * 
 * Job 1: Every day — mark overdue invoices
 * Job 2: 5th of month — generate monthly invoices per client
 * 
 * Add to includes/header.php:
 *     require_once __DIR__ . '/cron_monthly_invoices.php';
 */

if (!isset($db)) return;

// ── JOB 1: Mark overdue invoices — runs daily ─────────────────────────────────
// Uses a lightweight date-based lock so it only fires once per day
$today     = date('Y-m-d');
$todayLock = 'overdue_check_' . $today;   // unique key per day

$db->query("
    CREATE TABLE IF NOT EXISTS cron_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        job_name  VARCHAR(100) NOT NULL,
        run_month VARCHAR(7)   NOT NULL,
        ran_at    DATETIME     NOT NULL,
        result    TEXT,
        UNIQUE KEY unique_job_month (job_name, run_month)
    )
");

// Check if overdue job already ran today
$ovChk = $db->prepare("SELECT id FROM cron_log WHERE job_name = ? AND run_month = ?");
$ovChk->bind_param('ss', $todayLock, $today);
$ovChk->execute();
$overdueRan = $ovChk->get_result()->fetch_assoc();
$ovChk->close();

if (!$overdueRan) {
    // Lock it
    $ovLock = $db->prepare("INSERT IGNORE INTO cron_log (job_name, run_month, ran_at, result) VALUES (?, ?, NOW(), 'running')");
    $ovLock->bind_param('ss', $todayLock, $today);
    $ovLock->execute();

    if ($db->affected_rows > 0) {
        // Update all pending invoices whose due date has passed
        $ovUpdate = $db->query("
            UPDATE invoices
            SET    payment_status = 'Overdue'
            WHERE  payment_status = 'Pending'
              AND  due_date < CURDATE()
              AND  paid_amount = 0
        ");

        $affected = $db->affected_rows;

        $ovDone = $db->prepare("UPDATE cron_log SET result = ?, ran_at = NOW() WHERE job_name = ? AND run_month = ?");
        $result = "Marked $affected invoices as Overdue";
        $ovDone->bind_param('sss', $result, $todayLock, $today);
        $ovDone->execute();
        $ovDone->close();
    }
    $ovLock->close();
}

// ── JOB 2: Monthly invoice generation — runs on the 5th ──────────────────────
if ((int)date('j') !== 5) return;

$currentMonth = date('Y-m');

$chk = $db->prepare("SELECT id FROM cron_log WHERE job_name = 'monthly_invoices' AND run_month = ?");
$chk->bind_param('s', $currentMonth);
$chk->execute();
$alreadyRan = $chk->get_result()->fetch_assoc();
$chk->close();

if ($alreadyRan) return;

$lock = $db->prepare("INSERT IGNORE INTO cron_log (job_name, run_month, ran_at, result) VALUES ('monthly_invoices', ?, NOW(), 'running')");
$lock->bind_param('s', $currentMonth);
$lock->execute();
if ($db->affected_rows === 0) return;
$lock->close();

if (!function_exists('createClientInvoice')) {
    require_once __DIR__ . '/createClientInvoice.php';
}

// Get all distinct clients with active monthly projects
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

$created = 0; $skipped = 0; $errors = 0; $log = [];

foreach ($clients as $row) {
    $clientId = (int)$row['client_id'];

    // Skip if invoice already exists for this client this month
    $dupChk = $db->prepare("
        SELECT id FROM invoices
        WHERE  client_id = ?
          AND  DATE_FORMAT(invoice_date, '%Y-%m') = ?
        LIMIT 1
    ");
    $dupChk->bind_param('is', $clientId, $currentMonth);
    $dupChk->execute();
    $duplicate = $dupChk->get_result()->fetch_assoc();
    $dupChk->close();

    if ($duplicate) {
        $log[] = "SKIP: Client #$clientId — invoice already exists for $currentMonth.";
        $skipped++;
        continue;
    }

    $result = createClientInvoice($db, $clientId);

    if ($result['success']) {
        $log[] = "OK: Client #$clientId — " . $result['message'];
        $created++;
    } else {
        $log[] = "ERR: Client #$clientId — " . $result['message'];
        $errors++;
    }
}

$summary = "Created: $created | Skipped: $skipped | Errors: $errors\n" . implode("\n", $log);
$upd = $db->prepare("UPDATE cron_log SET result = ?, ran_at = NOW() WHERE job_name = 'monthly_invoices' AND run_month = ?");
$upd->bind_param('ss', $summary, $currentMonth);
$upd->execute();
$upd->close();