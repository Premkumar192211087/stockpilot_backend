<?php
/**
 * Auto Invoice From Sales
 * Finds paid/partial sales that don't have a matching invoice yet and
 * auto-creates them. Idempotent: safe to run multiple times per day.
 *
 * Run via cron (e.g. every hour or end of day):
 *   php auto_invoice_from_sales.php
 * Or via HTTP:
 *   GET /api_legacy/auto_invoice_from_sales.php?store_id=1
 *   GET /api_legacy/auto_invoice_from_sales.php?dry_run=1
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
    // Fetch all stores (or the specified one)
    if ($filterStore) {
        $storeStmt = $conn->prepare("SELECT store_id FROM stores WHERE store_id = ?");
        // PDO bind
$storeStmt->execute([$filterStore]);} else {
        $storeStmt = $conn->prepare("SELECT store_id FROM stores WHERE status = 'active' OR status IS NULL");
    }
    $storeStmt->execute();
    $stores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
    

    $totalCreated = 0;
    $details      = [];

    foreach ($stores as $storeRow) {
        $store_id = (int)$storeRow['store_id'];

        // Sales with no matching invoice entry
        $salesStmt = $conn->prepare("
            SELECT s.sale_id, s.invoice_number, s.sale_date,
                   s.final_amount AS total_amount, s.payment_status,
                   s.customer_id,
                   COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.customer_id
            WHERE s.store_id = ?
              AND s.payment_status IN ('paid', 'partial')
              AND s.invoice_number NOT IN (
                  SELECT invoice_number FROM invoices WHERE store_id = ?
              )
            ORDER BY s.sale_date ASC
            LIMIT 200
        ");
        // PDO bind
$salesStmt->execute([$store_id, $store_id]);$salesStmt->execute();
        $salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
        

        if (empty($salesRows)) continue;

        foreach ($salesRows as $sale) {
            $invoiceStatus = $sale['payment_status'] === 'paid' ? 'paid' : 'partial';
            $issueDate     = $sale['sale_date'];
            $dueDate       = date('Y-m-d', strtotime($issueDate . ' +30 days'));

            if ($dryRun) {
                $details[] = [
                    'store_id'       => $store_id,
                    'sale_id'        => $sale['sale_id'],
                    'invoice_number' => $sale['invoice_number'],
                    'amount'         => $sale['total_amount'],
                    'status'         => $invoiceStatus,
                    'dry_run'        => true,
                ];
                continue;
            }

            $insStmt = $conn->prepare("
                INSERT INTO invoices
                    (store_id, invoice_number, customer_id, issue_date, due_date,
                     total, amount_paid, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Auto-generated from sale')
                ON CONFLICT (invoice_number) DO UPDATE SET updated_at = NOW()
            ");
            $amtPaid = $invoiceStatus === 'paid' ? $sale['total_amount'] : 0.0;
            $insStmt->execute([
                $store_id, $sale['invoice_number'], $sale['customer_id'],
                $issueDate, $dueDate,
                $sale['total_amount'], $amtPaid, $invoiceStatus
            ]);
            $invoice_id = $conn->lastInsertId();
            

            if ($invoice_id > 0) {
                notifyInvoiceCreated(
                    $conn, $store_id, null,
                    $invoice_id, $sale['invoice_number'], $sale['total_amount']
                );
                $details[] = [
                    'store_id'       => $store_id,
                    'invoice_id'     => $invoice_id,
                    'invoice_number' => $sale['invoice_number'],
                    'amount'         => $sale['total_amount'],
                    'status'         => $invoiceStatus,
                ];
                $totalCreated++;
            }
        }
    }

    $msg = $dryRun
        ? count($details) . " invoice(s) would be created (dry run)"
        : "$totalCreated invoice(s) auto-created from sales";

    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
        foreach ($details as $d) {
            $tag = isset($d['invoice_id']) ? "INV#{$d['invoice_id']}" : "(dry run)";
            echo "  Store {$d['store_id']} | $tag | {$d['invoice_number']} | ₹" . number_format($d['amount'], 2) . " | {$d['status']}\n";
        }
    } else {
        sendResponse(true, $msg, [
            'created' => $totalCreated,
            'dry_run' => $dryRun,
            'details' => $details,
        ]);
    }

} catch (Exception $e) {
    logError('auto_invoice_from_sales error: ' . $e->getMessage());
    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        sendResponse(false, 'Failed to auto-create invoices: ' . $e->getMessage());
    }
}


?>
