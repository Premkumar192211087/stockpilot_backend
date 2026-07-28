<?php
/**
 * Master Automation Runner
 * Single endpoint that runs every scheduled automation in sequence.
 * Called daily by the Android app via WorkManager — no cron or Windows
 * Task Scheduler required.
 *
 * GET /api_legacy/run_automations.php?store_id=1
 * GET /api_legacy/run_automations.php?store_id=1&dry_run=1
 *
 * Security: only processes store_id values that exist in the database.
 * Each sub-script uses output buffering so their sendResponse() calls
 * are captured rather than flushed directly (BATCH_MODE suppresses exit).
 */

define('BATCH_MODE', true);

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'GET method required');
    exit();
}

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'store_id is required');
    exit();
}

// Verify store exists
$conn = getDBConnection();
$storeCheck = $conn->prepare("SELECT store_id FROM stores WHERE store_id = ? LIMIT 1");
// PDO bind
$storeCheck->execute([$store_id]);$storeCheck->execute();
if ($storeCheck->rowCount() === 0) {
    sendResponse(false, 'Store not found');
    exit();
}



// Helper: run one automation file, capture its JSON response
function runAutomation($file, $getParams = []) {
    // Merge params into $_GET so the included script can read them
    $prevGet = $_GET;
    $_GET    = array_merge($_GET, $getParams);

    ob_start();
    include __DIR__ . '/' . $file;
    $raw = ob_get_clean();

    $_GET = $prevGet;

    $decoded = json_decode($raw, true);
    return $decoded ?? ['success' => false, 'message' => "No JSON from $file", 'raw' => $raw];
}

$startTime = microtime(true);
$results   = [];

// 1. Process expired batches (write-off inventory)
$results['expired_batches'] = runAutomation('process_expired_batches.php', [
    'store_id' => $store_id,
]);

// 2. Auto-reorder draft POs for low stock products
$results['auto_reorder'] = runAutomation('auto_reorder_pos.php', [
    'store_id' => $store_id,
]);

// 3. Trigger any due recurring PO templates
$results['recurring_pos'] = runAutomation('trigger_recurring_pos.php');

// 4. Auto-generate invoices for paid sales that don't have one
$results['auto_invoices'] = runAutomation('auto_invoice_from_sales.php', [
    'store_id' => $store_id,
]);

// 5. Mark overdue bills + send bill reminder emails
$results['overdue_bills'] = runAutomation('check_overdue_bills.php');

// 6. Customer payment reminder emails (accounts receivable)
$results['payment_reminders'] = runAutomation('customer_payment_reminders.php', [
    'store_id' => $store_id,
]);

// 7. All in-app notification triggers (low stock, expiry, PO delays,
//    shipment delays, slow movers, stale PO cancel)
$results['notification_triggers'] = runAutomation('run_notification_triggers.php', [
    'store_id' => $store_id,
]);

// 8. Supplier performance scores — weekly (run only on Sundays)
if (date('N') === '7') {
    $results['supplier_scores'] = runAutomation('update_supplier_scores.php', [
        'store_id' => $store_id,
    ]);
}

// 9. Daily KPI report email to store admins
$results['daily_report'] = runAutomation('send_daily_report.php', [
    'store_id' => $store_id,
]);

$elapsed = round(microtime(true) - $startTime, 2);

// Build a flat summary to return to the Android app
$summary = [
    'store_id'    => $store_id,
    'ran_at'      => date('Y-m-d H:i:s'),
    'elapsed_sec' => $elapsed,
    'tasks'       => [],
];

foreach ($results as $taskName => $result) {
    $summary['tasks'][$taskName] = [
        'success' => $result['success'] ?? false,
        'message' => $result['message'] ?? '',
    ];
    // Surface any task-specific counts (e.g. pos_created, created, processed)
    if (isset($result['data'])) {
        foreach (['pos_created','created','processed','updated','emails_sent',
                  'reminders_created','total_created','overdue_marked'] as $key) {
            if (isset($result['data'][$key])) {
                $summary['tasks'][$taskName][$key] = $result['data'][$key];
            }
        }
    }
}

sendResponse(true, 'Automation run complete', $summary);
exit();
?>
