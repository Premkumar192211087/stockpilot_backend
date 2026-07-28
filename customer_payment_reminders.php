<?php
/**
 * Customer Payment Reminders (Accounts Receivable)
 * Sends email reminders to customers for invoices that are:
 *   - Due within 3 days (friendly reminder)
 *   - Overdue (urgent reminder)
 * Also creates in-app notifications for each.
 * Deduplicates: will not re-send if a reminder was sent in the last 24 h.
 *
 * Run via cron daily (e.g. 9 AM):
 *   php customer_payment_reminders.php
 * Or via HTTP:
 *   GET /api_legacy/customer_payment_reminders.php?store_id=1
 *   GET /api_legacy/customer_payment_reminders.php?dry_run=1
 */

require_once 'config.php';
require_once 'notification_helper.php';
require_once 'smtp_mailer.php';

$isCli       = php_sapi_name() === 'cli';
$dryRun      = $isCli
    ? in_array('--dry-run', $argv ?? [], true)
    : (isset($_GET['dry_run']) && $_GET['dry_run'] == '1');
$filterStore = $isCli ? null : (isset($_GET['store_id']) ? (int)$_GET['store_id'] : null);

$conn = getDBConnection();

try {
    if ($filterStore) {
        $storeStmt = $conn->prepare("SELECT store_id, store_name FROM stores WHERE store_id = ?");
        // PDO bind
$storeStmt->execute([$filterStore]);} else {
        $storeStmt = $conn->prepare("SELECT store_id, store_name FROM stores WHERE status = 'active' OR status IS NULL");
    }
    $storeStmt->execute();
    $stores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
    

    $totalReminders = 0;
    $totalEmails    = 0;

    foreach ($stores as $storeRow) {
        $store_id   = (int)$storeRow['store_id'];
        $store_name = $storeRow['store_name'] ?? "Store #$store_id";

        // Invoices unpaid/partial with due_date within next 3 days OR already overdue
        $invStmt = $conn->prepare("
            SELECT i.invoice_id, i.invoice_number, i.due_date, i.total,
                   COALESCE(i.amount_paid, 0) AS amount_paid,
                   DATEDIFF(i.due_date, CURDATE()) AS due_in_days,
                   c.customer_id, c.customer_name, c.email AS customer_email
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.customer_id
            WHERE i.store_id = ?
              AND i.status NOT IN ('paid', 'cancelled')
              AND i.due_date IS NOT NULL
              AND DATEDIFF(i.due_date, CURDATE()) <= 3
            ORDER BY i.due_date ASC
        ");
        // PDO bind
$invStmt->execute([$store_id]);$invStmt->execute();
        $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);
        

        foreach ($invoices as $inv) {
            $dueInDays    = (int)$inv['due_in_days'];
            $isOverdue    = $dueInDays < 0;
            $outstanding  = (float)$inv['total'] - (float)$inv['amount_paid'];
            $customerName = $inv['customer_name'] ?? 'Valued Customer';
            $customerEmail= $inv['customer_email'] ?? '';

            $title = $isOverdue
                ? "Payment Overdue: Invoice #{$inv['invoice_number']}"
                : "Payment Reminder: Invoice #{$inv['invoice_number']} due in {$dueInDays} day(s)";

            // Skip if a notification with this exact title was sent in the last 24 h
            $recentCheckStmt = $conn->prepare("
                SELECT id FROM notifications
                WHERE store_id = ? AND title = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                LIMIT 1
            ");
            // PDO bind
$recentCheckStmt->execute([$store_id, $title]);$recentCheckStmt->execute();
            $alreadySent = $recentCheckStmt->rowCount() > 0;
            

            if ($alreadySent) continue;

            $dueText = $isOverdue
                ? abs($dueInDays) . ' day(s) overdue'
                : ($dueInDays === 0 ? 'due today' : "due in {$dueInDays} day(s)");

            $message = "$customerName — Invoice #{$inv['invoice_number']} is {$dueText}. "
                     . "Outstanding: ₹" . number_format($outstanding, 2);

            if (!$dryRun) {
                // In-app notification
                insertNotification($conn, $store_id, null, $title, $message, 'invoice', [
                    'invoice_id'     => (int)$inv['invoice_id'],
                    'invoice_number' => $inv['invoice_number'],
                    'customer_name'  => $customerName,
                    'outstanding'    => $outstanding,
                    'due_in_days'    => $dueInDays,
                ]);
                $totalReminders++;

                // Email the customer (only if they have an email address)
                if ($customerEmail) {
                    $headerColor = $isOverdue ? '#c0392b' : '#e67e22';
                    $headerText  = $isOverdue ? 'Payment Overdue' : 'Friendly Payment Reminder';
                    $urgencyNote = $isOverdue
                        ? "<p style='color:#c0392b;font-weight:bold'>Your payment is " . abs($dueInDays) . " day(s) overdue. Please settle at your earliest convenience.</p>"
                        : "<p style='color:#555'>Your payment is due <strong>{$dueText}</strong>. Kindly ensure timely payment to avoid any inconvenience.</p>";

                    $emailBody = "
                        <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto'>
                          <div style='background:{$headerColor};padding:20px 28px'>
                            <h2 style='color:#fff;margin:0'>{$headerText}</h2>
                            <p style='color:rgba(255,255,255,.8);margin:4px 0 0'>{$store_name}</p>
                          </div>
                          <div style='padding:24px 28px;background:#fff'>
                            <p style='color:#333'>Dear <strong>{$customerName}</strong>,</p>
                            {$urgencyNote}
                            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                              <tr style='background:#f5f6fa'>
                                <td style='padding:10px;color:#555'>Invoice Number</td>
                                <td style='padding:10px;font-weight:bold'>{$inv['invoice_number']}</td>
                              </tr>
                              <tr>
                                <td style='padding:10px;color:#555'>Due Date</td>
                                <td style='padding:10px'>{$inv['due_date']}</td>
                              </tr>
                              <tr style='background:#f5f6fa'>
                                <td style='padding:10px;color:#555'>Amount Outstanding</td>
                                <td style='padding:10px;font-weight:bold;color:{$headerColor}'>&#8377;" . number_format($outstanding, 2) . "</td>
                              </tr>
                            </table>
                            <p style='color:#555'>If you have already made the payment, please disregard this reminder.</p>
                            <p style='color:#555'>Thank you for your business.</p>
                            <p style='color:#aaa;font-size:12px;margin-top:24px'>— {$store_name} via StockPilot</p>
                          </div>
                        </div>";

                    $subject = $isOverdue
                        ? "[{$store_name}] Payment Overdue — Invoice #{$inv['invoice_number']}"
                        : "[{$store_name}] Payment Due {$dueText} — Invoice #{$inv['invoice_number']}";

                    $recipient = "{$customerName} <{$customerEmail}>";
                    if (sendEmail($recipient, $subject, $emailBody)) {
                        $totalEmails++;
                    }
                }
            } else {
                // Dry run output
                if ($isCli) {
                    echo "  [DRY] Store {$store_id} | {$inv['invoice_number']} | {$customerName} | {$dueText} | ₹" . number_format($outstanding, 2) . " | Email: " . ($customerEmail ?: 'none') . "\n";
                }
                $totalReminders++;
            }
        }
    }

    $msg = $dryRun
        ? "$totalReminders reminder(s) would be sent (dry run)"
        : "$totalReminders in-app notification(s) created, $totalEmails email(s) sent";

    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
    } else {
        sendResponse(true, $msg, [
            'reminders_created' => $totalReminders,
            'emails_sent'       => $totalEmails,
            'dry_run'           => $dryRun,
        ]);
    }

} catch (Exception $e) {
    logError('customer_payment_reminders error: ' . $e->getMessage());
    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        sendResponse(false, 'Failed to send payment reminders: ' . $e->getMessage());
    }
}


?>
