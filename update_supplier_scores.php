<?php
/**
 * Update Supplier Performance Scores
 * Calculates on-time delivery rate and average delay for each supplier
 * based on completed purchase orders, then stores the results in
 * suppliers.performance_score and suppliers.on_time_rate (columns added here
 * if not present via ALTER TABLE IF NOT EXISTS).
 *
 * Scoring formula (0–100):
 *   on_time_rate(%) * 0.6  +  fill_rate(%) * 0.4
 *
 * Run via cron (e.g. weekly Sunday midnight):
 *   php update_supplier_scores.php
 * Or via HTTP:
 *   GET /api_legacy/update_supplier_scores.php?store_id=1
 */

require_once 'config.php';
require_once 'notification_helper.php';

$isCli      = php_sapi_name() === 'cli';
$filterStore = $isCli ? null : (isset($_GET['store_id']) ? (int)$_GET['store_id'] : null);

$conn = getDBConnection();

try {
    // Ensure score columns exist (safe to run repeatedly)
    $conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS performance_score DECIMAL(5,2) DEFAULT NULL COMMENT 'Composite score 0-100'");
    $conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS on_time_rate DECIMAL(5,2) DEFAULT NULL COMMENT 'Pct of POs received on time'");
    $conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS avg_delay_days DECIMAL(6,2) DEFAULT NULL COMMENT 'Average days late (negative = early)'");
    $conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS total_pos_scored INT DEFAULT 0 COMMENT 'Number of POs included in score'");
    $conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS score_updated_at TIMESTAMP NULL COMMENT 'When score was last recalculated'");

    // Build WHERE clause for store filter
    $storeFilter = $filterStore ? "AND po.store_id = $filterStore" : '';

    // Calculate metrics per supplier from fully received POs
    // actual_delivery_date is set by receive_purchase_order.php when status becomes 'received'
    $metricsStmt = $conn->query("
        SELECT
            po.supplier_id,
            COUNT(*) AS total_pos,
            SUM(CASE WHEN po.actual_delivery_date IS NOT NULL
                          AND po.actual_delivery_date <= po.expected_delivery_date
                     THEN 1 ELSE 0 END) AS on_time_count,
            AVG(CASE WHEN po.actual_delivery_date IS NOT NULL
                          AND po.expected_delivery_date IS NOT NULL
                     THEN DATEDIFF(po.actual_delivery_date, po.expected_delivery_date)
                     ELSE NULL END) AS avg_delay,
            -- Fill rate: received vs ordered across all items
            COALESCE(
                SUM(poi_totals.total_received) / NULLIF(SUM(poi_totals.total_ordered), 0) * 100,
                0
            ) AS fill_rate_pct
        FROM purchase_orders po
        JOIN (
            SELECT po_id,
                   SUM(quantity_ordered) AS total_ordered,
                   SUM(quantity_received) AS total_received
            FROM purchase_order_items
            GROUP BY po_id
        ) poi_totals ON poi_totals.po_id = po.po_id
        WHERE po.status = 'received'
          AND po.supplier_id IS NOT NULL
          $storeFilter
        GROUP BY po.supplier_id
        HAVING total_pos >= 1
    ");

    $updated = 0;
    $details = [];

    while ($row = $metricsStmt->fetch(PDO::FETCH_ASSOC)) {
        $supplier_id   = (int)$row['supplier_id'];
        $totalPos      = (int)$row['total_pos'];
        $onTimeRate    = $totalPos > 0 ? round((float)$row['on_time_count'] / $totalPos * 100, 2) : 0.0;
        $avgDelay      = $row['avg_delay'] !== null ? round((float)$row['avg_delay'], 2) : null;
        $fillRate      = min(100.0, round((float)$row['fill_rate_pct'], 2));
        $compositeScore = round($onTimeRate * 0.6 + $fillRate * 0.4, 2);

        $updateStmt = $conn->prepare("
            UPDATE suppliers
            SET performance_score = ?,
                on_time_rate      = ?,
                avg_delay_days    = ?,
                total_pos_scored  = ?,
                score_updated_at  = NOW()
            WHERE supplier_id = ?
        ");
        // PDO bind
$updateStmt->execute([$compositeScore, $onTimeRate, $avgDelay, $totalPos, $supplier_id]);$updateStmt->execute();
        

        // Get supplier name for reporting / notification
        $nameStmt = $conn->prepare("SELECT supplier_name, store_id FROM suppliers WHERE supplier_id = ? LIMIT 1");
        // PDO bind
$nameStmt->execute([$supplier_id]);$nameStmt->execute();
        $nameRow  = $nameStmt->fetch(PDO::FETCH_ASSOC);
        

        $supplierName = $nameRow['supplier_name'] ?? "Supplier #$supplier_id";
        $storeId      = (int)($nameRow['store_id'] ?? 0);

        // Alert if score is poor (< 60)
        if ($compositeScore < 60 && $storeId > 0) {
            insertNotification(
                $conn, $storeId, null,
                "Low Supplier Score: $supplierName",
                sprintf(
                    "%s scored %.0f/100. On-time: %.0f%%, Fill rate: %.0f%%, Avg delay: %s days.",
                    $supplierName, $compositeScore, $onTimeRate, $fillRate,
                    $avgDelay !== null ? sprintf('%+.1f', $avgDelay) : 'n/a'
                ),
                'system',
                ['supplier_id' => $supplier_id, 'score' => $compositeScore, 'on_time_rate' => $onTimeRate]
            );
        }

        $details[] = [
            'supplier_id'      => $supplier_id,
            'supplier_name'    => $supplierName,
            'total_pos'        => $totalPos,
            'on_time_rate_pct' => $onTimeRate,
            'fill_rate_pct'    => $fillRate,
            'avg_delay_days'   => $avgDelay,
            'performance_score'=> $compositeScore,
        ];
        $updated++;
    }

    $msg = "$updated supplier score(s) updated";

    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] $msg\n";
        foreach ($details as $d) {
            echo sprintf(
                "  %s | Score: %.0f | On-time: %.0f%% | Fill: %.0f%% | Delay: %s days | POs: %d\n",
                $d['supplier_name'], $d['performance_score'],
                $d['on_time_rate_pct'], $d['fill_rate_pct'],
                $d['avg_delay_days'] !== null ? sprintf('%+.1f', $d['avg_delay_days']) : 'n/a',
                $d['total_pos']
            );
        }
    } else {
        sendResponse(true, $msg, [
            'updated' => $updated,
            'details' => $details,
        ]);
    }

} catch (Exception $e) {
    logError('update_supplier_scores error: ' . $e->getMessage());
    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        sendResponse(false, 'Failed to update supplier scores: ' . $e->getMessage());
    }
}


?>
