<?php
/**
 * createClientInvoice()
 *
 * Single project  → invoice with project_id set, one line item
 * Multiple projects → combined invoice with project_id = NULL, one line item per project
 * Both cases append any unpaid balance from previous invoices as an extra line item.
 *
 * Place at: includes/createClientInvoice.php
 */
function createClientInvoice(mysqli $db, int $client_id, array $project_ids = []): array
{
    // ── 1. Fetch the projects to invoice ──────────────────────────────────────
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

    // ── 2. Build line items ───────────────────────────────────────────────────
    $lineItems     = [];
    $projectsTotal = 0.00;

    foreach ($projects as $project) {
        $amount = $project['project_type'] === 'Monthly'
            ? (float)$project['monthly_fee']
            : (float)$project['cost'];

        if ($amount <= 0) continue;

        $label = $project['project_type'] === 'Monthly'
            ? $project['project_name'] . ' (Monthly Fee — ' . date('F Y') . ')'
            : $project['project_name'] . ' (One-time)';

        $lineItems[]    = ['project_id' => $project['id'], 'description' => $label, 'amount' => $amount];
        $projectsTotal += $amount;
    }

    if (empty($lineItems)) {
        return ['success' => false, 'message' => 'All projects have zero amount, invoice not created.'];
    }

    // ── 3. Unpaid balance from previous invoices ──────────────────────────────
    $balStmt = $db->prepare("
        SELECT COALESCE(SUM(remaining_amount), 0) AS unpaid
        FROM   invoices
        WHERE  client_id       = ?
          AND  payment_status != 'Paid'
          AND  remaining_amount > 0
    ");
    $balStmt->bind_param('i', $client_id);
    $balStmt->execute();
    $unpaidBalance = (float)$balStmt->get_result()->fetch_assoc()['unpaid'];
    $balStmt->close();

    if ($unpaidBalance > 0) {
        $lineItems[] = [
            'project_id'  => null,
            'description' => 'Carried-over unpaid balance from previous invoices',
            'amount'      => $unpaidBalance,
        ];
    }

    $grandTotal = $projectsTotal + $unpaidBalance;

    // ── 4. Decide: single project invoice OR combined ─────────────────────────
    $isSingleProject = count($projects) === 1;
    // For a single project invoice, project_id is set on the invoice itself.
    // For combined, project_id is NULL (requires the column to allow NULL —
    // run: ALTER TABLE invoices MODIFY COLUMN project_id INT DEFAULT NULL)
    $invoiceProjectId = $isSingleProject ? $projects[0]['id'] : null;

    // ── 5. Build notes ────────────────────────────────────────────────────────
    $notesLines = ['Invoice Breakdown:'];
    foreach ($lineItems as $item) {
        $notesLines[] = '  • ' . $item['description'] . ' — Rs ' . number_format($item['amount'], 2);
    }
    $notesLines[] = '─────────────────────────────';
    $notesLines[] = '  Total: Rs ' . number_format($grandTotal, 2);
    if ($unpaidBalance > 0) {
        $notesLines[] = '  (Includes Rs ' . number_format($unpaidBalance, 2) . ' unpaid balance)';
    }
    $notes = implode("\n", $notesLines);

    // ── 6. Insert invoice ─────────────────────────────────────────────────────
    $invoiceNumber   = generateInvoiceNumber();
    $invoiceDate     = date('Y-m-d');
    $dueDate         = date('Y-m-d', strtotime('+30 days'));
    $tax             = 0.00;
    $discount        = 0.00;
    $paidAmount      = 0.00;
    $remainingAmount = $grandTotal;
    $paymentStatus   = 'Pending';

    $invStmt = $db->prepare("
        INSERT INTO invoices
            (invoice_number, client_id, project_id, invoice_date, due_date,
             amount, tax, discount, total, paid_amount, remaining_amount,
             payment_status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$invStmt) {
        return ['success' => false, 'message' => 'Prepare failed: ' . $db->error];
    }

    // project_id is int or NULL — bind as integer (NULL binds fine with 'i')
    $invStmt->bind_param(
        'siissddddddss',
        $invoiceNumber, $client_id, $invoiceProjectId,
        $invoiceDate, $dueDate,
        $projectsTotal, $tax, $discount, $grandTotal,
        $paidAmount, $remainingAmount,
        $paymentStatus, $notes
    );

    if (!$invStmt->execute()) {
        return ['success' => false, 'message' => 'DB error: ' . $invStmt->error];
    }

    $invoiceId = $db->insert_id;
    $invStmt->close();

    // ── 7. Insert line items ──────────────────────────────────────────────────
    foreach ($lineItems as $item) {
        $liStmt = $db->prepare("
            INSERT INTO invoice_items (invoice_id, project_id, description, amount)
            VALUES (?, ?, ?, ?)
        ");
        if ($liStmt) {
            $liStmt->bind_param('iisd', $invoiceId, $item['project_id'], $item['description'], $item['amount']);
            $liStmt->execute();
            $liStmt->close();
        }
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