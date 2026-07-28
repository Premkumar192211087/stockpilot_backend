<?php
/**
 * Get Sales
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
$sale_id  = $_GET['sale_id']  ?? null;

if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    // Build WHERE clause with optional filters
    $where = "s.store_id = ?";
    $params = [(int)$store_id];
    $types = "i";

    // Single-sale fetch for SaleDetailActivity
    if ($sale_id) {
        $where   .= " AND s.sale_id = ?";
        $params[] = (int)$sale_id;
        $types   .= "i";
    }
    
    // Add date range filter
    if (!empty($_GET['date_range'])) {
        $dateRange = $_GET['date_range'];
        switch ($dateRange) {
            case 'Today':
                $where .= " AND DATE(s.sale_date) = CURDATE()";
                break;
            case 'This Week':
                $where .= " AND YEARWEEK(s.sale_date, 1) = YEARWEEK(CURDATE(), 1)";
                break;
            case 'This Month':
                $where .= " AND MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())";
                break;
            case 'Last Month':
                $where .= " AND MONTH(s.sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(s.sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                break;
            case 'Last 7 Days':
                $where .= " AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'Last 30 Days':
                $where .= " AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                break;
        }
    }
    
    // Add search filter (changed from search_query to search for consistency)
    $searchParam = $_GET['search'] ?? $_GET['search_query'] ?? null;
    if (!empty($searchParam)) {
        $where .= " AND (s.invoice_number LIKE ? OR c.customer_name LIKE ?)";
        $search = "%" . $searchParam . "%";
        $params[] = $search;
        $params[] = $search;
        $types .= "ss";
    }
    
    // Add payment status filter
    if (!empty($_GET['payment_status'])) {
        $where .= " AND s.payment_status = ?";
        $params[] = $_GET['payment_status'];
        $types .= "s";
    }
    
    $stmt = $conn->prepare("
        SELECT s.*, c.customer_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.customer_id
        WHERE $where
        ORDER BY s.sale_date DESC
    ");
    
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    $sales = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $sales[] = $row;
    }
    
    // Return with proper structure expected by Java
    sendResponse(true, 'Sales retrieved successfully', ['sales' => $sales]);
    
} catch (Exception $e) {
    logError('Get sales error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve sales');
}



?>
