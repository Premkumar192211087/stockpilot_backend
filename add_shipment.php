<?php
/**
 * Add Shipment
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

function generateShipmentNumber($conn, $store_id) {
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'SHP-' . date('Ymd-His') . '-' . random_int(100, 999);
        $stmt = $conn->prepare('SELECT shipment_id FROM shipments WHERE store_id = ? AND shipment_number = ? LIMIT 1');
        // PDO bind
$stmt->execute([$store_id, $candidate]);$stmt->execute();
        $exists = $stmt->rowCount() > 0;
        
        if (!$exists) return $candidate;
    }
    return 'SHP-' . date('YmdHis') . '-' . random_int(1000, 9999);
}

$conn = getDBConnection();
$data = getJSONInput();

$store_id = (int)($data['store_id'] ?? 0);
$recipient_name = trim((string)($data['recipient_name'] ?? ''));
if ($store_id <= 0 || $recipient_name === '') {
    sendResponse(false, 'Store and recipient name are required');
}

try {
    $shipment_number = trim((string)($data['shipment_number'] ?? ''));
    if ($shipment_number === '') $shipment_number = generateShipmentNumber($conn, $store_id);

    $order_type = trim((string)($data['order_type'] ?? 'sales_order'));
    if (!in_array($order_type, ['purchase_order', 'sales_order'], true)) $order_type = 'sales_order';
    $order_id = (int)($data['order_id'] ?? 0);
    $carrier_name = trim((string)($data['carrier_name'] ?? $data['carrier'] ?? ''));
    $tracking_number = trim((string)($data['tracking_number'] ?? ''));
    $shipping_method = strtolower(trim((string)($data['shipping_method'] ?? 'standard')));
    if ($shipping_method === '') $shipping_method = 'standard';
    $shipping_cost = (float)($data['shipping_cost'] ?? 0);
    $ship_date = trim((string)($data['ship_date'] ?? ''));
    if ($ship_date === '') $ship_date = null;
    $estimated_delivery_date = trim((string)($data['estimated_delivery_date'] ?? $data['estimated_delivery'] ?? ''));
    if ($estimated_delivery_date === '') $estimated_delivery_date = null;
    $status = trim((string)($data['status'] ?? 'pending'));
    if (!in_array($status, ['pending', 'shipped', 'in_transit', 'delivered', 'returned', 'cancelled'], true)) $status = 'pending';
    $recipient_address = trim((string)($data['recipient_address'] ?? $data['shipping_address'] ?? ''));
    $recipient_phone = trim((string)($data['recipient_phone'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));
    $created_by = (int)($data['created_by'] ?? $data['user_id'] ?? 1);
    if ($created_by <= 0) $created_by = 1;

    $stmt = $conn->prepare('
        INSERT INTO shipments (
            store_id, shipment_number, order_type, order_id,
            carrier_name, tracking_number, shipping_method, shipping_cost,
            ship_date, estimated_delivery_date, status,
            recipient_name, recipient_address, recipient_phone,
            notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    // PDO bind
$stmt->execute([$store_id, $shipment_number, $order_type, $order_id,
        $carrier_name, $tracking_number, $shipping_method, $shipping_cost,
        $ship_date, $estimated_delivery_date, $status,
        $recipient_name, $recipient_address, $recipient_phone,
        $notes, $created_by]);$stmt->execute();
    $shipment_id = $conn->lastInsertId();
    

    try {
        notifyShipmentCreated($conn, $store_id, $created_by, $shipment_id, $shipment_number);
    } catch (Throwable $notifyError) {
        logError('Add shipment notification error: ' . $notifyError->getMessage());
    }

    sendResponse(true, 'Shipment created successfully', [
        'shipment_id' => $shipment_id,
        'shipment_number' => $shipment_number
    ]);
} catch (Exception $e) {
    logError('Add shipment error: ' . $e->getMessage());
    sendResponse(false, 'Failed to create shipment');
}


?>
