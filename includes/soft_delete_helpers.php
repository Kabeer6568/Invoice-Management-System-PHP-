<?php
/**
 * SOFT DELETE HELPER FUNCTIONS
 * One place to rule all soft delete operations
 */

/**
 * Get all active records (not deleted)
 */
function getActive($table, $additional_where = '', $order_by = 'created_at DESC') {
    global $db;
    $sql = "SELECT * FROM $table WHERE deleted_at IS NULL";
    if ($additional_where) $sql .= " AND $additional_where";
    $sql .= " ORDER BY $order_by";
    return $db->query($sql);
}

/**
 * Get single active record by ID
 */
function getActiveById($table, $id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Soft delete a record (move to trash)
 */
function softDelete($table, $id) {
    global $db;
    $stmt = $db->prepare("UPDATE $table SET deleted_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}

/**
 * Restore a record from trash
 */
function restoreFromTrash($table, $id) {
    global $db;
    $stmt = $db->prepare("UPDATE $table SET deleted_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}

/**
 * Permanently delete a record
 */
function permanentDelete($table, $id) {
    global $db;
    $stmt = $db->prepare("DELETE FROM $table WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}

/**
 * Get count of active records
 */
function countActive($table, $additional_where = '') {
    global $db;
    $sql = "SELECT COUNT(*) as total FROM $table WHERE deleted_at IS NULL";
    if ($additional_where) $sql .= " AND $additional_where";
    $result = $db->query($sql);
    return $result->fetch_assoc()['total'];
}

/**
 * Get trashed records (deleted)
 */
function getTrashed($table, $limit = null, $offset = null) {
    global $db;
    $sql = "SELECT * FROM $table WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC";
    if ($limit) {
        $sql .= " LIMIT $limit";
        if ($offset) $sql .= " OFFSET $offset";
    }
    return $db->query($sql);
}

/**
 * Get count of trashed records
 */
function countTrashed($table) {
    global $db;
    $result = $db->query("SELECT COUNT(*) as total FROM $table WHERE deleted_at IS NOT NULL");
    return $result->fetch_assoc()['total'];
}

/**
 * Auto cleanup old trash (run via cron job)
 * Payments are included — they have a deleted_at column after the migration.
 *
 * @param int $days - Days to keep in trash (default 90)
 * @return int Number of records permanently deleted
 */
function autoCleanupTrash($days = 90) {
    global $db;
    $cutoff_date   = date('Y-m-d H:i:s', strtotime("-$days days"));
    $total_deleted = 0;

    // payments added here after migration_payments_soft_delete.sql is run
    $tables = ['clients', 'projects', 'invoices', 'payments'];

    foreach ($tables as $table) {
        $stmt = $db->prepare("DELETE FROM $table WHERE deleted_at IS NOT NULL AND deleted_at <= ?");
        $stmt->bind_param("s", $cutoff_date);
        $stmt->execute();
        $total_deleted += $stmt->affected_rows;
    }

    return $total_deleted;
}