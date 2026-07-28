<?php
/**
 * Update Customer
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Invalid request method');
}

$customer_id = $_GET['customer_id'] ?? null;
if (!$customer_id) {
    sendResponse(false, 'Customer ID is required');
}

$conn = getDBConnection();
$data = getJSONInput();

try {
    $updates = [];
    $types = "";
    $values = [];
    
    $allowed_fields = ['customer_name', 'email', 'phone', 'address', 'status'];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
            $types .= 's';
        }
    }
    
    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }
    
    $sql = "UPDATE customers SET " . implode(', ', $updates) . " WHERE customer_id = ?";
    $types .= 'i';
    $values[] = $customer_id;
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    sendResponse(true, 'Customer updated successfully');
    
} catch (Exception $e) {
    logError('Update customer error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update customer');
}



?>
