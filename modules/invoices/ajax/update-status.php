<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/functions.php';

header('Content-Type: application/json');

// Manual auth check — redirectIfNotLoggedIn() would send a Location header
// which breaks JSON responses
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit();
}

$id     = isset($_POST['id'])     ? (int)$_POST['id']     : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';

$allowed = ['Paid', 'Pending'];

if (!$id || !in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$stmt = $db->prepare("SELECT total, paid_amount FROM invoices WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
    exit();
}

$remaining = $status === 'Paid' ? 0.00 : max(0, $invoice['total'] - $invoice['paid_amount']);

$stmt = $db->prepare("UPDATE invoices SET payment_status = ?, remaining_amount = ? WHERE id = ?");
$stmt->bind_param('sdi', $status, $remaining, $id);

if ($stmt->execute()) {
    logActivity($_SESSION['admin_id'], 'UPDATE_INVOICE_STATUS', "Changed invoice #$id status to $status");
    echo json_encode(['success' => true, 'status' => $status]);
} else {
    echo json_encode(['success' => false, 'message' => $db->error]);
}
$stmt->close();