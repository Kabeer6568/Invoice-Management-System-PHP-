<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
redirectIfNotLoggedIn();

// Handle deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        logActivity($_SESSION['admin_id'], 'DELETE_PROJECT', "Deleted project ID: $id");
        header("Location: index.php?msg=deleted");
        exit();
    }
}

// Pagination
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 10;
$offset = ($page - 1) * $limit;

// Filters
$search     = isset($_GET['search'])     ? $_GET['search']     : '';
$status     = isset($_GET['status'])     ? $_GET['status']     : '';
$department = isset($_GET['department']) ? $_GET['department'] : '';
$type       = isset($_GET['type'])       ? $_GET['type']       : '';
$client_id  = isset($_GET['client'])     ? (int)$_GET['client'] : 0;

$where  = [];
$params = [];
$types  = "";

if ($search) {
    $where[]  = "(p.project_name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= "ss";
}

if ($status) {
    $where[]  = "p.status = ?";
    $params[] = $status;
    $types   .= "s";
}

if ($department) {
    $where[]  = "p.department = ?";
    $params[] = $department;
    $types   .= "s";
}

if ($type) {
    $where[]  = "p.project_type = ?";
    $params[] = $type;
    $types   .= "s";
}

if ($client_id) {
    $where[]  = "p.client_id = ?";
    $params[] = $client_id;
    $types   .= "i";
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// Total count
$count_sql = "SELECT COUNT(*) as total FROM projects p $where_clause";
$stmt = $db->prepare($count_sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get projects — subquery gets the latest invoice payment_status per client
// Uses client_id because auto-invoices have project_id = NULL
$sql = "SELECT p.*,
               c.name AS client_name,
               (
                   SELECT i.payment_status
                   FROM   invoices i
                   WHERE  i.client_id = p.client_id
                   ORDER  BY i.invoice_date DESC
                   LIMIT  1
               ) AS payment_status
        FROM   projects p
        JOIN   clients  c ON p.client_id = c.id
        $where_clause
        ORDER  BY p.created_at DESC
        LIMIT  $offset, $limit";

$stmt = $db->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$projects = $stmt->get_result();

// Clients for filter dropdown
$clients = $db->query("SELECT id, name FROM clients ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Projects</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Manage Projects</h1>
            <a href="create.php" class="btn-primary">Add New Project</a>
        </div>

        <form method="GET" class="filter-form">
            <div class="filter-row">
                <input type="text" name="search" placeholder="Search projects..."
                       value="<?php echo escape($search); ?>">

                <select name="status">
                    <option value="">All Status</option>
                    <option value="In Progress" <?php echo $status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="Hold"        <?php echo $status == 'Hold'        ? 'selected' : ''; ?>>Hold</option>
                    <option value="Completed"   <?php echo $status == 'Completed'   ? 'selected' : ''; ?>>Completed</option>
                </select>

                <select name="department">
                    <option value="">All Departments</option>
                    <option value="Web Dev"      <?php echo $department == 'Web Dev'      ? 'selected' : ''; ?>>Web Dev</option>
                    <option value="SEO"          <?php echo $department == 'SEO'          ? 'selected' : ''; ?>>SEO</option>
                    <option value="Marketing"    <?php echo $department == 'Marketing'    ? 'selected' : ''; ?>>Marketing</option>
                    <option value="Design"       <?php echo $department == 'Design'       ? 'selected' : ''; ?>>Design</option>
                    <option value="Social Media" <?php echo $department == 'Social Media' ? 'selected' : ''; ?>>Social Media</option>
                    <option value="Other"        <?php echo $department == 'Other'        ? 'selected' : ''; ?>>Other</option>
                </select>

                <select name="type">
                    <option value="">All Types</option>
                    <option value="One-time" <?php echo $type == 'One-time' ? 'selected' : ''; ?>>One-time</option>
                    <option value="Monthly"  <?php echo $type == 'Monthly'  ? 'selected' : ''; ?>>Monthly</option>
                </select>

                <button type="submit">Filter</button>
                <a href="index.php" class="btn-secondary">Reset</a>
            </div>
        </form>

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success">Project deleted successfully!</div>
        <?php endif; ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Project Name</th>
                    <th>Client</th>
                    <th>Department</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Cost</th>
                    <th>Payment Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($project = $projects->fetch_assoc()):
                    $payStatus = $project['payment_status'] ?? 'Pending';
                ?>
                <tr>
                    <td><?php echo escape($project['project_name']); ?></td>
                    <td><?php echo escape($project['client_name']); ?></td>
                    <td><?php echo escape($project['department']); ?></td>
                    <td><?php echo escape($project['project_type']); ?></td>
                    <td>
                        <span class="status-<?php echo strtolower(str_replace(' ', '-', $project['status'])); ?>">
                            <?php echo escape($project['status']); ?>
                        </span>
                    </td>
                    <td>Rs.<?php echo number_format($project['cost'] > 0 ? $project['cost'] : $project['monthly_fee'], 2); ?></td>
                    <td>
                        <span class="status-<?php echo strtolower($payStatus); ?>">
                            <?php echo escape($payStatus); ?>
                        </span>
                    </td>
                    <td>
                        <a href="view.php?id=<?php echo $project['id']; ?>">View</a>
                        <a href="edit.php?id=<?php echo $project['id']; ?>">Edit</a>
                        <a href="?delete=<?php echo $project['id']; ?>"
                           onclick="return confirm('Are you sure? This will also delete associated invoices!')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&department=<?php echo urlencode($department); ?>&type=<?php echo urlencode($type); ?>"
               class="<?php echo $i == $page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>
</body>
</html>