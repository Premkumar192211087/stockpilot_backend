<?php
/**
 * Update Shipment - WITH STATUS NOTIFICATIONS
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Invalid request method');
}

$shipment_id = $_GET['shipment_id'] ?? null;
if (!$shipment_id) {
    sendResponse(false, 'Shipment ID is required');
}

$conn = getDBConnection();
$data = getJSONInput();

try {
    // Get current shipment for comparison
    $currentStmt = $conn->prepare("SELECT shipment_number, tracking_number, status, store_id FROM shipments WHERE shipment_id = ?");
    // PDO bind
$currentStmt->execute([$shipment_id]);$currentStmt->execute();
    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
    
    
    $conn->beginTransaction();
    
    $updates = [];
    $types = "";
    $values = [];
    
    $allowed_fields = ['shipment_number', 'order_type', 'order_id', 'carrier', 
                       'tracking_number', 
                       'ship_date', 'estimated_delivery', 'actual_delivery',
                       'status', 'shipping_address', 'notes'];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
            $types .= is_numeric($data[$field]) && strpos($data[$field], '.') !== false ? 'd' : 's';
        }
    }
    
    if (!empty($updates)) {
        $sql = "UPDATE shipments SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE shipment_id = ?";
        $types .= 'i';
        $values[] = $shipment_id;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
    }
    
    // Send notification if status changed
    if (isset($data['status']) && $data['status'] !== $current['status']) {
        $tracking = $data['tracking_number'] ?? $current['tracking_number'];
        notifyShipmentStatusUpdate(
            $conn,
            $current['store_id'],
            $data['user_id'] ?? 1,
            $shipment_id,
            $tracking,
            $data['status']
        );
    }
    
    $conn->commit();
    sendResponse(true, 'Shipment updated successfully');
    
} catch (Exception $e) {
    $conn->rollBack();
    logError('Update shipment error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update shipment');
}


?>
