<?php
/**
 * Update Purchase Order
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Invalid request method');
}

$po_id = $_GET['po_id'] ?? null;
if (!$po_id) {
    sendResponse(false, 'Purchase order ID is required');
}

$conn = getDBConnection();
$data = getJSONInput();

try {
    // Get current PO data for comparison
    $currentStmt = $conn->prepare("SELECT po_number, status, store_id FROM purchase_orders WHERE po_id = ?");
    // PDO bind
$currentStmt->execute([$po_id]);$currentStmt->execute();
    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
    
    
    $updates = [];
    $types = "";
    $values = [];
    
    $allowed_fields = ['supplier_id', 'po_number', 'order_date', 'expected_delivery_date',
                       'total_amount', 'status', 'notes'];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
            $types .= (in_array($field, ['total_amount'])) ? 'd' : 
                     (in_array($field, ['supplier_id'])) ? 'i' : 's';
        }
    }
    
    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }
    
    $sql = "UPDATE purchase_orders SET " . implode(', ', $updates) . " WHERE po_id = ?";
    $types .= 'i';
    $values[] = $po_id;
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    // Check if status changed - send notification
    if (isset($data['status']) && $data['status'] !== $current['status']) {
        notifyPurchaseOrderStatusUpdate(
            $conn,
            $current['store_id'],
            $data['user_id'] ?? 1,
            $po_id,
            $current['po_number'],
            $data['status']
        );
    }
    
    sendResponse(true, 'Purchase order updated successfully');
    
} catch (Exception $e) {
    logError('Update PO error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update purchase order');
}



?>
