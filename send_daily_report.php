<?php
/**
 * Send Daily KPI Report Email
 * Fetches dashboard stats for every active store and emails a formatted
 * digest to the store's admin/owner.
 *
 * Run via cron daily (e.g. 7 AM):
 *   php send_daily_report.php
 * Or via HTTP (single store):
 *   GET /api_legacy/send_daily_report.php?store_id=1
 */

require_once 'config.php';
require_once 'notification_helper.php';
require_once 'smtp_mailer.php';

$isCli       = php_sapi_name() === 'cli';
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
    

    $emailsSent = 0;

    foreach ($stores as $storeRow) {
        $store_id   = (int)$storeRow['store_id'];
        $store_name = $storeRow['store_name'] ?? "Store #$store_id";

        $kpi = fetchStoreKPIs($conn, $store_id);
        $html = buildReportEmail($store_name, $kpi);
        $subject = "[StockPilot] Daily Report — $store_name — " . date('d M Y');

        $sent = sendEmailToStoreAdmins($conn, $store_id, $subject, $html);
        $emailsSent += $sent;

        if ($isCli) {
            echo "[" . date('Y-m-d H:i:s') . "] $store_name: $sent email(s) sent\n";
        }
    }

    $msg = "Daily report sent to $emailsSent recipient(s) across " . count($stores) . " store(s)";
    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] Done. $msg\n";
    } else {
        sendResponse(true, $msg, ['emails_sent' => $emailsSent, 'stores' => count($stores)]);
    }

} catch (Exception $e) {
    logError('send_daily_report error: ' . $e->getMessage());
    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        sendResponse(false, 'Failed to send daily report: ' . $e->getMessage());
    }
}



// ---------------------------------------------------------------------------

function fetchStoreKPIs($conn, $store_id) {
    $kpi = [];

    $queries = [
        'today_sales_count'   => "SELECT COUNT(*) v FROM sales WHERE store_id=? AND DATE(sale_date)=CURDATE()",
        'today_revenue'       => "SELECT COALESCE(SUM(final_amount),0) v FROM sales WHERE store_id=? AND DATE(sale_date)=CURDATE()",
        'monthly_revenue'     => "SELECT COALESCE(SUM(final_amount),0) v FROM sales WHERE store_id=? AND MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())",
        'to_be_received'      => "SELECT COALESCE(SUM(total),0) v FROM invoices WHERE store_id=? AND status IN ('unpaid','partial')",
        'overdue_invoices'    => "SELECT COUNT(*) v FROM invoices WHERE store_id=? AND status NOT IN ('paid','cancelled') AND due_date < CURDATE()",
        'pending_bills'       => "SELECT COUNT(*) v FROM bills WHERE store_id=? AND status IN ('pending','partial')",
        'overdue_bills'       => "SELECT COUNT(*) v FROM bills WHERE store_id=? AND status='overdue'",
        'accounts_payable'    => "SELECT COALESCE(SUM(total-amount_paid),0) v FROM bills WHERE store_id=? AND status IN ('pending','partial','overdue')",
        'low_stock_items'     => "SELECT COUNT(*) v FROM products p LEFT JOIN inventory_alerts ia ON ia.product_id=p.id AND ia.store_id=p.store_id WHERE p.store_id=? AND p.status='active' AND p.quantity<=COALESCE(ia.min_stock_level,10)",
        'expired_batches'     => "SELECT COUNT(*) v FROM batch_details WHERE store_id=? AND quantity>0 AND exp_date<CURDATE()",
        'to_be_packed'        => "SELECT COUNT(*) v FROM sales s WHERE s.store_id=? AND s.payment_status='paid' AND s.sale_id NOT IN (SELECT order_id FROM shipments WHERE order_type='sales_order' AND store_id=?)",
        'pending_pos'         => "SELECT COUNT(*) v FROM purchase_orders WHERE store_id=? AND status IN ('draft','pending')",
    ];

    foreach ($queries as $key => $sql) {
        // to_be_packed uses two bind params
        $stmt = $conn->prepare($sql);
        if ($key === 'to_be_packed') {
            // PDO bind
$stmt->execute([$store_id, $store_id]);} else {
            // PDO bind
$stmt->execute([$store_id]);}
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $kpi[$key] = $row['v'] ?? 0;
        
    }

    return $kpi;
}

