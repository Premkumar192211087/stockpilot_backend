<?php
/**
 * Add Payment - Enhanced with auto-status updates
 * 1. Inserts payment record
 * 2. ✅ NEW: Auto-updates invoice status (paid/partial) if linked
 * 3. ✅ NEW: Auto-updates bill status + amount_paid if linked
 * 4. Sends notifications
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

function normalizePaymentTypeInput($value) {
    $value = strtolower(trim((string)$value));
    if (strpos($value, 'made') !== false || strpos($value, 'paid') !== false || strpos($value, 'out') !== false) return 'made';
    return 'received';
}

function normalizePaymentMethodInput($value) {
    $value = strtolower(trim((string)$value));
    $value = str_replace(['-', ' '], '_', $value);
    switch ($value) {
        case 'cash': return 'cash';
        case 'card':
        case 'credit':
        case 'credit_card': return 'credit_card';
        case 'debit':
        case 'debit_card': return 'debit_card';
        case 'upi':
        case 'mobile':
        case 'mobile_payment': return 'upi';
        case 'bank':
        case 'bank_transfer':
        case 'net_banking':
        case 'neft':
        case 'rtgs': return 'net_banking';
        case 'cheque':
        case 'check': return 'cheque';
        default: return 'cash';
    }
}

function nullableInt($value) {
    if (!isset($value) || $value === '' || $value === 0 || $value === '0') return null;
    return (int)$value;
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id', 'amount', 'payment_method']);

try {
    $store_id = (int)$data['store_id'];
    $amount = (float)$data['amount'];
    if ($amount <= 0) {
        sendResponse(false, 'Amount must be greater than zero');
    }

    $payment_type = normalizePaymentTypeInput($data['payment_type'] ?? 'received');
    $payment_method = normalizePaymentMethodInput($data['payment_method'] ?? 'cash');
    $payment_date = trim($data['payment_date'] ?? '');
    if ($payment_date === '') {
        $payment_date = date('Y-m-d');
    }

    $customer_id = nullableInt($data['customer_id'] ?? null);
    $supplier_id = nullableInt($data['supplier_id'] ?? null);
    $invoice_id = nullableInt($data['invoice_id'] ?? null);
    $po_id = nullableInt($data['po_id'] ?? null);
    $bill_id = nullableInt($data['bill_id'] ?? null);
    $reference_number = trim($data['reference_number'] ?? '');
    $notes = trim($data['notes'] ?? '');
    $status = trim($data['status'] ?? 'completed');
    if (!in_array($status, ['completed', 'pending', 'failed'], true)) {
        $status = 'completed';
    }

    $invoice_number = '';
    if ($invoice_id) {
        $invStmt = $conn->prepare("SELECT invoice_number, customer_id FROM invoices WHERE invoice_id = ? AND store_id = ?");
        // PDO bind
$invStmt->execute([$invoice_id, $store_id]);$invStmt->execute();
        // PDO result ready in $invStmt
    $invResult = $invStmt;
        if ($inv = $invResult->fetch(PDO::FETCH_ASSOC)) {
            $invoice_number = $inv['invoice_number'];
            if (!$customer_id) $customer_id = (int)$inv['customer_id'];
        }
        
    }

    if ($reference_number === '' && $invoice_number !== '') {
        $reference_number = $invoice_number;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO payments (
            store_id, customer_id, supplier_id, invoice_id, po_id,
            amount, payment_method, payment_type, payment_date,
            reference_number, notes, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    // PDO bind
$stmt->execute([$store_id,
        $customer_id,
        $supplier_id,
        $invoice_id,
        $po_id,
        $amount,
        $payment_method,
        $payment_type,
        $payment_date,
        $reference_number,
        $notes,
        $status]);$stmt->execute();
    $payment_id = $conn->lastInsertId();
    

    // ✅ AUTO-UPDATE INVOICE STATUS if payment linked to invoice
    if ($invoice_id && $status === 'completed') {
        // Sum all completed payments for this invoice
        $paidStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_paid 
            FROM payments 
            WHERE invoice_id = ? AND status = 'completed'
        ");
        // PDO bind
$paidStmt->execute([$invoice_id]);$paidStmt->execute();
        $totalPaid = (float)$paidStmt->fetch(PDO::FETCH_ASSOC)['total_paid'];
        

        // Get invoice total
        $invTotalStmt = $conn->prepare("SELECT total, invoice_number FROM invoices WHERE invoice_id = ?");
        // PDO bind
$invTotalStmt->execute([$invoice_id]);$invTotalStmt->execute();
        $invData = $invTotalStmt->fetch(PDO::FETCH_ASSOC);
        

        if ($invData) {
            $invoiceTotal = (float)$invData['total'];
            $newInvStatus = 'unpaid';
            if ($totalPaid >= $invoiceTotal) {
                $newInvStatus = 'paid';
            } elseif ($totalPaid > 0) {
                $newInvStatus = 'partial';
            }
            
            $updateInv = $conn->prepare("UPDATE invoices SET status = ? WHERE invoice_id = ?");
            // PDO bind
$updateInv->execute([$newInvStatus, $invoice_id]);$updateInv->execute();
            

            // Notify invoice status change
            notifyInvoiceStatusUpdate($conn, $store_id, $data['user_id'] ?? null, $invoice_id, $invData['invoice_number'], $newInvStatus);
        }
    }

    // ✅ AUTO-UPDATE BILL STATUS if payment linked to bill
    if ($bill_id && $status === 'completed') {
        // Check if bills table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'bills'");
        if ($tableCheck->num_rows > 0) {
            // Update bill amount_paid
            $updateBillStmt = $conn->prepare("
                UPDATE bills SET amount_paid = amount_paid + ? WHERE bill_id = ?
            ");
            // PDO bind
$updateBillStmt->execute([$amount, $bill_id]);$updateBillStmt->execute();
            

            // Get updated bill to determine status
            $billStmt = $conn->prepare("SELECT total, amount_paid, bill_number FROM bills WHERE bill_id = ?");
            // PDO bind
$billStmt->execute([$bill_id]);$billStmt->execute();
            $billData = $billStmt->fetch(PDO::FETCH_ASSOC);
            

            if ($billData) {
                $billTotal = (float)$billData['total'];
                $billPaid = (float)$billData['amount_paid'];
                $newBillStatus = 'pending';
                if ($billPaid >= $billTotal) {
                    $newBillStatus = 'paid';
                } elseif ($billPaid > 0) {
                    $newBillStatus = 'partial';
                }

                $updateBillStatus = $conn->prepare("UPDATE bills SET status = ? WHERE bill_id = ?");
                // PDO bind
$updateBillStatus->execute([$newBillStatus, $bill_id]);$updateBillStatus->execute();
                

                notifyBillStatusUpdate($conn, $store_id, $data['user_id'] ?? null, $bill_id, $billData['bill_number'], $newBillStatus);
            }
        }
    }

    if ($payment_type === 'received') {
        notifyPaymentReceived(
            $conn,
            $store_id,
            $data['user_id'] ?? null,
            $payment_id,
            $invoice_number ?: ($reference_number ?: 'N/A'),
            $amount,
            $payment_method
        );
    } else {
        notifyPaymentMade(
            $conn,
            $store_id,
            $data['user_id'] ?? null,
            $payment_id,
            $reference_number ?: 'N/A',
            $amount,
            $payment_method
        );
    }
    
    sendResponse(true, 'Payment added successfully', [
        'payment_id' => $payment_id,
        'payment_type' => $payment_type,
        'payment_method' => $payment_method
    ]);
    
} catch (Exception $e) {
    logError('Add payment error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add payment');
}


?>
