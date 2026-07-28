<?php
/**
 * Delete Recurring Purchase Order Template
 * DELETE (or POST) JSON / query: template_id, store_id
 * Removes the template and its line items.
 */

require_once 'config.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'], true)) {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

$template_id = (int)($data['template_id'] ?? $_GET['template_id'] ?? 0);
$store_id    = (int)($data['store_id'] ?? $_GET['store_id'] ?? 0);
if ($template_id <= 0 || $store_id <= 0) {
    sendResponse(false, 'Template and store are required');
}

try {
    $conn->beginTransaction();

    $di = $conn->prepare("DELETE FROM recurring_po_items WHERE template_id = ?");
    // PDO bind
$di->execute([$template_id]);$di->execute();
    

    $dt = $conn->prepare("DELETE FROM recurring_po_templates WHERE template_id = ? AND store_id = ?");
    // PDO bind
$dt->execute([$template_id, $store_id]);$dt->execute();
    $affected = $dt->affected_rows;
    

    $conn->commit();

    if ($affected === 0) {
        sendResponse(false, 'Recurring purchase order not found');
    }
    sendResponse(true, 'Recurring purchase order deleted', ['template_id' => $template_id]);
} catch (Exception $e) {
    $conn->rollBack();
    logError('Delete recurring PO error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete recurring purchase order');
}


?>
