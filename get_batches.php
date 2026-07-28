<?php
/**
 * Get Product Batches
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $where  = "bd.store_id = ?";
    $params = [(int) $store_id];
    $types  = "i";

    // Filter by product_id when loading for a specific product
    if (!empty($_GET['product_id'])) {
        $where  .= " AND bd.product_id = ?";
        $params[] = (int) $_GET['product_id'];
        $types  .= "i";
    }

    $stmt = $conn->prepare("
        SELECT
            bd.id,
            bd.store_id,
            bd.product_id,
            COALESCE(p.product_name, bd.product_name) AS product_name,
            bd.batch_id,
            bd.barcode,
            bd.quantity,
            bd.mfg_date,
            bd.exp_date,
            bd.damaged_quantity,
            DATEDIFF(bd.exp_date, CURDATE()) AS days_until_expiry
        FROM batch_details bd
        LEFT JOIN products p ON bd.product_id = p.id
        WHERE $where
        ORDER BY bd.id DESC
    ");

    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $batches = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $batches[] = $row;
    }

    sendResponse(true, 'Batches retrieved successfully', $batches);

} catch (Exception $e) {
    logError('Get batches error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve batches');
}



