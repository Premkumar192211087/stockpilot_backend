<?php
/**
 * Receive Purchase Order Items - Enhanced
 * 1. Updates quantity_received for PO items
 * 2. Updates PO status (pending/partial/received)
 * 3. ✅ NEW: Updates products.quantity (inventory update)
 * 4. ✅ NEW: Auto-generates vendor bill on full/partial receive
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'POST method required');
}

$data = getJSONInput();
validateRequired($data, ['po_id', 'store_id', 'items']);

$po_id = intval($data['po_id']);
$store_id = intval($data['store_id']);
$items = $data['items']; // Array of {poi_id, quantity_received}
$notes = $data['notes'] ?? '';

$conn = getDBConnection();

try {
    $conn->beginTransaction();
    
    // Verify PO belongs to store
    $verifyStmt = $conn->prepare("SELECT po_id, status, supplier_id FROM purchase_orders WHERE po_id = ? AND store_id = ?");
    // PDO bind
$verifyStmt->execute([$po_id, $store_id]);$verifyStmt->execute();
    // PDO result ready in $verifyStmt
    $verifyResult = $verifyStmt;
    
    if ($verifyResult->num_rows === 0) {
        sendResponse(false, 'Purchase order not found');
    }
    
    $po = $verifyResult->fetch(PDO::FETCH_ASSOC);
    if ($po['status'] === 'cancelled') {
        sendResponse(false, 'Cannot receive items for a cancelled purchase order');
    }
    $supplier_id = (int)$po['supplier_id'];
    
    
    // Update each item's quantity_received AND update product inventory
    $updateStmt = $conn->prepare("
        UPDATE purchase_order_items 
        SET quantity_received = quantity_received + ? 
        WHERE poi_id = ? AND po_id = ?
    ");
    
    // Get product_id for each PO item to update inventory
    $getProductStmt = $conn->prepare("
        SELECT product_id, unit_price FROM purchase_order_items WHERE poi_id = ? AND po_id = ?
    ");
    
    // Update product stock quantity
    $updateStockStmt = $conn->prepare("
        UPDATE products SET quantity = quantity + ?, cost_price = ? WHERE id = ? AND store_id = ?
    ");
    
    $receivedItems = []; // Track for bill generation
    
    foreach ($items as $item) {
        $qtyReceived = intval($item['quantity_received']);
        $poiId = intval($item['poi_id']);
        
        if ($qtyReceived <= 0) continue;
        
        // 1. Update PO item received quantity
        // PDO bind
$updateStmt->execute([$qtyReceived, $poiId, $po_id]);$updateStmt->execute();
        
        // 2. Get product_id and unit_price for this PO item
        // PDO bind
$getProductStmt->execute([$poiId, $po_id]);$getProductStmt->execute();
        // PDO result ready in $getProductStmt
    $productResult = $getProductStmt;
        
        if ($productRow = $productResult->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int)$productRow['product_id'];
            $unitPrice = (float)$productRow['unit_price'];
            
            // 3. ✅ UPDATE PRODUCT INVENTORY
            // Check margin before applying new cost_price
            $marginCheckStmt = $conn->prepare("SELECT cost_price, price FROM products WHERE id = ? AND store_id = ?");
            // PDO bind
$marginCheckStmt->execute([$productId, $store_id]);$marginCheckStmt->execute();
            $marginRow = $marginCheckStmt->fetch(PDO::FETCH_ASSOC);
            

            if ($marginRow && $unitPrice > 0 && (float)$marginRow['price'] > 0) {
                $sellingPrice  = (float)$marginRow['price'];
                $oldCost       = (float)$marginRow['cost_price'];
                $oldMarginPct  = $oldCost > 0 ? (($sellingPrice - $oldCost) / $sellingPrice) * 100 : null;
                $newMarginPct  = (($sellingPrice - $unitPrice) / $sellingPrice) * 100;
                $marginThreshold = 15.0; // alert if margin drops below 15 %

                if ($newMarginPct < $marginThreshold) {
                    $prodNameStmt = $conn->prepare("SELECT product_name FROM products WHERE id = ?");
                    // PDO bind
$prodNameStmt->execute([$productId]);$prodNameStmt->execute();
                    $prodNameRow = $prodNameStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $prodName = $prodNameRow['product_name'] ?? "Product #$productId";

                    $marginTitle = "Low Margin Alert: $prodName";
                    $marginMsg   = sprintf(
                        "New cost ₹%.2f reduces margin to %.1f%% (selling ₹%.2f). Consider updating the price.",
                        $unitPrice, $newMarginPct, $sellingPrice
                    );
                    $marginData = [
                        'product_id'    => $productId,
                        'product_name'  => $prodName,
                        'new_cost'      => $unitPrice,
                        'selling_price' => $sellingPrice,
                        'new_margin_pct'=> round($newMarginPct, 2),
                        'old_margin_pct'=> $oldMarginPct !== null ? round($oldMarginPct, 2) : null,
                    ];
                    insertNotification($conn, $store_id, null, $marginTitle, $marginMsg, 'system', $marginData);
                }
            }

            // PDO bind
$updateStockStmt->execute([$qtyReceived, $unitPrice, $productId, $store_id]);$updateStockStmt->execute();
            
            // 4. ✅ INSERT STOCK MOVEMENT for audit trail
            $totalValue = $qtyReceived * $unitPrice;
            $movementStmt = $conn->prepare("
                INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference_type, reference_id, unit_price, total_value, performed_by, notes, timestamp)
                VALUES (?, ?, 'in', ?, 'purchase', ?, ?, ?, ?, ?, NOW())
            ");
            $performedBy = $data['user_id'] ?? 1;
            $movementNote = "Received from PO #$po_id";
            // PDO bind
$movementStmt->execute([$productId, $store_id, $qtyReceived, $po_id, $unitPrice, $totalValue, $performedBy, $movementNote]);$movementStmt->execute();
            
            
            $receivedItems[] = [
                'product_id' => $productId,
                'quantity' => $qtyReceived,
                'unit_price' => $unitPrice,
                'total_price' => $totalValue
            ];
        }
    }
    
    
    
    
    // Determine new PO status based on total ordered vs received
    $statusStmt = $conn->prepare("
        SELECT 
            SUM(quantity_ordered) as total_ordered,
            SUM(quantity_received) as total_received
        FROM purchase_order_items
        WHERE po_id = ?
    ");
    // PDO bind
$statusStmt->execute([$po_id]);$statusStmt->execute();
    $statusResult = $statusStmt->fetch(PDO::FETCH_ASSOC);
    
    
    $totalOrdered = intval($statusResult['total_ordered']);
    $totalReceived = intval($statusResult['total_received']);
    
    $newStatus = 'pending';
    if ($totalReceived >= $totalOrdered) {
        $newStatus = 'received';
    } elseif ($totalReceived > 0) {
        $newStatus = 'partial';
    }
    
    // Update PO status
    $updatePO = $conn->prepare("
        UPDATE purchase_orders 
        SET status = ?, 
            actual_delivery_date = CASE WHEN ? = 'received' THEN CURDATE() ELSE actual_delivery_date END,
            notes = CASE WHEN ? != '' THEN CONCAT(COALESCE(notes, ''), ' | Received: ', ?) ELSE notes END,
            updated_at = NOW()
        WHERE po_id = ?
    ");
    // PDO bind
$updatePO->execute([$newStatus, $newStatus, $notes, $notes, $po_id]);$updatePO->execute();
    
    
    $conn->commit();
    
    // ✅ AUTO-GENERATE BILL from received items (after commit)
    $bill_id = null;
    if (!empty($receivedItems)) {
        $bill_id = autoGenerateBill($conn, $po_id, $store_id, $supplier_id, $receivedItems);
    }
    
    // Send notification about PO status update
    try {
        $created_by = $data['user_id'] ?? 1;
        $poStmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_id = ?");
        // PDO bind
$poStmt->execute([$po_id]);$poStmt->execute();
        $poResult = $poStmt->fetch(PDO::FETCH_ASSOC);
        
        $po_number = $poResult['po_number'] ?? "PO-$po_id";
        notifyPurchaseOrderStatusUpdate($conn, $store_id, $created_by, $po_id, $po_number, $newStatus);
    } catch (Throwable $notifyError) {
        logError('Receive PO notification error: ' . $notifyError->getMessage());
    }
    
    sendResponse(true, 'Purchase order items received successfully', [
        'po_id' => $po_id,
        'new_status' => $newStatus,
        'total_ordered' => $totalOrdered,
        'total_received' => $totalReceived,
        'inventory_updated' => true,
        'bill_id' => $bill_id
    ]);
    
} catch (Exception $e) {
    $conn->rollBack();
    logError('Receive purchase order error: ' . $e->getMessage());
    sendResponse(false, 'Failed to receive purchase order items');
}



/**
 * Auto-generate or upgrade a vendor bill when PO items are received.
 *
 * If create_purchase_order.php already created a draft bill for this PO,
 * this function upgrades it to 'pending' and replaces its items with the
 * actually received quantities/amounts, avoiding duplicate bills.
 *
 * If no draft bill exists (legacy POs created before the auto-draft feature),
 * a new 'pending' bill is created as before.
 */
