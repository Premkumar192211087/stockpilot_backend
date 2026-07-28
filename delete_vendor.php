<?php
/**
 * Delete Vendor
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$vendor_id = $_GET['vendor_id'] ?? null;
if (!$vendor_id) {
    sendResponse(false, 'Vendor ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
    // PDO bind
$stmt->execute([$vendor_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Vendor deleted successfully');
    } else {
        sendResponse(false, 'Vendor not found');
    }
    
} catch (Exception $e) {
    logError('Delete vendor error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete vendor');
}



?>
