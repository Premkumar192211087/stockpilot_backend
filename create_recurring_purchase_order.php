<?php
/**
 * Create Recurring Purchase Order Template
 * POST JSON: store_id, supplier_id, template_name, frequency, next_run_date,
 *            auto_approve (opt), notes (opt), created_by/user_id (opt), items[] (opt)
 * items[]: { product_id, quantity, unit_price }
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

$store_id      = (int)($data['store_id'] ?? 0);
$supplier_id   = (int)($data['supplier_id'] ?? 0);
$template_name = trim((string)($data['template_name'] ?? ''));
$frequency     = (string)($data['frequency'] ?? 'monthly');
$next_run_date = trim((string)($data['next_run_date'] ?? ''));
$auto_approve  = (int)(bool)($data['auto_approve'] ?? 0);
$notes         = trim((string)($data['notes'] ?? ''));
$created_by    = (int)($data['created_by'] ?? $data['user_id'] ?? 1);
if ($created_by <= 0) $created_by = 1;
$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

if ($store_id <= 0 || $supplier_id <= 0 || $template_name === '') {
    sendResponse(false, 'Store, supplier and template name are required');
}
if (!in_array($frequency, ['weekly', 'biweekly', 'monthly', 'quarterly'], true)) {
    $frequency = 'monthly';
}
if ($next_run_date === '') $next_run_date = date('Y-m-d');

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO recurring_po_templates
            (store_id, supplier_id, template_name, frequency, next_run_date,
             is_active, auto_approve, created_by, notes)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
    ");
    // PDO bind
$stmt->execute([$store_id, $supplier_id, $template_name,
        $frequency, $next_run_date, $auto_approve, $created_by, $notes]);$stmt->execute();
    $template_id = $conn->lastInsertId();
    

    if (!empty($items)) {
        $it = $conn->prepare("
            INSERT INTO recurring_po_items (template_id, product_id, quantity, unit_price)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $product_id = (int)($item['product_id'] ?? $item['productId'] ?? 0);
            $qty        = (int)($item['quantity'] ?? $item['quantity_ordered'] ?? 0);
            $unit_price = (float)($item['unit_price'] ?? $item['unitPrice'] ?? $item['price'] ?? 0);
            if ($product_id <= 0 || $qty <= 0) continue;
            // PDO bind
$it->execute([$template_id, $product_id, $qty, $unit_price]);$it->execute();
        }
        
    }

    $conn->commit();
    sendResponse(true, 'Recurring purchase order created', ['template_id' => $template_id]);
} catch (Exception $e) {
    $conn->rollBack();
    logError('Create recurring PO error: ' . $e->getMessage());
    sendResponse(false, 'Failed to create recurring purchase order');
}


?>
