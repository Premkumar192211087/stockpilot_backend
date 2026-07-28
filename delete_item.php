<?php
/**
 * Delete Product/Item
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$item_id = $_GET['item_id'] ?? null;
if (!$item_id) {
    sendResponse(false, 'Item ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    // PDO bind
$stmt->execute([$item_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Product deleted successfully');
    } else {
        sendResponse(false, 'Product not found');
    }
    
} catch (Exception $e) {
    logError('Delete product error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete product');
}



?>
