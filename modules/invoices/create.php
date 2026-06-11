<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$error   = '';
$success = '';

$selected_client  = isset($_GET['client'])  ? (int)$_GET['client']  : 0;
$selected_project = isset($_GET['project']) ? (int)$_GET['project'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['create_invoice'])) {
        $client_id    = (int)$_POST['client_id'];
        $project_id   = (int)$_POST['project_id'];   // 0 = no project selected
        $invoice_date = $_POST['invoice_date'];
        $due_date     = $_POST['due_date'];
        $tax          = (float)$_POST['tax'];
        $discount     = (float)$_POST['discount'];
        $notes        = trim($_POST['notes']);

        // Build line items
        $itemDescs   = $_POST['item_desc']   ?? [];
        $itemAmounts = $_POST['item_amount'] ?? [];

        $cleanItems = [];
        foreach ($itemDescs as $i => $desc) {
            $desc   = trim($desc);
            $amount = (float)($itemAmounts[$i] ?? 0);
            if ($desc === '' || $amount <= 0) continue;
            $cleanItems[] = ['description' => $desc, 'amount' => $amount];
        }

        if ($client_id == 0) {
            $error = "Please select a client.";
        } elseif (empty($cleanItems)) {
            $error = "Add at least one line item with a description and amount.";
        } else {
            $subtotal        = array_sum(array_column($cleanItems, 'amount'));
            $total           = $subtotal + $tax - $discount;
            $remaining       = $total;
            $invoiceNumber   = generateInvoiceNumber();
            $paymentStatus   = 'Pending';
            $paidAmount      = 0.00;
            // Use NULL for project_id if none selected (combined invoice)
            $dbProjectId     = $project_id > 0 ? $project_id : null;

            $stmt = $db->prepare("
                INSERT INTO invoices
                    (invoice_number, client_id, project_id, invoice_date, due_date,
                     amount, tax, discount, total, paid_amount, remaining_amount,
                     payment_status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'siissddddddss',
                $invoiceNumber, $client_id, $dbProjectId,
                $invoice_date, $due_date,
                $subtotal, $tax, $discount, $total,
                $paidAmount, $remaining,
                $paymentStatus, $notes
            );

            if ($stmt->execute()) {
                $invoiceId = $db->insert_id;

                // Insert line items
                foreach ($cleanItems as $item) {
                    $li = $db->prepare("
                        INSERT INTO invoice_items (invoice_id, project_id, description, amount)
                        VALUES (?, ?, ?, ?)
                    ");
                    $liProjectId = $project_id > 0 ? $project_id : null;
                    $li->bind_param('iisd', $invoiceId, $liProjectId, $item['description'], $item['amount']);
                    $li->execute();
                    $li->close();
                }

                updateInvoiceStatus($invoiceId);
                logActivity($_SESSION['admin_id'], 'CREATE_INVOICE', "Created invoice: $invoiceNumber (ID: $invoiceId)");
                $success = "Invoice created successfully! Invoice Number: $invoiceNumber";

                $selected_client  = 0;
                $selected_project = 0;
                $_POST = [];
            } else {
                $error = "Error creating invoice: " . $db->error;
            }
            $stmt->close();
        }
    }
}

$clients = $db->query("SELECT id, name, company FROM clients ORDER BY name");

// Prefill amount if coming from a project link
$prefill_amount = 0.00;
if ($selected_project) {
    $s = $db->prepare("SELECT cost, monthly_fee, project_name, project_type FROM projects WHERE id = ?");
    $s->bind_param("i", $selected_project);
    $s->execute();
    $pd = $s->get_result()->fetch_assoc();
    if ($pd) {
        $prefill_amount = $pd['project_type'] === 'Monthly' ? $pd['monthly_fee'] : $pd['cost'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Invoice</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .items-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
        .items-table th { background:#f5f5f5; padding:10px 12px; text-align:left; font-size:13px; border-bottom:2px solid #ddd; }
        .items-table td { padding:8px 6px; vertical-align:middle; }
        .items-table td input[type="text"]   { width:100%; box-sizing:border-box; }
        .items-table td input[type="number"] { width:140px; }
        .btn-remove  { background:#e74c3c; color:#fff; border:none; border-radius:4px; padding:5px 10px; cursor:pointer; font-size:13px; }
        .btn-remove:hover  { background:#c0392b; }
        .btn-add-row { background:#27ae60; color:#fff; border:none; border-radius:4px; padding:8px 18px; cursor:pointer; font-size:14px; margin-bottom:20px; }
        .btn-add-row:hover { background:#219150; }
        #live-total { font-size:1.4em; font-weight:700; color:#2c3e50; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Create New Invoice</h1>
            <a href="index.php" class="btn-secondary">Back to Invoices</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>

        <form method="POST" class="form-container">
            <?php echo csrfField(); ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Client *</label>
                    <select name="client_id" id="client_id" required>
                        <option value="">Select Client</option>
                        <?php while ($client = $clients->fetch_assoc()): ?>
                        <option value="<?php echo $client['id']; ?>"
                            <?php echo ($selected_client == $client['id']) ? 'selected' : ''; ?>>
                            <?php echo escape($client['name']); ?> (<?php echo escape($client['company']); ?>)
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Project <small style="color:#888;">(optional)</small></label>
                    <select name="project_id" id="project_id" disabled>
                        <option value="">Select Client First</option>
                    </select>
                </div>
            </div>

            <hr style="margin: 20px 0;">

            <div class="form-row">
                <div class="form-group">
                    <label>Invoice Date *</label>
                    <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+10 days')); ?>" required>
                </div>
            </div>

            <!-- ── Line Items ── -->
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
                    <tr class="item-row">
                        <td>
                            <input type="text" name="item_desc[]"
                                   value="<?php echo $pd['project_name'] ?? 'Services'; ?>"
                                   placeholder="e.g. SEO Service, Domain Renewal" required>
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" name="item_amount[]"
                                   value="<?php echo number_format($prefill_amount, 2, '.', ''); ?>"
                                   class="item-amount" required>
                        </td>
                        <td>
                            <button type="button" class="btn-remove" onclick="removeRow(this)">✕ Remove</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <button type="button" class="btn-add-row" onclick="addRow()">+ Add Line Item</button>

            <!-- ── Tax / Discount / Total ── -->
            <div class="form-row" style="max-width:380px; margin-left:auto;">
                <div class="form-group">
                    <label>Tax</label>
                    <input type="number" step="0.01" name="tax" id="tax" value="0.00" oninput="recalc()">
                </div>
                <div class="form-group">
                    <label>Discount</label>
                    <input type="number" step="0.01" name="discount" id="discount" value="0.00" oninput="recalc()">
                </div>
            </div>

            <div style="text-align:right; margin-bottom:20px; padding:12px; background:#f9f9f9; border-radius:6px;">
                <div style="color:#666; margin-bottom:4px;">Subtotal: Rs. <span id="subtotal-display">0.00</span></div>
                <div>Total: Rs. <span id="live-total">0.00</span></div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3"><?php echo isset($_POST['notes']) ? escape($_POST['notes']) : ''; ?></textarea>
            </div>

            <button type="submit" name="create_invoice" class="btn-primary">Create Invoice</button>
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
            <input type="text" name="item_desc[]"
                   placeholder="e.g. Domain Renewal — example.com" required>
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

// Load projects when client changes
document.addEventListener('DOMContentLoaded', function () {
    const clientSelect  = document.getElementById('client_id');
    const projectSelect = document.getElementById('project_id');

    clientSelect.addEventListener('change', function () {
        const clientId = this.value;
        projectSelect.innerHTML = '<option value="">Loading...</option>';
        projectSelect.disabled  = true;

        if (!clientId) {
            projectSelect.innerHTML = '<option value="">Select Client First</option>';
            return;
        }

        fetch('ajax/get-client-projects.php?client_id=' + clientId)
            .then(r => r.json())
            .then(projects => {
                projectSelect.innerHTML = '<option value="">— No specific project —</option>';
                projects.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value          = p.id;
                    opt.textContent    = p.name;
                    opt.dataset.amount = p.amount;
                    opt.dataset.name   = p.name;
                    projectSelect.appendChild(opt);
                });
                projectSelect.disabled = false;
            })
            .catch(() => {
                projectSelect.innerHTML = '<option value="">Error loading projects</option>';
            });
    });

    // When project selected, prefill first line item
    projectSelect.addEventListener('change', function () {
        const selected = this.options[this.selectedIndex];
        const amount   = selected.dataset.amount || '';
        const name     = selected.dataset.name   || '';

        if (amount && name) {
            const firstDesc   = document.querySelector('input[name="item_desc[]"]');
            const firstAmount = document.querySelector('input[name="item_amount[]"]');
            if (firstDesc)   firstDesc.value   = name;
            if (firstAmount) firstAmount.value = parseFloat(amount).toFixed(2);
            recalc();
        }
    });

    document.querySelectorAll('.item-amount').forEach(el => el.addEventListener('input', recalc));
    recalc();
});
</script>
</body>
</html>