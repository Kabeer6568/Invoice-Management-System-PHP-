<?php
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/soft_delete_helpers.php'; // ADD THIS
redirectIfNotLoggedIn();

// Handle soft delete (move to trash) - UPDATED
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    if (softDelete('projects', $id)) {
        logActivity($_SESSION['admin_id'], 'SOFT_DELETE_PROJECT', "Moved project ID: $id to trash");
        header("Location: index.php?msg=moved_to_trash");
        exit();
    }
}

// Pagination
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 20;
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

// ADD THIS: Always exclude deleted projects
$where[] = "p.deleted_at IS NULL";

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
$sql = "SELECT p.*,
               c.name AS client_name,
               (
                   SELECT i.payment_status
                   FROM   invoices i
                   WHERE  i.client_id = p.client_id
                   ORDER  BY i.id DESC
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

// Get trash count for display
$trash_count = countTrashed('projects');
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
            <div>
                <a href="create.php" class="btn-primary">Add New Project</a>
                <?php if($trash_count > 0): ?>
                <a href="../trash/?filter=projects" class="btn-secondary" style="background: #6c757d;">
                    Trash (<?php echo $trash_count; ?>)
                </a>
                <?php endif; ?>
            </div>
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
            <div class="alert alert-success">
                <?php 
                if($_GET['msg'] == 'moved_to_trash') echo "Project moved to trash! You can restore it from the Trash page.";
                if($_GET['msg'] == 'deleted') echo "Project deleted successfully!";
                ?>
            </div>
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
                    <!-- <th>Payment Status</th> -->
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
                    <!-- <td>
                        <span class="status-<?php //echo strtolower($payStatus); ?>"> -->
                            <?php //echo escape($payStatus); ?>
                        <!-- </span>
                    </td> -->
                    <td>
                        <div class="action-buttons">
                            <a href="view.php?id=<?php echo $project['id']; ?>"  class="action-btn view">
                                <svg  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                View
                            </a>
                            <a href="edit.php?id=<?php echo $project['id']; ?>"  class="action-btn edit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </a>
                            <a href="?delete=<?php echo $project['id']; ?>"
                            onclick="return confirm('Move this project to trash? You can restore it later.')" class="action-btn delete">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                Delete
                            </a>
                        </div>
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