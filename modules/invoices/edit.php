<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

ini_set('display_errors', 1);
error_reporting(E_ALL);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header("Location: index.php"); exit(); }

$stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
if (!$invoice) { header("Location: index.php"); exit(); }

if ($invoice['payment_status'] == 'Paid') {
    header("Location: view.php?id=$id&error=paid"); exit();
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);

    $client_id    = (int)$_POST['client_id'];
    $invoice_date = $_POST['invoice_date'];
    $due_date     = $_POST['due_date'];
    $tax          = (float)$_POST['tax'];
    $discount     = (float)$_POST['discount'];
    $notes        = trim($_POST['notes']);

    $itemDescs   = $_POST['item_desc']   ?? [];
    $itemAmounts = $_POST['item_amount'] ?? [];
    $itemIds     = $_POST['item_id']     ?? [];

    $cleanItems = [];
    foreach ($itemDescs as $i => $desc) {
        $desc   = trim($desc);
        $amount = (float)($itemAmounts[$i] ?? 0);
        if ($desc === '' || $amount <= 0) continue;
        $cleanItems[] = [
            'id'          => (int)($itemIds[$i] ?? 0),
            'description' => $desc,
            'amount'      => $amount,
        ];
    }

    if (empty($cleanItems)) {
        $error = "Add at least one line item with a description and amount.";
    } else {
        $subtotal  = array_sum(array_column($cleanItems, 'amount'));
        $total     = $subtotal + $tax - $discount;
        $remaining = max(0, $total - $invoice['paid_amount']);

        $status = $invoice['payment_status'];
        if ($invoice['paid_amount'] >= $total)  { $status = 'Paid';    $remaining = 0; }
        elseif ($invoice['paid_amount'] > 0)    { $status = 'Partial'; }

        $upd = $db->prepare("
            UPDATE invoices
            SET client_id=?, invoice_date=?, due_date=?,
                amount=?, tax=?, discount=?, total=?,
                remaining_amount=?, payment_status=?, notes=?
            WHERE id=?
        ");
        $upd->bind_param("issdddddssi",
            $client_id, $invoice_date, $due_date,
            $subtotal, $tax, $discount, $total,
            $remaining, $status, $notes, $id
        );

        if ($upd->execute()) {
            // Delete removed items
            $keepIds = array_filter(array_column($cleanItems, 'id'));
            if (!empty($keepIds)) {
                $ph  = implode(',', array_fill(0, count($keepIds), '?'));
                $del = $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ? AND id NOT IN ($ph)");
                $del->bind_param(str_repeat('i', count($keepIds) + 1), $id, ...$keepIds);
            } else {
                $del = $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
                $del->bind_param("i", $id);
            }
            $del->execute();
            $del->close();

            // Upsert items
            foreach ($cleanItems as $item) {
                if ($item['id'] > 0) {
                    $s = $db->prepare("UPDATE invoice_items SET description=?, amount=? WHERE id=? AND invoice_id=?");
                    $s->bind_param("sdii", $item['description'], $item['amount'], $item['id'], $id);
                } else {
                    $s = $db->prepare("INSERT INTO invoice_items (invoice_id, project_id, description, amount) VALUES (?, NULL, ?, ?)");
                    $s->bind_param("isd", $id, $item['description'], $item['amount']);
                }
                $s->execute();
                $s->close();
            }

            logActivity($_SESSION['admin_id'], 'UPDATE_INVOICE', "Updated invoice ID: $id");
            $success = "Invoice updated successfully!";

            $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $invoice = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Error updating invoice: " . $db->error;
        }
        $upd->close();
    }
}

// Load line items (seed from invoice amount if none exist yet)
$liStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
$liStmt->bind_param("i", $id);
$liStmt->execute();
$lineItems = $liStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$liStmt->close();

if (empty($lineItems)) {
    $lineItems = [['id' => 0, 'description' => 'Services', 'amount' => $invoice['amount']]];
}

