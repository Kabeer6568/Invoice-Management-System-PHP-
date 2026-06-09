<?php
/**
 * createClientInvoice()
 * 
 * Creates one combined invoice for a client covering:
 *   - All their active monthly/one-time project fees
 *   - Any unpaid balance carried over from previous invoices
 * 
 * Add this function to your includes/functions.php
 * 
 * @param  mysqli  $db         Your DB connection
 * @param  int     $client_id  The client to invoice
 * @param  array   $project_ids  Specific project IDs to include (empty = all active)
 * @return array   ['success' => bool, 'invoice_id' => int, 'invoice_number' => string, 'message' => string]
 */
function createClientInvoice(mysqli $db, int $client_id, array $project_ids = []): array
{
    // ── 1. Fetch the projects to include ─────────────────────────────────────
    if (!empty($project_ids)) {
        $placeholders = implode(',', array_fill(0, count($project_ids), '?'));
        $types        = str_repeat('i', count($project_ids));
        $stmt = $db->prepare("
            SELECT id, project_name, project_type, cost, monthly_fee
            FROM   projects
            WHERE  client_id = ?
              AND  id IN ($placeholders)
              AND  status NOT IN ('Completed', 'Hold')
        ");
        $stmt->bind_param('i' . $types, $client_id, ...$project_ids);
    } else {
        $stmt = $db->prepare("
            SELECT id, project_name, project_type, cost, monthly_fee
            FROM   projects
            WHERE  client_id = ?
              AND  status NOT IN ('Completed', 'Hold')
        ");
        $stmt->bind_param('i', $client_id);
    }

    $stmt->execute();
    $projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($projects)) {
        return ['success' => false, 'message' => 'No active projects found for this client.'];
    }

    // ── 2. Build line items from projects ─────────────────────────────────────
    $lineItems   = [];
    $projectsTotal = 0.00;

    foreach ($projects as $project) {
        $amount = $project['project_type'] === 'Monthly'
            ? (float)$project['monthly_fee']
            : (float)$project['cost'];

        if ($amount <= 0) continue;

        $label = $project['project_type'] === 'Monthly'
            ? $project['project_name'] . ' (Monthly Fee — ' . date('F Y') . ')'
            : $project['project_name'] . ' (One-time)';

        $lineItems[] = [
            'project_id'  => $project['id'],
            'description' => $label,
            'amount'      => $amount,
        ];

        $projectsTotal += $amount;
    }

    if (empty($lineItems)) {
        return ['success' => false, 'message' => 'All projects have zero amount, invoice not created.'];
    }

    // ── 3. Calculate unpaid balance from previous invoices ────────────────────
    $balStmt = $db->prepare("
        SELECT COALESCE(SUM(remaining_amount), 0) AS unpaid
        FROM   invoices
        WHERE  client_id = ?
          AND  status    != 'Paid'
          AND  remaining_amount > 0
    ");
    $balStmt->bind_param('i', $client_id);
    $balStmt->execute();
    $unpaidBalance = (float)$balStmt->get_result()->fetch_assoc()['unpaid'];
    $balStmt->close();

    // Add carried-over balance as a line item if any
    if ($unpaidBalance > 0) {
        $lineItems[] = [
            'project_id'  => null,
            'description' => 'Carried-over unpaid balance from previous invoices',
            'amount'      => $unpaidBalance,
        ];
    }

    // ── 4. Build notes breakdown ──────────────────────────────────────────────
    $notesLines = ["Invoice Breakdown:"];
    foreach ($lineItems as $item) {
        $notesLines[] = "  • " . $item['description'] . " — Rs " . number_format($item['amount'], 2);
    }
    $grandTotal = $projectsTotal + $unpaidBalance;
    $notesLines[] = "─────────────────────────────";
    $notesLines[] = "  Total: Rs " . number_format($grandTotal, 2);
    if ($unpaidBalance > 0) {
        $notesLines[] = "  (Includes Rs " . number_format($unpaidBalance, 2) . " unpaid balance)";
    }
    $notes = implode("\n", $notesLines);

    // ── 5. Insert the invoice ─────────────────────────────────────────────────
    $invoiceNumber   = generateInvoiceNumber();
    $invoiceDate     = date('Y-m-d');
    $dueDate         = date('Y-m-d', strtotime('+30 days'));
    $tax             = 0.00;
    $discount        = 0.00;
    $remainingAmount = $grandTotal;

    // project_id = NULL since this invoice spans multiple projects
    $invStmt = $db->prepare("
        INSERT INTO invoices
            (invoice_number, client_id, project_id, invoice_date, due_date,
             amount, tax, discount, total, paid_amount, remaining_amount, notes)
        VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?)
    ");
    $invStmt->bind_param(
        'sisssdddds',
        $invoiceNumber, $client_id, $invoiceDate, $dueDate,
        $projectsTotal, $tax, $discount, $grandTotal, $remainingAmount, $notes
    );

    if (!$invStmt->execute()) {
        return ['success' => false, 'message' => 'DB error creating invoice: ' . $db->error];
    }

    $invoiceId = $db->insert_id;
    $invStmt->close();

    // ── 6. Insert line items ──────────────────────────────────────────────────
    foreach ($lineItems as $item) {
        $liStmt = $db->prepare("
            INSERT INTO invoice_items (invoice_id, project_id, description, amount)
            VALUES (?, ?, ?, ?)
        ");
        $liStmt->bind_param('iisd', $invoiceId, $item['project_id'], $item['description'], $item['amount']);
        $liStmt->execute();
        $liStmt->close();
    }

    updateInvoiceStatus($invoiceId);

    return [
        'success'        => true,
        'invoice_id'     => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'message'        => "Invoice $invoiceNumber created — Rs " . number_format($grandTotal, 2)
                          . ($unpaidBalance > 0 ? " (includes Rs " . number_format($unpaidBalance, 2) . " unpaid balance)" : ""),
    ];
}