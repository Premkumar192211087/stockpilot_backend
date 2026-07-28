<?php
/**
 * Get Low Stock Report - Enhanced with Summary KPIs
 * Returns: summary stats + items array
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    // 1. Summary statistics
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) as total_count,
            SUM(CASE WHEN p.quantity <= 5 THEN 1 ELSE 0 END) as critical_count,
            COALESCE(SUM(p.price * p.quantity), 0) as value_at_risk,
            COUNT(DISTINCT COALESCE(p.category, 'Uncategorized')) as categories_affected
        FROM products p
        WHERE p.store_id = ?
        AND p.quantity < 10
    ");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    

    // 2. Low stock items list
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.product_name,
            p.sku,
            p.quantity,
            p.price,
            p.cost_price,
            COALESCE(p.category, 'Uncategorized') AS category
        FROM products p
        WHERE p.store_id = ?
        AND p.quantity < 10
        ORDER BY p.quantity ASC
    ");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $low_stock_items = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $low_stock_items[] = $row;
    }
    

    sendResponse(true, 'Low stock report retrieved successfully', [
        'total_count'         => (int)$summary['total_count'],
        'critical_count'      => (int)$summary['critical_count'],
        'value_at_risk'       => (float)$summary['value_at_risk'],
        'categories_affected' => (int)$summary['categories_affected'],
        'items'               => $low_stock_items
    ]);

} catch (Exception $e) {
    logError('Get low stock report error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve low stock report');
}


?>
