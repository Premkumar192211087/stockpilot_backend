<?php
/**
 * Admin: Update customer order status
 * PUT/POST JSON: order_id, new_status, notes (optional), store_id
 */
require_once 'config.php';

$data = getJSONInput();
if (empty($data)) {
    $data = $_POST;
}
validateRequired($data, ['order_id', 'new_status', 'store_id']);

$order_id   = (int)$data['order_id'];
$new_status = sanitizeString($data['new_status']);
$notes      = isset($data['notes']) ? sanitizeString($data['notes']) : '';
$store_id   = (int)$data['store_id'];

$valid = ['placed','confirmed','processing','packed','shipped','delivered','cancelled','returned'];
if (!in_array($new_status, $valid)) {
    sendResponse(false, 'Invalid status: ' . $new_status);
}

$conn = getDBConnection();

try {
    // Get current order
    $s = $conn->prepare("SELECT order_id, status, customer_id, order_number, total_amount FROM customer_orders WHERE order_id = ? AND store_id = ?");
    // PDO bind
$s->execute([$order_id, $store_id]);$s->execute();
    $order = $s->fetch(PDO::FETCH_ASSOC);
    

    if (!$order) {
        sendResponse(false, 'Order not found');
    }

    $old_status = $order['status'];
    if ($old_status === $new_status) {
        sendResponse(false, 'Order is already in ' . $new_status . ' status');
    }

    $conn->beginTransaction();

    // Update order status
    $upd = $conn->prepare("UPDATE customer_orders SET status = ?, updated_at = NOW() WHERE order_id = ?");
    // PDO bind
$upd->execute([$new_status, $order_id]);$upd->execute();
    

    // Record status history
    $sh = $conn->prepare("INSERT INTO order_status_history (order_id, old_status, new_status, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
    // PDO bind
$sh->execute([$order_id, $old_status, $new_status, $notes]);$sh->execute();
    

    // Notify customer when shipped or delivered
    if (in_array($new_status, ['shipped', 'delivered', 'confirmed'])) {
        $msgs = [
            'confirmed'  => 'Your order ' . $order['order_number'] . ' has been confirmed.',
            'shipped'    => 'Your order ' . $order['order_number'] . ' has been shipped! It\'s on the way.',
            'delivered'  => 'Your order ' . $order['order_number'] . ' has been delivered. Thank you!',
        ];
        $msg = $msgs[$new_status] ?? 'Your order status has been updated to ' . $new_status;
        $ndata = json_encode(['order_id' => $order_id, 'order_number' => $order['order_number'], 'status' => $new_status]);
        $n = $conn->prepare("INSERT INTO notifications (store_id, title, message, type, status, data) VALUES (?, 'Order Update', ?, 'sales', 'unread', ?)");
        // PDO bind
$n->execute([$store_id, $msg, $ndata]);$n->execute();
        
    }

    $conn->commit();

    // Return updated order
    $s2 = $conn->prepare("SELECT co.*, a.address_line1, a.city, a.state, a.postal_code FROM customer_orders co LEFT JOIN addresses a ON co.shipping_address_id = a.address_id WHERE co.order_id = ?");
    // PDO bind
$s2->execute([$order_id]);$s2->execute();
    $updated = $s2->fetch(PDO::FETCH_ASSOC);
    

    sendResponse(true, 'Order status updated to ' . $new_status, $updated);

} catch (Exception $e) {
    $conn->rollBack();
    logError('admin_update_order_status: ' . $e->getMessage());
    sendResponse(false, 'Failed to update order: ' . $e->getMessage());
}


?>
