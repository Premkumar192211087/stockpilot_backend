<?php
/**
 * Loyalty Balance API
 * GET ?customer_id=X&store_id=X
 */
require_once 'config.php';

$customer_id = (int)($_GET['customer_id'] ?? 0);
$store_id    = (int)($_GET['store_id'] ?? 0);

if ($customer_id <= 0 || $store_id <= 0) {
    sendResponse(false, 'customer_id and store_id required');
}

$conn = getDBConnection();

try {
    // Get customer points
    $s = $conn->prepare("SELECT loyalty_points, full_name FROM customers WHERE customer_id = ?");
    // PDO bind
$s->execute([$customer_id]);$s->execute();
    $cust = $s->fetch(PDO::FETCH_ASSOC);
    

    if (!$cust) sendResponse(false, 'Customer not found');

    $points = (int)$cust['loyalty_points'];

    // Get tiers for this store
    $t = $conn->prepare("SELECT * FROM loyalty_tiers WHERE store_id = ? ORDER BY min_points ASC");
    // PDO bind
$t->execute([$store_id]);$t->execute();
    $tiers = $t->fetchAll(PDO::FETCH_ASSOC);
    

    // Find current and next tier
    $current_tier = null;
    $next_tier    = null;
    foreach ($tiers as $i => $tier) {
        if ($points >= (int)$tier['min_points']) {
            $current_tier = $tier;
            $next_tier    = $tiers[$i + 1] ?? null;
        }
    }

    // Progress to next tier
    $progress_pct = 0;
    $points_to_next = 0;
    if ($next_tier && $current_tier) {
        $range          = (int)$next_tier['min_points'] - (int)$current_tier['min_points'];
        $earned_in_tier = $points - (int)$current_tier['min_points'];
        $progress_pct   = $range > 0 ? round(($earned_in_tier / $range) * 100) : 100;
        $points_to_next = max(0, (int)$next_tier['min_points'] - $points);
    }

    // Recent transactions
    $r = $conn->prepare("
        SELECT txn_id, txn_type, points, description, balance_after, created_at
        FROM loyalty_transactions
        WHERE customer_id = ? AND store_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    // PDO bind
$r->execute([$customer_id, $store_id]);$r->execute();
    $recent = $r->fetchAll(PDO::FETCH_ASSOC);
    

    sendResponse(true, 'Loyalty balance loaded', [
        'points'           => $points,
        'current_tier'     => $current_tier,
        'next_tier'        => $next_tier,
        'progress_percent' => $progress_pct,
        'points_to_next'   => $points_to_next,
        'cash_value'       => round($points * 0.10, 2), // 100 points = ₹10
        'recent_transactions' => $recent,
        'tiers'            => $tiers,
    ]);

} catch (Exception $e) {
    logError('loyalty_balance: ' . $e->getMessage());
    sendResponse(false, 'Failed: ' . $e->getMessage());
}


?>
