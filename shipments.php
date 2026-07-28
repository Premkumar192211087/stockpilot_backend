<?php
/**
 * Get Shipments
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$shipment_id = isset($_GET['shipment_id']) ? (int)$_GET['shipment_id'] : 0;
$conn = getDBConnection();

try {
    $where = "store_id = ?";
    $params = [$store_id];
    $types = "i";

    if ($shipment_id > 0) {
        $where .= " AND shipment_id = ?";
        $params[] = $shipment_id;
        $types .= "i";
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'All') {
        $where .= " AND status = ?";
        $params[] = strtolower($_GET['status']);
        $types .= "s";
    }

    $searchParam = $_GET['search'] ?? $_GET['search_query'] ?? null;
    if (!empty($searchParam)) {
        $where .= " AND (shipment_number LIKE ? OR recipient_name LIKE ? OR tracking_number LIKE ? OR carrier_name LIKE ?)";
        $search = "%" . $searchParam . "%";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= "ssss";
    }

    $stmt = $conn->prepare("
        SELECT
            shipment_id,
            shipment_number,
            order_type,
            order_id,
            carrier_name,
            tracking_number,
            shipping_method,
            shipping_cost,
            ship_date,
            estimated_delivery_date,
            actual_delivery_date,
            status,
            recipient_name,
            recipient_address,
            recipient_phone,
            store_id,
            notes,
            created_by,
            created_at,
            updated_at
        FROM shipments
        WHERE $where
        ORDER BY ship_date DESC, created_at DESC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $shipments = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $shipments[] = $row;
    }

    $payload = ['shipments' => $shipments];
    if ($shipment_id > 0 && count($shipments) > 0) {
        $payload['shipment'] = $shipments[0];

        // Fetch items shipped in this shipment
        $itemStmt = $conn->prepare("
            SELECT
                si.shipment_item_id AS item_id,
                si.product_id,
                COALESCE(p.product_name, 'Unknown') AS product_name,
                p.sku,
                si.quantity_shipped  AS quantity,
                si.unit_price,
                si.total_value       AS total_price,
                si.batch_id
            FROM shipment_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.shipment_id = ? AND si.store_id = ?
            ORDER BY si.shipment_item_id ASC
        ");
        // PDO bind
$itemStmt->execute([$shipment_id, $store_id]);$itemStmt->execute();
        $payload['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        
    }

    sendResponse(true, 'Shipments retrieved successfully', $payload);
} catch (Exception $e) {
    logError('Get shipments error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve shipments');
}

if (isset($stmt)) {
    
}

?>
