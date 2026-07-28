<?php
/**
 * Update Recurring Purchase Order Template
 * PUT (or POST) JSON: template_id, store_id, and any of:
 *   is_active, auto_approve, template_name, frequency, next_run_date, supplier_id, notes
 */

require_once 'config.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'], true)) {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

$template_id = (int)($data['template_id'] ?? 0);
$store_id    = (int)($data['store_id'] ?? 0);
if ($template_id <= 0 || $store_id <= 0) {
    sendResponse(false, 'Template and store are required');
}

try {
    $updates = [];
    $types   = "";
    $values  = [];

    if (isset($data['is_active']))     { $updates[] = "is_active = ?";     $types .= "i"; $values[] = (int)(bool)$data['is_active']; }
    if (isset($data['auto_approve']))  { $updates[] = "auto_approve = ?";  $types .= "i"; $values[] = (int)(bool)$data['auto_approve']; }
    if (isset($data['template_name'])) { $updates[] = "template_name = ?"; $types .= "s"; $values[] = trim((string)$data['template_name']); }
    if (isset($data['frequency'])) {
        $freq = (string)$data['frequency'];
        if (in_array($freq, ['weekly', 'biweekly', 'monthly', 'quarterly'], true)) {
            $updates[] = "frequency = ?"; $types .= "s"; $values[] = $freq;
        }
    }
    if (isset($data['next_run_date']))  { $updates[] = "next_run_date = ?"; $types .= "s"; $values[] = (string)$data['next_run_date']; }
    if (isset($data['supplier_id']))    { $updates[] = "supplier_id = ?";   $types .= "i"; $values[] = (int)$data['supplier_id']; }
    if (array_key_exists('notes', $data)) { $updates[] = "notes = ?";       $types .= "s"; $values[] = (string)$data['notes']; }

    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }

    $sql = "UPDATE recurring_po_templates SET " . implode(', ', $updates) . " WHERE template_id = ? AND store_id = ?";
    $types  .= "ii";
    $values[] = $template_id;
    $values[] = $store_id;

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    

    sendResponse(true, 'Recurring purchase order updated', ['template_id' => $template_id]);
} catch (Exception $e) {
    logError('Update recurring PO error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update recurring purchase order');
}


?>
