<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

$error = '';
$success = '';

// Get selected client from URL first
$selected_client = isset($_GET['client']) ? (int)$_GET['client'] : 0;
$selected_project = isset($_GET['project']) ? (int)$_GET['project'] : 0;
$auto_create = isset($_GET['auto']) && $_GET['auto'] == 1;

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token']);
    
    // Check if this is the "Load Projects" button (has priority)
    // if (isset($_POST['load_projects'])) {
    //     $selected_client = (int)$_POST['client_id'];
    //     header("Location: create.php?client=" . $selected_client);
    //     exit();
    // }
    
    // Otherwise, it's the invoice creation
    if (isset($_POST['create_invoice'])) {
        $client_id = (int)$_POST['client_id'];
        $project_id = (int)$_POST['project_id'];
        $invoice_date = $_POST['invoice_date'];
        $due_date = $_POST['due_date'];
        $amount = (float)$_POST['amount'];
        $tax = (float)$_POST['tax'];
        $discount = (float)$_POST['discount'];
        $total = $amount + $tax - $discount;
        $notes = trim($_POST['notes']);
        
        $invoice_number = generateInvoiceNumber();
        
        if ($client_id == 0 || $project_id == 0) {
            $error = "Please select client and project!";
        } else {
            $stmt = $db->prepare("INSERT INTO invoices (invoice_number, client_id, project_id, invoice_date, due_date, amount, tax, discount, total, paid_amount, remaining_amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
            $remaining = $total;
            $stmt->bind_param("siissddddds", $invoice_number, $client_id, $project_id, $invoice_date, $due_date, $amount, $tax, $discount, $total, $remaining, $notes);
            
            if ($stmt->execute()) {
                $invoice_id = $db->insert_id;
                logActivity($_SESSION['admin_id'], 'CREATE_INVOICE', "Created invoice: $invoice_number (ID: $invoice_id)");
                updateInvoiceStatus($invoice_id);
                
                if ($auto_create) {
                    header("Location: view.php?id=$invoice_id");
                    exit();
                }
                
                $success = "Invoice created successfully! Invoice Number: $invoice_number";
                
                // Reset selections after successful creation
                $selected_client = 0;
                $selected_project = 0;
                $_POST = array();
            } else {
                $error = "Error creating invoice: " . $db->error;
            }
            $stmt->close();
        }
    }
}

// Get all clients for dropdown
$clients = $db->query("SELECT id, name, company FROM clients ORDER BY name");

// Get project details if selected
$project_details = null;
if ($selected_project) {
    $stmt = $db->prepare("SELECT cost, monthly_fee FROM projects WHERE id = ?");
    $stmt->bind_param("i", $selected_project);
    $stmt->execute();
    $project_details = $stmt->get_result()->fetch_assoc();
}