function autoGenerateBill($conn, $po_id, $store_id, $supplier_id, $receivedItems) {
    try {
        $poStmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_id = ?");
        // PDO bind
$poStmt->execute([$po_id]);$poStmt->execute();
        $poData = $poStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$poData) return null;
        $po_number = $poData['po_number'] ?? "PO-$po_id";

        $subtotal = 0.0;
        foreach ($receivedItems as $item) {
            $subtotal += $item['total_price'];
        }

        // Check for an existing draft bill created at PO creation time
        $draftStmt = $conn->prepare("
            SELECT bill_id, bill_number FROM bills
            WHERE po_id = ? AND store_id = ? AND status = 'draft'
            LIMIT 1
        ");
        // PDO bind
$draftStmt->execute([$po_id, $store_id]);$draftStmt->execute();
        $draftBill = $draftStmt->fetch(PDO::FETCH_ASSOC);
        

        if ($draftBill) {
            // Upgrade existing draft bill: update totals and status to pending
            $bill_id     = (int)$draftBill['bill_id'];
            $bill_number = $draftBill['bill_number'];

            $upStmt = $conn->prepare("
                UPDATE bills
                SET subtotal = ?, total = ?, status = 'pending', updated_at = NOW()
                WHERE bill_id = ?
            ");
            // PDO bind
$upStmt->execute([$subtotal, $subtotal, $bill_id]);$upStmt->execute();
            

            // Replace bill items with actually received quantities
            $delStmt = $conn->prepare("DELETE FROM bill_items WHERE bill_id = ?");
            // PDO bind
$delStmt->execute([$bill_id]);$delStmt->execute();
            

        } else {
            // No draft bill — create a fresh pending bill (legacy path)
            $termStmt = $conn->prepare("SELECT payment_days FROM suppliers WHERE supplier_id = ?");
            // PDO bind
$termStmt->execute([$supplier_id]);$termStmt->execute();
            $termRow     = $termStmt->fetch(PDO::FETCH_ASSOC);
            
            $paymentDays = max(1, (int)($termRow['payment_days'] ?? 30));

            $bill_number = 'BILL-' . date('Ymd-His') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $bill_date   = date('Y-m-d');
            $due_date    = date('Y-m-d', strtotime("+{$paymentDays} days"));

            $billStmt = $conn->prepare("
                INSERT INTO bills (store_id, bill_number, supplier_id, po_id, bill_date, due_date,
                                   subtotal, total, amount_paid, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending')
            ");
            // PDO bind
$billStmt->execute([$store_id, $bill_number, $supplier_id, $po_id,
                                  $bill_date, $due_date, $subtotal, $subtotal]);$billStmt->execute();
            $bill_id = $conn->lastInsertId();
            
        }

        // Insert the received items into bill_items
        $biStmt = $conn->prepare("
            INSERT INTO bill_items (bill_id, product_id, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($receivedItems as $item) {
            // PDO bind
$biStmt->execute([$bill_id, $item['product_id'],
                                $item['quantity'], $item['unit_price'], $item['total_price']]);$biStmt->execute();
        }
        

        notifyBillGenerated($conn, $store_id, null, $bill_id, $bill_number, $po_number, $subtotal);
        return $bill_id;

    } catch (Exception $e) {
        logError('Auto-generate bill error: ' . $e->getMessage());
        return null;
    }
}
?>
