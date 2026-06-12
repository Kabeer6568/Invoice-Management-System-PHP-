<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/soft_delete_helpers.php'; // You have this
redirectIfNotLoggedIn();

// Handle soft delete (move to trash) - CHANGE THIS SECTION
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // CHANGE: Use softDelete() instead of hard DELETE
    if (softDelete('clients', $id)) {
        logActivity($_SESSION['admin_id'], 'SOFT_DELETE_CLIENT', "Moved client ID: $id to trash");
        header("Location: index.php?msg=moved_to_trash");
        exit();
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search
$search = $_GET['search'] ?? '';
$client_type = $_GET['client_type'] ?? '';

$where = [];
$params = [];
$types = "";

// ADD THIS: Always exclude deleted clients
$where[] = "deleted_at IS NULL";

// search
if ($search) {
    $where[] = "(name LIKE ? OR company LIKE ? OR email LIKE ? OR client_type LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= "ssss";
}

// filter
if ($client_type) {
    $where[] = "client_type = ?";
    $params[] = $client_type;
    $types .= "s";
}

// build WHERE SQL
$where_sql = "";
if ($where) {
    $where_sql = " WHERE " . implode(" AND ", $where);
}

$count_sql = "SELECT COUNT(*) as total FROM clients $where_sql";
$stmt = $db->prepare($count_sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$total_result = $stmt->get_result();
$total = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

$data_sql = "SELECT * FROM clients $where_sql ORDER BY id DESC LIMIT ?, ?";

$stmt = $db->prepare($data_sql);

// add pagination params
$params2 = $params;
$types2 = $types . "ii";
$params2[] = $offset;
$params2[] = $limit;

$stmt->bind_param($types2, ...$params2);
$stmt->execute();

$clients = $stmt->get_result();

// Get trash count using your helper function
$trash_count = countTrashed('clients');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Clients</title>
    <link rel="stylesheet" href="http://localhost/invoice-management-system/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Manage Clients</h1>
            <div>
                <a href="create.php" class="btn-primary">Add New Client</a>
                <?php if($trash_count > 0): ?>
                <a href="../trash/?type=clients" class="btn-secondary" style="background: #6c757d;">
                    Trash (<?php echo $trash_count; ?>)
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <form method="GET" class="filter-form">
            <input type="text" name="search" placeholder="Search clients..." value="<?php echo escape($search); ?>">

            <select name="client_type">
                <option value="">Client Type</option>
                <option value="Prepaid" <?php echo $client_type == 'Prepaid' ? 'selected' : ''; ?>>Prepaid</option>
                <option value="Postpaid" <?php echo $client_type == 'Postpaid' ? 'selected' : ''; ?>>Postpaid</option>
            </select>
            
            <button type="submit">Search</button>
        </form>
        
        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success">
                <?php 
                if($_GET['msg'] == 'moved_to_trash') echo "Client moved to trash! You can restore it from the Trash page.";
                if($_GET['msg'] == 'deleted') echo "Client deleted successfully!";
                ?>
            </div>
        <?php endif; ?>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Client Type</th>
                    <th>Projects</th>
                    <th>Balance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($client = $clients->fetch_assoc()): 
                    $summary = getClientSummary($client['id']);
                ?>
                <tr>
                    <td><?php echo escape($client['name']); ?></td>
                    <td><?php echo escape($client['company']); ?></td>
                    <td><?php echo escape($client['email']); ?></td>
                    <td><?php echo escape($client['phone']); ?></td>
                    <td><?php echo escape($client['client_type']); ?></td>
                    <td><?php echo $summary['total_projects']; ?></td>
                    <td>Rs.<?php echo number_format($summary['pending_balance'], 2); ?></td>
                    <td>
                        <a href="edit.php?id=<?php echo $client['id']; ?>">Edit</a>
                        <a href="?delete=<?php echo $client['id']; ?>" onclick="return confirm('Move this client to trash? You can restore it later.')">Delete</a>
                        <a href="http://localhost/invoice-management-system/modules/projects/?client=<?php echo $client['id']; ?>">View Projects</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&client_type=<?php echo urlencode($client_type); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>