<?php
/**
 * Create Purchase Order
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

function generatePurchaseOrderNumber($conn, $store_id) {
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'PO-' . date('Ymd-His') . '-' . random_int(100, 999);
        $stmt = $conn->prepare('SELECT po_id FROM purchase_orders WHERE store_id = ? AND po_number = ? LIMIT 1');
        // PDO bind
$stmt->execute([$store_id, $candidate]);$stmt->execute();
        $exists = $stmt->rowCount() > 0;
        
        if (!$exists) return $candidate;
    }
    return 'PO-' . date('YmdHis') . '-' . random_int(1000, 9999);
}

function resolvePurchaseProductId($conn, $store_id, $item) {
    $product_id = (int)($item['product_id'] ?? $item['productId'] ?? 0);
    if ($product_id > 0) return $product_id;

    $product_name = trim((string)($item['product_name'] ?? $item['productName'] ?? $item['name'] ?? ''));
    if ($product_name === '') return 0;

    $stmt = $conn->prepare('SELECT id FROM products WHERE store_id = ? AND product_name = ? LIMIT 1');
    // PDO bind
$stmt->execute([$store_id, $product_name]);$stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) return (int)$row['id'];

    $quantity = 0;
    $price = (float)($item['unit_price'] ?? $item['unitPrice'] ?? 0);
    $category = 'Uncategorized';
    $status = 'active';
    $insert = $conn->prepare('
        INSERT INTO products (store_id, product_name, quantity, price, cost_price, category, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    // PDO bind
$insert->execute([$store_id, $product_name, $quantity, $price, $price, $category, $status]);$insert->execute();
    $new_id = $conn->lastInsertId();
    
    return (int)$new_id;
}

$conn = getDBConnection();
$data = getJSONInput();

$store_id = (int)($data['store_id'] ?? 0);
$supplier_id = (int)($data['supplier_id'] ?? 0);
if ($store_id <= 0 || $supplier_id <= 0) {
    sendResponse(false, 'Store and supplier are required');
}

$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
$created_by = (int)($data['created_by'] ?? $data['user_id'] ?? 1);
if ($created_by <= 0) $created_by = 1;

try {
    $vendorStmt = $conn->prepare('SELECT supplier_name FROM suppliers WHERE supplier_id = ? AND (store_id = ? OR store_id IS NULL) LIMIT 1');
    // PDO bind
$vendorStmt->execute([$supplier_id, $store_id]);$vendorStmt->execute();
    $vendor = $vendorStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vendor) {
        sendResponse(false, 'Supplier not found');
    }

    $po_number = trim((string)($data['po_number'] ?? ''));
    if ($po_number === '') $po_number = generatePurchaseOrderNumber($conn, $store_id);

    $order_date = trim((string)($data['order_date'] ?? ''));
    if ($order_date === '') $order_date = date('Y-m-d');
    $expected_delivery_date = trim((string)($data['expected_delivery_date'] ?? ''));
    if ($expected_delivery_date === '') $expected_delivery_date = null;
    $notes = trim((string)($data['notes'] ?? ''));
    $status = trim((string)($data['status'] ?? 'pending'));
    if (!in_array($status, ['pending', 'partial', 'received', 'cancelled'], true)) $status = 'pending';

    $conn->beginTransaction();

    $initial_total = (float)($data['total_amount'] ?? 0);
    $stmt = $conn->prepare('
        INSERT INTO purchase_orders (
            store_id, supplier_id, po_number, order_date,
            expected_delivery_date, total_amount, status, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    // PDO bind
$stmt->execute([$store_id, $supplier_id, $po_number, $order_date,
        $expected_delivery_date, $initial_total, $status, $notes, $created_by]);$stmt->execute();
    $po_id = $conn->lastInsertId();
    

    $computed_total = 0.0;
    $itemStmt = $conn->prepare('
        INSERT INTO purchase_order_items (
            store_id, po_id, product_id, quantity_ordered, quantity_received, unit_price, total_price
        ) VALUES (?, ?, ?, ?, 0, ?, ?)
    ');

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $qty = (int)($item['quantity_ordered'] ?? $item['quantityOrdered'] ?? $item['quantity'] ?? 0);
        $unit_price = (float)($item['unit_price'] ?? $item['unitPrice'] ?? $item['price'] ?? 0);
        if ($qty <= 0) continue;
        $product_id = resolvePurchaseProductId($conn, $store_id, $item);
        if ($product_id <= 0) continue;
        $total_price = $qty * $unit_price;
        $computed_total += $total_price;
        // PDO bind
$itemStmt->execute([$store_id, $po_id, $product_id, $qty, $unit_price, $total_price]);$itemStmt->execute();
    }
    

    $final_total = $computed_total > 0 ? $computed_total : $initial_total;
    if ($final_total != $initial_total) {
        $totalStmt = $conn->prepare('UPDATE purchase_orders SET total_amount = ? WHERE po_id = ? AND store_id = ?');
        // PDO bind
$totalStmt->execute([$final_total, $po_id, $store_id]);$totalStmt->execute();
        
    }

    $conn->commit();

    // Auto-generate a draft bill for this PO (status = draft until items are received)
    $bill_id = null;
    try {
        $bill_id = autoGenerateDraftBill($conn, $po_id, $store_id, $supplier_id, $po_number, $final_total);
    } catch (Throwable $billErr) {
        logError('Auto draft bill error: ' . $billErr->getMessage());
    }

    try {
        notifyPurchaseOrder($conn, $store_id, $created_by, $po_id, $po_number, $vendor['supplier_name'], $final_total);
    } catch (Throwable $notifyError) {
        logError('Create PO notification error: ' . $notifyError->getMessage());
    }

    sendResponse(true, 'Purchase order created successfully', [
        'po_id'        => $po_id,
        'po_number'    => $po_number,
        'total_amount' => $final_total,
        'bill_id'      => $bill_id,
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    logError('Create PO error: ' . $e->getMessage());
    sendResponse(false, 'Failed to create purchase order');
}



/**
 * Auto-generate a DRAFT vendor bill when a PO is created.
 * Status stays 'draft' until items are received; receive_purchase_order.php
 * then upgrades the same bill to 'pending'.
 */