function buildReportEmail($store_name, $kpi) {
    $date = date('l, d F Y');
    $r    = fn($v) => '&#8377;' . number_format((float)$v, 2);
    $n    = fn($v) => (int)$v;

    $alertRows = '';
    if ((int)$kpi['low_stock_items'] > 0)  $alertRows .= alertRow('Low Stock Items',      $kpi['low_stock_items'],  '#e74c3c');
    if ((int)$kpi['expired_batches'] > 0)  $alertRows .= alertRow('Expired Batches',      $kpi['expired_batches'],  '#e74c3c');
    if ((int)$kpi['overdue_invoices'] > 0) $alertRows .= alertRow('Overdue Invoices',     $kpi['overdue_invoices'], '#e67e22');
    if ((int)$kpi['overdue_bills'] > 0)    $alertRows .= alertRow('Overdue Bills',         $kpi['overdue_bills'],    '#e67e22');
    if ($alertRows === '') $alertRows = '<tr><td colspan="2" style="padding:8px;color:#27ae60">&#10003; No critical alerts today</td></tr>';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f6fa;margin:0;padding:0">
<div style="max-width:620px;margin:24px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">

  <!-- Header -->
  <div style="background:#2c3e50;padding:24px 32px">
    <h1 style="color:#fff;margin:0;font-size:20px">StockPilot Daily Report</h1>
    <p style="color:#95a5a6;margin:4px 0 0">{$store_name} &mdash; {$date}</p>
  </div>

  <!-- Sales Summary -->
  <div style="padding:24px 32px">
    <h2 style="color:#2c3e50;font-size:15px;margin:0 0 12px">Sales Today</h2>
    <table style="width:100%;border-collapse:collapse">
      <tr>
        <td style="padding:8px 0;color:#555">Orders</td>
        <td style="padding:8px 0;text-align:right;font-weight:bold">{$n($kpi['today_sales_count'])}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#555">Revenue Today</td>
        <td style="padding:8px 0;text-align:right;font-weight:bold;color:#27ae60">{$r($kpi['today_revenue'])}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#555">Revenue This Month</td>
        <td style="padding:8px 0;text-align:right;font-weight:bold">{$r($kpi['monthly_revenue'])}</td>
      </tr>
    </table>
  </div>

  <hr style="margin:0 32px;border:none;border-top:1px solid #eee">

  <!-- Receivables & Payables -->
  <div style="padding:24px 32px">
    <h2 style="color:#2c3e50;font-size:15px;margin:0 0 12px">Receivables &amp; Payables</h2>
    <table style="width:100%;border-collapse:collapse">
      <tr>
        <td style="padding:8px 0;color:#555">Outstanding Invoices (A/R)</td>
        <td style="padding:8px 0;text-align:right;font-weight:bold;color:#2980b9">{$r($kpi['to_be_received'])}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#555">Accounts Payable (Bills)</td>
        <td style="padding:8px 0;text-align:right;font-weight:bold;color:#8e44ad">{$r($kpi['accounts_payable'])}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#555">Pending Bills</td>
        <td style="padding:8px 0;text-align:right">{$n($kpi['pending_bills'])}</td>
      </tr>
    </table>
  </div>

  <hr style="margin:0 32px;border:none;border-top:1px solid #eee">

  <!-- Operations -->
  <div style="padding:24px 32px">
    <h2 style="color:#2c3e50;font-size:15px;margin:0 0 12px">Operations</h2>
    <table style="width:100%;border-collapse:collapse">
      <tr>
        <td style="padding:8px 0;color:#555">Orders to Pack</td>
        <td style="padding:8px 0;text-align:right">{$n($kpi['to_be_packed'])}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#555">Pending Purchase Orders</td>
        <td style="padding:8px 0;text-align:right">{$n($kpi['pending_pos'])}</td>
      </tr>
    </table>
  </div>

  <hr style="margin:0 32px;border:none;border-top:1px solid #eee">

  <!-- Alerts -->
  <div style="padding:24px 32px">
    <h2 style="color:#2c3e50;font-size:15px;margin:0 0 12px">Alerts Requiring Attention</h2>
    <table style="width:100%;border-collapse:collapse">
      {$alertRows}
    </table>
  </div>

  <!-- Footer -->
  <div style="background:#f5f6fa;padding:16px 32px;text-align:center">
    <p style="color:#aaa;font-size:12px;margin:0">
      Sent automatically by StockPilot &mdash; {$date}<br>
      To change notification settings, open the StockPilot app.
    </p>
  </div>

</div>
</body>
</html>
HTML;
}

function alertRow($label, $count, $color) {
    return "<tr><td style='padding:8px 0;color:#555'>{$label}</td>"
         . "<td style='padding:8px 0;text-align:right;font-weight:bold;color:{$color}'>{$count}</td></tr>";
}
?>
