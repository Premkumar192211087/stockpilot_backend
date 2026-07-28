<?php
/**
 * Get Purchase Receives
 * Returns purchase orders with receive status (received, partial, pending)
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$conn = getDBConnection();

try {
    $query = "
        SELECT 
            po.po_id,
            po.po_number,
            po.order_date,
            po.expected_delivery_date,
            po.actual_delivery_date,
            po.total_amount,
            po.status,
            po.notes,
            s.supplier_name,
            s.contact_person,
            s.phone as supplier_phone,
            COALESCE(staff.full_name, ul.username, '') as created_by_name,
            (SELECT SUM(poi.quantity_ordered) FROM purchase_order_items poi WHERE poi.po_id = po.po_id AND poi.store_id = po.store_id) as total_ordered,
            (SELECT SUM(poi.quantity_received) FROM purchase_order_items poi WHERE poi.po_id = po.po_id AND poi.store_id = po.store_id) as total_received
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id AND po.store_id = s.store_id
        LEFT JOIN staff_details staff ON po.created_by = staff.staff_id AND po.store_id = staff.store_id
        LEFT JOIN user_login ul ON po.created_by = ul.id AND po.store_id = ul.store_id
        WHERE po.store_id = ?
    ";
    
    $params = [$store_id];
    $types = "i";
    
    // Filter by status
    if (!empty($status) && $status !== 'all') {
        $query .= " AND po.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    // Search by PO number or supplier name
    if (!empty($search)) {
        $query .= " AND (po.po_number LIKE ? OR s.supplier_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "ss";
    }
    
    $query .= " ORDER BY po.order_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    $receives = [];
    $summary = [
        'total_orders' => 0,
        'pending_count' => 0,
        'partial_count' => 0,
        'received_count' => 0,
        'total_value' => 0
    ];
    
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        // Calculate receive percentage
        $totalOrdered = intval($row['total_ordered'] ?? 0);
        $totalReceived = intval($row['total_received'] ?? 0);
        $row['receive_percentage'] = $totalOrdered > 0 ? round(($totalReceived / $totalOrdered) * 100, 1) : 0;
        
        $receives[] = $row;
        $summary['total_orders']++;
        $summary['total_value'] += floatval($row['total_amount']);
        
        switch ($row['status']) {
            case 'pending': $summary['pending_count']++; break;
            case 'partial': $summary['partial_count']++; break;
            case 'received': $summary['received_count']++; break;
        }
    }
    
    $summary['total_value'] = round($summary['total_value'], 2);
    
    // Get items for each PO
    foreach ($receives as &$receive) {
        $itemStmt = $conn->prepare("
            SELECT 
                poi.poi_id,
                poi.quantity_ordered,
                poi.quantity_received,
                poi.unit_price,
                poi.total_price,
                p.product_name,
                p.sku
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.id
            WHERE poi.po_id = ?
        ");
        // PDO bind
$itemStmt->execute([$receive['po_id']]);$itemStmt->execute();
        // PDO result ready in $itemStmt
    $itemResult = $itemStmt;
        
        $items = [];
        while ($item = $itemResult->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $item;
        }
        $receive['items'] = $items;
        
    }
    
    sendResponse(true, 'Purchase receives retrieved successfully', [
        'receives' => $receives,
        'summary' => $summary
    ]);
    
} catch (Exception $e) {
    logError('Get purchase receives error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve purchase receives');
}



?>
