<?php
/**
 * Get all active stores — used by the customer store picker
 * GET (no params required)
 */
require_once 'config.php';

$conn = getDBConnection();

try {
    $s = $conn->query("
        SELECT store_id, store_name, store_location, address, phone
        FROM stores
        WHERE status = 'active'
        ORDER BY store_id ASC
    ");
    $stores = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stores as &$st) {
        $st['store_id'] = (int)$st['store_id'];
    }
    unset($st);

    sendResponse(true, 'Stores loaded', ['stores' => $stores, 'count' => count($stores)]);

} catch (Exception $e) {
    logError('get_stores: ' . $e->getMessage());
    sendResponse(false, 'Failed to load stores');
}


?>
