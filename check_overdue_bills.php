<?php
/**
 * Bill Overdue Check - Scheduled Cron Script
 * 
 * Purpose: Checks for bills past their due date and:
 * 1. Auto-updates status to 'overdue' 
 * 2. Sends notifications for newly overdue bills
 * 3. Sends reminder notifications for bills due soon (3 days)
 * 
 * Run via cron: php check_overdue_bills.php
 * Suggested schedule: Daily at 8 AM
 *   0 8 * * * php /path/to/check_overdue_bills.php
 */

require_once 'config.php';
require_once 'notification_helper.php';
require_once 'smtp_mailer.php';

$conn = getDBConnection();

try {
    // 1. Mark overdue bills (past due_date, still pending/partial)
    $overdueStmt = $conn->prepare("
        SELECT b.bill_id, b.bill_number, b.store_id, b.supplier_id, b.total, b.amount_paid, b.due_date,
               COALESCE(s.supplier_name, 'Unknown') as supplier_name,
               DATEDIFF(CURDATE(), b.due_date) as days_overdue
        FROM bills b
        LEFT JOIN suppliers s ON b.supplier_id = s.supplier_id
        WHERE b.status IN ('pending', 'partial')
        AND b.due_date < CURDATE()
    ");
    $overdueStmt->execute();
    // PDO result ready in $overdueStmt
    $overdueResult = $overdueStmt;
    
    $overdueCount = 0;
    while ($bill = $overdueResult->fetch(PDO::FETCH_ASSOC)) {
        // Update status to overdue
        $updateStmt = $conn->prepare("UPDATE bills SET status = 'overdue' WHERE bill_id = ?");
        // PDO bind
$updateStmt->execute([$bill['bill_id']]);$updateStmt->execute();
        
        
        // Send notification
        $outstanding = $bill['total'] - $bill['amount_paid'];
        $daysOverdue = (int)$bill['days_overdue'];
        $title = "⚠️ Bill Overdue: " . $bill['bill_number'];
        $message = "Bill to " . $bill['supplier_name'] . " is " . $daysOverdue . " day(s) overdue. Outstanding: ₹" . number_format($outstanding, 2);
        
        $data = [
            'bill_id' => $bill['bill_id'],
            'bill_number' => $bill['bill_number'],
            'supplier_name' => $bill['supplier_name'],
            'outstanding' => $outstanding,
            'days_overdue' => $daysOverdue
        ];
        
        insertNotification($conn, $bill['store_id'], null, $title, $message, 'bill', $data);

        // Email store admins about the overdue bill
        $emailBody = "
            <h2 style='color:#c0392b'>Bill Overdue</h2>
            <p>Bill <strong>{$bill['bill_number']}</strong> to <strong>{$bill['supplier_name']}</strong>
               is <strong>{$daysOverdue} day(s) overdue</strong>.</p>
            <table style='border-collapse:collapse;width:100%'>
              <tr><td style='padding:6px;color:#555'>Due Date</td><td style='padding:6px'><strong>{$bill['due_date']}</strong></td></tr>
              <tr><td style='padding:6px;color:#555'>Outstanding</td><td style='padding:6px;color:#c0392b'><strong>&#8377;" . number_format($outstanding, 2) . "</strong></td></tr>
            </table>
            <p style='margin-top:16px;color:#555'>Please arrange payment as soon as possible to avoid further penalties.</p>
            <p style='color:#aaa;font-size:12px'>— StockPilot Automated Alert</p>";
        sendEmailToStoreAdmins($conn, $bill['store_id'], "⚠ Bill Overdue: {$bill['bill_number']}", $emailBody);

        $overdueCount++;
    }
    
    
    // 2. Send reminders for bills due within 3 days
    $upcomingStmt = $conn->prepare("
        SELECT b.bill_id, b.bill_number, b.store_id, b.total, b.amount_paid, b.due_date,
               COALESCE(s.supplier_name, 'Unknown') as supplier_name,
               DATEDIFF(b.due_date, CURDATE()) as days_until_due
        FROM bills b
        LEFT JOIN suppliers s ON b.supplier_id = s.supplier_id
        WHERE b.status IN ('pending', 'partial')
        AND b.due_date >= CURDATE()
        AND b.due_date <= CURDATE() + INTERVAL '3 days'
    ");
    $upcomingStmt->execute();
    // PDO result ready in $upcomingStmt
    $upcomingResult = $upcomingStmt;
    
    $reminderCount = 0;
    while ($bill = $upcomingResult->fetch(PDO::FETCH_ASSOC)) {
        $outstanding = $bill['total'] - $bill['amount_paid'];
        $daysUntil = (int)$bill['days_until_due'];
        $dueText = $daysUntil === 0 ? "due today" : "due in " . $daysUntil . " day(s)";
        
        $title = "📋 Bill Due Soon: " . $bill['bill_number'];
        $message = "Bill to " . $bill['supplier_name'] . " is " . $dueText . ". Outstanding: ₹" . number_format($outstanding, 2);
        
        $data = [
            'bill_id' => $bill['bill_id'],
            'bill_number' => $bill['bill_number'],
            'supplier_name' => $bill['supplier_name'],
            'outstanding' => $outstanding,
            'days_until_due' => $daysUntil
        ];
        
        insertNotification($conn, $bill['store_id'], null, $title, $message, 'bill', $data);

        // Email reminder to store admins
        $dueLabel  = $daysUntil === 0 ? 'today' : "in {$daysUntil} day(s)";
        $emailBody = "
            <h2 style='color:#e67e22'>Bill Payment Reminder</h2>
            <p>Bill <strong>{$bill['bill_number']}</strong> to <strong>{$bill['supplier_name']}</strong>
               is due <strong>{$dueLabel}</strong>.</p>
            <table style='border-collapse:collapse;width:100%'>
              <tr><td style='padding:6px;color:#555'>Due Date</td><td style='padding:6px'><strong>{$bill['due_date']}</strong></td></tr>
              <tr><td style='padding:6px;color:#555'>Outstanding</td><td style='padding:6px'><strong>&#8377;" . number_format($outstanding, 2) . "</strong></td></tr>
            </table>
            <p style='margin-top:16px;color:#555'>Please ensure payment is processed on time.</p>
            <p style='color:#aaa;font-size:12px'>— StockPilot Automated Reminder</p>";
        sendEmailToStoreAdmins($conn, $bill['store_id'], "📋 Bill Due {$dueLabel}: {$bill['bill_number']}", $emailBody);

        $reminderCount++;
    }
    
    
    // 3. Summary (for cron log output)
    $summary = [
        'overdue_marked' => $overdueCount,
        'reminders_sent' => $reminderCount,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // If called via HTTP, send JSON response
    if (php_sapi_name() !== 'cli') {
        sendResponse(true, 'Overdue check completed', $summary);
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Overdue bills marked: $overdueCount, Reminders sent: $reminderCount\n";
    }
    
} catch (Exception $e) {
    logError('Check overdue bills error: ' . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        sendResponse(false, 'Failed to check overdue bills');
    } else {
        echo "[ERROR] " . $e->getMessage() . "\n";
    }
}


?>
