<?php
/**
 * Update Product/Item
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

// Accept item_id from URL param OR body
$item_id = $_GET['item_id'] ?? $data['item_id'] ?? $data['id'] ?? null;
if (!$item_id) {
    sendResponse(false, 'Item ID is required');
}

try {
    // Map friendly field aliases to actual DB column names
    if (isset($data['selling_price']) && !isset($data['price']))    $data['price']    = $data['selling_price'];
    if (isset($data['current_stock']) && !isset($data['quantity'])) $data['quantity']  = $data['current_stock'];

    $allowed_fields = ['product_name', 'sku', 'barcode', 'category',
                       'cost_price', 'price', 'quantity', 'image_url', 'status'];

    $updates = [];
    $types   = "";
    $values  = [];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[]  = $data[$field];
            $val = $data[$field];
            if (is_int($val)) {
                $types .= 'i';
            } elseif (is_float($val) || (is_string($val) && strpos($val, '.') !== false && is_numeric($val))) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
    }

    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }

    $sql = "UPDATE products SET " . implode(', ', $updates) . " WHERE id = ?";
    $types  .= 'i';
    $values[] = (int) $item_id;

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    // Low-stock notification check using inventory_alerts table
    if (isset($data['quantity']) || isset($data['price'])) {
        $checkStmt = $conn->prepare("
            SELECT p.id, p.product_name, p.quantity, ia.min_stock_level, p.store_id
            FROM products p
            JOIN inventory_alerts ia ON p.id = ia.product_id AND p.store_id = ia.store_id
            WHERE p.id = ? AND p.quantity <= ia.min_stock_level AND ia.alert_enabled = 1
        ");
        // PDO bind
$checkStmt->execute([$item_id]);$checkStmt->execute();
        // PDO result ready in $checkStmt
    $result = $checkStmt;
        if ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            notifyLowStock(
                $conn, $row['store_id'], $data['user_id'] ?? 1,
                $row['id'], $row['product_name'],
                $row['quantity'], $row['min_stock_level']
            );
        }
        
    }

    sendResponse(true, 'Product updated successfully');

} catch (Exception $e) {
    logError('Update product error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update product');
}



