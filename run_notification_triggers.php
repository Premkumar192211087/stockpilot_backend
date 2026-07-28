<?php
/**
 * Run recurring notification triggers.
 * Call this from cron, Windows Task Scheduler, or manually:
 *   http://localhost/api_legacy/run_notification_triggers.php?store_id=1
 */

require_once 'config.php';
require_once 'notification_helper.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    sendResponse(false, 'Invalid request method');
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? getJSONInput() : $_GET;
$store_id = isset($input['store_id']) ? (int)$input['store_id'] : 0;
$user_id = isset($input['user_id']) && $input['user_id'] !== '' ? (int)$input['user_id'] : null;

if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();
$created = [
    'low_stock'     => 0,
    'expiry'        => 0,
    'invoice'       => 0,
    'purchase_order'=> 0,
    'shipment'      => 0,
    'slow_mover'    => 0,
    'stale_po'      => 0,
];

function recentNotificationExists($conn, $store_id, $type, $title) {
    $stmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE store_id = ?
          AND type = ?
          AND title = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        LIMIT 1
    ");
    // PDO bind
$stmt->execute([$store_id, $type, $title]);$stmt->execute();
    $exists = $stmt->rowCount() > 0;
    
    return $exists;
}

function insertIfNotRecent($conn, $store_id, $user_id, $title, $message, $type, $data) {
    $type = normalizeNotificationType($type);
    if (recentNotificationExists($conn, $store_id, $type, $title)) {
        return false;
    }
    return insertNotification($conn, $store_id, $user_id, $title, $message, $type, $data);
}

