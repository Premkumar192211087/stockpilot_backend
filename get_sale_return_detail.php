<?php
/**
 * Get Sales Return Detail
 * Returns full details of a specific sales return including return items
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
$return_id = $_GET['return_id'] ?? null;

if (!$store_id || !$return_id) {
    sendResponse(false, 'Store ID and Return ID are required');
}

$conn = getDBConnection();

try {
    // Get return header
    $stmt = $conn->prepare("
        SELECT 
            sr.return_id,
            sr.sale_id,
            sr.store_id,
            sr.return_number,
            sr.return_date,
            sr.reason,
            sr.status,
            sr.return_amount,
            sr.customer_id,
            sr.created_at,
            sr.updated_at,
            s.invoice_number as sale_invoice_number,
            c.customer_name
        FROM sales_returns sr
        LEFT JOIN sales s ON sr.sale_id = s.sale_id
        LEFT JOIN customers c ON sr.customer_id = c.customer_id
        WHERE sr.return_id = ? AND sr.store_id = ?
    ");
    // PDO bind
$stmt->execute([$return_id, $store_id]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    if ($result->num_rows === 0) {
        sendResponse(false, 'Sales return not found');
    }
    
    $returnData = $result->fetch(PDO::FETCH_ASSOC);
    
    
    // Get return items
    $itemStmt = $conn->prepare("
        SELECT 
            ri.return_item_id as returnItemId,
            ri.product_id as productId,
            ri.quantity_returned as quantityReturned,
            ri.unit_price as unitPrice,
            ri.refund_amount as refundAmount,
            ri.condition_status as conditionStatus,
            p.product_name as productName
        FROM return_items ri
        LEFT JOIN products p ON ri.product_id = p.product_id
        WHERE ri.return_id = ?
    ");
    // PDO bind
$itemStmt->execute([$return_id]);$itemStmt->execute();
    // PDO result ready in $itemStmt
    $itemResult = $itemStmt;
    
    $items = [];
    while ($row = $itemResult->fetch(PDO::FETCH_ASSOC)) {
        $items[] = $row;
    }
    
    
    // Build response matching the Android model expectations
    $response = [
        'returns' => [$returnData],
        'items' => $items
    ];
    
    sendResponse(true, 'Sales return detail retrieved', $response);
    
} catch (Exception $e) {
    logError('Get sales return detail error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve sales return detail');
}


?>
