<?php
/**
 * Get products for a vendor.
 *
 * Two calling conventions are supported:
 *
 * A) Vendor portal "My Products" tab:
 *      ?supplier_id=X[&store_id=Y][&search=...]
 *    Returns the distinct products this supplier has supplied to the store
 *    through purchase orders (purchase_order_items). If the supplier has a
 *    linked vendor_store_id, that store's full catalog is returned instead.
 *
 * B) PO product picker (buyer fetching a linked vendor's catalog):
 *      ?vendor_store_id=X&buyer_store_id=Y[&search=...]
 */

require_once 'config.php';

$supplier_id     = isset($_GET['supplier_id'])     ? (int)$_GET['supplier_id']     : 0;
$store_id        = isset($_GET['store_id'])        ? (int)$_GET['store_id']        : 0;
$vendor_store_id = isset($_GET['vendor_store_id']) ? (int)$_GET['vendor_store_id'] : 0;
$buyer_store_id  = isset($_GET['buyer_store_id'])  ? (int)$_GET['buyer_store_id']  : 0;
$search          = trim($_GET['search'] ?? '');

$conn = getDBConnection();

$productColumns = "
    p.id,
    p.id            AS product_id,
    p.product_name,
    p.product_name  AS productName,
    p.sku,
    p.barcode,
    p.category,
    p.price,
    p.price         AS costPrice,
    p.quantity,
    p.status,
    p.cost_price,
    p.store_id,
    p.store_id      AS storeId,
    COALESCE(p.image_url, '') AS image_url,
    COALESCE(p.image_url, '') AS imageUrl
";

try {
    // --- Convention A: vendor portal (supplier_id) ---------------------
    if ($supplier_id > 0) {
        // If this supplier is itself a registered store, show that catalog.
        $vs = $conn->prepare("SELECT vendor_store_id FROM suppliers WHERE supplier_id = ? LIMIT 1");
        // PDO bind
$vs->execute([$supplier_id]);$vs->execute();
        $linked = $vs->fetch(PDO::FETCH_ASSOC);
        

        $linkedStore = $linked ? (int)($linked['vendor_store_id'] ?? 0) : 0;

        if ($linkedStore > 0) {
            $where  = "p.store_id = ? AND p.status = 'active'";
            $types  = "i";
            $params = [$linkedStore];
        } else {
            // Fall back to products this supplier has actually supplied via POs.
            $where  = "p.id IN (
                          SELECT DISTINCT poi.product_id
                          FROM purchase_order_items poi
                          JOIN purchase_orders po ON po.po_id = poi.po_id
                          WHERE po.supplier_id = ?
                       )";
            $types  = "i";
            $params = [$supplier_id];
        }

        if ($search !== '') {
            $where   .= " AND (p.product_name LIKE ? OR p.sku LIKE ? OR p.category LIKE ?)";
            $like     = "%$search%";
            $params[] = $like; $params[] = $like; $params[] = $like;
            $types   .= "sss";
        }

        $stmt = $conn->prepare("SELECT $productColumns FROM products p WHERE $where ORDER BY p.product_name ASC");
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        

        sendResponse(true, 'Vendor products retrieved successfully', [
            'products' => $products,
            'items'    => $products,
        ]);
    }

    // --- Convention B: PO picker (vendor_store_id + buyer_store_id) ----
    if ($vendor_store_id <= 0 || $buyer_store_id <= 0) {
        sendResponse(false, 'supplier_id (or vendor_store_id and buyer_store_id) is required');
    }

    $check = $conn->prepare("
        SELECT supplier_id FROM suppliers
        WHERE store_id = ? AND vendor_store_id = ?
        LIMIT 1
    ");
    // PDO bind
$check->execute([$buyer_store_id, $vendor_store_id]);$check->execute();
    if ($check->rowCount() === 0) {
        sendResponse(false, 'No linked vendor found for this buyer');
    }
    

    $where  = "p.store_id = ? AND p.status = 'active'";
    $params = [$vendor_store_id];
    $types  = "i";

    if ($search !== '') {
        $where   .= " AND (p.product_name LIKE ? OR p.sku LIKE ? OR p.category LIKE ?)";
        $like     = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sss";
    }

    $stmt = $conn->prepare("SELECT $productColumns FROM products p WHERE $where ORDER BY p.product_name ASC");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

    sendResponse(true, 'Vendor products retrieved successfully', [
        'products' => $products,
        'items'    => $products,
    ]);

} catch (Exception $e) {
    logError('Get vendor products error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve vendor products');
}


?>
