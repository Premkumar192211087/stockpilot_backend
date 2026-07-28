<?php
/**
 * Customer Products – public catalogue
 *
 * GET ?store_id=X&page=1&limit=20&category=&search=&sort=
 *   sort options: price_asc, price_desc, name_asc, newest (default)
 *
 * Returns paginated product list visible to customers.
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'GET method required');
}

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'store_id is required');
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;
$category = isset($_GET['category']) && trim($_GET['category']) !== '' ? trim($_GET['category']) : null;
$search   = isset($_GET['search']) && trim($_GET['search']) !== '' ? trim($_GET['search']) : null;
$sort     = $_GET['sort'] ?? 'newest';

$conn = getDBConnection();

try {
    // --- Build WHERE clause dynamically ---
    $where  = "WHERE p.store_id = ? AND p.status = 'active' AND p.is_visible_to_customers = 1";
    $types  = "i";
    $params = [$store_id];

    if ($category !== null) {
        $where  .= " AND p.category = ?";
        $types  .= "s";
        $params[] = $category;
    }

    if ($search !== null) {
        $where  .= " AND (p.product_name LIKE ? OR p.sku LIKE ?)";
        $types  .= "ss";
        $searchWild = "%$search%";
        $params[] = $searchWild;
        $params[] = $searchWild;
    }

    // --- Sorting ---
    switch ($sort) {
        case 'price_asc':  $orderBy = "p.price ASC";           break;
        case 'price_desc': $orderBy = "p.price DESC";          break;
        case 'name_asc':   $orderBy = "p.product_name ASC";    break;
        case 'newest':
        default:           $orderBy = "p.created_at DESC";     break;
    }

    // --- Count total ---
    $countSql = "SELECT COUNT(*) AS total FROM products p $where";
    $cs = $conn->prepare($countSql);
    $cs->execute();
    $total = (int)$cs->fetch(PDO::FETCH_ASSOC)['total'];
    

    $total_pages = (int)ceil($total / $limit);

    // --- Fetch page ---
    $sql = "
        SELECT p.id, p.product_name, p.price, p.image_url, p.category,
               p.quantity AS stock_available, p.sku, p.description
        FROM products p
        $where
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    $types  .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

    // Cast numerics
    foreach ($products as &$p) {
        $p['id']              = (int)$p['id'];
        $p['price']           = (float)$p['price'];
        $p['stock_available'] = (int)$p['stock_available'];
    }
    unset($p);

    // --- Available categories for filter UI ---
    $catStmt = $conn->prepare("
        SELECT DISTINCT category
        FROM products
        WHERE store_id = ? AND status = 'active' AND is_visible_to_customers = 1
        ORDER BY category ASC
    ");
    // PDO bind
$catStmt->execute([$store_id]);$catStmt->execute();
    $catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $categories = array_column($catRows, 'category');

    sendResponse(true, 'Products loaded', [
        'products'    => $products,
        'categories'  => $categories,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $total_pages,
    ]);

} catch (Exception $e) {
    logError('customer_products error: ' . $e->getMessage());
    sendResponse(false, 'Failed to load products: ' . $e->getMessage());
}


?>
