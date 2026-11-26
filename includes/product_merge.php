<?php
/**
 * Merge two duplicate products into one (Masters -> Products -> Merge).
 *
 * Everything that points at the REMOVED product is moved onto the KEPT one,
 * then the removed product is deactivated (never deleted, so its name can
 * still be found). Used by modules/masters/products_merge.php only.
 *
 * What is moved:
 *  - every column that is a foreign key to products, plus any column named
 *    product_id (found from information_schema at run time, so a table added
 *    later is not forgotten);
 *  - the JSON approved snapshots on boms / boqs ({product_id: qty}).
 *
 * Two unique keys need care, because both products can hold a row for the
 * same location:
 *  - opening_stocks (location, product): the two rows are FOLDED into the
 *    kept one (quantities added, rate value-weighted, earliest date), and the
 *    two 'opening' stock batches likewise. modules/masters/opening_stocks.php
 *    assumes ONE opening batch per product per location, so two must never be
 *    left side by side. Consumption-voucher batch links on the folded batch
 *    are moved first: they cascade-delete with the batch.
 *  - product_pricing (product, location): the KEPT product's price stays; the
 *    removed product's price for that location is dropped.
 *
 * Other stock batches keep their own quantity and cost; only product_id moves.
 * Rows that have updated_at keep it (assigned explicitly), so a merge does not
 * make documents look edited today.
 */

if (!function_exists('pmReferenceColumns')) {

/**
 * [ [table, column], ... ] that hold a product id, excluding products itself.
 */
function pmReferenceColumns($conn) {
    $rows = $conn->query("
        SELECT TABLE_NAME AS t, COLUMN_NAME AS c
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'products'
        UNION
        SELECT c.TABLE_NAME, c.COLUMN_NAME
        FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES tb
          ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
         AND tb.TABLE_TYPE = 'BASE TABLE'
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'product_id'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        if ($r['t'] === 'products') continue;
        $out[$r['t'] . '.' . $r['c']] = [$r['t'], $r['c']];
    }
    ksort($out);
    return array_values($out);
}

/** Whether a table has an updated_at column (cached). */
function pmHasUpdatedAt($conn, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $s = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'updated_at'
        ");
        $s->execute([$table]);
        $cache[$table] = (int)$s->fetchColumn() > 0;
    }
    return $cache[$table];
}

/** Whether a table exists (cached). */
function pmTableExists($conn, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $s = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $s->execute([$table]);
        $cache[$table] = (int)$s->fetchColumn() > 0;
    }
    return $cache[$table];
}

/** Whether a column exists (cached). */
function pmColumnExists($conn, $table, $column) {
    static $cache = [];
    $k = $table . '.' . $column;
    if (!isset($cache[$k])) {
        $s = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $s->execute([$table, $column]);
        $cache[$k] = (int)$s->fetchColumn() > 0;
    }
    return $cache[$k];
}

