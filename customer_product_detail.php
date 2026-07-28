<?php
/**
 * Customer Product Detail
 *
 * GET ?product_id=X&store_id=Y
 *
 * Returns full product details + up to 4 related products in the same category.
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'GET method required');
}

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$store_id   = isset($_GET['store_id'])   ? (int)$_GET['store_id']   : 0;

if ($product_id <= 0 || $store_id <= 0) {
    sendResponse(false, 'product_id and store_id are required');
}

$conn = getDBConnection();

try {
    // --- Main product ---
    $stmt = $conn->prepare("
        SELECT id, product_name, price, cost_price, image_url, category,
               quantity AS stock_available, sku, barcode, description, status,
               is_visible_to_customers, created_at
        FROM products
        WHERE id = ? AND store_id = ?
    ");
    // PDO bind
$stmt->execute([$product_id, $store_id]);$stmt->execute();
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    

    if (!$product) {
        sendResponse(false, 'Product not found');
    }

    // Cast types
    $product['id']              = (int)$product['id'];
    $product['price']           = (float)$product['price'];
    $product['cost_price']      = (float)$product['cost_price'];
    $product['stock_available'] = (int)$product['stock_available'];
    $product['is_visible_to_customers'] = (int)$product['is_visible_to_customers'];

    // --- Related products (same category, exclude self, limit 4) ---
    $category = $product['category'];
    $relStmt = $conn->prepare("
        SELECT id, product_name, price, image_url, category,
               quantity AS stock_available, sku
        FROM products
        WHERE store_id = ? AND category = ? AND id != ?
              AND status = 'active' AND is_visible_to_customers = 1
        ORDER BY RAND()
        LIMIT 4
    ");
    // PDO bind
$relStmt->execute([$store_id, $category, $product_id]);$relStmt->execute();
    $related = $relStmt->fetchAll(PDO::FETCH_ASSOC);
    

    foreach ($related as &$r) {
        $r['id']              = (int)$r['id'];
        $r['price']           = (float)$r['price'];
        $r['stock_available'] = (int)$r['stock_available'];
    }
    unset($r);

    sendResponse(true, 'Product detail loaded', [
        'product'          => $product,
        'related_products' => $related,
    ]);

} catch (Exception $e) {
    logError('customer_product_detail error: ' . $e->getMessage());
    sendResponse(false, 'Failed to load product detail: ' . $e->getMessage());
}


?>
