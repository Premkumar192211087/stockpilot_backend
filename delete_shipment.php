<?php
/**
 * Delete Shipment
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$shipment_id = $_GET['shipment_id'] ?? null;
if (!$shipment_id) {
    sendResponse(false, 'Shipment ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("DELETE FROM shipments WHERE shipment_id = ?");
    // PDO bind
$stmt->execute([$shipment_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Shipment deleted successfully');
    } else {
        sendResponse(false, 'Shipment not found');
    }
    
} catch (Exception $e) {
    logError('Delete shipment error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete shipment');
}



?>