$clients = $db->query("SELECT id, name, company FROM clients ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Invoice <?php echo escape($invoice['invoice_number']); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Edit Invoice: <?php echo escape($invoice['invoice_number']); ?></h1>
            <a href="view.php?id=<?php echo $id; ?>" class="btn-secondary">View Invoice</a>
            <a href="index.php" class="btn-secondary">Back</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>

        <div class="alert alert-info">
            <strong>Paid so far:</strong> Rs.<?php echo number_format($invoice['paid_amount'], 2); ?> — record new payments via the Payments module.
        </div>

        <form method="POST" class="form-container">
            <?php echo csrfField(); ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Client *</label>
                    <select name="client_id" required>
                        <?php while ($c = $clients->fetch_assoc()): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($invoice['client_id'] == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo escape($c['name']); ?> (<?php echo escape($c['company']); ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Invoice Date *</label>
                    <input type="date" name="invoice_date" value="<?php echo $invoice['invoice_date']; ?>" required>
                </div>
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="date" name="due_date" value="<?php echo $invoice['due_date']; ?>" required>
                </div>
            </div>

            <h3 style="margin-bottom:10px;">Line Items</h3>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:62%">Description</th>
                        <th style="width:25%">Amount (Rs)</th>
                        <th style="width:13%"></th>
                    </tr>
                </thead>
                <tbody id="items-body">
                <?php foreach ($lineItems as $item): ?>
                    <tr class="item-row">
                        <td>
                            <input type="hidden" name="item_id[]" value="<?php echo $item['id']; ?>">
                            <input type="text" name="item_desc[]"
                                   value="<?php echo escape($item['description']); ?>"
                                   placeholder="e.g. Domain Renewal — example.com" required>
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" name="item_amount[]"
                                   value="<?php echo number_format($item['amount'], 2, '.', ''); ?>"
                                   class="item-amount" required>
                        </td>
                        <td>
                            <button type="button" class="btn-remove" onclick="removeRow(this)">✕ Remove</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <button type="button" class="btn-add-row" onclick="addRow()">+ Add Line Item</button>

            <div class="form-row" style="max-width:380px; margin-left:auto;">
                <div class="form-group">
                    <label>Tax</label>
                    <input type="number" step="0.01" name="tax" id="tax"
                           value="<?php echo $invoice['tax']; ?>" oninput="recalc()">
                </div>
                <div class="form-group">
                    <label>Discount</label>
                    <input type="number" step="0.01" name="discount" id="discount"
                           value="<?php echo $invoice['discount']; ?>" oninput="recalc()">
                </div>
            </div>

            <div style="text-align:right; margin-bottom:20px; padding:12px; background:#f9f9f9; border-radius:6px;">
                <div style="color:#666; margin-bottom:4px;">Subtotal: Rs. <span id="subtotal-display">0.00</span></div>
                <div>Total: Rs. <span id="live-total">0.00</span></div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="4"><?php echo escape($invoice['notes']); ?></textarea>
            </div>

            <button type="submit" class="btn-primary">Save Invoice</button>
        </form>
    </div>

<script>
function recalc() {
    let subtotal = 0;
    document.querySelectorAll('.item-amount').forEach(el => {
        subtotal += parseFloat(el.value) || 0;
    });
    const tax      = parseFloat(document.getElementById('tax').value)      || 0;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    document.getElementById('subtotal-display').textContent = subtotal.toFixed(2);
    document.getElementById('live-total').textContent       = (subtotal + tax - discount).toFixed(2);
}

function addRow() {
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.innerHTML = `
        <td>
            <input type="hidden" name="item_id[]" value="0">
            <input type="text" name="item_desc[]" placeholder="e.g. Domain Renewal — example.com" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="item_amount[]"
                   value="0.00" class="item-amount" required oninput="recalc()">
        </td>
        <td>
            <button type="button" class="btn-remove" onclick="removeRow(this)">✕ Remove</button>
        </td>`;
    document.getElementById('items-body').appendChild(row);
    row.querySelector('input[type="text"]').focus();
    recalc();
}

function removeRow(btn) {
    if (document.querySelectorAll('.item-row').length <= 1) {
        alert('An invoice must have at least one line item.');
        return;
    }
    btn.closest('tr').remove();
    recalc();
}

document.querySelectorAll('.item-amount').forEach(el => el.addEventListener('input', recalc));
recalc();
</script>

<!-- <script type="text/javascript" src="../../assets/js/main.js"></script> -->
</body>
</html>