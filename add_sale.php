<?php
/**
 * Add Sale
 * Accepts customer_name (string), payment_method, payment_status,
 * store_id, total_amount, discount_amount, tax_amount, final_amount, items[]
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id', 'final_amount']);

// Normalize payment method from app values to DB enum
function normalizePaymentMethod($method) {
    $map = [
        'cash'          => 'cash',
        'Cash'          => 'cash',
        'card'          => 'credit_card',
        'Card'          => 'credit_card',
        'upi'           => 'upi',
        'UPI'           => 'upi',
        'bank transfer' => 'net_banking',
        'Bank Transfer' => 'net_banking',
        'credit_card'   => 'credit_card',
        'debit_card'    => 'debit_card',
        'net_banking'   => 'net_banking',
        'cheque'        => 'cheque',
    ];
    return $map[$method] ?? 'cash';
}

// Normalize payment status from app values to DB enum
function normalizePaymentStatus($status) {
    $map = [
        'Paid'    => 'paid',
        'paid'    => 'paid',
        'Unpaid'  => 'pending',
        'unpaid'  => 'pending',
        'pending' => 'pending',
        'Partial' => 'partial',
        'partial' => 'partial',
    ];
    return $map[$status] ?? 'paid';
}

try {
    // Auto-generate invoice number: INV-YYYYMMDD-XXXXXX
    $invoice_number = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    $store_id        = (int)$data['store_id'];
    $final_amount    = (float)$data['final_amount'];
    $total_amount    = (float)($data['total_amount'] ?? $data['subtotal'] ?? $final_amount);
    $discount_amount = (float)($data['discount_amount'] ?? 0);
    $tax_amount      = (float)($data['tax_amount'] ?? 0);
    $payment_method  = normalizePaymentMethod($data['payment_method'] ?? 'cash');
    $payment_status  = normalizePaymentStatus($data['payment_status'] ?? 'paid');
    $notes           = $data['notes'] ?? '';
    $user_id         = (int)($data['user_id'] ?? 1);
    $customer_name   = trim($data['customer_name'] ?? '');

    // Use customer_id directly if the app sent it; fall back to name lookup
    $customer_id = isset($data['customer_id']) && (int)$data['customer_id'] > 0
        ? (int)$data['customer_id']
        : null;

    if ($customer_id === null && $customer_name && $customer_name !== 'Walk-in Customer') {
        $cs = $conn->prepare("SELECT customer_id FROM customers WHERE customer_name = ? LIMIT 1");
        // PDO bind
$cs->execute([$customer_name]);$cs->execute();
        $cs->bind_result($found_id);
        if ($cs->fetch()) $customer_id = $found_id;
        
    }

    $stmt = $conn->prepare("
        INSERT INTO sales (
            store_id, customer_id, invoice_number, sale_date,
            total_amount, tax_amount, discount_amount, final_amount,
            payment_method, payment_status, served_by, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $sale_date = date('Y-m-d H:i:s');
    // PDO bind
$stmt->execute([$store_id,
        $customer_id,
        $invoice_number,
        $sale_date,
        $total_amount,
        $tax_amount,
        $discount_amount,
        $final_amount,
        $payment_method,
        $payment_status,
        $user_id,
        $notes]);$stmt->execute();
    $sale_id = $conn->lastInsertId();
    

    // Insert line items if provided
    $items = $data['items'] ?? [];
    if (!empty($items) && is_array($items)) {
        $si = $conn->prepare("
            INSERT INTO sale_items (store_id, sale_id, product_id, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $product_name = $item['product_name'] ?? '';
            $qty          = (int)($item['quantity'] ?? 1);
            $unit_price   = (float)($item['unit_price'] ?? 0);
            $total_price  = (float)($item['total_price'] ?? $qty * $unit_price);

            // Use product_id directly if the app sent it; fall back to name lookup
            $product_id = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            if ($product_id <= 0 && $product_name) {
                $ps = $conn->prepare("SELECT id FROM products WHERE store_id = ? AND product_name = ? LIMIT 1");
                // PDO bind
$ps->execute([$store_id, $product_name]);$ps->execute();
                $ps->bind_result($pid);
                if ($ps->fetch()) $product_id = $pid;
                
            }

            if ($product_id > 0) {
                // PDO bind
$si->execute([$store_id, $sale_id, $product_id, $qty, $unit_price, $total_price]);$si->execute();

                // Deduct stock and check low stock threshold
                $stockUpdate = $conn->prepare("UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ? AND store_id = ?");
                // PDO bind
$stockUpdate->execute([$qty, $product_id, $store_id]);$stockUpdate->execute();
                

                // Check if stock has fallen below threshold
                $stockCheck = $conn->prepare("
                    SELECT p.product_name, p.quantity, COALESCE(ia.min_stock_level, 10) AS min_stock_level
                    FROM products p
                    LEFT JOIN inventory_alerts ia
                        ON ia.product_id = p.id
                       AND ia.store_id = p.store_id
                       AND ia.alert_enabled = 1
                    WHERE p.id = ? AND p.store_id = ?
                    LIMIT 1
                ");
                // PDO bind
$stockCheck->execute([$product_id, $store_id]);$stockCheck->execute();
                $stockResult = $stockCheck->fetch(PDO::FETCH_ASSOC);
                

                if ($stockResult) {
                    $threshold = max((int)($stockResult['min_stock_level'] ?? 10), 0);
                    if ((int)$stockResult['quantity'] <= $threshold) {
                        try {
                            notifyLowStock($conn, $store_id, $user_id, $product_id, $stockResult['product_name'], (int)$stockResult['quantity'], $threshold);
                        } catch (Throwable $e) {
                            logError('Low stock notification error: ' . $e->getMessage());
                        }
                    }
                }
            }
        }
        
    }

    // Auto-generate invoice immediately for sales with a known customer
    $invoice_id = null;
    if ($customer_id) {
        try {
            $invoice_id = autoGenerateInvoice(
                $conn, $store_id, $customer_id, $invoice_number,
                $sale_id, $total_amount, $tax_amount, $discount_amount,
                $final_amount, $payment_status
            );
        } catch (Throwable $invErr) {
            logError('Auto invoice error: ' . $invErr->getMessage());
        }
    }

    // Send notification for new sale
    notifySaleCreated(
        $conn,
        $store_id,
        $user_id,
        $sale_id,
        $invoice_number,
        $final_amount
    );

    sendResponse(true, 'Sale created successfully', [
        'sale_id'        => $sale_id,
        'invoice_number' => $invoice_number,
        'invoice_id'     => $invoice_id,
    ]);

} catch (Exception $e) {
    logError('Add sale error: ' . $e->getMessage());
    sendResponse(false, 'Failed to create sale: ' . $e->getMessage());
}



/**
 * Auto-generate an invoice immediately when a sale is created.
 * Uses the same invoice_number already stamped on the sale row so the
 * two records are naturally linked without an extra foreign key.
 * Returns invoice_id on success, null if the invoice already exists.
 */
