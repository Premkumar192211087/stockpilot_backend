<?php
/**
 * Get Bills - Enhanced with proper bills table
 * Returns vendor bills with supplier info, status filtering, search
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    // Check if bills table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'bills'");
    if ($tableCheck->num_rows === 0) {
        // Fallback: return empty array if table not created yet
        sendResponse(true, 'Bills retrieved successfully', ['bills' => [], 'summary' => ['total' => 0, 'pending' => 0, 'paid' => 0, 'overdue' => 0, 'total_amount' => 0, 'total_paid' => 0]]);
    }

    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $bill_id = $_GET['bill_id'] ?? null;

    // Single bill detail
    if ($bill_id) {
        $stmt = $conn->prepare("
            SELECT b.*, 
                COALESCE(s.supplier_name, 'Unknown') as supplier_name,
                COALESCE(s.contact_person, '') as contact_person,
                COALESCE(s.phone, '') as supplier_phone,
                COALESCE(po.po_number, '') as po_number
            FROM bills b
            LEFT JOIN suppliers s ON b.supplier_id = s.supplier_id
            LEFT JOIN purchase_orders po ON b.po_id = po.po_id
            WHERE b.bill_id = ? AND b.store_id = ?
        ");
        // PDO bind
$stmt->execute([$bill_id, $store_id]);$stmt->execute();
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        

        if (!$bill) {
            sendResponse(false, 'Bill not found');
        }

        // Get bill items
        $itemStmt = $conn->prepare("
            SELECT bi.*, COALESCE(p.product_name, bi.description, 'Unknown') as product_name
            FROM bill_items bi
            LEFT JOIN products p ON bi.product_id = p.id
            WHERE bi.bill_id = ?
        ");
        // PDO bind
$itemStmt->execute([$bill_id]);$itemStmt->execute();
        // PDO result ready in $itemStmt
    $itemResult = $itemStmt;
        $items = [];
        while ($row = $itemResult->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $row;
        }
        

        sendResponse(true, 'Bill retrieved', ['bill' => $bill, 'items' => $items]);
    }

    // Auto-mark overdue bills
    $conn->query("UPDATE bills SET status = 'overdue' WHERE store_id = $store_id AND status IN ('pending', 'partial') AND due_date < CURDATE()");

    // Build query
    $where = "b.store_id = ?";
    $params = [$store_id];
    $types = "i";

    if (!empty($status)) {
        $where .= " AND b.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if (!empty($search)) {
        $where .= " AND (b.bill_number LIKE ? OR s.supplier_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "ss";
    }

    $stmt = $conn->prepare("
        SELECT b.*, 
            COALESCE(s.supplier_name, 'Unknown') as supplier_name,
            COALESCE(po.po_number, '') as po_number
        FROM bills b
        LEFT JOIN suppliers s ON b.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON b.po_id = po.po_id
        WHERE $where
        ORDER BY b.created_at DESC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $bills = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $bills[] = $row;
    }
    

    // Summary stats
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count,
            COALESCE(SUM(total), 0) as total_amount,
            COALESCE(SUM(amount_paid), 0) as total_paid,
            COALESCE(SUM(CASE WHEN status IN ('pending','partial','overdue') THEN total - amount_paid ELSE 0 END), 0) as total_outstanding
        FROM bills
        WHERE store_id = ?
    ");
    // PDO bind
$summaryStmt->execute([$store_id]);$summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    

    sendResponse(true, 'Bills retrieved successfully', [
        'bills' => $bills,
        'summary' => $summary
    ]);

} catch (Exception $e) {
    logError('Get bills error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve bills');
}


?>
