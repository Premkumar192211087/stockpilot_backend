<?php
/**
 * Get Recurring Purchase Order Templates
 * GET: store_id
 * Returns { templates: [...] } with supplier_name and computed total_amount.
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("
        SELECT t.template_id, t.template_name, t.frequency, t.next_run_date,
               t.is_active, t.auto_approve, t.notes, t.supplier_id,
               s.supplier_name,
               COALESCE(SUM(i.quantity * i.unit_price), 0) AS total_amount
        FROM recurring_po_templates t
        LEFT JOIN suppliers s ON t.supplier_id = s.supplier_id
        LEFT JOIN recurring_po_items i ON i.template_id = t.template_id
        WHERE t.store_id = ?
        GROUP BY t.template_id
        ORDER BY t.is_active DESC, t.next_run_date ASC
    ");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

    foreach ($rows as &$r) {
        $r['template_id']  = (int)$r['template_id'];
        $r['supplier_id']  = (int)$r['supplier_id'];
        $r['is_active']    = (int)$r['is_active'];
        $r['auto_approve'] = (int)$r['auto_approve'];
        $r['total_amount'] = (float)$r['total_amount'];
    }
    unset($r);

    sendResponse(true, 'Recurring purchase orders retrieved', ['templates' => $rows]);
} catch (Exception $e) {
    logError('Get recurring POs error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve recurring purchase orders');
}


?>
