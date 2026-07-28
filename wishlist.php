<?php
/**
 * Wishlist API
 *
 * GET/POST ?action=get|toggle
 *
 * Common params: customer_id, store_id
 *
 * toggle: product_id  — adds if missing, removes if present.
 */

require_once 'config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if (!$action) {
    $json = getJSONInput();
    $action = $json['action'] ?? null;
}

$input = array_merge($_GET, $_POST);
if (empty($input['customer_id'])) {
    $json = $json ?? getJSONInput();
    $input = array_merge($input, $json);
}

$customer_id = (int)($input['customer_id'] ?? 0);
$store_id    = (int)($input['store_id'] ?? 0);

if ($customer_id <= 0 || $store_id <= 0 || !$action) {
    sendResponse(false, 'customer_id, store_id, and action are required');
}

$conn = getDBConnection();

try {
    switch ($action) {

        // ==============================================================
        case 'get':
        // ==============================================================
            $stmt = $conn->prepare("
                SELECT w.wishlist_id, w.product_id, w.created_at,
                       p.product_name, p.price, p.image_url, p.category,
                       p.quantity AS stock_available, p.sku, p.status
                FROM wishlists w
                JOIN products p ON w.product_id = p.id
                WHERE w.customer_id = ? AND w.store_id = ?
                ORDER BY w.created_at DESC
            ");
            // PDO bind
$stmt->execute([$customer_id, $store_id]);$stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            

            foreach ($items as &$it) {
                $it['wishlist_id']     = (int)$it['wishlist_id'];
                $it['product_id']      = (int)$it['product_id'];
                $it['price']           = (float)$it['price'];
                $it['stock_available'] = (int)$it['stock_available'];
            }
            unset($it);

            sendResponse(true, 'Wishlist loaded', [
                'items'      => $items,
                'item_count' => count($items),
            ]);
            break;

        // ==============================================================
        case 'toggle':
        // ==============================================================
            $product_id = (int)($input['product_id'] ?? 0);
            if ($product_id <= 0) {
                sendResponse(false, 'product_id is required');
            }

            // Check if already wishlisted
            $chk = $conn->prepare("
                SELECT wishlist_id FROM wishlists
                WHERE customer_id = ? AND store_id = ? AND product_id = ?
            ");
            // PDO bind
$chk->execute([$customer_id, $store_id, $product_id]);$chk->execute();
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            

            if ($existing) {
                // Remove
                $del = $conn->prepare("DELETE FROM wishlists WHERE wishlist_id = ?");
                // PDO bind
$del->execute([$existing['wishlist_id']]);$del->execute();
                
                $is_wishlisted = false;
                $msg = 'Removed from wishlist';
            } else {
                // Add — validate product exists
                $pChk = $conn->prepare("
                    SELECT id FROM products
                    WHERE id = ? AND store_id = ? AND status = 'active' AND is_visible_to_customers = 1
                ");
                // PDO bind
$pChk->execute([$product_id, $store_id]);$pChk->execute();
                if (!$pChk->fetch(PDO::FETCH_ASSOC)) {
                    
                    sendResponse(false, 'Product not found or not available');
                }
                

                $ins = $conn->prepare("
                    INSERT INTO wishlists (customer_id, store_id, product_id) VALUES (?, ?, ?)
                ");
                // PDO bind
$ins->execute([$customer_id, $store_id, $product_id]);$ins->execute();
                
                $is_wishlisted = true;
                $msg = 'Added to wishlist';
            }

            sendResponse(true, $msg, [
                'product_id'     => $product_id,
                'is_wishlisted'  => $is_wishlisted,
            ]);
            break;

        default:
            sendResponse(false, "Unknown action: $action");
    }

} catch (Exception $e) {
    logError('wishlist error: ' . $e->getMessage());
    sendResponse(false, 'Wishlist operation failed: ' . $e->getMessage());
}


?>
