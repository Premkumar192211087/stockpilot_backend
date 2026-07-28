<?php
/**
 * Notification Helper Functions
 * Reusable functions for creating notifications across all backend APIs
 */

/**
 * Insert a notification into the database
 */
if (!function_exists('normalizeNotificationType')) {
    function normalizeNotificationType($type) {
        $type = strtolower(trim((string)$type));
        if ($type === 'sales') return 'sale';
        return $type;
    }
}

function insertNotification($conn, $store_id, $user_id, $title, $message, $type, $data = null) {
    try {
        $type = normalizeNotificationType($type);
        $data_json = null;
        if ($data !== null && is_array($data)) {
            $data_json = json_encode($data);
        }
        
        $stmt = $conn->prepare("INSERT INTO notifications (store_id, user_id, title, message, type, status, data, created_at) VALUES (?, ?, ?, ?, ?, 'unread', ?, NOW())");
        
        if (!$stmt) {
            error_log("Notification prepare failed: " . $conn->error);
            return false;
        }
        
        // PDO bind
$stmt->execute([$store_id, $user_id, $title, $message, $type, $data_json]);$result = $stmt->execute();
        
        if (!$result) {
            error_log("Notification insert failed: " . $stmt->error);
        }
        
        
        
        // ALSO send FCM push notification
        if ($result) {
            sendFCMPush($conn, $store_id, $user_id, $title, $message, $type, $data);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Notification exception: " . $e->getMessage());
        return false;
    }
}

function notifyInvoiceCreated($conn, $store_id, $user_id, $invoice_id, $invoice_number, $amount) {
    $title = "New Invoice #$invoice_number";
    $message = "Invoice for amount $" . number_format($amount, 2) . " has been created";
    $data = ["invoice_id" => $invoice_id, "invoice_number" => $invoice_number, "amount" => $amount];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "invoice", $data);
}

function notifySaleCreated($conn, $store_id, $user_id, $sale_id, $invoice_number, $amount) {
    $title = "New Sale #$invoice_number";
    $message = "New sale for $" . number_format($amount, 2);
    $data = ["sale_id" => $sale_id, "invoice_number" => $invoice_number, "amount" => $amount];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "sale", $data);
}

function notifyShipmentCreated($conn, $store_id, $user_id, $shipment_id, $tracking_number) {
    $title = "New Shipment #$tracking_number";
    $message = "A new shipment has been created";
    $data = ["shipment_id" => $shipment_id, "tracking_number" => $tracking_number];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "shipment", $data);
}

function notifyShipmentStatusUpdate($conn, $store_id, $user_id, $shipment_id, $tracking_number, $status) {
    $title = "Shipment #$tracking_number " . ucfirst($status);
    $message = "Shipment status updated to: " . ucfirst($status);
    $data = ["shipment_id" => $shipment_id, "tracking_number" => $tracking_number, "status" => $status];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "shipment", $data);
}

function notifyCustomerAdded($conn, $store_id, $user_id, $customer_id, $customer_name) {
    $title = "New Customer Registered";
    $message = "$customer_name has been added to your customer list";
    $data = ["customer_id" => $customer_id, "customer_name" => $customer_name];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "customer", $data);
}

function notifyLowStock($conn, $store_id, $user_id, $item_id, $item_name, $current_stock, $threshold) {
    $title = "Low Stock Alert: $item_name";
    $message = "Only $current_stock units remaining (threshold: $threshold)";
    $data = ["item_id" => $item_id, "item_name" => $item_name, "current_stock" => $current_stock, "threshold" => $threshold];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "low_stock", $data);
}

function notifyPurchaseOrder($conn, $store_id, $user_id, $po_id, $po_number, $vendor_name, $total_amount) {
    $title = "Purchase Order #$po_number";
    $message = "PO for $vendor_name - $" . number_format($total_amount, 2);
    $data = ["po_id" => $po_id, "po_number" => $po_number, "vendor_name" => $vendor_name, "total_amount" => $total_amount];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "purchase_order", $data);
}

function notifyPurchaseOrderStatusUpdate($conn, $store_id, $user_id, $po_id, $po_number, $new_status) {
    $title = "PO #$po_number Status Updated";
    $message = "Purchase order status changed to: " . ucfirst(str_replace('_', ' ', $new_status));
    $data = ["po_id" => $po_id, "po_number" => $po_number, "status" => $new_status];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "purchase_order", $data);
}

/**
 * Create payment received notification
 */
function notifyPaymentReceived($conn, $store_id, $user_id, $payment_id, $invoice_number, $amount, $payment_method) {
    $title = "Payment Received: Invoice #$invoice_number";
    $message = "Payment of $" . number_format($amount, 2) . " received via " . ucfirst($payment_method);
    $data = ["payment_id" => $payment_id, "invoice_number" => $invoice_number, "amount" => $amount, "payment_method" => $payment_method];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "payment", $data);
}

function notifyPaymentMade($conn, $store_id, $user_id, $payment_id, $reference_number, $amount, $payment_method) {
    $title = "Payment Made";
    $message = "Payment of $" . number_format($amount, 2) . " made via " . ucfirst($payment_method);
    $data = ["payment_id" => $payment_id, "reference_number" => $reference_number, "amount" => $amount, "payment_method" => $payment_method];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "payment", $data);
}

