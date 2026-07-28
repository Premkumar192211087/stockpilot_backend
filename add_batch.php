<?php
/**
 * Add Batch
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id', 'quantity']);

try {
    // Resolve product — accept product_id directly, or look up by product_name / sku
    $productId   = $data['product_id'] ?? null;
    $productName = 'Unknown Product';

    if (!$productId && !empty($data['product_name'])) {
        $ps = $conn->prepare(
            "SELECT id, product_name FROM products
             WHERE (product_name = ? OR sku = ?) AND store_id = ? LIMIT 1"
        );
        // PDO bind
$ps->execute([$data['product_name'], $data['product_name'], $data['store_id']]);$ps->execute();
        // PDO result ready in $ps
    $pr = $ps;
        if ($row = $pr->fetch(PDO::FETCH_ASSOC)) {
            $productId   = $row['id'];
            $productName = $row['product_name'];
        }
        
    } elseif ($productId) {
        $ps = $conn->prepare("SELECT product_name FROM products WHERE id = ? LIMIT 1");
        // PDO bind
$ps->execute([$productId]);$ps->execute();
        // PDO result ready in $ps
    $pr = $ps;
        if ($row = $pr->fetch(PDO::FETCH_ASSOC)) {
            $productName = $row['product_name'];
        }
        
    }

    if (!$productId) $productId = 0;

    // Accept both mfg_date/exp_date (Android) and manufacturing_date/expiry_date
    $mfgDate    = $data['mfg_date']       ?? $data['manufacturing_date'] ?? null;
    $expDate    = $data['exp_date']        ?? $data['expiry_date']        ?? null;
    $batchId    = $data['batch_id']        ?? $data['batch_number']       ?? uniqid('BATCH');
    $barcode    = $data['barcode']         ?? '';
    $storeId    = (int) $data['store_id'];
    $qty        = (int) $data['quantity'];
    $dmgQty     = (int) ($data['damaged_quantity'] ?? 0);

    $stmt = $conn->prepare("
        INSERT INTO batch_details
            (store_id, product_id, batch_id, barcode, product_name, quantity,
             mfg_date, exp_date, damaged_quantity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // PDO bind
$stmt->execute([$storeId, $productId, $batchId, $barcode, $productName, $qty,
        $mfgDate, $expDate, $dmgQty]);$stmt->execute();
    $batch_id_inserted = $conn->lastInsertId();

    // Expiry warning notification
    if (!empty($expDate)) {
        $expiryDate    = new DateTime($expDate);
        $now           = new DateTime();
        $daysRemaining = (int) $now->diff($expiryDate)->format('%r%a');

        if ($daysRemaining >= 0 && $daysRemaining <= 7) {
            notifyBatchExpiry(
                $conn, $storeId, $data['user_id'] ?? 1,
                $batch_id_inserted, $productName, $expDate, $daysRemaining, $qty
            );
        }
    }

    sendResponse(true, 'Batch added successfully', [
        'batch_id'   => $batch_id_inserted,
        'product_id' => $productId,
    ]);

} catch (Exception $e) {
    logError('Add batch error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add batch: ' . $e->getMessage());
}



