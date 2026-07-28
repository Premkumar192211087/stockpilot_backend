<?php
/**
 * Get Reorder Suggestion for a product
 * Returns preferred supplier, reorder qty, estimated cost
 */

require_once 'config.php';

$product_id = $_GET['product_id'] ?? null;
$store_id = $_GET['store_id'] ?? null;

if (!$product_id || !$store_id) {
    sendResponse(false, 'product_id and store_id are required');
}

$conn = getDBConnection();

try {
    // Get product with preferred supplier and reorder info
    $stmt = $conn->prepare("
        SELECT 
            p.id as product_id,
            p.product_name,
            p.quantity as current_stock,
            p.cost_price,
            ps.supplier_id,
            s.supplier_name,
            s.payment_terms,
            COALESCE(s.payment_days, 30) as payment_days,
            ps.unit_cost as supplier_unit_cost,
            ps.min_order_quantity,
            ps.lead_time_days,
            COALESCE(ia.reorder_quantity, 50) as reorder_quantity,
            COALESCE(ia.min_stock_level, 10) as min_stock_level,
            COALESCE(ia.max_stock_level, 100) as max_stock_level
        FROM products p
        LEFT JOIN product_suppliers ps ON p.id = ps.product_id AND ps.store_id = ? AND ps.is_primary = 1
        LEFT JOIN suppliers s ON ps.supplier_id = s.supplier_id
        LEFT JOIN inventory_alerts ia ON p.id = ia.product_id AND p.store_id = ia.store_id
        WHERE p.id = ? AND p.store_id = ?
        LIMIT 1
    ");
    // PDO bind
$stmt->execute([$store_id, $product_id, $store_id]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    if ($result->num_rows === 0) {
        sendResponse(false, 'Product not found');
    }
    
    $suggestion = $result->fetch(PDO::FETCH_ASSOC);
    
    
    // Calculate suggested order quantity
    $currentStock = (int)$suggestion['current_stock'];
    $maxStock = (int)$suggestion['max_stock_level'];
    $reorderQty = (int)$suggestion['reorder_quantity'];
    $minOrder = (int)($suggestion['min_order_quantity'] ?: 1);
    
    // Suggest enough to reach max stock, but at least the reorder quantity
    $deficit = max(0, $maxStock - $currentStock);
    $suggestedQty = max($reorderQty, $deficit);
    $suggestedQty = max($suggestedQty, $minOrder); // At least min order
    
    $unitCost = (float)($suggestion['supplier_unit_cost'] ?: $suggestion['cost_price']);
    $estimatedCost = $suggestedQty * $unitCost;
    
    $suggestion['suggested_quantity'] = $suggestedQty;
    $suggestion['unit_cost'] = $unitCost;
    $suggestion['estimated_cost'] = $estimatedCost;
    $suggestion['has_supplier'] = !empty($suggestion['supplier_id']);
    
    // If no primary supplier, try to find any supplier
    if (!$suggestion['has_supplier']) {
        $fallbackStmt = $conn->prepare("
            SELECT ps.supplier_id, s.supplier_name, ps.unit_cost
            FROM product_suppliers ps
            JOIN suppliers s ON ps.supplier_id = s.supplier_id
            WHERE ps.product_id = ? AND ps.store_id = ? AND s.status = 'active'
            LIMIT 1
        ");
        // PDO bind
$fallbackStmt->execute([$product_id, $store_id]);$fallbackStmt->execute();
        $fallback = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
        
        
        if ($fallback) {
            $suggestion['supplier_id'] = $fallback['supplier_id'];
            $suggestion['supplier_name'] = $fallback['supplier_name'];
            $suggestion['supplier_unit_cost'] = $fallback['unit_cost'];
            $suggestion['unit_cost'] = (float)$fallback['unit_cost'];
            $suggestion['estimated_cost'] = $suggestedQty * (float)$fallback['unit_cost'];
            $suggestion['has_supplier'] = true;
        }
    }
    
    sendResponse(true, 'Reorder suggestion retrieved', ['suggestion' => $suggestion]);

} catch (Exception $e) {
    logError('Get reorder suggestion error: ' . $e->getMessage());
    sendResponse(false, 'Failed to get reorder suggestion');
}


?>
