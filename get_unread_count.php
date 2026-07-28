<?php
/**
 * Get Unread Notification Count
 * Returns the count of unread notifications for a store
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$user_id = $_GET['user_id'] ?? null; // Optional: filter by user

$conn = getDBConnection();

try {
    if ($user_id) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as unread_count 
            FROM notifications 
            WHERE store_id = ? AND (user_id IS NULL OR user_id = ?) AND status = 'unread'
        ");
        // PDO bind
$stmt->execute([$store_id, $user_id]);} else {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as unread_count 
            FROM notifications 
            WHERE store_id = ? AND status = 'unread'
        ");
        // PDO bind
$stmt->execute([$store_id]);}
    
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $row = $result->fetch(PDO::FETCH_ASSOC);
    
    
    sendResponse(true, 'Unread count retrieved', [
        'unread_count' => (int)$row['unread_count']
    ]);
    
} catch (Exception $e) {
    logError('Get unread count error: ' . $e->getMessage());
    sendResponse(false, 'Failed to get unread count');
}


?>