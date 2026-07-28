<?php
// get_item_groups.php
// API to fetch item groups with statistics

require_once 'config.php';

$conn = getDBConnection();


$response = array();

try {
    if (!isset($_GET['store_id'])) {
        throw new Exception("Store ID is required");
    }

    $storeId = intval($_GET['store_id']);
    
    // Query to get item groups with statistics
    $sql = "SELECT 
                ig.group_id,
                ig.store_id,
                ig.group_name,
                ig.parent_group_id,
                ig.description,
                ig.color_code,
                ig.icon_name,
                ig.created_at,
                COUNT(DISTINCT igm.product_id) as total_products,
                COALESCE(SUM(p.price * p.quantity), 0) as total_value,
                COALESCE(AVG(p.price), 0) as average_price,
                COUNT(CASE WHEN p.quantity <= 10 THEN 1 END) as low_stock_count
            FROM item_groups ig
            LEFT JOIN item_group_members igm ON ig.group_id = igm.group_id
            LEFT JOIN products p ON igm.product_id = p.id
            WHERE ig.store_id = ?
            GROUP BY ig.group_id
            ORDER BY ig.group_name";
    
    $stmt = $conn->prepare($sql);
    // PDO bind
$stmt->execute([$storeId]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    $groups = array();
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $groups[] = array(
            'group_id' => (int)$row['group_id'],
            'store_id' => (int)$row['store_id'],
            'group_name' => $row['group_name'],
            'parent_group_id' => $row['parent_group_id'] ? (int)$row['parent_group_id'] : null,
            'description' => $row['description'],
            'color_code' => $row['color_code'],
            'icon_name' => $row['icon_name'],
            'created_at' => $row['created_at'],
            'total_products' => (int)$row['total_products'],
            'total_value' => (float)$row['total_value'],
            'average_price' => (float)$row['average_price'],
            'low_stock_count' => (int)$row['low_stock_count']
        );
    }
    
    $response['success'] = true;
    $response['data'] = $groups;
    $response['count'] = count($groups);
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

?>
