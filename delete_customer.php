<?php
/**
 * Delete Customer
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$customer_id = $_GET['customer_id'] ?? null;
if (!$customer_id) {
    sendResponse(false, 'Customer ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("DELETE FROM customers WHERE customer_id = ?");
    // PDO bind
$stmt->execute([$customer_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Customer deleted successfully');
    } else {
        sendResponse(false, 'Customer not found');
    }
    
} catch (Exception $e) {
    logError('Delete customer error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete customer');
}



?>
