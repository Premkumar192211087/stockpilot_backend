<?php
/**
 * Customer Order Detail API
 * GET ?order_id=X&customer_id=Y&store_id=Z
 */
require_once 'config.php';

$order_id    = (int)($_GET['order_id'] ?? 0);
$customer_id = (int)($_GET['customer_id'] ?? 0);
$store_id    = (int)($_GET['store_id'] ?? 0);

if ($order_id <= 0 || $customer_id <= 0) sendResponse(false, 'order_id and customer_id required');

$conn = getDBConnection();

try {
    // Order
    $s = $conn->prepare("SELECT * FROM customer_orders WHERE order_id = ? AND customer_id = ?");
    // PDO bind
$s->execute([$order_id, $customer_id]);$s->execute();
    $order = $s->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) sendResponse(false, 'Order not found');

    // Items
    $i = $conn->prepare("
        SELECT oi.*, COALESCE(p.image_url,'') AS image_url
        FROM customer_order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    // PDO bind
$i->execute([$order_id]);$i->execute();
    $items = $i->fetchAll(PDO::FETCH_ASSOC);
    

    // Status history
    $h = $conn->prepare("SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC");
    // PDO bind
$h->execute([$order_id]);$h->execute();
    $history = $h->fetchAll(PDO::FETCH_ASSOC);
    

    // Address
    $address = null;
    if ($order['shipping_address_id']) {
        $a = $conn->prepare("SELECT * FROM addresses WHERE address_id = ?");
        // PDO bind
$a->execute([$order['shipping_address_id']]);$a->execute();
        $address = $a->fetch(PDO::FETCH_ASSOC);
        
    }

    // Shipment
    $sh = $conn->prepare("
        SELECT shipment_id, shipment_number, tracking_number, carrier_name, status,
               estimated_delivery_date, actual_delivery_date
        FROM shipments WHERE order_id = ? AND order_type = 'sales_order' AND store_id = ? LIMIT 1
    ");
    // PDO bind
$sh->execute([$order_id, $store_id]);$sh->execute();
    $shipment = $sh->fetch(PDO::FETCH_ASSOC);
    

    sendResponse(true, 'Order detail loaded', [
        'order'          => $order,
        'items'          => $items,
        'status_history' => $history,
        'address'        => $address,
        'shipment'       => $shipment,
    ]);
} catch (Exception $e) {
    logError('customer_order_detail error: ' . $e->getMessage());
    sendResponse(false, 'Failed to load order detail');
}

?>
