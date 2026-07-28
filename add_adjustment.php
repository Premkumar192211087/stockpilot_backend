<?php
/**
 * Add Inventory Adjustment
 * Since inventory_adjustments table doesn't exist, directly update products.quantity
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id', 'adjustment_type', 'quantity']);

try {
    // Resolve product_id — accept numeric ID directly or look up by product_name
    $productId   = $data['product_id'] ?? null;
    $productName = 'Unknown Item';

    if (!$productId && !empty($data['product_name'])) {
        $ps = $conn->prepare(
            "SELECT id, product_name FROM products
             WHERE product_name = ? AND store_id = ? LIMIT 1"
        );
        // PDO bind
$ps->execute([$data['product_name'], $data['store_id']]);$ps->execute();
        // PDO result ready in $ps
    $pr = $ps;
        if ($row = $pr->fetch(PDO::FETCH_ASSOC)) {
            $productId   = (int) $row['id'];
            $productName = $row['product_name'];
        }
        
    } elseif ($productId) {
        $ps = $conn->prepare("SELECT product_name FROM products WHERE id = ? LIMIT 1");
        // PDO bind
$ps->execute([$productId]);$ps->execute();
        // PDO result ready in $ps
    $pr = $ps;
        if ($row = $pr->fetch(PDO::FETCH_ASSOC)) {
            $productName = $row['product_name'];
        }
        
    }

    if (!$productId) {
        sendResponse(false, 'Product not found');
    }

    $storeId   = (int) $data['store_id'];
    $productId = (int) $productId;
    $adjType   = $data['adjustment_type'];
    $qty       = (int) $data['quantity'];
    $reason    = $data['reason'] ?? '';

    // Verify product exists and get current quantity
    $checkStmt = $conn->prepare("SELECT id, quantity FROM products WHERE id = ? AND store_id = ?");
    // PDO bind
$checkStmt->execute([$productId, $storeId]);$checkStmt->execute();
    // PDO result ready in $checkStmt
    $checkResult = $checkStmt;
    
    if ($checkResult->num_rows === 0) {
        sendResponse(false, 'Product not found in this store');
    }
    
    $currentProduct = $checkResult->fetch(PDO::FETCH_ASSOC);
    $currentQty = (int) $currentProduct['quantity'];
    

    // Update quantity directly on products table
    if ($adjType === 'increase' || $adjType === 'add') {
        $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ? AND store_id = ?");
        // PDO bind
$stmt->execute([$qty, $productId, $storeId]);$newQty = $currentQty + $qty;
    } else {
        // subtract / decrease
        $stmt = $conn->prepare("UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ? AND store_id = ?");
        // PDO bind
$stmt->execute([$qty, $productId, $storeId]);$newQty = max($currentQty - $qty, 0);
    }
    $stmt->execute();
    

    $qty_change = ($adjType === 'increase' || $adjType === 'add') ? $qty : -$qty;
    notifyInventoryAdjustment(
        $conn, $storeId, $data['user_id'] ?? 1,
        $productId, $productName, $qty_change,
        $reason ?: 'Manual adjustment'
    );

    if ($qty_change < 0) {
        $stockCheck = $conn->prepare("
            SELECT p.product_name, p.quantity, COALESCE(ia.min_stock_level, 10) AS min_stock_level
            FROM products p
            LEFT JOIN inventory_alerts ia
                ON ia.product_id = p.id
               AND ia.store_id = p.store_id
               AND ia.alert_enabled = 1
            WHERE p.id = ? AND p.store_id = ?
            LIMIT 1
        ");
        // PDO bind
$stockCheck->execute([$productId, $storeId]);$stockCheck->execute();
        $stockResult = $stockCheck->fetch(PDO::FETCH_ASSOC);
        

        if ($stockResult && (int)$stockResult['quantity'] <= (int)$stockResult['min_stock_level']) {
            notifyLowStock(
                $conn,
                $storeId,
                $data['user_id'] ?? 1,
                $productId,
                $stockResult['product_name'],
                (int)$stockResult['quantity'],
                (int)$stockResult['min_stock_level']
            );
        }
    }

    sendResponse(true, 'Adjustment applied successfully', [
        'product_id'   => $productId,
        'product_name' => $productName,
        'old_quantity'  => $currentQty,
        'new_quantity'  => $newQty,
        'adjustment'   => $qty_change,
    ]);

} catch (Exception $e) {
    logError('Add adjustment error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add adjustment');
}


