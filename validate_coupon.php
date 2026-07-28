<?php
/**
 * Validate Coupon API
 * POST JSON: coupon_code, store_id, customer_id, cart_total
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'POST method required');

$data = getJSONInput();
validateRequired($data, ['coupon_code', 'store_id', 'customer_id', 'cart_total']);

$code        = trim($data['coupon_code']);
$store_id    = (int)$data['store_id'];
$customer_id = (int)$data['customer_id'];
$cart_total   = (float)$data['cart_total'];

$conn = getDBConnection();

try {
    $s = $conn->prepare("
        SELECT * FROM promotions
        WHERE promo_code = ? AND store_id = ? AND is_active = 1
          AND NOW() BETWEEN start_date AND end_date
    ");
    // PDO bind
$s->execute([$code, $store_id]);$s->execute();
    $promo = $s->fetch(PDO::FETCH_ASSOC);
    

    if (!$promo) sendResponse(false, 'Invalid or expired coupon code');

    if ($promo['usage_limit'] !== null && $promo['usage_count'] >= $promo['usage_limit']) {
        sendResponse(false, 'Coupon usage limit reached');
    }

    if ($cart_total < $promo['min_order_amount']) {
        sendResponse(false, 'Minimum order amount of ₹' . number_format($promo['min_order_amount'], 2) . ' required');
    }

    // Per-customer limit
    $u = $conn->prepare("SELECT COUNT(*) v FROM coupon_usage WHERE promo_id = ? AND customer_id = ?");
    // PDO bind
$u->execute([$promo['promo_id'], $customer_id]);$u->execute();
    $used = (int)$u->fetch(PDO::FETCH_ASSOC)['v'];
    

    if ($used >= $promo['per_customer_limit']) {
        sendResponse(false, 'You have already used this coupon');
    }

    // Calculate discount
    $discount = 0;
    if ($promo['discount_type'] === 'percentage') {
        $discount = $cart_total * ($promo['discount_value'] / 100);
    } elseif ($promo['discount_type'] === 'fixed') {
        $discount = $promo['discount_value'];
    } elseif ($promo['discount_type'] === 'free_shipping') {
        $discount = 0;
    }

    if ($promo['max_discount_amount'] !== null && $discount > $promo['max_discount_amount']) {
        $discount = $promo['max_discount_amount'];
    }

    sendResponse(true, 'Coupon applied', [
        'valid'           => true,
        'promo_name'      => $promo['promo_name'],
        'discount_type'   => $promo['discount_type'],
        'discount_value'  => (float)$promo['discount_value'],
        'discount_amount' => round($discount, 2),
        'min_order_amount' => (float)$promo['min_order_amount'],
    ]);

} catch (Exception $e) {
    logError('validate_coupon error: ' . $e->getMessage());
    sendResponse(false, 'Coupon validation failed');
}

?>
