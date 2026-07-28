<?php
/**
 * Dashboard Statistics
 * Returns all KPIs needed by AdminHomeActivity
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $stats = [];

    // ============================================
    // SECTION 1: Order Fulfillment KPIs
    // ============================================

    // To Be Packed - sales orders that are paid but no shipment created yet
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM sales s
        WHERE s.store_id = ? AND s.payment_status = 'paid'
        AND s.sale_id NOT IN (
            SELECT order_id FROM shipments WHERE order_type = 'sales_order' AND store_id = ?
        )
    ");
    // PDO bind
$stmt->execute([$store_id, $store_id]);$stmt->execute();
    $stats['to_be_packed'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // To Be Shipped - shipments with status 'pending'
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM shipments WHERE store_id = ? AND status = 'pending'");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['to_be_shipped'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // To Be Delivered - shipments shipped or in_transit
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM shipments WHERE store_id = ? AND status IN ('shipped', 'in_transit')");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['to_be_delivered'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // To Be Invoiced - sales without matching invoice
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM sales s
        WHERE s.store_id = ?
        AND s.invoice_number NOT IN (
            SELECT invoice_number FROM invoices WHERE store_id = ?
        )
    ");
    // PDO bind
$stmt->execute([$store_id, $store_id]);$stmt->execute();
    $stats['to_be_invoiced'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // ============================================
    // SECTION 2: Financial KPIs
    // ============================================

    // Cash In Hand - total paid sales amount
    $stmt = $conn->prepare("SELECT COALESCE(SUM(final_amount), 0) as amount FROM sales WHERE store_id = ? AND payment_status = 'paid'");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['cash_in_hand'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['amount']);
    

    // To Be Received - unpaid/pending invoice amounts
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total), 0) as amount FROM invoices WHERE store_id = ? AND status IN ('unpaid', 'partial')");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['to_be_received'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['amount']);
    

    // Today's Revenue
    $stmt = $conn->prepare("SELECT COALESCE(SUM(final_amount), 0) as amount FROM sales WHERE store_id = ? AND DATE(sale_date) = CURDATE()");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['today_revenue'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['amount']);
    

    // ============================================
    // SECTION 3: Business Insights KPIs
    // ============================================

    // Total Customers — customers are global; a store's customers are those
    // who have transacted with this store (derived from sales).
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_id) as count FROM sales WHERE store_id = ? AND customer_id IS NOT NULL");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['total_customers'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Total Vendors
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers WHERE store_id = ? AND status = 'active'");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['total_vendors'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // New Customers This Month — store customers (from sales) registered this month.
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT s.customer_id) as count
        FROM sales s
        JOIN customers c ON c.customer_id = s.customer_id
        WHERE s.store_id = ?
          AND MONTH(c.registration_date) = MONTH(CURDATE())
          AND YEAR(c.registration_date) = YEAR(CURDATE())
    ");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['new_customers_month'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Total Products
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE store_id = ? AND status = 'active'");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // ============================================
    // SECTION 4: Alert KPIs
    // ============================================

    // Overdue Invoices (unpaid and past due date)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM invoices WHERE store_id = ? AND status = 'unpaid' AND due_date < CURDATE()");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['overdue_invoices'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Critical Stock Items (quantity < 10)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE store_id = ? AND quantity < 10 AND status = 'active'");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['critical_stock_items'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Expired Batches
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM batch_details WHERE store_id = ? AND exp_date < CURDATE()");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['expired_batches'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Low Stock Items
    $stats['low_stock_items'] = $stats['critical_stock_items'];

    // Today sales count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM sales WHERE store_id = ? AND DATE(sale_date) = CURDATE()");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['today_sales_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Monthly revenue
    $stmt = $conn->prepare("SELECT COALESCE(SUM(final_amount), 0) as amount FROM sales WHERE store_id = ? AND MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['monthly_revenue'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['amount']);
    

    // ============================================
    // SECTION 5: Accounts Payable KPIs (Bills)
    // ============================================

    // Pending Bills Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE store_id = ? AND status IN ('pending', 'partial')");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['pending_bills'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Overdue Bills Count
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE store_id = ? AND status = 'overdue'");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['overdue_bills'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    // Total Outstanding Payable (unpaid bill amounts)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total - amount_paid), 0) as amount FROM bills WHERE store_id = ? AND status IN ('pending', 'partial', 'overdue')");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['accounts_payable'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['amount']);
    

    // Bills Due Within 7 Days
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bills WHERE store_id = ? AND status IN ('pending', 'partial') AND due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL '7 days'");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    $stats['bills_due_soon'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    

    sendResponse(true, 'Dashboard stats retrieved', $stats);

} catch (Exception $e) {
    logError('Dashboard stats error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve dashboard stats');
}


?>