function autoGenerateDraftBill($conn, $po_id, $store_id, $supplier_id, $po_number, $total) {
    // Don't create a second bill if one already exists for this PO
    $dupStmt = $conn->prepare("SELECT bill_id FROM bills WHERE po_id = ? AND store_id = ? LIMIT 1");
    // PDO bind
$dupStmt->execute([$po_id, $store_id]);$dupStmt->execute();
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) return (int)$existing['bill_id'];

    // Get supplier payment terms for due-date calculation
    $termStmt = $conn->prepare("SELECT payment_days FROM suppliers WHERE supplier_id = ?");
    // PDO bind
$termStmt->execute([$supplier_id]);$termStmt->execute();
    $termRow     = $termStmt->fetch(PDO::FETCH_ASSOC);
    
    $paymentDays = max(1, (int)($termRow['payment_days'] ?? 30));

    $bill_number = 'BILL-' . date('Ymd-His') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    $bill_date   = date('Y-m-d');
    $due_date    = date('Y-m-d', strtotime("+{$paymentDays} days"));

    // Insert the draft bill
    $billStmt = $conn->prepare("
        INSERT INTO bills (store_id, bill_number, supplier_id, po_id, bill_date, due_date,
                           subtotal, total, amount_paid, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'draft', 'Auto-generated on PO creation')
    ");
    // PDO bind
$billStmt->execute([$store_id, $bill_number, $supplier_id, $po_id,
                          $bill_date, $due_date, $total, $total]);$billStmt->execute();
    $bill_id = $conn->lastInsertId();
    

    // Insert bill_items mirroring the PO items
    $poItemsStmt = $conn->prepare("
        SELECT product_id, quantity_ordered AS quantity, unit_price, total_price
        FROM purchase_order_items WHERE po_id = ? AND store_id = ?
    ");
    // PDO bind
$poItemsStmt->execute([$po_id, $store_id]);$poItemsStmt->execute();
    $poItems = $poItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    

    if (!empty($poItems)) {
        $biStmt = $conn->prepare("
            INSERT INTO bill_items (bill_id, product_id, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($poItems as $item) {
            // PDO bind
$biStmt->execute([$bill_id, $item['product_id'],
                                $item['quantity'], $item['unit_price'], $item['total_price']]);$biStmt->execute();
        }
        
    }

    // Notify
    require_once __DIR__ . '/notification_helper.php';
    notifyBillGenerated($conn, $store_id, null, $bill_id, $bill_number, $po_number, $total);

    return $bill_id;
}
?>
