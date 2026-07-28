<?php
/**
 * Customer Cancel Order API
 * POST JSON: order_id, customer_id, store_id, reason
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'POST method required');

$data = getJSONInput();
validateRequired($data, ['order_id', 'customer_id', 'store_id']);

$order_id    = (int)$data['order_id'];
$customer_id = (int)$data['customer_id'];
$store_id    = (int)$data['store_id'];
$reason      = sanitizeString($data['reason'] ?? 'Cancelled by customer');

$conn = getDBConnection();

try {
    $s = $conn->prepare("SELECT * FROM customer_orders WHERE order_id = ? AND customer_id = ? AND store_id = ?");
    // PDO bind
$s->execute([$order_id, $customer_id, $store_id]);$s->execute();
    $order = $s->fetch(PDO::FETCH_ASSOC);
    

    if (!$order) sendResponse(false, 'Order not found');
    if (!in_array($order['status'], ['placed', 'confirmed'])) {
        sendResponse(false, 'Order cannot be cancelled at this stage');
    }

    $conn->beginTransaction();

    // Update order status
    $u = $conn->prepare("UPDATE customer_orders SET status = 'cancelled', payment_status = IF(payment_status='paid','refunded',payment_status), updated_at = NOW() WHERE order_id = ?");
    // PDO bind
$u->execute([$order_id]);$u->execute();
    

    // Status history
    $h = $conn->prepare("INSERT INTO order_status_history (order_id, old_status, new_status, notes) VALUES (?, ?, 'cancelled', ?)");
    // PDO bind
$h->execute([$order_id, $order['status'], $reason]);$h->execute();
    

    // Restore stock
    $items = $conn->prepare("SELECT product_id, quantity FROM customer_order_items WHERE order_id = ?");
    // PDO bind
$items->execute([$order_id]);$items->execute();
    $orderItems = $items->fetchAll(PDO::FETCH_ASSOC);
    

    $restore = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
    $move = $conn->prepare("INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference_type, reference_id, performed_by, notes) VALUES (?, ?, 'in', ?, 'return', ?, ?, 'Order cancelled')");

    foreach ($orderItems as $item) {
        // PDO bind
$restore->execute([$item['quantity'], $item['product_id']]);$restore->execute();
        // PDO bind
$move->execute([$item['product_id'], $store_id, $item['quantity'], $order_id, $customer_id]);$move->execute();
    }
    
    

    // Notification
    $n = $conn->prepare("INSERT INTO notifications (store_id, title, message, type, status, data) VALUES (?, 'Order Cancelled', ?, 'sales', 'unread', ?)");
    $msg = "Order {$order['order_number']} was cancelled by customer";
    $ndata = json_encode(['order_id' => $order_id]);
    // PDO bind
$n->execute([$store_id, $msg, $ndata]);$n->execute();
    

    $conn->commit();
    sendResponse(true, 'Order cancelled successfully');
} catch (Exception $e) {
    $conn->rollBack();
    logError('customer_cancel_order error: ' . $e->getMessage());
    sendResponse(false, 'Failed to cancel order');
}

?>
