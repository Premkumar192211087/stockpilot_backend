<?php
/**
 * Get Inventory Reports - Enhanced Analytics
 */
require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) { sendResponse(false, 'Store ID is required'); }

$conn = getDBConnection();

try {
    $category = $_GET['category'] ?? 'all';
    $where = "p.store_id = ?";
    $params = [$store_id];
    $types = "i";

    if ($category !== 'all' && !empty($category)) {
        $where .= " AND p.category = ?";
        $params[] = $category;
        $types .= "s";
    }

    // Summary stats
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN p.quantity <= COALESCE(ia.min_stock_level, 10) AND p.quantity > 0 THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN p.quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
            COALESCE(SUM(p.quantity * p.cost_price), 0) as total_value,
            COALESCE(SUM(p.quantity), 0) as total_units
        FROM products p
        LEFT JOIN inventory_alerts ia ON p.id = ia.product_id AND p.store_id = ia.store_id
        WHERE $where
    ");
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    

    // Items list
    $stmt = $conn->prepare("
        SELECT
            p.id AS product_id,
            p.product_name,
            p.sku,
            p.quantity AS current_stock,
            p.cost_price,
            p.price AS selling_price,
            COALESCE(p.category, 'Uncategorized') AS category,
            (p.quantity * p.cost_price) as total_value,
            COALESCE(ia.min_stock_level, 10) as reorder_level
        FROM products p
        LEFT JOIN inventory_alerts ia ON p.id = ia.product_id AND p.store_id = ia.store_id
        WHERE $where
        ORDER BY p.quantity ASC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $items = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) { $items[] = $row; }
    

    // Distinct categories (from products.category varchar column directly)
    $stmt2 = $conn->prepare("
        SELECT DISTINCT p.category AS category_name
        FROM products p
        WHERE p.store_id = ? AND p.category IS NOT NULL AND p.category != ''
        ORDER BY p.category
    ");
    // PDO bind
$stmt2->execute([$store_id]);$stmt2->execute();
    // PDO result ready in $stmt2
    $catResult = $stmt2;
    $categories = [];
    while ($row = $catResult->fetch(PDO::FETCH_ASSOC)) { $categories[] = $row['category_name']; }
    

    // Low stock alerts
    $stmt3 = $conn->prepare("
        SELECT
            p.id AS product_id,
            p.product_name,
            COALESCE(p.category, 'Uncategorized') AS category,
            p.quantity AS current_stock,
            COALESCE(ia.min_stock_level, 10) as min_stock_level,
            CASE WHEN p.quantity = 0 THEN 'Out of Stock' ELSE 'Low Stock' END as status
        FROM products p
        LEFT JOIN inventory_alerts ia ON p.id = ia.product_id AND p.store_id = ia.store_id
        WHERE p.store_id = ? AND p.quantity <= COALESCE(ia.min_stock_level, 10)
        ORDER BY p.quantity ASC
        LIMIT 20
    ");
    // PDO bind
$stmt3->execute([$store_id]);$stmt3->execute();
    // PDO result ready in $stmt3
    $lowResult = $stmt3;
    $lowStockAlerts = [];
    while ($row = $lowResult->fetch(PDO::FETCH_ASSOC)) { $lowStockAlerts[] = $row; }
    

    // Stock by category (using p.category directly)
    $stmt4 = $conn->prepare("
        SELECT
            COALESCE(p.category, 'Uncategorized') as category,
            COUNT(*) as product_count,
            COALESCE(SUM(p.quantity), 0) as total_stock,
            COALESCE(SUM(p.quantity * p.cost_price), 0) as total_value
        FROM products p
        WHERE p.store_id = ?
        GROUP BY p.category
        ORDER BY total_value DESC
    ");
    // PDO bind
$stmt4->execute([$store_id]);$stmt4->execute();
    // PDO result ready in $stmt4
    $catStockResult = $stmt4;
    $stockByCategory = [];
    while ($row = $catStockResult->fetch(PDO::FETCH_ASSOC)) { $stockByCategory[] = $row; }
    

    // Stock movements - table doesn't exist, return empty array
    $stockMovements = [];

    sendResponse(true, 'Inventory reports retrieved', [
        'inventory_summary' => [
            'total_products' => (int)$summary['total'],
            'low_stock'      => (int)$summary['low_stock'],
            'expired'        => (int)$summary['out_of_stock'],
            'total_value'    => (float)$summary['total_value'],
            'total_units'    => (int)$summary['total_units']
        ],
        'items'                 => $items,
        'categories'            => $categories,
        'low_stock_alerts'      => $lowStockAlerts,
        'stock_by_category'     => $stockByCategory,
        'stock_movements'       => $stockMovements,
        'total_inventory_value' => (float)$summary['total_value']
    ]);

} catch (Exception $e) {
    logError('Inventory reports error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve inventory reports');
}

