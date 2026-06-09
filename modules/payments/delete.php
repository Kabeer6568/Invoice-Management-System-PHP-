<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: index.php");
    exit();
}

// Get payment info
$stmt = $db->prepare("SELECT invoice_id, amount FROM payments WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();

if ($payment) {
    // Delete payment
    $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        // Update invoice paid amount
        $stmt = $db->prepare("UPDATE invoices SET paid_amount = paid_amount - ? WHERE id = ?");
        $stmt->bind_param("di", $payment['amount'], $payment['invoice_id']);
        $stmt->execute();
        
        // Update invoice status
        updateInvoiceStatus($payment['invoice_id']);
        
        logActivity($_SESSION['admin_id'], 'DELETE_PAYMENT', "Deleted payment ID: $id");
        header("Location: index.php?msg=deleted");
        exit();
    }
}

header("Location: index.php");
exit();