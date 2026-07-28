<?php
/**
 * Record UPI Payment Transaction
 * POST JSON: order_id, customer_id, store_id, upi_transaction_id, status, amount, response_code, response_message
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'POST required');

$data = getJSONInput();
validateRequired($data, ['order_id', 'customer_id', 'store_id', 'status', 'amount']);

$order_id    = (int)$data['order_id'];
$customer_id = (int)$data['customer_id'];
$store_id    = (int)$data['store_id'];
$upi_txn_id  = sanitizeString($data['upi_transaction_id'] ?? '');
$status      = sanitizeString($data['status']);
$amount      = (float)$data['amount'];
$resp_code   = sanitizeString($data['response_code'] ?? '');
$resp_msg    = sanitizeString($data['response_message'] ?? '');

if (!in_array($status, ['initiated', 'success', 'failed', 'pending'])) {
    sendResponse(false, 'Invalid status');
}

$conn = getDBConnection();

try {
    // Verify order belongs to customer
    $ov = $conn->prepare("SELECT order_id, payment_status FROM customer_orders WHERE order_id = ? AND customer_id = ?");
    // PDO bind
$ov->execute([$order_id, $customer_id]);$ov->execute();
    $order = $ov->fetch(PDO::FETCH_ASSOC);
    

    if (!$order) sendResponse(false, 'Order not found');

    $conn->beginTransaction();

    // Insert transaction record
    $s = $conn->prepare("
        INSERT INTO payment_transactions (order_id, customer_id, store_id, gateway, upi_transaction_id, amount, status, response_code, response_message)
        VALUES (?, ?, ?, 'upi', ?, ?, ?, ?, ?)
    ");
    // PDO bind
$s->execute([$order_id, $customer_id, $store_id, $upi_txn_id, $amount, $status, $resp_code, $resp_msg]);$s->execute();
    $txn_id = $conn->lastInsertId();
    

    // Update order payment status
    $pay_status = $status === 'success' ? 'paid' : ($status === 'failed' ? 'failed' : 'pending');
    $upd = $conn->prepare("UPDATE customer_orders SET payment_status = ?, payment_reference = ? WHERE order_id = ?");
    // PDO bind
$upd->execute([$pay_status, $upi_txn_id, $order_id]);$upd->execute();
    

    $conn->commit();

    sendResponse(true, 'Transaction recorded', [
        'txn_id'         => $txn_id,
        'payment_status' => $pay_status,
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    logError('record_payment_transaction: ' . $e->getMessage());
    sendResponse(false, 'Failed: ' . $e->getMessage());
}


?>
