<?php
/**
 * Create Sales Return
 * Inserts into returns table (the actual DB table for sales returns).
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id', 'sale_id', 'return_amount', 'reason']);

try {
    // Auto-generate return number: RET-YYYYMMDD-XXXXXX
    $return_number = 'RET-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    $store_id      = (int)$data['store_id'];
    $sale_id       = (int)$data['sale_id'];
    $return_amount = (float)$data['return_amount'];
    $reason        = trim($data['reason'] ?? '');
    $notes         = trim($data['notes'] ?? '');
    $user_id       = (int)($data['user_id'] ?? 1);
    $customer_id   = isset($data['customer_id']) ? (int)$data['customer_id'] : null;

    // Normalize status: app sends pending/approved/rejected; DB has pending/processed/cancelled
    $raw_status = strtolower($data['status'] ?? 'pending');
    $status_map = [
        'pending'  => 'pending',
        'approved' => 'processed',
        'rejected' => 'cancelled',
        'processed'=> 'processed',
        'cancelled'=> 'cancelled',
    ];
    $status = $status_map[$raw_status] ?? 'pending';

    $return_date        = date('Y-m-d');
    $total_refund       = $return_amount;

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO returns (
            return_number, sale_id, customer_id, store_id,
            return_date, return_amount, total_refund_amount,
            return_reason, processed_by, status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // PDO bind
$stmt->execute([$return_number,
        $sale_id,
        $customer_id,
        $store_id,
        $return_date,
        $return_amount,
        $total_refund,
        $reason,
        $user_id,
        $status,
        $notes]);$stmt->execute();
    $return_id = $conn->lastInsertId();
    

    // Insert return line items if provided and restock inventory when processed
    $items = $data['items'] ?? [];
    $restockedCount = 0;
    if (!empty($items) && is_array($items)) {
        $itemStmt = $conn->prepare("
            INSERT INTO return_items (return_id, product_id, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        $restockStmt = $conn->prepare("
            UPDATE products SET quantity = quantity + ? WHERE id = ? AND store_id = ?
        ");
        $movementStmt = $conn->prepare("
            INSERT INTO stock_movements
                (product_id, store_id, movement_type, quantity, reference_type, reference_id,
                 unit_price, total_value, performed_by, notes, timestamp)
            VALUES (?, ?, 'in', ?, 'return', ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($items as $item) {
            $product_id  = (int)($item['product_id'] ?? 0);
            $qty         = (int)($item['quantity'] ?? 0);
            $unit_price  = (float)($item['unit_price'] ?? 0);
            $total_price = (float)($item['total_price'] ?? $qty * $unit_price);

            if ($product_id <= 0 || $qty <= 0) continue;

            $itemStmt->execute([$return_id, $product_id, $qty, $unit_price, $total_price]);

            // Restock immediately when the return is created as processed/approved
            if ($status === 'processed') {
                $restockStmt->execute([$qty, $product_id, $store_id]);

                $moveNote = "Restocked from return #{$return_number}";
                $movementStmt->execute([
                    $product_id, $store_id, $qty,
                    $return_id, $unit_price, $total_price,
                    $user_id, $moveNote
                ]);
                $restockedCount++;
            }
        }
        
        
        
    }

    $conn->commit();

    // Send notification for sales return
    notifySalesReturn(
        $conn,
        $store_id,
        $user_id,
        $return_id,
        $sale_id,
        $return_amount,
        $reason
    );

    sendResponse(true, 'Sales return created successfully', [
        'return_id'        => $return_id,
        'return_number'    => $return_number,
        'items_restocked'  => $restockedCount,
        'inventory_updated'=> $restockedCount > 0,
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    logError('Create sales return error: ' . $e->getMessage());
    sendResponse(false, 'Failed to create sales return: ' . $e->getMessage());
}


