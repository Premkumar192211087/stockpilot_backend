<?php
/**
 * Delete Sale
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$sale_id = $_GET['sale_id'] ?? null;
if (!$sale_id) {
    sendResponse(false, 'Sale ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("DELETE FROM sales WHERE sale_id = ?");
    // PDO bind
$stmt->execute([$sale_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Sale deleted successfully');
    } else {
        sendResponse(false, 'Sale not found');
    }
    
} catch (Exception $e) {
    logError('Delete sale error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete sale');
}



?>
