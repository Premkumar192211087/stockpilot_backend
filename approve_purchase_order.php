<?php
/**
 * Approve Purchase Order
 * POST JSON: po_id, store_id, user_id
 * Moves a PO from 'draft' to 'pending' (approved, awaiting delivery).
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

$po_id    = (int)($data['po_id'] ?? 0);
$store_id = (int)($data['store_id'] ?? 0);
$user_id  = (int)($data['user_id'] ?? 1);

if ($po_id <= 0 || $store_id <= 0) {
    sendResponse(false, 'Purchase order and store are required');
}

try {
    $stmt = $conn->prepare("SELECT po_number, status FROM purchase_orders WHERE po_id = ? AND store_id = ? LIMIT 1");
    // PDO bind
$stmt->execute([$po_id, $store_id]);$stmt->execute();
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    

    if (!$po) {
        sendResponse(false, 'Purchase order not found');
    }
    if ($po['status'] !== 'draft') {
        sendResponse(false, 'Only draft purchase orders can be approved');
    }

    $upd = $conn->prepare("UPDATE purchase_orders SET status = 'pending' WHERE po_id = ? AND store_id = ?");
    // PDO bind
$upd->execute([$po_id, $store_id]);$upd->execute();
    

    try {
        notifyPurchaseOrderStatusUpdate($conn, $store_id, $user_id, $po_id, $po['po_number'], 'pending');
    } catch (Throwable $e) {
        logError('Approve PO notify error: ' . $e->getMessage());
    }

    sendResponse(true, 'Purchase order approved', [
        'po_id'  => $po_id,
        'status' => 'pending',
    ]);
} catch (Exception $e) {
    logError('Approve PO error: ' . $e->getMessage());
    sendResponse(false, 'Failed to approve purchase order');
}


?>
