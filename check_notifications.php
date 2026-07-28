<?php
/**
 * Check/Get Notifications
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
$user_id = $_GET['user_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    if ($user_id) {
        $stmt = $conn->prepare("
            SELECT 
                id, 
                store_id, 
                title, 
                message, 
                type, 
                status, 
                created_at,
                created_at as timestamp,
                user_id,
                data,
                read_at
            FROM notifications
            WHERE store_id = ? AND (user_id IS NULL OR user_id = ?)
            ORDER BY created_at DESC
        ");
        // PDO bind
$stmt->execute([$store_id, $user_id]);} else {
        $stmt = $conn->prepare("
            SELECT 
                id, 
                store_id, 
                title, 
                message, 
                type, 
                status, 
                created_at,
                created_at as timestamp,
                user_id,
                data,
                read_at
            FROM notifications
            WHERE store_id = ?
            ORDER BY created_at DESC
        ");
        // PDO bind
$stmt->execute([$store_id]);}

    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    $notifications = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['data'])) {
            $decodedData = json_decode($row['data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row['data'] = $decodedData;
            }
        }
        $notifications[] = $row;
    }
    
    sendResponse(true, 'Notifications retrieved successfully', [
        'notifications' => $notifications
    ]);
    
} catch (Exception $e) {
    logError('Get notifications error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve notifications');
}

if (isset($stmt)) {
    
}

?>
