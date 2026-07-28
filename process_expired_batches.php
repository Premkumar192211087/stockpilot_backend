<?php
/**
 * Process Expired Batches
 * Finds batches past their expiry date, deducts remaining quantity from
 * products.quantity, logs a stock_movement of type 'expiry_loss', and
 * zeroes out the batch. Idempotent: batches already at 0 are skipped.
 *
 * Run via cron daily (e.g. 1 AM):
 *   php process_expired_batches.php
 * Or via HTTP:
 *   GET /api_legacy/process_expired_batches.php?store_id=1
 *   GET /api_legacy/process_expired_batches.php?dry_run=1
 */

require_once 'config.php';
require_once 'notification_helper.php';

$isCli      = php_sapi_name() === 'cli';
$dryRun     = $isCli
    ? in_array('--dry-run', $argv ?? [], true)
    : (isset($_GET['dry_run']) && $_GET['dry_run'] == '1');
$filterStore = $isCli ? null : (isset($_GET['store_id']) ? (int)$_GET['store_id'] : null);

$conn = getDBConnection();

try {
    // Find expired batches that still have stock
    $whereExtra = $filterStore ? "AND b.store_id = $filterStore" : '';
    $expiredStmt = $conn->prepare("
        SELECT b.id AS batch_id, b.product_id, b.store_id, b.quantity,
               b.exp_date, b.product_name,
               p.cost_price
        FROM batch_details b
        JOIN products p ON b.product_id = p.id AND b.store_id = p.store_id
        WHERE b.quantity > 0
          AND b.exp_date IS NOT NULL
          AND b.exp_date < CURDATE()
          $whereExtra
        ORDER BY b.store_id, b.exp_date
    ");
    $expiredStmt->execute();
    $expired = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);
    

    if (empty($expired)) {
        $msg = 'No expired batches with remaining stock found';
        if ($isCli) {
            echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
        } else {
            sendResponse(true, $msg, ['processed' => 0, 'dry_run' => $dryRun]);
        }
        
        exit;
    }

    $processed = 0;
    $details   = [];

    // Prepare statements once
    $deductStmt = $conn->prepare("
        UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ? AND store_id = ?
    ");
    $zeroStmt = $conn->prepare("
        UPDATE batch_details SET quantity = 0 WHERE id = ?
    ");
    $moveStmt = $conn->prepare("
        INSERT INTO stock_movements
            (product_id, store_id, movement_type, quantity, reference_type, reference_id,
             unit_price, total_value, performed_by, notes, timestamp)
        VALUES (?, ?, 'out', ?, 'expiry', ?, ?, ?, 0, ?, NOW())
    ");

    foreach ($expired as $batch) {
        $batch_id   = (int)$batch['batch_id'];
        $product_id = (int)$batch['product_id'];
        $store_id   = (int)$batch['store_id'];
        $qty        = (int)$batch['quantity'];
        $cost       = (float)$batch['cost_price'];
        $lossValue  = $qty * $cost;

        if ($dryRun) {
            $details[] = [
                'batch_id'     => $batch_id,
                'product_id'   => $product_id,
                'product_name' => $batch['product_name'],
                'store_id'     => $store_id,
                'qty_to_write' => $qty,
                'exp_date'     => $batch['exp_date'],
                'loss_value'   => $lossValue,
                'dry_run'      => true,
            ];
            continue;
        }

        $conn->beginTransaction();
        try {
            // Deduct from product stock
            // PDO bind
$deductStmt->execute([$qty, $product_id, $store_id]);$deductStmt->execute();

            // Zero out the batch
            // Zero out the batch
            $zeroStmt->execute([$batch_id]);

            // Log stock movement
            $moveNote = "Expired batch #{$batch_id} — exp. {$batch['exp_date']}";
            $moveStmt->execute([
                $product_id, $store_id, $qty,
                $batch_id, $cost, $lossValue, $moveNote
            ]);

            $conn->commit();

            // Notify store
            $notifTitle = "Inventory Write-Off: {$batch['product_name']}";
            $notifMsg   = "$qty unit(s) expired (batch #{$batch_id}, exp. {$batch['exp_date']}). "
                        . "Value lost: ₹" . number_format($lossValue, 2);
            insertNotification($conn, $store_id, null, $notifTitle, $notifMsg, 'adjustment', [
                'batch_id'   => $batch_id,
                'product_id' => $product_id,
                'qty'        => $qty,
                'loss_value' => $lossValue,
                'exp_date'   => $batch['exp_date'],
            ]);

            $details[] = [
                'batch_id'     => $batch_id,
                'product_id'   => $product_id,
                'product_name' => $batch['product_name'],
                'store_id'     => $store_id,
                'qty_written'  => $qty,
                'loss_value'   => $lossValue,
                'exp_date'     => $batch['exp_date'],
            ];
            $processed++;

        } catch (Exception $txErr) {
            $conn->rollBack();
            logError("process_expired_batches batch #{$batch_id}: " . $txErr->getMessage());
        }
    }

    
    
    

    $msg = $dryRun
        ? count($details) . " expired batch(es) would be written off (dry run)"
        : "$processed expired batch(es) written off";

    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
        foreach ($details as $d) {
            $tag = $dryRun ? "DRY" : "DONE";
            echo "  [$tag] Batch #{$d['batch_id']} | {$d['product_name']} "
                . "| Store {$d['store_id']} | Qty {$d['qty_written']} "
                . "| Loss ₹" . number_format($d['loss_value'], 2)
                . " | Exp {$d['exp_date']}\n";
        }
    } else {
        sendResponse(true, $msg, [
            'processed' => $processed,
            'dry_run'   => $dryRun,
            'details'   => $details,
        ]);
    }

} catch (Exception $e) {
    logError('process_expired_batches error: ' . $e->getMessage());
    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        sendResponse(false, 'Failed to process expired batches: ' . $e->getMessage());
    }
}


?>
