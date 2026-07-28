<?php
/**
 * Customer Checkout API — Place order from cart
 * POST JSON: customer_id, store_id, shipping_address_id, payment_method, coupon_code (optional), notes
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'POST method required');
}

$data = getJSONInput();
validateRequired($data, ['customer_id', 'store_id', 'shipping_address_id', 'payment_method']);

$customer_id = (int)$data['customer_id'];
$store_id    = (int)$data['store_id'];
$address_id  = (int)$data['shipping_address_id'];
$payment     = sanitizeString($data['payment_method']);
$coupon_code = isset($data['coupon_code']) ? trim($data['coupon_code']) : '';
$notes       = isset($data['notes']) ? sanitizeString($data['notes']) : '';

$conn = getDBConnection();

try {
    // 1. Get cart items
    $stmt = $conn->prepare("
        SELECT ci.cart_item_id, ci.product_id, ci.quantity,
               p.product_name, p.price, p.quantity AS stock_available
        FROM cart_items ci
        JOIN carts c ON ci.cart_id = c.cart_id
        JOIN products p ON ci.product_id = p.id
        WHERE c.customer_id = ? AND c.store_id = ?
    ");
    // PDO bind
$stmt->execute([$customer_id, $store_id]);$stmt->execute();
    $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

    if (empty($cartItems)) {
        sendResponse(false, 'Cart is empty');
    }

    // 2. Validate stock
    foreach ($cartItems as $item) {
        if ($item['quantity'] > $item['stock_available']) {
            sendResponse(false, $item['product_name'] . ' has only ' . $item['stock_available'] . ' in stock');
        }
    }

    // 3. Calculate subtotal
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }

    // 4. Validate coupon if provided
    $discount = 0;
    $promo_id = null;
    if (!empty($coupon_code)) {
        $cs = $conn->prepare("
            SELECT * FROM promotions
            WHERE promo_code = ? AND store_id = ? AND is_active = 1
              AND NOW() BETWEEN start_date AND end_date
        ");
        // PDO bind
$cs->execute([$coupon_code, $store_id]);$cs->execute();
        $promo = $cs->fetch(PDO::FETCH_ASSOC);
        

        if (!$promo) {
            sendResponse(false, 'Invalid or expired coupon code');
        }

        if ($promo['usage_limit'] !== null && $promo['usage_count'] >= $promo['usage_limit']) {
            sendResponse(false, 'Coupon usage limit reached');
        }

        if ($subtotal < $promo['min_order_amount']) {
            sendResponse(false, 'Minimum order amount of ₹' . $promo['min_order_amount'] . ' required');
        }

        // Check per-customer usage
        $uc = $conn->prepare("SELECT COUNT(*) v FROM coupon_usage WHERE promo_id = ? AND customer_id = ?");
        // PDO bind
$uc->execute([$promo['promo_id'], $customer_id]);$uc->execute();
        $used = (int)$uc->fetch(PDO::FETCH_ASSOC)['v'];
        

        if ($used >= $promo['per_customer_limit']) {
            sendResponse(false, 'You have already used this coupon');
        }

        $promo_id = $promo['promo_id'];
        if ($promo['discount_type'] === 'percentage') {
            $discount = $subtotal * ($promo['discount_value'] / 100);
        } elseif ($promo['discount_type'] === 'fixed') {
            $discount = $promo['discount_value'];
        }
        if ($promo['max_discount_amount'] !== null && $discount > $promo['max_discount_amount']) {
            $discount = $promo['max_discount_amount'];
        }
    }

    $tax = 0;
    $shipping = 0;
    $total = $subtotal - $discount + $tax + $shipping;
    $order_number = 'ORD-' . date('Ymd') . '-' . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

    // 5. Transaction
    $conn->beginTransaction();

    // Insert order
    $stmt = $conn->prepare("
        INSERT INTO customer_orders (order_number, customer_id, store_id, subtotal, tax_amount,
            discount_amount, shipping_cost, total_amount, payment_method, payment_status,
            shipping_address_id, coupon_code, notes, estimated_delivery)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, CURRENT_DATE + INTERVAL '5 days')
    ");
    $coupon_val = !empty($coupon_code) ? $coupon_code : null;
    $notes_val  = !empty($notes) ? $notes : null;

    $stmt->execute([$order_number, $customer_id, $store_id, $subtotal, $tax,
        $discount, $shipping, $total, $payment, $address_id,
        $coupon_val, $notes_val]);
    $order_id = $conn->lastInsertId();
    

    // Insert order items + reduce stock
    $itemStmt = $conn->prepare("
        INSERT INTO customer_order_items (order_id, product_id, product_name, quantity, unit_price, total_price)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stockStmt = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
    $moveStmt = $conn->prepare("
        INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference_type,
            reference_id, unit_price, total_value, performed_by, notes)
        VALUES (?, ?, 'out', ?, 'sale', ?, ?, ?, ?, 'Customer order')
    ");

    foreach ($cartItems as $item) {
        $lineTotal = $item['price'] * $item['quantity'];
        // PDO bind
$itemStmt->execute([$order_id, $item['product_id'],
            $item['product_name'], $item['quantity'], $item['price'], $lineTotal]);$itemStmt->execute();

        // PDO bind
$stockStmt->execute([$item['quantity'], $item['product_id']]);$stockStmt->execute();

        // PDO bind
$moveStmt->execute([$item['product_id'], $store_id,
            $item['quantity'], $order_id, $item['price'], $lineTotal, $customer_id]);$moveStmt->execute();
    }
    
    
    

    // Insert status history
    $sh = $conn->prepare("INSERT INTO order_status_history (order_id, new_status, notes) VALUES (?, 'placed', 'Order placed by customer')");
    // PDO bind
$sh->execute([$order_id]);$sh->execute();
    

    // Coupon usage
    if ($promo_id) {
        $cu = $conn->prepare("INSERT INTO coupon_usage (promo_id, customer_id, order_id, discount_applied) VALUES (?, ?, ?, ?)");
        // PDO bind
$cu->execute([$promo_id, $customer_id, $order_id, $discount]);$cu->execute();
        
        $conn->query("UPDATE promotions SET usage_count = usage_count + 1 WHERE promo_id = $promo_id");
    }

    // Clear cart
    $conn->query("DELETE ci FROM cart_items ci JOIN carts c ON ci.cart_id = c.cart_id WHERE c.customer_id = $customer_id AND c.store_id = $store_id");

    // Add loyalty points (1 per ₹100)
    $points = (int)floor($total / 100);
    if ($points > 0) {
        $conn->query("UPDATE customers SET loyalty_points = loyalty_points + $points, last_purchase_date = NOW() WHERE customer_id = $customer_id");
    }

    // Notification
    $notif = $conn->prepare("INSERT INTO notifications (store_id, title, message, type, status, data)
        VALUES (?, 'New Customer Order', ?, 'sales', 'unread', ?)");
    $msg = "Order $order_number placed for " . number_format($total, 2);
    $ndata = json_encode(['order_id' => $order_id, 'order_number' => $order_number]);
    // PDO bind
$notif->execute([$store_id, $msg, $ndata]);$notif->execute();
    

    $conn->commit();

    sendResponse(true, 'Order placed successfully', [
        'order_id'     => $order_id,
        'order_number' => $order_number,
        'total'        => $total,
        'points_earned' => $points,
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    logError('customer_checkout error: ' . $e->getMessage());
    sendResponse(false, 'Checkout failed: ' . $e->getMessage());
}


?>
