<?php
/**
 * Get Sale Items by sale_id
 */

require_once 'config.php';

$sale_id  = $_GET['sale_id']  ?? null;
$store_id = $_GET['store_id'] ?? null;

if (!$sale_id) {
    sendResponse(false, 'Sale ID is required');
}

$conn = getDBConnection();

try {
    $query = "
        SELECT
            si.item_id,
            si.store_id,
            si.sale_id,
            si.product_id,
            COALESCE(p.product_name, 'Unknown Product') AS product_name,
            si.quantity,
            si.unit_price,
            si.total_price
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.sale_id = ?
    ";

    $params = [$sale_id];
    $types  = "i";

    if ($store_id) {
        $query   .= " AND si.store_id = ?";
        $params[] = (int)$store_id;
        $types   .= "i";
    }

    $stmt = $conn->prepare($query);
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $items = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $items[] = $row;
    }

    sendResponse(true, 'Sale items retrieved', ['items' => $items]);

} catch (Exception $e) {
    logError('Get sale items error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve sale items');
}


