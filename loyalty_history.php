<?php
/**
 * Loyalty History API
 * GET ?customer_id=X&store_id=X&page=1&limit=20
 */
require_once 'config.php';

$customer_id = (int)($_GET['customer_id'] ?? 0);
$store_id    = (int)($_GET['store_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = min(50, max(5, (int)($_GET['limit'] ?? 20)));
$offset      = ($page - 1) * $limit;

if ($customer_id <= 0) sendResponse(false, 'customer_id required');

$conn = getDBConnection();

try {
    $count_s = $conn->prepare("SELECT COUNT(*) v FROM loyalty_transactions WHERE customer_id = ? AND store_id = ?");
    // PDO bind
$count_s->execute([$customer_id, $store_id]);$count_s->execute();
    $total = (int)$count_s->fetch(PDO::FETCH_ASSOC)['v'];
    

    $s = $conn->prepare("
        SELECT txn_id, txn_type, points, reference_type, reference_id,
               description, balance_after, created_at
        FROM loyalty_transactions
        WHERE customer_id = ? AND store_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    // PDO bind
$s->execute([$customer_id, $store_id, $limit, $offset]);$s->execute();
    $txns = $s->fetchAll(PDO::FETCH_ASSOC);
    

    foreach ($txns as &$t) {
        $t['txn_id']      = (int)$t['txn_id'];
        $t['points']      = (int)$t['points'];
        $t['balance_after'] = (int)$t['balance_after'];
    }
    unset($t);

    sendResponse(true, 'Loyalty history loaded', [
        'transactions' => $txns,
        'total'        => $total,
        'page'         => $page,
        'total_pages'  => (int)ceil($total / $limit),
    ]);

} catch (Exception $e) {
    logError('loyalty_history: ' . $e->getMessage());
    sendResponse(false, 'Failed: ' . $e->getMessage());
}


?>