try {
    $lowStockStmt = $conn->prepare("
        SELECT p.id, p.product_name, p.quantity, COALESCE(ia.min_stock_level, 10) AS min_stock_level
        FROM products p
        LEFT JOIN inventory_alerts ia
            ON ia.product_id = p.id
           AND ia.store_id = p.store_id
           AND ia.alert_enabled = 1
        WHERE p.store_id = ?
          AND p.status = 'active'
          AND p.quantity <= COALESCE(ia.min_stock_level, 10)
    ");
    // PDO bind
$lowStockStmt->execute([$store_id]);$lowStockStmt->execute();
    // PDO result ready in $lowStockStmt
    $lowStockRows = $lowStockStmt;
    while ($row = $lowStockRows->fetch(PDO::FETCH_ASSOC)) {
        $title = "Low Stock Alert: " . $row['product_name'];
        $message = "Only " . (int)$row['quantity'] . " units remaining (threshold: " . (int)$row['min_stock_level'] . ")";
        $data = [
            'item_id' => (int)$row['id'],
            'product_id' => (int)$row['id'],
            'item_name' => $row['product_name'],
            'current_stock' => (int)$row['quantity'],
            'threshold' => (int)$row['min_stock_level'],
        ];
        if (insertIfNotRecent($conn, $store_id, $user_id, $title, $message, 'low_stock', $data)) {
            $created['low_stock']++;
        }
    }
    

    $expiryStmt = $conn->prepare("
        SELECT id, product_id, product_name, batch_id, exp_date, quantity, DATEDIFF(exp_date, CURDATE()) AS days_remaining
        FROM batch_details
        WHERE store_id = ?
          AND quantity > 0
          AND exp_date IS NOT NULL
          AND DATEDIFF(exp_date, CURDATE()) BETWEEN 0 AND 7
    ");
    // PDO bind
$expiryStmt->execute([$store_id]);$expiryStmt->execute();
    // PDO result ready in $expiryStmt
    $expiryRows = $expiryStmt;
    while ($row = $expiryRows->fetch(PDO::FETCH_ASSOC)) {
        $title = ((int)$row['days_remaining'] <= 1 ? "URGENT: " : "") . "Batch Expiring: " . $row['product_name'];
        $message = (int)$row['quantity'] . " units expiring in " . (int)$row['days_remaining'] . " day(s) on " . $row['exp_date'];
        $data = [
            'batch_id' => $row['batch_id'],
            'item_id' => (int)$row['product_id'],
            'product_id' => (int)$row['product_id'],
            'item_name' => $row['product_name'],
            'expiry_date' => $row['exp_date'],
            'days_remaining' => (int)$row['days_remaining'],
            'quantity' => (int)$row['quantity'],
        ];
        if (insertIfNotRecent($conn, $store_id, $user_id, $title, $message, 'expiry', $data)) {
            $created['expiry']++;
        }
    }
    

    $invoiceStmt = $conn->prepare("
        SELECT invoice_id, invoice_number, due_date, status, DATEDIFF(due_date, CURDATE()) AS due_in_days
        FROM invoices
        WHERE store_id = ?
          AND due_date IS NOT NULL
          AND LOWER(status) NOT IN ('paid', 'cancelled')
          AND DATEDIFF(due_date, CURDATE()) <= 2
    ");
    // PDO bind
$invoiceStmt->execute([$store_id]);$invoiceStmt->execute();
    // PDO result ready in $invoiceStmt
    $invoiceRows = $invoiceStmt;
    while ($row = $invoiceRows->fetch(PDO::FETCH_ASSOC)) {
        $overdue = (int)$row['due_in_days'] < 0;
        $title = $overdue ? "Invoice Overdue: " . $row['invoice_number'] : "Invoice Due Soon: " . $row['invoice_number'];
        $message = $overdue
            ? "Invoice " . $row['invoice_number'] . " is overdue"
            : "Invoice " . $row['invoice_number'] . " is due in " . (int)$row['due_in_days'] . " day(s)";
        $data = [
            'invoice_id' => (int)$row['invoice_id'],
            'invoice_number' => $row['invoice_number'],
            'due_date' => $row['due_date'],
            'due_in_days' => (int)$row['due_in_days'],
            'status' => $overdue ? 'overdue' : 'due_soon',
        ];
        if (insertIfNotRecent($conn, $store_id, $user_id, $title, $message, 'invoice', $data)) {
            $created['invoice']++;
        }
    }
    

    $poStmt = $conn->prepare("
        SELECT po_id, po_number, order_date, expected_delivery_date, status
        FROM purchase_orders
        WHERE store_id = ?
          AND status IN ('pending', 'partial')
          AND (
              expected_delivery_date < CURDATE()
              OR order_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
          )
    ");
    // PDO bind
$poStmt->execute([$store_id]);$poStmt->execute();
    // PDO result ready in $poStmt
    $poRows = $poStmt;
    while ($row = $poRows->fetch(PDO::FETCH_ASSOC)) {
        $title = "Purchase Order Pending: " . $row['po_number'];
        $message = "Purchase order " . $row['po_number'] . " is still " . $row['status'];
        $data = [
            'po_id' => (int)$row['po_id'],
            'po_number' => $row['po_number'],
            'status' => $row['status'],
            'expected_delivery_date' => $row['expected_delivery_date'],
        ];
        if (insertIfNotRecent($conn, $store_id, $user_id, $title, $message, 'purchase_order', $data)) {
            $created['purchase_order']++;
        }
    }
    

    $shipmentStmt = $conn->prepare("
        SELECT shipment_id, shipment_number, tracking_number, estimated_delivery_date, status
        FROM shipments
        WHERE store_id = ?
          AND estimated_delivery_date IS NOT NULL
          AND estimated_delivery_date < CURDATE()
          AND status NOT IN ('delivered', 'returned', 'cancelled')
    ");
    // PDO bind
$shipmentStmt->execute([$store_id]);$shipmentStmt->execute();
    // PDO result ready in $shipmentStmt
    $shipmentRows = $shipmentStmt;
    while ($row = $shipmentRows->fetch(PDO::FETCH_ASSOC)) {
        $label = $row['tracking_number'] ?: $row['shipment_number'];
        $title = "Shipment Delayed: " . $label;
        $message = "Shipment " . $label . " has passed its estimated delivery date";
        $data = [
            'shipment_id' => (int)$row['shipment_id'],
            'shipment_number' => $row['shipment_number'],
            'tracking_number' => $row['tracking_number'],
            'status' => $row['status'],
            'estimated_delivery_date' => $row['estimated_delivery_date'],
        ];
        if (insertIfNotRecent($conn, $store_id, $user_id, $title, $message, 'shipment', $data)) {
            $created['shipment']++;
        }
    }
    

    // --- Stock Aging / Slow Mover Alert ---
    // Products not sold in the last 30 days that still have stock
    $slowStmt = $conn->prepare("
        SELECT p.id, p.product_name, p.quantity, p.cost_price,
               COALESCE(MAX(si.created_at), MAX(s.sale_date)) AS last_sold_at
        FROM products p
        LEFT JOIN sale_items si ON si.product_id = p.id
        LEFT JOIN sales s ON s.sale_id = si.sale_id AND s.store_id = p.store_id
        WHERE p.store_id = ?
          AND p.status = 'active'
          AND p.quantity > 0
        GROUP BY p.id
        HAVING last_sold_at IS NULL OR last_sold_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    // PDO bind
$slowStmt->execute([$store_id]);$slowStmt->execute();
    // PDO result ready in $slowStmt
    $slowRows = $slowStmt;
    while ($row = $slowRows->fetch(PDO::FETCH_ASSOC)) {
        $daysSince = $row['last_sold_at']
            ? (int)((time() - strtotime($row['last_sold_at'])) / 86400)
            : null;
        $sinceText = $daysSince !== null ? "last sold $daysSince days ago" : "never sold";
        $stockValue = (float)$row['quantity'] * (float)$row['cost_price'];

        $title   = "Slow Mover: {$row['product_name']}";
        $message = "{$row['quantity']} units in stock ($sinceText). Tied-up value: ₹" . number_format($stockValue, 2);
        $data    = [
            'product_id'   => (int)$row['id'],
            'product_name' => $row['product_name'],
            'quantity'     => (int)$row['quantity'],
            'days_since_sold' => $daysSince,
            'stock_value'  => $stockValue,
        ];
        if (insertIfNotRecent($conn, $store_id, $user_id, $title, $message, 'system', $data)) {
            $created['slow_mover']++;
        }
    }
    

    // --- Auto-Cancel Stale Draft POs (draft for 30+ days, zero receives) ---
    $staleStmt = $conn->prepare("
        SELECT po.po_id, po.po_number, po.order_date,
               DATEDIFF(CURDATE(), po.order_date) AS days_old
        FROM purchase_orders po
        WHERE po.store_id = ?
          AND po.status = 'draft'
          AND po.order_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND NOT EXISTS (
              SELECT 1 FROM purchase_order_items poi
              WHERE poi.po_id = po.po_id AND poi.quantity_received > 0
          )
    ");
    $staleStmt->execute([$store_id]);
    while ($row = $staleStmt->fetch(PDO::FETCH_ASSOC)) {
        // Cancel the PO
        $cancelStmt = $conn->prepare("UPDATE purchase_orders SET status = 'cancelled', updated_at = NOW() WHERE po_id = ?");
        $cancelStmt->execute([(int)$row['po_id']]);

        $title   = "Stale PO Auto-Cancelled: {$row['po_number']}";
        $message = "Draft PO {$row['po_number']} ({$row['days_old']} days old, no items received) was auto-cancelled.";
        $data    = [
            'po_id'     => (int)$row['po_id'],
            'po_number' => $row['po_number'],
            'days_old'  => (int)$row['days_old'],
        ];
        insertNotification($conn, $store_id, $user_id, $title, $message, 'purchase_order', $data);
        $created['stale_po']++;
    }
    

    sendResponse(true, 'Notification triggers completed', [
        'created'       => $created,
        'total_created' => array_sum($created),
    ]);
} catch (Exception $e) {
    logError('Run notification triggers error: ' . $e->getMessage());
    sendResponse(false, 'Failed to run notification triggers');
}


