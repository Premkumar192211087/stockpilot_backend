<?php
/**
 * Get Financial Reports - Enhanced Analytics
 * Returns: revenue, costs, profit, invoice status, cash flow, trend data
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $from_date = $_GET['from_date'] ?? $_GET['start_date'] ?? '2025-01-01';
    $to_date = $_GET['to_date'] ?? $_GET['end_date'] ?? date('Y-m-d');
    $period = $_GET['period'] ?? 'monthly'; // daily, weekly, monthly
    
    $report = [];
    
    // 1. Total Sales Count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_sales
        FROM sales WHERE store_id = ? AND sale_date BETWEEN ? AND ?
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    $report['total_sales'] = (string)$stmt->fetch(PDO::FETCH_ASSOC)['total_sales'];
    
    
    // 2. Total Revenue
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(final_amount), 0) as total_revenue
        FROM sales WHERE store_id = ? AND sale_date BETWEEN ? AND ?
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    $report['total_revenue'] = number_format((float)$stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'], 2, '.', '');
    
    
    // 3. Total Purchase Costs
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total_purchases
        FROM purchase_orders WHERE store_id = ? AND order_date BETWEEN ? AND ?
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    $total_purchases = (float)$stmt->fetch(PDO::FETCH_ASSOC)['total_purchases'];
    $report['total_purchases'] = number_format($total_purchases, 2, '.', '');
    
    
    // 4. Total Returns
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_refund_amount), 0) as total_returns
        FROM returns WHERE store_id = ? AND return_date BETWEEN ? AND ?
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $res = $stmt;
    $total_returns = $res->num_rows > 0 ? (float)$res->fetch(PDO::FETCH_ASSOC)['total_returns'] : 0;
    $report['total_returns'] = number_format($total_returns, 2, '.', '');
    
    
    // 5. Net Profit
    $net_profit = (float)$report['total_revenue'] - $total_purchases - $total_returns;
    $report['net_profit'] = number_format($net_profit, 2, '.', '');
    
    // 6. Invoice Status Breakdown
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(status, 'unknown') as status,
            COUNT(*) as count,
            COALESCE(SUM(total), 0) as amount
        FROM invoices
        WHERE store_id = ? AND issue_date BETWEEN ? AND ?
        GROUP BY status
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $paid = 0; $unpaid = 0; $partial = 0;
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $s = strtolower($row['status']);
        if ($s === 'paid') $paid = (int)$row['count'];
        elseif ($s === 'unpaid' || $s === 'pending') $unpaid = (int)$row['count'];
        elseif ($s === 'partial' || $s === 'partially_paid') $partial = (int)$row['count'];
    }
    $report['paid_invoices'] = (string)$paid;
    $report['unpaid_invoices'] = (string)$unpaid;
    $report['partial_invoices'] = (string)$partial;
    
    
    // 7. Cash Amount (payments received via cash)
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(final_amount), 0) as cash_amount
        FROM sales
        WHERE store_id = ? AND sale_date BETWEEN ? AND ?
        AND LOWER(payment_method) = 'cash'
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    $report['cash_amount'] = number_format((float)$stmt->fetch(PDO::FETCH_ASSOC)['cash_amount'], 2, '.', '');
    
    
    // 8. Sales Trend Data for Chart
    $date_format = "DATE(sale_date)";
    $group_label = "DATE(sale_date)";
    if ($period === 'weekly') {
        $date_format = "DATE(DATE_SUB(sale_date, INTERVAL WEEKDAY(sale_date) DAY))";
        $group_label = "YEARWEEK(sale_date, 1)";
    } elseif ($period === 'monthly') {
        $date_format = "DATE_FORMAT(sale_date, '%Y-%m-01')";
        $group_label = "TO_CHAR(sale_date, 'YYYY-MM')";
    }
    
    $stmt = $conn->prepare("
        SELECT 
            $date_format as period_date,
            COUNT(*) as order_count,
            COALESCE(SUM(final_amount), 0) as revenue
        FROM sales
        WHERE store_id = ? AND sale_date BETWEEN ? AND ?
        GROUP BY $group_label
        ORDER BY period_date ASC
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['trend_data'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $report['trend_data'][] = [
            'date' => $row['period_date'],
            'orders' => (int)$row['order_count'],
            'revenue' => (float)$row['revenue']
        ];
    }
    
    
    // 9. Expense Categories (from purchase orders by vendor)
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(s.supplier_name, 'Unknown') as category,
            COALESCE(SUM(po.total_amount), 0) as amount
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        WHERE po.store_id = ? AND po.order_date BETWEEN ? AND ?
        GROUP BY s.supplier_id, s.supplier_name
        ORDER BY amount DESC
        LIMIT 5
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['expense_categories'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $report['expense_categories'][] = $row;
    }
    
    
    sendResponse(true, 'Financial reports retrieved successfully', $report);
    
} catch (Exception $e) {
    logError('Get financial reports error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve financial reports');
}


?>
