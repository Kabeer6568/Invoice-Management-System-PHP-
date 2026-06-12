<?php
/**
 * SOFT DELETE HELPER FUNCTIONS
 * One place to rule all soft delete operations
 */

/**
 * Get all active records (not deleted)
 * This automatically adds WHERE deleted_at IS NULL to your query
 * 
 * @param string $table - Table name (clients, projects, invoices)
 * @param string $additional_where - Extra conditions like "name LIKE '%john%'"
 * @param string $order_by - Order by clause
 * @return mysqli_result
 */
function getActive($table, $additional_where = '', $order_by = 'created_at DESC') {
    global $db;
    
    $sql = "SELECT * FROM $table WHERE deleted_at IS NULL";
    
    if ($additional_where) {
        $sql .= " AND $additional_where";
    }
    
    $sql .= " ORDER BY $order_by";
    
    return $db->query($sql);
}

/**
 * Get single active record by ID
 * 
 * @param string $table - Table name
 * @param int $id - Record ID
 * @return array|null
 */
function getActiveById($table, $id) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Soft delete a record (move to trash)
 * 
 * @param string $table - Table name
 * @param int $id - Record ID
 * @return bool
 */
function softDelete($table, $id) {
    global $db;
    
    $stmt = $db->prepare("UPDATE $table SET deleted_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    return $stmt->execute();
}

/**
 * Restore a record from trash
 * 
 * @param string $table - Table name
 * @param int $id - Record ID
 * @return bool
 */
function restoreFromTrash($table, $id) {
    global $db;
    
    $stmt = $db->prepare("UPDATE $table SET deleted_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    return $stmt->execute();
}

/**
 * Permanently delete a record
 * 
 * @param string $table - Table name
 * @param int $id - Record ID
 * @return bool
 */
function permanentDelete($table, $id) {
    global $db;
    
    $stmt = $db->prepare("DELETE FROM $table WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->bind_param("i", $id);
    
    return $stmt->execute();
}

/**
 * Get count of active records
 * 
 * @param string $table - Table name
 * @param string $additional_where - Extra conditions
 * @return int
 */
function countActive($table, $additional_where = '') {
    global $db;
    
    $sql = "SELECT COUNT(*) as total FROM $table WHERE deleted_at IS NULL";
    
    if ($additional_where) {
        $sql .= " AND $additional_where";
    }
    
    $result = $db->query($sql);
    $row = $result->fetch_assoc();
    
    return $row['total'];
}

/**
 * Get trashed records (deleted)
 * 
 * @param string $table - Table name
 * @param int $limit - Limit results
 * @param int $offset - Offset for pagination
 * @return mysqli_result
 */
function getTrashed($table, $limit = null, $offset = null) {
    global $db;
    
    $sql = "SELECT * FROM $table WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC";
    
    if ($limit) {
        $sql .= " LIMIT $limit";
        if ($offset) {
            $sql .= " OFFSET $offset";
        }
    }
    
    return $db->query($sql);
}

/**
 * Get count of trashed records
 * 
 * @param string $table - Table name
 * @return int
 */
function countTrashed($table) {
    global $db;
    
    $result = $db->query("SELECT COUNT(*) as total FROM $table WHERE deleted_at IS NOT NULL");
    $row = $result->fetch_assoc();
    
    return $row['total'];
}

/**
 * Auto cleanup old trash (run via cron job)
 * 
 * @param int $days - Days to keep in trash (default 90)
 * @return int Number of records deleted
 */
function autoCleanupTrash($days = 90) {
    global $db;
    
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));
    $total_deleted = 0;
    
    $tables = ['clients', 'projects', 'invoices'];
    
    foreach ($tables as $table) {
        $stmt = $db->prepare("DELETE FROM $table WHERE deleted_at IS NOT NULL AND deleted_at <= ?");
        $stmt->bind_param("s", $cutoff_date);
        $stmt->execute();
        $total_deleted += $stmt->affected_rows;
    }
    
    return $total_deleted;
}
?>