<?php
/**
 * Toggle Customer Status
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['customer_id', 'status']);

// Validate status value
if (!in_array($data['status'], ['active', 'inactive'])) {
    sendResponse(false, 'Invalid status. Must be "active" or "inactive"');
}

try {
    $stmt = $conn->prepare("
        UPDATE customers 
        SET status = ? 
        WHERE customer_id = ?
    ");
    
    // PDO bind
$stmt->execute([$data['status'], $data['customer_id']]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Customer status updated successfully', [
            'customer_id' => $data['customer_id'],
            'status' => $data['status']
        ]);
    } else {
        sendResponse(false, 'Customer not found or status unchanged');
    }
    
} catch (Exception $e) {
    logError('Toggle customer status error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update customer status');
}



?>
