<?php
/**
 * Delete Notification
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$notification_id = $_GET['notification_id'] ?? null;
$store_id = $_GET['store_id'] ?? null;

if (!$notification_id || !$store_id) {
    sendResponse(false, 'Notification ID and Store ID are required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("
        DELETE FROM notifications 
        WHERE id = ? AND store_id = ?
    ");
    
    // PDO bind
$stmt->execute([$notification_id, $store_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Notification deleted successfully');
    } else {
        sendResponse(false, 'Notification not found');
    }
    
} catch (Exception $e) {
    logError('Delete notification error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete notification');
}



?>
