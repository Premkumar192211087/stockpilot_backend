<?php
/**
 * Auto Reorder Purchase Orders
 * For each store, finds products at or below their reorder point that have
 * auto_reorder=1 set in inventory_alerts. Groups them by preferred supplier
 * and creates a draft PO for each supplier group.
 *
 * Run via cron daily (e.g. 6 AM):
 *   php auto_reorder_pos.php
 * Or via HTTP:
 *   GET /api_legacy/auto_reorder_pos.php?store_id=1
 *   GET /api_legacy/auto_reorder_pos.php?store_id=1&dry_run=1
 */

require_once 'config.php';
require_once 'notification_helper.php';

$isCli  = php_sapi_name() === 'cli';
$dryRun = $isCli
    ? in_array('--dry-run', $argv ?? [], true)
    : (isset($_GET['dry_run']) && $_GET['dry_run'] == '1');

$filterStore = $isCli ? null : (isset($_GET['store_id']) ? (int)$_GET['store_id'] : null);

$conn = getDBConnection();

try {
    // Get all active stores (or just the requested one)
    if ($filterStore) {
        $storeStmt = $conn->prepare("SELECT store_id FROM stores WHERE store_id = ? LIMIT 1");
        // PDO bind
$storeStmt->execute([$filterStore]);} else {
        $storeStmt = $conn->prepare("SELECT store_id FROM stores WHERE status = 'active' OR status IS NULL");
    }
    $storeStmt->execute();
    $stores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
    

    $totalPosCreated = 0;
    $summary = [];

    foreach ($stores as $storeRow) {
        $store_id = (int)$storeRow['store_id'];

        // Find products that need reordering: qty <= min_stock_level AND auto_reorder=1
        // Group by preferred supplier so we can create one PO per supplier
        $reorderStmt = $conn->prepare("
            SELECT
                p.id AS product_id,
                p.product_name,
                p.quantity AS current_stock,
                COALESCE(ia.min_stock_level, 10)    AS min_stock_level,
                COALESCE(ia.max_stock_level, 100)   AS max_stock_level,
                COALESCE(ia.reorder_quantity, 50)   AS reorder_quantity,
                COALESCE(ia.preferred_supplier_id,
                    (SELECT ps2.supplier_id FROM product_suppliers ps2
                     WHERE ps2.product_id = p.id AND ps2.store_id = p.store_id
                       AND ps2.is_primary = 1 LIMIT 1)
                ) AS supplier_id,
                COALESCE(
                    (SELECT ps3.unit_cost FROM product_suppliers ps3
                     WHERE ps3.product_id = p.id AND ps3.store_id = p.store_id
                       AND ps3.is_primary = 1 LIMIT 1),
                    p.cost_price
                ) AS unit_cost,
                COALESCE(
                    (SELECT ps4.min_order_quantity FROM product_suppliers ps4
                     WHERE ps4.product_id = p.id AND ps4.store_id = p.store_id
                       AND ps4.is_primary = 1 LIMIT 1),
                    1
                ) AS min_order_qty
            FROM products p
            JOIN inventory_alerts ia
                ON ia.product_id = p.id AND ia.store_id = p.store_id
               AND ia.alert_enabled = 1 AND ia.auto_reorder = 1
            WHERE p.store_id = ?
              AND p.status = 'active'
              AND p.quantity <= COALESCE(ia.min_stock_level, 10)
            ORDER BY supplier_id, p.id
        ");
        // PDO bind
$reorderStmt->execute([$store_id]);$reorderStmt->execute();
        $reorderRows = $reorderStmt->fetchAll(PDO::FETCH_ASSOC);
        

        if (empty($reorderRows)) continue;

        // Group by supplier_id
        $bySupplier = [];
        foreach ($reorderRows as $row) {
            $sid = (int)($row['supplier_id'] ?? 0);
            if ($sid <= 0) continue; // skip products with no supplier
            $bySupplier[$sid][] = $row;
        }

        foreach ($bySupplier as $supplier_id => $products) {
            // Check for existing open draft PO for this supplier/store (avoid duplicates)
            $dupStmt = $conn->prepare("
                SELECT po_id FROM purchase_orders
                WHERE store_id = ? AND supplier_id = ? AND status = 'draft'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                LIMIT 1
            ");
            // PDO bind
$dupStmt->execute([$store_id, $supplier_id]);$dupStmt->execute();
            $dupExists = $dupStmt->rowCount() > 0;
            
            if ($dupExists) continue; // already created a draft today

            // Build PO items
            $poItems    = [];
            $totalAmount = 0.0;
            foreach ($products as $prod) {
                $deficit     = max(0, (int)$prod['max_stock_level'] - (int)$prod['current_stock']);
                $suggestedQty = max((int)$prod['reorder_quantity'], $deficit, (int)$prod['min_order_qty']);
                $unitCost    = (float)$prod['unit_cost'];
                $lineTotal   = $suggestedQty * $unitCost;
                $totalAmount += $lineTotal;
                $poItems[]   = [
                    'product_id'      => (int)$prod['product_id'],
                    'product_name'    => $prod['product_name'],
                    'quantity_ordered' => $suggestedQty,
                    'unit_price'      => $unitCost,
                    'total_price'     => $lineTotal,
                ];
            }

            if ($dryRun) {
                $supplierNameStmt = $conn->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
                // PDO bind
$supplierNameStmt->execute([$supplier_id]);$supplierNameStmt->execute();
                $sName = $supplierNameStmt->fetch(PDO::FETCH_ASSOC)['supplier_name'] ?? "Supplier #$supplier_id";
                

                $summary[] = [
                    'store_id'     => $store_id,
                    'supplier_id'  => $supplier_id,
                    'supplier_name'=> $sName,
                    'items_count'  => count($poItems),
                    'total_amount' => $totalAmount,
                    'dry_run'      => true,
                ];
                continue;
            }

            // Get supplier details for lead time / due date
            $supStmt = $conn->prepare("SELECT supplier_name, payment_days FROM suppliers WHERE supplier_id = ?");
            // PDO bind
$supStmt->execute([$supplier_id]);$supStmt->execute();
            $supRow = $supStmt->fetch(PDO::FETCH_ASSOC);
            

            $supplierName   = $supRow['supplier_name'] ?? "Supplier #$supplier_id";
            $paymentDays    = max(7, (int)($supRow['payment_days'] ?? 30));
            $po_number      = 'PO-AUTO-' . date('Ymd-His') . '-' . $store_id . '-' . $supplier_id;
            $expected_del   = date('Y-m-d', strtotime("+$paymentDays days"));

            // Insert PO
            $poStmt = $conn->prepare("
                INSERT INTO purchase_orders
                    (po_number, supplier_id, store_id, order_date, expected_delivery_date,
                     total_amount, status, created_by, notes)
                VALUES (?, ?, ?, CURDATE(), ?, ?, 'draft', 1, 'Auto-generated reorder PO')
            ");
            // PDO bind
$poStmt->execute([$po_number, $supplier_id, $store_id, $expected_del, $totalAmount]);$poStmt->execute();
            $po_id = $conn->lastInsertId();
            

            // Insert PO items
            $poItemStmt = $conn->prepare("
                INSERT INTO purchase_order_items
                    (store_id, po_id, product_id, quantity_ordered, quantity_received, unit_price, total_price)
                VALUES (?, ?, ?, ?, 0, ?, ?)
            ");
            foreach ($poItems as $item) {
                $poItemStmt->execute([
                    $store_id, $po_id,
                    $item['product_id'], $item['quantity_ordered'],
                    $item['unit_price'], $item['total_price']
                ]);
            }
            

            // Notify
            $itemNames = implode(', ', array_column($poItems, 'product_name'));
            insertNotification(
                $conn, $store_id, null,
                "Auto Reorder PO Created: $po_number",
                "Draft PO for $supplierName — " . count($poItems) . " product(s): $itemNames. Total: ₹" . number_format($totalAmount, 2),
                'purchase_order',
                ['po_id' => $po_id, 'po_number' => $po_number, 'supplier_name' => $supplierName]
            );

            $summary[] = [
                'store_id'     => $store_id,
                'po_id'        => $po_id,
                'po_number'    => $po_number,
                'supplier_name'=> $supplierName,
                'items_count'  => count($poItems),
                'total_amount' => $totalAmount,
            ];
            $totalPosCreated++;
        }
    }

    $msg = $dryRun
        ? count($summary) . " draft PO(s) would be created (dry run)"
        : "$totalPosCreated auto-reorder PO(s) created";

    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
        foreach ($summary as $s) {
            $tag = isset($s['po_number']) ? $s['po_number'] : "(dry run)";
            echo "  Store {$s['store_id']} | $tag | {$s['supplier_name']} | "
                . "{$s['items_count']} item(s) | ₹" . number_format($s['total_amount'], 2) . "\n";
        }
    } else {
        sendResponse(true, $msg, [
            'pos_created' => $totalPosCreated,
            'dry_run'     => $dryRun,
            'details'     => $summary,
        ]);
    }

} catch (Exception $e) {
    logError('auto_reorder_pos error: ' . $e->getMessage());
    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        sendResponse(false, 'Failed to run auto-reorder: ' . $e->getMessage());
    }
}


?>
