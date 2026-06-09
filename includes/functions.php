<?php
function escape($string) {
    global $db;
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function generateInvoiceNumber() {
    global $db;
    $year = date('Y');
    $result = $db->query("SELECT COUNT(*) as count FROM invoices WHERE YEAR(created_at) = $year");
    $row = $result->fetch_assoc();
    $count = $row['count'] + 1;
    return "INV-" . $year . "-" . str_pad($count, 5, "0", STR_PAD_LEFT);
}

function getClientSummary($client_id) {
    global $db;
    $summary = [
        'total_projects' => 0,
        'total_invoices' => 0,
        'total_paid' => 0,
        'pending_balance' => 0
    ];
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE client_id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary['total_projects'] = $result->fetch_assoc()['count'];
    
    $stmt = $db->prepare("SELECT COUNT(*) as count, SUM(paid_amount) as total_paid, SUM(remaining_amount) as pending FROM invoices WHERE client_id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $summary['total_invoices'] = $data['count'];
    $summary['total_paid'] = $data['total_paid'] ?? 0;
    $summary['pending_balance'] = $data['pending'] ?? 0;
    
    return $summary;
}

function updateInvoiceStatus($invoice_id) {
    global $db;
    $stmt = $db->prepare("SELECT total, paid_amount, due_date FROM invoices WHERE id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    
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
?>