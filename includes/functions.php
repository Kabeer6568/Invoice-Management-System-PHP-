<?php
function escape($string) {
    global $db;
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function generateInvoiceNumber(): string {
    global $db;

    $year   = date('Y');
    $prefix = "INV-$year-";
    $like   = $prefix . '%';

    // Find the highest existing number for this year
    $stmt = $db->prepare("
        SELECT invoice_number FROM invoices
        WHERE  invoice_number LIKE ?
        ORDER  BY invoice_number DESC
        LIMIT  1
    ");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = $last
        ? (int)substr($last['invoice_number'], strlen($prefix)) + 1
        : 1;

    // Loop until we find a number not already taken
    do {
        $candidate = $prefix . str_pad($next, 5, '0', STR_PAD_LEFT);
        $chk = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
        $chk->bind_param('s', $candidate);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($exists) $next++;
    } while ($exists);

    return $candidate;
}

function getClientSummary($client_id) {
    global $db;
    $summary = [
        'total_projects'  => 0,
        'total_invoices'  => 0,
        'total_paid'      => 0,
        'pending_balance' => 0
    ];

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE client_id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $summary['total_projects'] = $stmt->get_result()->fetch_assoc()['count'];

    $stmt = $db->prepare("
        SELECT COUNT(*)            as count,
               SUM(paid_amount)    as total_paid,
               SUM(remaining_amount) as pending
        FROM   invoices
        WHERE  client_id = ?
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $summary['total_invoices']  = $data['count'];
    $summary['total_paid']      = $data['total_paid'] ?? 0;
    $summary['pending_balance'] = $data['pending']    ?? 0;

    return $summary;
}

function updateInvoiceStatus($invoice_id) {
    global $db;

    $stmt = $db->prepare("SELECT total, paid_amount, due_date FROM invoices WHERE id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();

    $status = 'Pending';
    if ($invoice['paid_amount'] >= $invoice['total']) {
        $status = 'Paid';
    } elseif ($invoice['paid_amount'] > 0) {
        $status = 'Partial';
    } elseif (strtotime($invoice['due_date']) < time()) {
        $status = 'Overdue';
    }

    $remaining = $invoice['total'] - $invoice['paid_amount'];
    $stmt = $db->prepare("UPDATE invoices SET payment_status = ?, remaining_amount = ? WHERE id = ?");
    $stmt->bind_param("sdi", $status, $remaining, $invoice_id);
    $stmt->execute();
}


function generateViewToken() {
    return bin2hex(random_bytes(32));
}



?>