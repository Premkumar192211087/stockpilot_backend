<?php
require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("
        SELECT
            DATE(sale_date) AS sale_date,
            COALESCE(SUM(final_amount), 0) AS revenue
        FROM sales
        WHERE store_id = ?
          AND sale_date >= CURDATE() - INTERVAL 6 DAY
        GROUP BY DATE(sale_date)
        ORDER BY sale_date ASC
    ");
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $days = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $days[$row['sale_date']] = (float) $row['revenue'];
    }
    

    // Fill all 7 days so the chart always has 7 points (0 for days with no sales)
    $trend = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $trend[] = [
            'date'    => date('D', strtotime($date)),   // Mon, Tue … Sun
            'revenue' => $days[$date] ?? 0.0,
        ];
    }

    sendResponse(true, 'Sales trend fetched', ['trend' => $trend]);
} catch (Exception $e) {
    sendResponse(false, 'Failed to fetch sales trend');
}