/** Product row with its category name, or null. */
function pmFetchProduct($conn, $id, $forUpdate = false) {
    $s = $conn->prepare("
        SELECT p.*, pc.name AS category_name
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE p.id = ?" . ($forUpdate ? " FOR UPDATE" : ""));
    $s->execute([(int)$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Reasons the two products cannot be merged (empty = OK).
 */
function pmBlockers($keep, $remove) {
    $errors = [];
    if (!$keep || !$remove) {
        $errors[] = __('pm_err_not_found');
        return $errors;
    }
    if ((int)$keep['id'] === (int)$remove['id']) {
        $errors[] = __('pm_err_same');
    }
    if ((int)$keep['is_active'] !== 1) {
        $errors[] = __('pm_err_keep_inactive');
    }
    if (mb_strtolower(trim($keep['unit'])) !== mb_strtolower(trim($remove['unit']))) {
        $errors[] = sprintf(__('pm_err_unit'), $keep['unit'], $remove['unit']);
    }
    return $errors;
}

/** Approved snapshots (boms / boqs) whose JSON names the product. */
function pmSnapshotRows($conn, $productId) {
    $out = [];
    foreach (['boms', 'boqs'] as $t) {
        if (!pmTableExists($conn, $t) || !pmColumnExists($conn, $t, 'approved_snapshot')) continue;
        $s = $conn->prepare("SELECT id, approved_snapshot FROM `$t` WHERE approved_snapshot LIKE ?");
        $s->execute(['%"' . (int)$productId . '"%']);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $snap = json_decode((string)$r['approved_snapshot'], true);
            if (is_array($snap) && array_key_exists((int)$productId, $snap)) {
                $out[] = ['table' => $t, 'id' => (int)$r['id'], 'snap' => $snap];
            }
        }
    }
    return $out;
}

/**
 * Everything the preview screen shows. Read-only.
 */
function pmPreview($conn, $keepId, $removeId) {
    $keep = pmFetchProduct($conn, $keepId);
    $remove = pmFetchProduct($conn, $removeId);
    $res = [
        'keep' => $keep,
        'remove' => $remove,
        'errors' => pmBlockers($keep, $remove),
        'tables' => [],
        'stock' => [],
        'opening_fold' => [],
        'pricing_drop' => [],
        'snapshots' => 0,
    ];
    if (!$keep || !$remove || (int)$keep['id'] === (int)$remove['id']) {
        return $res;
    }
    $k = (int)$keep['id'];
    $r = (int)$remove['id'];

    foreach (pmReferenceColumns($conn) as $tc) {
        list($t, $c) = $tc;
        $s = $conn->query("SELECT `$c` AS pid, COUNT(*) AS n FROM `$t` WHERE `$c` IN ($k, $r) GROUP BY `$c`");
        $n = [$k => 0, $r => 0];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $n[(int)$row['pid']] = (int)$row['n'];
        if ($n[$k] || $n[$r]) {
            $res['tables'][] = ['table' => $t, 'column' => $c, 'keep' => $n[$k], 'remove' => $n[$r]];
        }
    }

    $s = $conn->query("
        SELECT sb.location_id, l.name AS location_name,
               SUM(CASE WHEN sb.product_id = $k THEN sb.quantity_remaining ELSE 0 END) AS keep_qty,
               SUM(CASE WHEN sb.product_id = $r THEN sb.quantity_remaining ELSE 0 END) AS remove_qty
        FROM stock_batches sb
        LEFT JOIN locations l ON l.id = sb.location_id
        WHERE sb.product_id IN ($k, $r)
        GROUP BY sb.location_id, l.name
        HAVING ABS(keep_qty) > 0.0005 OR ABS(remove_qty) > 0.0005
        ORDER BY l.name
    ");
    $res['stock'] = $s->fetchAll(PDO::FETCH_ASSOC);

    if (pmTableExists($conn, 'opening_stocks')) {
        $res['opening_fold'] = $conn->query("
            SELECT l.name AS location_name, a.quantity AS keep_qty, b.quantity AS remove_qty
            FROM opening_stocks a
            JOIN opening_stocks b ON b.location_id = a.location_id AND b.product_id = $r
            LEFT JOIN locations l ON l.id = a.location_id
            WHERE a.product_id = $k
            ORDER BY l.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    if (pmTableExists($conn, 'product_pricing')) {
        $res['pricing_drop'] = $conn->query("
            SELECT l.name AS location_name, a.selling_price AS keep_price, b.selling_price AS remove_price
            FROM product_pricing a
            JOIN product_pricing b ON b.location_id = a.location_id AND b.product_id = $r
            LEFT JOIN locations l ON l.id = a.location_id
            WHERE a.product_id = $k
            ORDER BY l.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    $res['snapshots'] = count(pmSnapshotRows($conn, $r));
    return $res;
}

/**
 * Do the merge in ONE transaction. Throws on any problem (nothing written).
 *
 * @return array summary for the audit log / success message
 */
function pmMerge($conn, $keepId, $removeId, $newName, $newCategoryId) {
    $keepId = (int)$keepId;
    $removeId = (int)$removeId;
    $newName = trim((string)$newName);
    $newCategoryId = (int)$newCategoryId;

    if ($newName === '') throw new Exception(__('pm_err_name'));
    if (mb_strlen($newName) > 200) throw new Exception(__('pm_err_name_long'));

    // Collected before the transaction: information_schema reads are not
    // worth holding locks for, and DDL never runs here.
    $refCols = pmReferenceColumns($conn);

    $conn->beginTransaction();
    try {
        // Lock both rows in id order so two merges cannot deadlock each other.
        $first = min($keepId, $removeId);
        $second = max($keepId, $removeId);
        pmFetchProduct($conn, $first, true);
        pmFetchProduct($conn, $second, true);
        $keep = pmFetchProduct($conn, $keepId);
        $remove = pmFetchProduct($conn, $removeId);

        $errors = pmBlockers($keep, $remove);
        if ($errors) throw new Exception(implode(' ', $errors));

        $cat = $conn->prepare("SELECT id FROM product_categories WHERE id = ?");
        $cat->execute([$newCategoryId]);
        if (!$cat->fetchColumn()) throw new Exception(__('pm_err_category'));

        $summary = [
            'removed_id' => $removeId,
            'removed_name' => $remove['name'],
            'kept_name_before' => $keep['name'],
            'kept_category_before' => (int)$keep['category_id'],
            'name' => $newName,
            'category_id' => $newCategoryId,
            'moved' => [],
            'opening_folded' => 0,
            'pricing_dropped' => 0,
            'snapshots' => 0,
        ];

        // 1. Opening stock at a location both products hold: fold into one.
        if (pmTableExists($conn, 'opening_stocks')) {
            $pairs = $conn->prepare("
                SELECT a.id AS keep_os, b.id AS remove_os, a.location_id,
                       a.quantity AS qk, a.rate_per_unit AS rk, a.exchange_rate_to_base AS fk, a.stock_date AS dk,
                       b.quantity AS qr, b.rate_per_unit AS rr, b.exchange_rate_to_base AS fr, b.stock_date AS dr
                FROM opening_stocks a
                JOIN opening_stocks b ON b.location_id = a.location_id AND b.product_id = ?
                WHERE a.product_id = ?
                FOR UPDATE
            ");
            $pairs->execute([$removeId, $keepId]);
            foreach ($pairs->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $fk = (float)$p['fk'] > 0 ? (float)$p['fk'] : 1.0;
                $fr = (float)$p['fr'] > 0 ? (float)$p['fr'] : 1.0;
                $qty = (float)$p['qk'] + (float)$p['qr'];
                // Value in base currency, expressed back in the kept row's currency.
                $baseValue = (float)$p['qk'] * (float)$p['rk'] * $fk + (float)$p['qr'] * (float)$p['rr'] * $fr;
                $rate = $qty > 0 ? round($baseValue / ($qty * $fk), 2) : (float)$p['rk'];
                $date = min($p['dk'], $p['dr']);

                $conn->prepare("
                    UPDATE opening_stocks
                    SET quantity = ?, rate_per_unit = ?, stock_date = ?, updated_at = updated_at
                    WHERE id = ?
                ")->execute([$qty, $rate, $date, $p['keep_os']]);
                $conn->prepare("DELETE FROM opening_stocks WHERE id = ?")->execute([$p['remove_os']]);

                // The matching 'opening' stock batches.
                $b = $conn->prepare("
                    SELECT id, product_id, quantity_in, quantity_remaining, rate_per_unit, batch_date
                    FROM stock_batches
                    WHERE location_id = ? AND product_id IN (?, ?) AND transaction_type = 'opening'
                    ORDER BY id
                    FOR UPDATE
                ");
                $b->execute([$p['location_id'], $keepId, $removeId]);
                $kb = null;
                $rbs = [];
                foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ((int)$row['product_id'] === $keepId && $kb === null) $kb = $row;
                    else $rbs[] = $row;
                }
                if ($kb === null && $rbs) {
                    // Only the removed product had a batch: it simply moves
                    // with the generic product_id update below.
                    $rbs = [];
                }
                foreach ($rbs as $rb) {
                    $qin = (float)$kb['quantity_in'] + (float)$rb['quantity_in'];
                    $brate = $qin > 0
                        ? round(((float)$kb['quantity_in'] * (float)$kb['rate_per_unit']
                               + (float)$rb['quantity_in'] * (float)$rb['rate_per_unit']) / $qin, 2)
                        : (float)$kb['rate_per_unit'];
                    $kb['quantity_in'] = $qin;
                    $kb['quantity_remaining'] = (float)$kb['quantity_remaining'] + (float)$rb['quantity_remaining'];
                    $kb['rate_per_unit'] = $brate;
                    $kb['batch_date'] = min($kb['batch_date'], $rb['batch_date']);

                    $conn->prepare("
                        UPDATE stock_batches
                        SET quantity_in = ?, quantity_remaining = ?, rate_per_unit = ?, batch_date = ?, updated_at = updated_at
                        WHERE id = ?
                    ")->execute([$kb['quantity_in'], $kb['quantity_remaining'], $kb['rate_per_unit'], $kb['batch_date'], $kb['id']]);
                    // Consumption links cascade-delete with the batch: move them first.
                    if (pmTableExists($conn, 'consumption_voucher_batches')) {
                        $conn->prepare("UPDATE consumption_voucher_batches SET batch_id = ? WHERE batch_id = ?")
                             ->execute([$kb['id'], $rb['id']]);
                    }
                    $conn->prepare("DELETE FROM stock_batches WHERE id = ?")->execute([$rb['id']]);
                }
                $summary['opening_folded']++;
            }
        }

        // 2. Selling price at a location both products hold: keep the kept one's.
        if (pmTableExists($conn, 'product_pricing')) {
            $d = $conn->prepare("
                DELETE b FROM product_pricing b
                JOIN product_pricing a ON a.location_id = b.location_id AND a.product_id = ?
                WHERE b.product_id = ?
            ");
            $d->execute([$keepId, $removeId]);
            $summary['pricing_dropped'] = $d->rowCount();
        }

        // 3. Every other reference.
        foreach ($refCols as $tc) {
            list($t, $c) = $tc;
            $sql = "UPDATE `$t` SET `$c` = ?" . (pmHasUpdatedAt($conn, $t) ? ", updated_at = updated_at" : "") . " WHERE `$c` = ?";
            $u = $conn->prepare($sql);
            $u->execute([$keepId, $removeId]);
            if ($u->rowCount() > 0) $summary['moved'][$t] = $u->rowCount();
        }

        // 4. Approved snapshots on BOMs / BOQs: {product_id: qty}.
        foreach (pmSnapshotRows($conn, $removeId) as $sr) {
            $snap = $sr['snap'];
            $snap[$keepId] = (float)($snap[$keepId] ?? 0) + (float)$snap[$removeId];
            unset($snap[$removeId]);
            $conn->prepare("UPDATE `{$sr['table']}` SET approved_snapshot = ?, updated_at = updated_at WHERE id = ?")
                 ->execute([json_encode($snap), $sr['id']]);
            $summary['snapshots']++;
        }

        // 5. The products themselves.
        $conn->prepare("UPDATE products SET name = ?, category_id = ? WHERE id = ?")
             ->execute([$newName, $newCategoryId, $keepId]);
        $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$removeId]);

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw ($e instanceof Exception) ? $e : new Exception($e->getMessage());
    }

    logAudit('products', 'merge', $keepId,
        ['name' => $summary['kept_name_before'], 'category_id' => $summary['kept_category_before']],
        $summary);
    logAudit('products', 'merged_into', $removeId,
        ['name' => $summary['removed_name'], 'is_active' => 1],
        ['merged_into' => $keepId, 'is_active' => 0]);

    return $summary;
}

}