// Get projects for selected client
$projects_for_client = [];
if ($selected_client) {
    $stmt = $db->prepare("SELECT p.id, p.project_name, p.cost, p.monthly_fee FROM projects p WHERE p.client_id = ? AND p.status != 'Completed'");
    $stmt->bind_param("i", $selected_client);
    $stmt->execute();
    $projects_result = $stmt->get_result();
    while($project = $projects_result->fetch_assoc()) {
        $projects_for_client[] = $project;
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
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Create New Invoice</h1>
            <a href="index.php" class="btn-secondary">Back to Invoices</a>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" class="form-container">
            <?php echo csrfField(); ?>
            
            <div class="form-row">
                

                <div class="form-group">
    <label>Client *</label>
    <select name="client_id" id="client_id" required>
        <option value="">Select Client</option>
        <?php
        $clients = $db->query("SELECT id, name, company FROM clients ORDER BY name");
        while($client = $clients->fetch_assoc()):
        ?>
        <option value="<?= $client['id'] ?>">
            <?= escape($client['name']) ?> (<?= escape($client['company']) ?>)
        </option>
        <?php endwhile; ?>
    </select>
</div>
                
                

                <div class="form-group">
    <label>Project *</label>
    <select name="project_id" id="project_id" required disabled>
        <option value="">Select Client First</option>
    </select>
</div>
            </div>
            
            <!-- Load Projects Button
            <div class="form-group">
                <button type="submit" name="load_projects" class="btn-secondary">Load Projects for Selected Client</button>
                <small style="display: block; margin-top: 5px; color: #666;">
                    Tip: Select a client first, then click "Load Projects" to see available projects
                </small>
            </div> -->
            
            
            <hr style="margin: 20px 0;">
            
            <div class="form-row">
                <div class="form-group">
                    <label>Invoice Date *</label>
                    <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Amount *</label>
                    <input type="number" step="0.01" name="amount" id="amount" value="<?php echo $project_details ? ($project_details['cost'] ?: $project_details['monthly_fee']) : '0.00'; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Tax</label>
                    <input type="number" step="0.01" name="tax" id="tax" value="0.00">
                </div>
                
                <div class="form-group">
                    <label>Discount</label>
                    <input type="number" step="0.01" name="discount" id="discount" value="0.00">
                </div>
            </div>
            
            <div class="form-group">
                <label>Total Amount</label>
                <div id="total_display" class="total-display">Rs 0.00</div>
                <input type="hidden" name="total" id="total_hidden">
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3"><?php echo isset($_POST['notes']) ? escape($_POST['notes']) : ''; ?></textarea>
            </div>
            
            <button type="submit" name="create_invoice" class="btn-primary">Create Invoice</button>
            
        </form>
    </div>

<script>

function calculateTotal() {

    let amount = parseFloat(document.getElementById('amount')?.value) || 0;
    let tax = parseFloat(document.getElementById('tax')?.value) || 0;
    let discount = parseFloat(document.getElementById('discount')?.value) || 0;

    let total = amount + tax - discount;

    let totalDisplay = document.getElementById('total_display');
    let totalHidden = document.getElementById('total_hidden');

    if (totalDisplay) {
        totalDisplay.innerHTML = 'Rs. ' + total.toFixed(2);
    }

    if (totalHidden) {
        totalHidden.value = total;
    }
}

document.addEventListener('DOMContentLoaded', function() {

    const clientSelect = document.getElementById('client_id');
    const projectSelect = document.getElementById('project_id');

    clientSelect.addEventListener('change', function() {

        const clientId = this.value;

        projectSelect.innerHTML =
            '<option value="">Loading Projects...</option>';

        projectSelect.disabled = true;

        if (!clientId) {

            projectSelect.innerHTML =
                '<option value="">Select Client First</option>';

            return;
        }

        fetch('ajax/get-client-projects.php?client_id=' + clientId)

        .then(response => response.json())

        .then(projects => {

            projectSelect.innerHTML =
                '<option value="">Select Project</option>';

            projects.forEach(project => {

                let option = document.createElement('option');

                option.value = project.id;
                option.textContent =
                    project.name.toLocaleString();

                    // project.name + ' - Rs. ' +
                    // Number(project.amount).toLocaleString();

                option.dataset.amount = project.amount;

                projectSelect.appendChild(option);
            });

            projectSelect.disabled = false;
        })

        .catch(error => {

            console.error(error);

            projectSelect.innerHTML =
                '<option value="">Error Loading Projects</option>';
        });
    });

    projectSelect.addEventListener('change', function() {

        let selected =
            this.options[this.selectedIndex];

        let amount =
            selected.dataset.amount || 0;

        let amountField =
            document.getElementById('amount');

        if (amountField) {
            amountField.value = amount;
        }

        calculateTotal();
    });

    document
        .getElementById('amount')
        ?.addEventListener('input', calculateTotal);

    document
        .getElementById('tax')
        ?.addEventListener('input', calculateTotal);

    document
        .getElementById('discount')
        ?.addEventListener('input', calculateTotal);

    calculateTotal();
});

</script>
</body>
</html>