<?php
/**
 * Get Invoices
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$conn = getDBConnection();

try {
    $where = "i.store_id = ?";
    $params = [$store_id];
    $types = "i";

    if ($invoice_id > 0) {
        $where .= " AND i.invoice_id = ?";
        $params[] = $invoice_id;
        $types .= "i";
    }

    if (!empty($_GET['search'])) {
        $where .= " AND (i.invoice_number LIKE ? OR c.customer_name LIKE ?)";
        $search = "%" . $_GET['search'] . "%";
        $params[] = $search;
        $params[] = $search;
        $types .= "ss";
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'All') {
        $where .= " AND i.status = ?";
        $params[] = strtolower($_GET['status']);
        $types .= "s";
    }

    $stmt = $conn->prepare("
        SELECT
            i.invoice_id,
            i.invoice_number,
            i.customer_id,
            i.issue_date,
            i.due_date,
            i.subtotal,
            i.tax,
            i.discount,
            i.total,
            i.status,
            i.notes,
            i.store_id,
            i.created_at,
            c.customer_name
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        WHERE $where
        ORDER BY i.created_at DESC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $invoices = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $invoices[] = $row;
    }

    $payload = ['invoices' => $invoices];
    if ($invoice_id > 0 && count($invoices) > 0) {
        $payload['invoice'] = $invoices[0];

        // Fetch line items via sale_items (linked through matching invoice_number)
        $itemStmt = $conn->prepare("
            SELECT
                si.sale_item_id  AS item_id,
                si.product_id,
                COALESCE(p.product_name, si.product_name, 'Unknown') AS product_name,
                p.sku,
                si.quantity,
                si.unit_price,
                si.total_price
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.sale_id
            LEFT JOIN products p ON si.product_id = p.id
            WHERE s.invoice_number = ? AND s.store_id = ?
            ORDER BY si.sale_item_id ASC
        ");
        $inv_number = $invoices[0]['invoice_number'];
        // PDO bind
$itemStmt->execute([$inv_number, $store_id]);$itemStmt->execute();
        $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $payload['items'] = $itemRows;
    }

    sendResponse(true, 'Invoices retrieved successfully', $payload);
} catch (Exception $e) {
    logError('Get invoices error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve invoices');
}

if (isset($stmt)) {
    
}

?>
