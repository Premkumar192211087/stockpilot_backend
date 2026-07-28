<?php
/**
 * Add Vendor
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['supplier_name', 'contact_person', 'store_id']);

try {
    // vendor_store_id links this supplier to their own user account's store.
    // When set, the PO product picker will show the vendor's own product catalog
    // instead of the buyer's inventory.
    $vendor_store_id = isset($data['vendor_store_id']) && (int)$data['vendor_store_id'] > 0
        ? (int)$data['vendor_store_id']
        : null;

    $payment_days = isset($data['payment_days']) ? (int)$data['payment_days'] : 30;

    $stmt = $conn->prepare("
        INSERT INTO suppliers (
            store_id, supplier_name, contact_person, email, phone,
            address, payment_terms, payment_days, status, vendor_store_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $status = $data['status'] ?? 'active';
    // PDO bind
$stmt->execute([$data['store_id'],
        $data['supplier_name'],
        $data['contact_person'],
        $data['email'] ?? null,
        $data['phone'] ?? null,
        $data['address'] ?? '',
        $data['payment_terms'] ?? 'Net 30',
        $payment_days,
        $status,
        $vendor_store_id]);$stmt->execute();
    $new_supplier_id = $conn->lastInsertId();

    sendResponse(true, 'Vendor added successfully', [
        'supplier_id'     => $new_supplier_id,
        'vendor_store_id' => $vendor_store_id,
    ]);
    
} catch (Exception $e) {
    logError('Add vendor error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add vendor');
}



?>
