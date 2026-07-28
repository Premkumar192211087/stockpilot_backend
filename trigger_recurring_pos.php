<?php
/**
 * Trigger Recurring Purchase Orders
 * Calls the run_due_recurring_purchase_orders() stored procedure which creates
 * purchase orders from any recurring templates whose next_run_date <= today.
 *
 * Call via cron daily:
 *   php trigger_recurring_pos.php
 * Or via HTTP:
 *   GET /api_legacy/trigger_recurring_pos.php
 *   GET /api_legacy/trigger_recurring_pos.php?dry_run=1  (preview without executing)
 */

require_once 'config.php';
require_once 'notification_helper.php';

$isCli = php_sapi_name() === 'cli';

$dryRun = $isCli
    ? in_array('--dry-run', $argv ?? [], true)
    : (isset($_GET['dry_run']) && $_GET['dry_run'] == '1');

$conn = getDBConnection();

try {
    // Count templates due before running so we can report what was processed
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS due_count
        FROM recurring_po_templates
        WHERE is_active = 1 AND next_run_date <= CURRENT_DATE()
    ");
    $countStmt->execute();
    $dueCount = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['due_count'];
    

    if ($dueCount === 0) {
        $msg = 'No recurring PO templates are due today';
        if ($isCli) {
            echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
        } else {
            sendResponse(true, $msg, ['due_count' => 0, 'pos_created' => 0, 'dry_run' => $dryRun]);
        }
        
        exit;
    }

    if ($dryRun) {
        // Return preview of what would be created without executing
        $previewStmt = $conn->prepare("
            SELECT t.template_id, t.template_name, t.store_id, t.frequency,
                   t.next_run_date, t.auto_approve,
                   s.supplier_name,
                   COALESCE(SUM(i.quantity * i.unit_price), 0) AS estimated_total
            FROM recurring_po_templates t
            JOIN suppliers s ON t.supplier_id = s.supplier_id
            LEFT JOIN recurring_po_items i ON i.template_id = t.template_id
            WHERE t.is_active = 1 AND t.next_run_date <= CURRENT_DATE()
            GROUP BY t.template_id
            ORDER BY t.next_run_date, t.template_id
        ");
        $previewStmt->execute();
        $preview = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
        

        if ($isCli) {
            echo "[DRY RUN] " . count($preview) . " template(s) due:\n";
            foreach ($preview as $row) {
                echo "  - Template #{$row['template_id']} \"{$row['template_name']}\" "
                    . "| Supplier: {$row['supplier_name']} "
                    . "| Est: ₹" . number_format($row['estimated_total'], 2)
                    . " | Auto-approve: " . ($row['auto_approve'] ? 'yes' : 'no') . "\n";
            }
        } else {
            sendResponse(true, "$dueCount template(s) would be processed (dry run)", [
                'due_count' => $dueCount,
                'dry_run' => true,
                'templates' => $preview,
            ]);
        }
        
        exit;
    }

    // Execute the stored procedure
    $conn->query("CALL run_due_recurring_purchase_orders()");

    // Count POs just created (created in the last minute from recurring templates)
    $createdStmt = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM purchase_orders
        WHERE notes LIKE 'Generated from recurring PO template%'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $posCreated = (int)$createdStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    $summary = [
        'due_count'   => $dueCount,
        'pos_created' => $posCreated,
        'dry_run'     => false,
        'timestamp'   => date('Y-m-d H:i:s'),
    ];

    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] Recurring PO run complete. "
            . "Templates due: $dueCount | POs created: $posCreated\n";
    } else {
        sendResponse(true, "Recurring POs processed: $posCreated created from $dueCount due template(s)", $summary);
    }

} catch (Exception $e) {
    logError('trigger_recurring_pos error: ' . $e->getMessage());
    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        sendResponse(false, 'Failed to trigger recurring POs: ' . $e->getMessage());
    }
}


?>
