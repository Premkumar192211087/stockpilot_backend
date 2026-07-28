<?php
/**
 * Get Products/Items
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$conn = getDBConnection();

try {
    $where = "p.store_id = ?";
    $params = [$store_id];
    $types = "i";

    if ($item_id > 0) {
        $where .= " AND p.id = ?";
        $params[] = $item_id;
        $types .= "i";
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'All') {
        $status = $_GET['status'];
        if ($status === 'In Stock') {
            $where .= " AND p.quantity > 10";
        } elseif ($status === 'Low Stock') {
            $where .= " AND p.quantity <= 10 AND p.quantity > 0";
        } elseif ($status === 'Out of Stock') {
            $where .= " AND p.quantity = 0";
        }
    }

    $searchParam = $_GET['search'] ?? $_GET['search_query'] ?? null;
    if (!empty($searchParam)) {
        $where .= " AND (p.product_name LIKE ? OR p.sku LIKE ? OR p.category LIKE ? OR p.barcode LIKE ?)";
        $search = "%" . $searchParam . "%";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= "ssss";
    }

    $sortMap = [
        'product_name' => 'product_name',
        'sku' => 'sku',
        'current_stock' => 'quantity',
        'selling_price' => 'price',
        'quantity' => 'quantity',
        'price' => 'price',
        'created_at' => 'created_at'
    ];
    $sortBy = $_GET['sort_by'] ?? 'product_name';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'ASC');
    $sortBy = $sortMap[$sortBy] ?? 'product_name';
    if (!in_array($sortOrder, ['ASC', 'DESC'])) $sortOrder = 'ASC';

    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.id AS product_id,
            p.product_name,
            p.product_name AS productName,
            p.sku,
            p.barcode,
            p.category,
            p.price,
            p.quantity,
            p.status,
            p.cost_price,
            p.cost_price AS costPrice,
            p.store_id,
            p.store_id AS storeId,
            COALESCE(p.image_url, '') AS image_url,
            COALESCE(p.image_url, '') AS imageUrl,
            p.created_at
        FROM products p
        WHERE $where
        ORDER BY p.$sortBy $sortOrder
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $products = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $products[] = $row;
    }

    $payload = ['products' => $products, 'items' => $products];
    if ($item_id > 0 && count($products) > 0) {
        $payload['product'] = $products[0];
        $payload['item'] = $products[0];
    }

    sendResponse(true, 'Products retrieved successfully', $payload);
} catch (Exception $e) {
    logError('Get products error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve products');
}

if (isset($stmt)) {
    
}

?>
