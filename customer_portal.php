<?php
/**
 * Customer Portal API
 *
 * GET ?action=dashboard|orders|invoices|profile|order_detail
 *     &customer_id=X
 *     &store_id=Y   (optional — filters orders/invoices by store; 0 means all stores)
 */
require_once 'config.php';

$customer_id = (int)($_GET['customer_id'] ?? 0);
$store_id    = (int)($_GET['store_id']    ?? 0); // 0 = no filter
$action      = $_GET['action'] ?? 'dashboard';

if ($customer_id <= 0) {
    sendResponse(false, 'customer_id is required');
}

$conn = getDBConnection();

// Helper: build a WHERE clause fragment that optionally filters by store
function storeClause(int $storeId, string $alias = ''): string {
    $col = $alias ? "$alias.store_id" : "store_id";
    return $storeId > 0 ? "AND $col = $storeId" : "";
}

try {
    switch ($action) {

        // ----------------------------------------------------------------
        case 'dashboard':
        // ----------------------------------------------------------------
            $sc   = storeClause($store_id, 's');
            $stats = [];

            // Total orders from sales table (legacy POS)
            $r = $conn->query("SELECT COUNT(*) v FROM sales s WHERE s.customer_id=$customer_id $sc");
            $stats['total_orders'] = (int)$r->fetch(PDO::FETCH_ASSOC)['v'];

            // Total spent
            $r = $conn->query("SELECT COALESCE(SUM(final_amount),0) v FROM sales s WHERE s.customer_id=$customer_id AND payment_status='paid' $sc");
            $stats['total_spent'] = (float)$r->fetch(PDO::FETCH_ASSOC)['v'];

            // Pending invoices
            $ic = storeClause($store_id, 'i');
            $r  = $conn->query("SELECT COUNT(*) v FROM invoices i WHERE i.customer_id=$customer_id AND i.status NOT IN ('paid','cancelled') $ic");
            $stats['pending_invoices'] = (int)$r->fetch(PDO::FETCH_ASSOC)['v'];

            // Outstanding amount
            $r = $conn->query("SELECT COALESCE(SUM(total),0) v FROM invoices i WHERE i.customer_id=$customer_id AND i.status NOT IN ('paid','cancelled') $ic");
            $stats['outstanding_amount'] = (float)$r->fetch(PDO::FETCH_ASSOC)['v'];

            // Loyalty points — customer-wide, no store filter
            $r = $conn->query("SELECT COALESCE(loyalty_points,0) v FROM customers WHERE customer_id=$customer_id");
            $stats['loyalty_points'] = (int)$r->fetch(PDO::FETCH_ASSOC)['v'];

            // Recent 3 sales orders
            $r = $conn->query("
                SELECT sale_id, invoice_number, sale_date, final_amount, payment_status
                FROM sales s
                WHERE s.customer_id=$customer_id $sc
                ORDER BY sale_date DESC LIMIT 3
            ");
            $stats['recent_orders'] = $r->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, 'Dashboard loaded', ['stats' => $stats]);
            break;

        // ----------------------------------------------------------------
        case 'orders':
        // ----------------------------------------------------------------
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = 20;
            $offset = ($page - 1) * $limit;
            $sc     = storeClause($store_id, 's');

            $r = $conn->query("
                SELECT s.sale_id, s.invoice_number, s.sale_date,
                       s.total_amount, s.tax_amount, s.discount_amount, s.final_amount,
                       s.payment_method, s.payment_status, s.notes,
                       (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.sale_id) AS item_count
                FROM sales s
                WHERE s.customer_id = $customer_id $sc
                ORDER BY s.sale_date DESC
                LIMIT $limit OFFSET $offset
            ");
            $orders = $r->fetchAll(PDO::FETCH_ASSOC);

            $r2    = $conn->query("SELECT COUNT(*) v FROM sales s WHERE s.customer_id=$customer_id $sc");
            $total = (int)$r2->fetch(PDO::FETCH_ASSOC)['v'];

            sendResponse(true, 'Orders loaded', [
                'orders'      => $orders,
                'total'       => $total,
                'page'        => $page,
                'total_pages' => (int)ceil($total / $limit),
            ]);
            break;

        // ----------------------------------------------------------------
        case 'order_detail':
        // ----------------------------------------------------------------
            $sale_id = (int)($_GET['sale_id'] ?? 0);
            if ($sale_id <= 0) sendResponse(false, 'sale_id required');
            $sc = storeClause($store_id);

            $stmt = $conn->prepare("
                SELECT sale_id, invoice_number, sale_date,
                       total_amount, tax_amount, discount_amount, final_amount,
                       payment_method, payment_status, notes
                FROM sales
                WHERE sale_id=? AND customer_id=?
            ");
            // PDO bind
$stmt->execute([$sale_id, $customer_id]);$stmt->execute();
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            

            if (!$order) sendResponse(false, 'Order not found');

            $i = $conn->prepare("
                SELECT si.sale_item_id, si.product_id,
                       COALESCE(p.product_name,'Unknown') AS product_name,
                       p.sku, si.quantity, si.unit_price, si.total_price
                FROM sale_items si
                LEFT JOIN products p ON si.product_id = p.id
                WHERE si.sale_id = ?
            ");
            // PDO bind
$i->execute([$sale_id]);$i->execute();
            $items = $i->fetchAll(PDO::FETCH_ASSOC);
            

            sendResponse(true, 'Order detail loaded', ['order' => $order, 'items' => $items]);
            break;

        // ----------------------------------------------------------------
        case 'invoices':
        // ----------------------------------------------------------------
            $ic = storeClause($store_id);
            $r  = $conn->query("
                SELECT invoice_id, invoice_number, issue_date, due_date,
                       subtotal, tax, discount, total, status, notes
                FROM invoices
                WHERE customer_id=$customer_id $ic
                ORDER BY issue_date DESC LIMIT 50
            ");
            sendResponse(true, 'Invoices loaded', ['invoices' => $r->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ----------------------------------------------------------------
        case 'profile':
        // ----------------------------------------------------------------
            // No store_id filter — store_id no longer on customers table
            $s = $conn->prepare("
                SELECT customer_id, customer_name, email, phone, address,
                       loyalty_points, registration_date, status, profile_image
                FROM customers
                WHERE customer_id = ?
            ");
            // PDO bind
$s->execute([$customer_id]);$s->execute();
            $profile = $s->fetch(PDO::FETCH_ASSOC);
            

            if (!$profile) sendResponse(false, 'Customer not found');
            sendResponse(true, 'Profile loaded', ['customer' => $profile]);
            break;

        default:
            sendResponse(false, "Unknown action: $action");
    }

} catch (Exception $e) {
    logError('customer_portal: ' . $e->getMessage());
    sendResponse(false, 'Failed: ' . $e->getMessage());
}


?>