/**
 * Create inventory adjustment notification
 */
function notifyInventoryAdjustment($conn, $store_id, $user_id, $adjustment_id, $item_name, $quantity_change, $reason) {
    $action = $quantity_change > 0 ? "increased" : "decreased";
    $title = "Stock Adjustment: $item_name";
    $message = "Inventory $action by " . abs($quantity_change) . " units. Reason: $reason";
    $data = ["adjustment_id" => $adjustment_id, "item_name" => $item_name, "quantity_change" => $quantity_change, "reason" => $reason];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "adjustment", $data);
}

/**
 * Create batch expiry warning notification
 */
function notifyBatchExpiry($conn, $store_id, $user_id, $batch_id, $item_name, $expiry_date, $days_remaining, $quantity) {
    $urgency = $days_remaining <= 1 ? "URGENT: " : "";
    $title = $urgency . "Batch Expiring: $item_name";
    $message = "$quantity units expiring in $days_remaining day(s) on $expiry_date";
    $data = ["batch_id" => $batch_id, "item_name" => $item_name, "expiry_date" => $expiry_date, "days_remaining" => $days_remaining, "quantity" => $quantity];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "expiry", $data);
}

/**
 * Create sales return notification
 */
function notifySalesReturn($conn, $store_id, $user_id, $return_id, $original_sale_id, $amount, $reason) {
    $title = "Sales Return: Sale #$original_sale_id";
    $message = "Return of $" . number_format($amount, 2) . " - Reason: $reason";
    $data = ["return_id" => $return_id, "sale_id" => $original_sale_id, "amount" => $amount, "reason" => $reason];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "return", $data);
}

function notifyInvoiceStatusUpdate($conn, $store_id, $user_id, $invoice_id, $invoice_number, $status, $due_date = null) {
    $label = ucfirst(str_replace('_', ' ', $status));
    $title = "Invoice #$invoice_number $label";
    $message = "Invoice status updated to $label";
    if ($status === 'overdue') {
        $message = "Invoice #$invoice_number is overdue";
    } elseif ($status === 'paid') {
        $message = "Invoice #$invoice_number has been paid";
    }
    $data = ["invoice_id" => $invoice_id, "invoice_number" => $invoice_number, "status" => $status];
    if ($due_date) $data["due_date"] = $due_date;
    return insertNotification($conn, $store_id, $user_id, $title, $message, "invoice", $data);
}

function notifyBillGenerated($conn, $store_id, $user_id, $bill_id, $bill_number, $po_number, $amount) {
    $title = "Bill Generated: $bill_number";
    $message = "Vendor bill for ₹" . number_format($amount, 2) . " auto-created from $po_number";
    $data = ["bill_id" => $bill_id, "bill_number" => $bill_number, "po_number" => $po_number, "amount" => $amount];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "bill", $data);
}

function notifyBillStatusUpdate($conn, $store_id, $user_id, $bill_id, $bill_number, $status) {
    $label = ucfirst(str_replace('_', ' ', $status));
    $title = "Bill #$bill_number $label";
    $message = "Bill status updated to $label";
    $data = ["bill_id" => $bill_id, "bill_number" => $bill_number, "status" => $status];
    return insertNotification($conn, $store_id, $user_id, $title, $message, "bill", $data);
}

function notifySystem($conn, $store_id, $user_id, $title, $message, $data = null) {
    return insertNotification($conn, $store_id, $user_id, $title, $message, "system", $data);
}

/**
 * Send FCM push notification to user's device
 * Uses send_notification.php's FCM v1 API
 */
function sendFCMPush($conn, $store_id, $user_id, $title, $message, $type, $data) {
    try {
        // Get FCM tokens for target user(s)
        if ($user_id) {
            // Send to specific user
            $stmt = $conn->prepare("SELECT token FROM fcm_tokens WHERE user_id = ? AND store_id = ? AND is_active = 1 ORDER BY updated_at DESC LIMIT 1");
            // PDO bind
$stmt->execute([$user_id, $store_id]);} else {
            // Broadcast to all users in store
            $stmt = $conn->prepare("SELECT ft.token FROM fcm_tokens ft INNER JOIN (SELECT user_id, MAX(updated_at) AS updated_at FROM fcm_tokens WHERE store_id = ? AND is_active = 1 GROUP BY user_id) latest ON latest.user_id = ft.user_id AND latest.updated_at = ft.updated_at WHERE ft.store_id = ? AND ft.is_active = 1");
            // PDO bind
$stmt->execute([$store_id, $store_id]);}
        
        $stmt->execute();
        // PDO result ready in $stmt
    $result = $stmt;
        $tokens = [];
        
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $tokens[] = $row['token'];
        }
        
        
        // If no tokens, skip FCM
        if (empty($tokens)) {
            return;
        }
        
        // Load send_notification functions
        require_once __DIR__ . '/send_notification.php';
        
        // Send to each device
        foreach ($tokens as $token) {
            sendFCMv1Notification($token, $title, $message, $type, $data);
        }
        
    } catch (Exception $e) {
        // Don't fail notification if FCM fails
        error_log("FCM push failed: " . $e->getMessage());
    }
}
?>


