<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

// Handle deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $db->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        logActivity($_SESSION['admin_id'], 'DELETE_CLIENT', "Deleted client ID: $id");
        header("Location: index.php?msg=deleted");
        exit();
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? $_GET['search'] : '';
$where = "";
if ($search) {
    $where = "WHERE name LIKE '%$search%' OR company LIKE '%$search%' OR email LIKE '%$search%'";
}

// Get total records
$total_result = $db->query("SELECT COUNT(*) as total FROM clients $where");
$total = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get clients
$query = "SELECT * FROM clients $where ORDER BY created_at DESC LIMIT $offset, $limit";
$clients = $db->query($query);
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
            <a href="create.php" class="btn-primary">Add New Client</a>
        </div>
        
        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="Search clients..." value="<?php echo escape($search); ?>">
            <button type="submit">Search</button>
        </form>
        
        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success">Client deleted successfully!</div>
        <?php endif; ?>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Email</th>
                    <th>Phone</th>
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
                    <td><?php echo $summary['total_projects']; ?></td>
                    <td>Rs.<?php echo number_format($summary['pending_balance'], 2); ?></td>
                    <td>
                        <a href="edit.php?id=<?php echo $client['id']; ?>">Edit</a>
                        <a href="?delete=<?php echo $client['id']; ?>" onclick="return confirm('Are you sure?')">Delete</a>
                        <a href="http://localhost/invoice-management-system/modules/projects/?client=<?php echo $client['id']; ?>">View Projects</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>