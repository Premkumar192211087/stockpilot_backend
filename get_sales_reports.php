<?php
/**
 * Get Sales Reports - Enhanced Analytics
 * Returns: summary, payment breakdown, daily trends, top products, recent sales
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
    
    $report = [];
    
    // 1. Sales Summary
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(final_amount), 0) as total_sales,
            COALESCE(AVG(final_amount), 0) as average_order_value,
            COALESCE(MAX(final_amount), 0) as highest_sale,
            COALESCE(MIN(final_amount), 0) as lowest_sale
        FROM sales
        WHERE store_id = ? AND sale_date BETWEEN ? AND ?
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    $report['total_sales'] = (float)$summary['total_sales'];
    $report['total_orders'] = (int)$summary['total_orders'];
    $report['average_order_value'] = round((float)$summary['average_order_value'], 2);
    $report['highest_sale'] = (float)$summary['highest_sale'];
    $report['lowest_sale'] = (float)$summary['lowest_sale'];
    
    
    // 2. Payment Methods Breakdown
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(payment_method, 'Unknown') as method,
            COUNT(*) as count,
            COALESCE(SUM(final_amount), 0) as total
        FROM sales
        WHERE store_id = ? AND sale_date BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['payment_methods'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $report['payment_methods'][] = [
            'method' => $row['method'],
            'count' => (int)$row['count'],
            'total' => (float)$row['total']
        ];
    }
    
    
    // 3. Daily Sales Trend
    $stmt = $conn->prepare("
        SELECT 
            DATE(sale_date) as date,
            COUNT(*) as order_count,
            COALESCE(SUM(final_amount), 0) as daily_total
        FROM sales
        WHERE store_id = ? AND sale_date BETWEEN ? AND ?
        GROUP BY DATE(sale_date)
        ORDER BY date ASC
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['daily_trend'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $report['daily_trend'][] = [
            'date' => $row['date'],
            'order_count' => (int)$row['order_count'],
            'total' => (float)$row['daily_total']
        ];
    }
    
    
    // 4. Top Selling Products
    $stmt = $conn->prepare("
        SELECT 
            p.product_name,
            SUM(si.quantity) as total_qty_sold,
            SUM(si.quantity * si.unit_price) as total_revenue
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.sale_id
        JOIN products p ON si.product_id = p.id
        WHERE s.store_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY si.product_id, p.product_name
        ORDER BY total_qty_sold DESC
        LIMIT 10
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['top_products'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $report['top_products'][] = [
            'product_name' => $row['product_name'],
            'quantity_sold' => (int)$row['total_qty_sold'],
            'revenue' => (float)$row['total_revenue']
        ];
    }
    
    
    // 5. Sales by Status
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(payment_status, 'unknown') as status,
            COUNT(*) as count,
            COALESCE(SUM(final_amount), 0) as total
        FROM sales
        WHERE store_id = ? AND sale_date BETWEEN ? AND ?
        GROUP BY payment_status
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['sales_by_status'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $report['sales_by_status'][] = [
            'status' => $row['status'],
            'count' => (int)$row['count'],
            'total' => (float)$row['total']
        ];
    }
    
    
    // 6. Recent Sales (last 20)
    $stmt = $conn->prepare("
        SELECT 
            s.sale_id,
            s.invoice_number,
            COALESCE(c.customer_name, 'Walk-in Customer') as customer_name,
            DATE(s.sale_date) as date,
            s.final_amount as amount,
            COALESCE(s.payment_method, 'Unknown') as payment_method,
            COALESCE(s.payment_status, 'unknown') as payment_status
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        WHERE s.store_id = ? AND s.sale_date BETWEEN ? AND ?
        ORDER BY s.sale_date DESC
        LIMIT 20
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['recent_sales'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $report['recent_sales'][] = $row;
    }
    
    
    // 7. Revenue by Category (for Pie Chart)
    $stmt = $conn->prepare("
        SELECT
            COALESCE(p.category, 'Uncategorized') as category,
            COALESCE(SUM(si.total_price), 0) as total_value
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.sale_id
        JOIN products p ON si.product_id = p.id
        WHERE s.store_id = ? AND s.sale_date BETWEEN ? AND ?
        GROUP BY p.category
        ORDER BY total_value DESC
    ");
    // PDO bind
$stmt->execute([$store_id, $from_date, $to_date]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    $report['revenue_by_category'] = [];
    $report['stock_by_category'] = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $entry = [
            'category' => $row['category'],
            'category_name' => $row['category'],
            'total_value' => (float)$row['total_value'],
            'revenue' => (float)$row['total_value']
        ];
        $report['revenue_by_category'][] = $entry;
        $report['stock_by_category'][] = $entry;
    }
    
    
    sendResponse(true, 'Sales reports retrieved successfully', $report);
    
} catch (Exception $e) {
    logError('Get sales reports error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve sales reports');
}


?>
