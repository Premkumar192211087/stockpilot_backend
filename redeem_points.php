<?php
/**
 * Redeem Loyalty Points
 * POST JSON: customer_id, store_id, order_id, points_to_redeem, order_total
 * 100 points = ₹10 discount (0.10 per point)
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'POST required');

$data = getJSONInput();
validateRequired($data, ['customer_id', 'store_id', 'order_id', 'points_to_redeem', 'order_total']);

$customer_id      = (int)$data['customer_id'];
$store_id         = (int)$data['store_id'];
$order_id         = (int)$data['order_id'];
$points_to_redeem = (int)$data['points_to_redeem'];
$order_total      = (float)$data['order_total'];

if ($points_to_redeem <= 0) sendResponse(false, 'points_to_redeem must be positive');

$conn = getDBConnection();

try {
    // Get current balance
    $s = $conn->prepare("SELECT loyalty_points FROM customers WHERE customer_id = ?");
    // PDO bind
$s->execute([$customer_id]);$s->execute();
    $cust = $s->fetch(PDO::FETCH_ASSOC);
    

    if (!$cust) sendResponse(false, 'Customer not found');

    $balance = (int)$cust['loyalty_points'];

    if ($points_to_redeem > $balance) {
        sendResponse(false, 'Insufficient points. Available: ' . $balance);
    }

    $discount = round($points_to_redeem * 0.10, 2);
    $max_discount = round($order_total * 0.50, 2); // Max 50% of order

    if ($discount > $max_discount) {
        $discount         = $max_discount;
        $points_to_redeem = (int)ceil($discount / 0.10);
    }

    $new_balance = $balance - $points_to_redeem;

    $conn->beginTransaction();

    // Deduct points
    $u = $conn->prepare("UPDATE customers SET loyalty_points = ? WHERE customer_id = ?");
    // PDO bind
$u->execute([$new_balance, $customer_id]);$u->execute();
    

    // Log transaction
    $desc = 'Redeemed for Order #' . $order_id;
    $l = $conn->prepare("INSERT INTO loyalty_transactions (customer_id, store_id, txn_type, points, reference_type, reference_id, description, balance_after) VALUES (?, ?, 'redeemed', ?, 'order', ?, ?, ?)");
    $neg = -$points_to_redeem;
    // PDO bind
$l->execute([$customer_id, $store_id, $neg, $order_id, $desc, $new_balance]);$l->execute();
    

    // Update order discount and total
    $od = $conn->prepare("UPDATE customer_orders SET discount_amount = discount_amount + ?, total_amount = total_amount - ? WHERE order_id = ? AND customer_id = ?");
    // PDO bind
$od->execute([$discount, $discount, $order_id, $customer_id]);$od->execute();
    

    $conn->commit();

    sendResponse(true, 'Points redeemed successfully', [
        'points_redeemed' => $points_to_redeem,
        'discount_applied' => $discount,
        'new_balance'      => $new_balance,
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    logError('redeem_points: ' . $e->getMessage());
    sendResponse(false, 'Redemption failed: ' . $e->getMessage());
}


?>
