<?php
/**
 * Dead Stock report: shared by the report page and its export, so the two can
 * never disagree about which rows are dead.
 *
 * A row is one product at one exact location. Parent locations are never rolled
 * up: stock moving at Store A must not make the same product look alive at
 * Store B.
 *
 * Quantity and rate deliberately use the same formulas as
 * modules/reports/stock_value_by_location_products.php, so the figures agree
 * with that page. Quantity comes from the movement tables, NOT stock_batches.
 *
 * What counts as movement: purchase, import, sale, consumption (including
 * returns), transfer out from dispatch, transfer in from receipt.
 * What does not: the opening stock entry and stock adjustments. They still
 * count towards the quantity on hand.
 */

const DEAD_STOCK_DEFAULT_DAYS = 90;
const DEAD_STOCK_MAX_DAYS = 3650;

/**
 * Parse the days filter. Returns null when it is not a whole number in range.
 */
function deadStockParseDays($raw) {
    $raw = trim((string)$raw);
    if (!ctype_digit($raw)) {
        return null;
    }
    $days = (int)$raw;
    return ($days >= 1 && $days <= DEAD_STOCK_MAX_DAYS) ? $days : null;
}

/**
 * Locations this report may cover. Blank = everything the user can access.
 * A chosen location brings its descendants, each still limited to what the user
 * can access. Returns an empty array when none of them are accessible.
 */
function deadStockResolveLocationIds($conn, $locationFilter) {
    $accessibleIds = array_map('intval', array_column(getUserLocations(), 'id'));
    $locationId = (int)$locationFilter;
    if ($locationId <= 0) {
        return $accessibleIds;
    }
    return array_values(array_intersect(
        array_map('intval', getDescendantLocationIds($locationId, $conn)),
        $accessibleIds
    ));
}

/**
 * Dead stock rows, ordered by location name, then highest value first.
 *
 * @param PDO   $conn
 * @param int[] $locationIds Already access-checked
 * @param int   $days        Minimum days without movement
 * @param int[] $categoryIds Empty = all categories
 * @return array
 */
function deadStockFetchRows($conn, array $locationIds, $days, array $categoryIds = []) {
    if (empty($locationIds)) {
        return [];
    }

    $days = (int)$days;
    $loc = implode(',', array_map('intval', $locationIds));
    $categoryCondition = empty($categoryIds)
        ? ''
        : 'AND p.category_id IN (' . implode(',', array_map('intval', $categoryIds)) . ')';

    $sql = "
        SELECT
            net.location_id,
            l.name AS location_name,
            p.id AS product_id,
            p.name AS product_name,
            p.unit,
            pc.name AS category_name,
            net.net_qty AS quantity,
            net.opening_date,
            SUBSTRING_INDEX(net.last_mark, '|', 1) AS last_movement_date,
            SUBSTRING_INDEX(net.last_mark, '|', -1) AS last_movement_type,
            DATEDIFF(CURDATE(), SUBSTRING_INDEX(net.last_mark, '|', 1)) AS days_idle,
            COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0) AS rate,
            net.net_qty * COALESCE(NULLIF(sbl.avg_rate, 0), sbp.avg_rate, 0) AS stock_value
        FROM (
            SELECT
                location_id,
                product_id,
                SUM(signed_qty) AS net_qty,
                MAX(IF(kind = 'opening', d, NULL)) AS opening_date,
                MAX(IF(kind NOT IN ('opening', 'adjustment'), CONCAT(d, '|', kind), NULL)) AS last_mark
            FROM (
                SELECT location_id, product_id, quantity AS signed_qty, stock_date AS d, 'opening' AS kind
                  FROM opening_stocks
                  WHERE stock_date <= CURDATE() AND location_id IN ($loc)
                UNION ALL
                SELECT pu.location_id, pd.product_id, pd.quantity, pu.purchase_date, 'purchase'
                  FROM purchase_details pd
                  JOIN purchases pu ON pd.purchase_id = pu.id
                  WHERE pu.purchase_date <= CURDATE() AND pu.location_id IN ($loc)
                UNION ALL
                SELECT pi.location_id, pid.product_id, pid.quantity, pi.import_date, 'import'
                  FROM purchase_import_details pid
                  JOIN purchase_imports pi ON pid.import_id = pi.id
                  WHERE pi.import_date <= CURDATE() AND pi.location_id IN ($loc)
                UNION ALL
                SELECT s.location_id, sd.product_id, -sd.quantity, s.sale_date, 'sale'
                  FROM sale_details sd
                  JOIN sales s ON sd.sale_id = s.id
                  WHERE s.sale_date <= CURDATE() AND s.location_id IN ($loc)
                UNION ALL
                SELECT t.to_location_id, td.product_id, td.received_quantity, DATE(t.received_at), 'transfer_in'
                  FROM stock_transfer_details td
                  JOIN stock_transfers t ON td.transfer_id = t.id
                  WHERE t.status = 'received' AND DATE(t.received_at) <= CURDATE() AND t.to_location_id IN ($loc)
                UNION ALL
                SELECT t.from_location_id, td.product_id, -td.dispatched_quantity, DATE(t.dispatched_at), 'transfer_out'
                  FROM stock_transfer_details td
                  JOIN stock_transfers t ON td.transfer_id = t.id
                  WHERE t.status IN ('in_transit', 'received') AND DATE(t.dispatched_at) <= CURDATE() AND t.from_location_id IN ($loc)
                UNION ALL
                SELECT sa.location_id, sad.product_id,
                       CASE WHEN sad.adjustment_type = 'increase' THEN sad.quantity ELSE -sad.quantity END,
                       sa.adjustment_date, 'adjustment'
                  FROM stock_adjustment_details sad
                  JOIN stock_adjustments sa ON sad.adjustment_id = sa.id
                  WHERE sa.adjustment_date <= CURDATE() AND sa.location_id IN ($loc)
                UNION ALL
                SELECT cv.location_id, cvd.product_id, -cvd.quantity, cv.voucher_date, 'consumption'
                  FROM consumption_voucher_details cvd
                  JOIN consumption_vouchers cv ON cvd.voucher_id = cv.id
                  WHERE cv.voucher_date <= CURDATE() AND cv.location_id IN ($loc)
            ) movements
            GROUP BY location_id, product_id
            HAVING net_qty > 0
               AND (last_mark IS NULL OR DATEDIFF(CURDATE(), SUBSTRING_INDEX(last_mark, '|', 1)) >= $days)
        ) net
        JOIN locations l ON l.id = net.location_id
        JOIN products p ON p.id = net.product_id AND p.is_active = 1 $categoryCondition
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN (
            SELECT product_id, location_id,
                   SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS avg_rate
            FROM stock_batches
            WHERE batch_date <= CURDATE() AND location_id IN ($loc)
            GROUP BY product_id, location_id
        ) sbl ON sbl.product_id = net.product_id AND sbl.location_id = net.location_id
        LEFT JOIN (
            SELECT product_id,
                   SUM(quantity_in * rate_per_unit) / NULLIF(SUM(quantity_in), 0) AS avg_rate
            FROM stock_batches
            WHERE batch_date <= CURDATE()
            GROUP BY product_id
        ) sbp ON sbp.product_id = net.product_id
        ORDER BY l.name ASC, net.location_id ASC, stock_value DESC, p.name ASC
    ";

    return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Translated label for a movement kind.
 */
function deadStockMovementLabel($kind) {
    return __('dead_stock_type_' . $kind);
}