function autoGenerateInvoice($conn, $store_id, $customer_id, $invoice_number,
                              $sale_id, $subtotal, $tax, $discount, $total, $payment_status) {
    // Skip if an invoice with this number already exists (idempotent)
    $dupStmt = $conn->prepare("SELECT invoice_id FROM invoices WHERE store_id = ? AND invoice_number = ? LIMIT 1");
    // PDO bind
$dupStmt->execute([$store_id, $invoice_number]);$dupStmt->execute();
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) return (int)$existing['invoice_id'];

    // Map sale payment_status → invoice status
    $statusMap = [
        'paid'    => 'paid',
        'partial' => 'partial',
        'pending' => 'unpaid',
    ];
    $inv_status  = $statusMap[$payment_status] ?? 'unpaid';
    $amount_paid = $inv_status === 'paid' ? $total : 0.0;
    $issue_date  = date('Y-m-d');
    $due_date    = date('Y-m-d', strtotime('+30 days'));

    $stmt = $conn->prepare("
        INSERT INTO invoices
            (store_id, customer_id, invoice_number, issue_date, due_date,
             subtotal, tax, discount, total, amount_paid, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Auto-generated from sale')
    ");
    // PDO bind
$stmt->execute([$store_id, $customer_id, $invoice_number,
        $issue_date, $due_date,
        $subtotal, $tax, $discount, $total, $amount_paid,
        $inv_status]);$stmt->execute();
    $invoice_id = $conn->lastInsertId();
    

    require_once __DIR__ . '/notification_helper.php';
    notifyInvoiceCreated($conn, $store_id, null, $invoice_id, $invoice_number, $total);

    return $invoice_id;
}
