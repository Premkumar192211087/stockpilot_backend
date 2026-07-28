<?php
/**
 * Get Purchase Orders
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
$conn = getDBConnection();

try {
    $where = "po.store_id = ?";
    $params = [$store_id];
    $types = "i";

    if ($po_id > 0) {
        $where .= " AND po.po_id = ?";
        $params[] = $po_id;
        $types .= "i";
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'All') {
        $where .= " AND po.status = ?";
        $params[] = strtolower($_GET['status']);
        $types .= "s";
    }

    $searchParam = $_GET['search'] ?? $_GET['search_query'] ?? null;
    if (!empty($searchParam)) {
        $where .= " AND (po.po_number LIKE ? OR s.supplier_name LIKE ?)";
        $search = "%" . $searchParam . "%";
        $params[] = $search;
        $params[] = $search;
        $types .= "ss";
    }

    $stmt = $conn->prepare("
        SELECT
            po.po_id,
            po.po_number,
            po.supplier_id,
            po.order_date,
            po.expected_delivery_date,
            po.actual_delivery_date,
            po.total_amount,
            po.status,
            po.notes,
            po.store_id,
            po.created_by,
            po.created_at,
            s.supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id AND po.store_id = s.store_id
        WHERE $where
        ORDER BY po.created_at DESC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $purchase_orders = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $purchase_orders[] = $row;
    }

    $payload = ['purchase_orders' => $purchase_orders];

    if ($po_id > 0 && count($purchase_orders) > 0) {
        $payload['purchase_order'] = $purchase_orders[0];

        $itemsStmt = $conn->prepare("
            SELECT
                poi.poi_id,
                poi.po_id,
                poi.product_id,
                COALESCE(p.product_name, '') AS product_name,
                poi.quantity_ordered,
                poi.quantity_received,
                poi.unit_price,
                poi.total_price,
                poi.store_id
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.id AND poi.store_id = p.store_id
            WHERE poi.store_id = ? AND poi.po_id = ?
            ORDER BY poi.poi_id ASC
        ");
        // PDO bind
$itemsStmt->execute([$store_id, $po_id]);$itemsStmt->execute();
        // PDO result ready in $itemsStmt
    $itemsResult = $itemsStmt;
        $items = [];
        while ($row = $itemsResult->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $row;
        }
        
        $payload['items'] = $items;
    }

    sendResponse(true, 'Purchase orders retrieved successfully', $payload);
} catch (Exception $e) {
    logError('Get purchase orders error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve purchase orders');
}

if (isset($stmt)) {
    
}

?>
