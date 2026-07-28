<?php
/**
 * Mark All Notifications as Read
 * Marks all notifications for a store as read
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id']);

$store_id = $data['store_id'];
$user_id = $data['user_id'] ?? null; // If provided, mark only for specific user and store-wide notifications

try {
    if ($user_id) {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET status = 'read', read_at = NOW() 
            WHERE store_id = ? AND (user_id IS NULL OR user_id = ?) AND status = 'unread'
        ");
        // PDO bind
$stmt->execute([$store_id, $user_id]);} else {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET status = 'read', read_at = NOW() 
            WHERE store_id = ? AND status = 'unread'
        ");
        // PDO bind
$stmt->execute([$store_id]);}
    
    $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    
    
    sendResponse(true, 'Notifications marked as read', [
        'count' => $affected_rows
    ]);
    
} catch (Exception $e) {
    logError('Mark all notifications read error: ' . $e->getMessage());
    sendResponse(false, 'Failed to mark notifications as read');
}


?>