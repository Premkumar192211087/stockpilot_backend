<?php
/**
 * Clear All Notifications
 * Deletes all notifications for a user in a store
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$store_id = $_GET['store_id'] ?? null;
$user_id = $_GET['user_id'] ?? null;

if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    if ($user_id) {
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE store_id = ? AND (user_id IS NULL OR user_id = ?)
        ");
        // PDO bind
$stmt->execute([$store_id, $user_id]);} else {
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE store_id = ?
        ");
        // PDO bind
$stmt->execute([$store_id]);}
    
    $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    
    
    sendResponse(true, 'All notifications cleared', [
        'deleted_count' => $affected_rows
    ]);
    
} catch (Exception $e) {
    logError('Clear all notifications error: ' . $e->getMessage());
    sendResponse(false, 'Failed to clear notifications');
}


?>
