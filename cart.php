<?php
/**
 * Shopping Cart API
 *
 * GET/POST ?action=get|add|update|remove|clear
 *
 * Common params: customer_id, store_id
 *
 * add:    product_id, quantity (default 1)
 * update: cart_item_id, quantity (0 = remove)
 * remove: cart_item_id
 * clear:  (no extra params)
 */

require_once 'config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if (!$action) {
    $json = getJSONInput();
    $action = $json['action'] ?? null;
}

// Collect params from GET, POST, or JSON body
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

/**
 * Get or create the cart for this customer+store.
 */
function getOrCreateCart($conn, $customer_id, $store_id) {
    $s = $conn->prepare("SELECT cart_id FROM carts WHERE customer_id = ? AND store_id = ? LIMIT 1");
    // PDO bind
$s->execute([$customer_id, $store_id]);$s->execute();
    $row = $s->fetch(PDO::FETCH_ASSOC);
    

    if ($row) return (int)$row['cart_id'];

    $ins = $conn->prepare("INSERT INTO carts (customer_id, store_id) VALUES (?, ?)");
    // PDO bind
$ins->execute([$customer_id, $store_id]);$ins->execute();
    $cart_id = $conn->lastInsertId();
    
    return $cart_id;
}

/**
 * Build full cart response with items, subtotal, count.
 */
function buildCartResponse($conn, $cart_id) {
    $stmt = $conn->prepare("
        SELECT ci.cart_item_id, ci.product_id, ci.quantity,
               p.product_name, p.price, p.image_url,
               p.quantity AS stock_available, p.sku,
               (ci.quantity * p.price) AS line_total
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.id
        WHERE ci.cart_id = ?
        ORDER BY ci.added_at ASC
    ");
    // PDO bind
$stmt->execute([$cart_id]);$stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

    $subtotal   = 0.0;
    $item_count = 0;
    foreach ($items as &$it) {
        $it['cart_item_id']    = (int)$it['cart_item_id'];
        $it['product_id']      = (int)$it['product_id'];
        $it['quantity']        = (int)$it['quantity'];
        $it['price']           = (float)$it['price'];
        $it['stock_available'] = (int)$it['stock_available'];
        $it['line_total']      = (float)$it['line_total'];
        $subtotal  += $it['line_total'];
        $item_count += $it['quantity'];
    }
    unset($it);

    return [
        'cart_id'    => $cart_id,
        'items'      => $items,
        'subtotal'   => round($subtotal, 2),
        'item_count' => $item_count,
    ];
}

try {
    switch ($action) {

        // ==============================================================
        case 'get':
        // ==============================================================
            $cart_id = getOrCreateCart($conn, $customer_id, $store_id);
            sendResponse(true, 'Cart loaded', buildCartResponse($conn, $cart_id));
            break;

        // ==============================================================
        case 'add':
        // ==============================================================
            $product_id = (int)($input['product_id'] ?? 0);
            $quantity   = max(1, (int)($input['quantity'] ?? 1));

            if ($product_id <= 0) {
                sendResponse(false, 'product_id is required');
            }

            // Validate product exists and is active
            $pStmt = $conn->prepare("
                SELECT id, quantity AS stock, status, is_visible_to_customers
                FROM products
                WHERE id = ? AND store_id = ?
            ");
            // PDO bind
$pStmt->execute([$product_id, $store_id]);$pStmt->execute();
            $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
            

            if (!$prod) {
                sendResponse(false, 'Product not found');
            }
            if ($prod['status'] !== 'active' || !(int)$prod['is_visible_to_customers']) {
                sendResponse(false, 'Product is not available');
            }
            if ((int)$prod['stock'] < $quantity) {
                sendResponse(false, 'Insufficient stock. Available: ' . $prod['stock']);
            }

            $cart_id = getOrCreateCart($conn, $customer_id, $store_id);

            // Upsert: if product already in cart, add quantity
            $existing = $conn->prepare("
                SELECT cart_item_id, quantity FROM cart_items
                WHERE cart_id = ? AND product_id = ?
            ");
            // PDO bind
$existing->execute([$cart_id, $product_id]);$existing->execute();
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            

            if ($row) {
                $newQty = (int)$row['quantity'] + $quantity;
                if ($newQty > (int)$prod['stock']) {
                    sendResponse(false, 'Cannot add more. Stock available: ' . $prod['stock']);
                }
                $upd = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?");
                // PDO bind
$upd->execute([$newQty, $row['cart_item_id']]);$upd->execute();
                
            } else {
                $ins = $conn->prepare("INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?)");
                // PDO bind
$ins->execute([$cart_id, $product_id, $quantity]);$ins->execute();
                
            }

            sendResponse(true, 'Item added to cart', buildCartResponse($conn, $cart_id));
            break;

        // ==============================================================
        case 'update':
        // ==============================================================
            $cart_item_id = (int)($input['cart_item_id'] ?? 0);
            $quantity     = (int)($input['quantity'] ?? 0);

            if ($cart_item_id <= 0) {
                sendResponse(false, 'cart_item_id is required');
            }

            $cart_id = getOrCreateCart($conn, $customer_id, $store_id);

            if ($quantity <= 0) {
                // Remove item
                $del = $conn->prepare("DELETE FROM cart_items WHERE cart_item_id = ? AND cart_id = ?");
                // PDO bind
$del->execute([$cart_item_id, $cart_id]);$del->execute();
                
            } else {
                // Validate stock
                $chk = $conn->prepare("
                    SELECT p.quantity AS stock
                    FROM cart_items ci
                    JOIN products p ON ci.product_id = p.id
                    WHERE ci.cart_item_id = ? AND ci.cart_id = ?
                ");
                // PDO bind
$chk->execute([$cart_item_id, $cart_id]);$chk->execute();
                $stockRow = $chk->fetch(PDO::FETCH_ASSOC);
                

                if (!$stockRow) {
                    sendResponse(false, 'Cart item not found');
                }
                if ($quantity > (int)$stockRow['stock']) {
                    sendResponse(false, 'Insufficient stock. Available: ' . $stockRow['stock']);
                }

                $upd = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ? AND cart_id = ?");
                // PDO bind
$upd->execute([$quantity, $cart_item_id, $cart_id]);$upd->execute();
                
            }

            sendResponse(true, 'Cart updated', buildCartResponse($conn, $cart_id));
            break;

        // ==============================================================
        case 'remove':
        // ==============================================================
            $cart_item_id = (int)($input['cart_item_id'] ?? 0);
            if ($cart_item_id <= 0) {
                sendResponse(false, 'cart_item_id is required');
            }

            $cart_id = getOrCreateCart($conn, $customer_id, $store_id);

            $del = $conn->prepare("DELETE FROM cart_items WHERE cart_item_id = ? AND cart_id = ?");
            // PDO bind
$del->execute([$cart_item_id, $cart_id]);$del->execute();
            

            sendResponse(true, 'Item removed', buildCartResponse($conn, $cart_id));
            break;

        // ==============================================================
        case 'clear':
        // ==============================================================
            $cart_id = getOrCreateCart($conn, $customer_id, $store_id);

            $clr = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
            // PDO bind
$clr->execute([$cart_id]);$clr->execute();
            

            sendResponse(true, 'Cart cleared', buildCartResponse($conn, $cart_id));
            break;

        default:
            sendResponse(false, "Unknown action: $action");
    }

} catch (Exception $e) {
    logError('cart error: ' . $e->getMessage());
    sendResponse(false, 'Cart operation failed: ' . $e->getMessage());
}


?>
