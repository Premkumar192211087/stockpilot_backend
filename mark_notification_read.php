<?php
/**
 * Mark Notification as Read
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['notification_id', 'store_id']);

try {
    // Check if notification exists
    $checkStmt = $conn->prepare("
        SELECT status FROM notifications 
        WHERE id = ? AND store_id = ?
    ");
    // PDO bind
$checkStmt->execute([$data['notification_id'], $data['store_id']]);$checkStmt->execute();
    // PDO result ready in $checkStmt
    $result = $checkStmt;
    
    if ($result->num_rows === 0) {
        sendResponse(false, 'Notification not found');
    }
    
    $notification = $result->fetch(PDO::FETCH_ASSOC);
    
    
    // Update status to read
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET status = 'read', read_at = NOW() 
        WHERE id = ? AND store_id = ?
    ");
    
    // PDO bind
$stmt->execute([$data['notification_id'], $data['store_id']]);$stmt->execute();
    
    if ($notification['status'] === 'read') {
        sendResponse(true, 'Notification was already marked as read');
    } else {
        sendResponse(true, 'Notification marked as read successfully');
    }
    
} catch (Exception $e) {
    logError('Mark notification read error: ' . $e->getMessage());
    sendResponse(false, 'Failed to mark notification as read');
}



?>
