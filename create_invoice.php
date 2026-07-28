<?php
/**
 * Create Invoice
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

function generateInvoiceNumber($conn, $store_id) {
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'INV-' . date('Ymd-His') . '-' . random_int(100, 999);
        $stmt = $conn->prepare('SELECT invoice_id FROM invoices WHERE store_id = ? AND invoice_number = ? LIMIT 1');
        // PDO bind
$stmt->execute([$store_id, $candidate]);$stmt->execute();
        $exists = $stmt->rowCount() > 0;
        
        if (!$exists) return $candidate;
    }
    return 'INV-' . date('YmdHis') . '-' . random_int(1000, 9999);
}

$conn = getDBConnection();
$data = getJSONInput();

$store_id = (int)($data['store_id'] ?? 0);
$customer_id = (int)($data['customer_id'] ?? 0);
if ($store_id <= 0 || $customer_id <= 0) {
    sendResponse(false, 'Store and customer are required');
}

try {
    $customerStmt = $conn->prepare('SELECT customer_id FROM customers WHERE customer_id = ? AND store_id = ? LIMIT 1');
    // PDO bind
$customerStmt->execute([$customer_id, $store_id]);$customerStmt->execute();
    $customerExists = $customerStmt->rowCount() > 0;
    
    if (!$customerExists) {
        sendResponse(false, 'Customer not found');
    }

    $invoice_number = trim((string)($data['invoice_number'] ?? ''));
    if ($invoice_number === '') $invoice_number = generateInvoiceNumber($conn, $store_id);

    $issue_date = trim((string)($data['issue_date'] ?? ''));
    if ($issue_date === '') $issue_date = date('Y-m-d');
    $due_date = trim((string)($data['due_date'] ?? ''));
    if ($due_date === '') $due_date = null;

    $subtotal = (float)($data['subtotal'] ?? 0);
    $tax = (float)($data['tax'] ?? 0);
    $discount = (float)($data['discount'] ?? 0);
    $total = isset($data['total']) ? (float)$data['total'] : ($subtotal + $tax - $discount);
    $status = trim((string)($data['status'] ?? 'unpaid'));
    $notes = trim((string)($data['notes'] ?? ''));

    $stmt = $conn->prepare('
        INSERT INTO invoices (
            store_id, customer_id, invoice_number, issue_date,
            due_date, subtotal, tax, discount,
            total, status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    // PDO bind
$stmt->execute([$store_id, $customer_id, $invoice_number, $issue_date,
        $due_date, $subtotal, $tax, $discount, $total, $status, $notes]);$stmt->execute();
    $invoice_id = $conn->lastInsertId();
    

    try {
        notifyInvoiceCreated($conn, $store_id, $data['user_id'] ?? 1, $invoice_id, $invoice_number, $total);
    } catch (Throwable $notifyError) {
        logError('Create invoice notification error: ' . $notifyError->getMessage());
    }

    sendResponse(true, 'Invoice created successfully', [
        'invoice_id' => $invoice_id,
        'invoice_number' => $invoice_number
    ]);
} catch (Exception $e) {
    logError('Create invoice error: ' . $e->getMessage());
    sendResponse(false, 'Failed to create invoice');
}


?>
