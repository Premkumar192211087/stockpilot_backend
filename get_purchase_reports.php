<?php
/**
 * Get Purchase Reports - Enhanced Analytics
 */
require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) { sendResponse(false, 'Store ID is required'); }

$conn = getDBConnection();
$from_date = $_GET['from_date'] ?? $_GET['start_date'] ?? '2025-01-01';
$to_date = $_GET['to_date'] ?? $_GET['end_date'] ?? date('Y-m-d');

try {
    $report = [];

    // Summary
    $stmt = $conn->prepare("SELECT COUNT(*) as total_orders, COALESCE(SUM(total_amount),0) as total_purchase_amount, COALESCE(AVG(total_amount),0) as avg_val FROM purchase_orders WHERE store_id=? AND order_date BETWEEN ? AND ?");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    $report['purchase_summary'] = ['total_purchase_amount'=>(float)$s['total_purchase_amount'],'total_orders'=>(int)$s['total_orders'],'average_order_value'=>round((float)$s['avg_val'],2)];
    

    // Vendor Distribution
    $ta = max((float)$s['total_purchase_amount'],1);
    $stmt = $conn->prepare("SELECT COALESCE(s.supplier_name,'Unknown') as vendor_name, COUNT(*) as cnt, COALESCE(SUM(po.total_amount),0) as amount FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.supplier_id WHERE po.store_id=? AND po.order_date BETWEEN ? AND ? GROUP BY s.supplier_id, s.supplier_name ORDER BY amount DESC");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['vendor_distribution'] = [];
    while ($r = $result->fetch(PDO::FETCH_ASSOC)) { $a=(float)$r['amount']; $report['vendor_distribution'][]=['vendor_name'=>$r['vendor_name'],'amount'=>$a,'percentage'=>round(($a/$ta)*100,1)]; }
    

    // Status Breakdown
    $to = max((int)$s['total_orders'],1);
    $stmt = $conn->prepare("SELECT COALESCE(status,'unknown') as status, COUNT(*) as count FROM purchase_orders WHERE store_id=? AND order_date BETWEEN ? AND ? GROUP BY status");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['purchase_status'] = [];
    while ($r = $result->fetch(PDO::FETCH_ASSOC)) { $report['purchase_status'][]=['status'=>$r['status'],'count'=>(int)$r['count'],'percentage'=>round(((int)$r['count']/$to)*100,1)]; }
    

    // Recent Purchases
    $stmt = $conn->prepare("SELECT po.po_id, COALESCE(s.supplier_name,'Unknown') as vendor_name, DATE(po.order_date) as order_date, po.total_amount, COALESCE(po.status,'unknown') as status FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.supplier_id WHERE po.store_id=? AND po.order_date BETWEEN ? AND ? ORDER BY po.order_date DESC LIMIT 20");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['recent_purchases'] = [];
    while ($r = $result->fetch(PDO::FETCH_ASSOC)) { $report['recent_purchases'][]=$r; }
    

    sendResponse(true, 'Purchase reports retrieved', $report);
} catch (Exception $e) {
    logError('Purchase reports error: '.$e->getMessage());
    sendResponse(false, 'Failed to retrieve purchase reports');
}

?>
