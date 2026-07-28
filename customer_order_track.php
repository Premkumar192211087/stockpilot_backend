<?php
/**
 * Customer Order Tracking
 * GET ?order_id=X&customer_id=X
 */
require_once 'config.php';

$order_id    = (int)($_GET['order_id'] ?? 0);
$customer_id = (int)($_GET['customer_id'] ?? 0);

if ($order_id <= 0 || $customer_id <= 0) {
    sendResponse(false, 'order_id and customer_id required');
}

$conn = getDBConnection();

try {
    // Get order details
    $s = $conn->prepare("
        SELECT co.*,
               a.full_name AS ship_name, a.address_line1, a.address_line2,
               a.city, a.state, a.postal_code, a.phone AS ship_phone
        FROM customer_orders co
        LEFT JOIN addresses a ON co.shipping_address_id = a.address_id
        WHERE co.order_id = ? AND co.customer_id = ?
    ");
    // PDO bind
$s->execute([$order_id, $customer_id]);$s->execute();
    $order = $s->fetch(PDO::FETCH_ASSOC);
    

    if (!$order) sendResponse(false, 'Order not found');

    // Get order items
    $i = $conn->prepare("
        SELECT oi.*, p.image_url
        FROM customer_order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    // PDO bind
$i->execute([$order_id]);$i->execute();
    $items = $i->fetchAll(PDO::FETCH_ASSOC);
    

    // Get status history
    $h = $conn->prepare("
        SELECT * FROM order_status_history
        WHERE order_id = ?
        ORDER BY created_at ASC
    ");
    // PDO bind
$h->execute([$order_id]);$h->execute();
    $history = $h->fetchAll(PDO::FETCH_ASSOC);
    

    // Status flow for progress indicator
    $status_flow = ['placed', 'confirmed', 'processing', 'packed', 'shipped', 'delivered'];
    $current_idx = array_search($order['status'], $status_flow);

    $order['items']         = $items;
    $order['status_history'] = $history;
    $order['status_flow']   = $status_flow;
    $order['current_step']  = $current_idx !== false ? $current_idx : -1;
    $order['subtotal']      = (float)$order['subtotal'];
    $order['total_amount']  = (float)$order['total_amount'];
    $order['tax_amount']    = (float)$order['tax_amount'];
    $order['discount_amount'] = (float)$order['discount_amount'];

    sendResponse(true, 'Order tracking loaded', $order);

} catch (Exception $e) {
    logError('customer_order_track: ' . $e->getMessage());
    sendResponse(false, 'Failed: ' . $e->getMessage());
}


?>
