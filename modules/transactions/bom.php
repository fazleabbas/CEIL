<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$isEdit = isset($_GET['id']) && !empty($_GET['id']);
$pageTitle = $isEdit ? __('edit_bom') : __('new_bom');

// An approver may edit a BOM pending approval without holding bom.edit;
// bomCanEdit() below decides per status.
if (!($isEdit ? (hasPermission('bom', 'edit') || hasPermission('bom', 'approve')) : hasPermission('bom', 'create'))) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Whether the optional `title` column exists (migration 0035). Guards all title
// reads/writes so the page still works if the migration hasn't run yet.
$hasTitleCol = (bool)$conn->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'boms'
      AND COLUMN_NAME = 'title'
")->fetchColumn();

// Initialize BOM data
$bom = [
    'id' => '',
    'title' => '',
    'bom_date' => date('Y-m-d'),
    'location_id' => '',
    'cost_centre_id' => '',
    'boq_id' => '',
    'remarks' => '',
];
$bomDetails = [];

// BOQ link + over-BOQ reasons (migration 0049), guarded like the title column.
$hasBoqCol = bomBoqLinkAvailable($conn);
// product_id => reason for going beyond the BOQ balance
$boqExcessReasons = [];

// If editing, load existing BOM
if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM boms WHERE id = ?");
    $stmt->execute([$id]);
    $bom = $stmt->fetch();

    if (!$bom) {
        setAlert(__('bom_not_found'), 'danger');
        redirect('modules/transactions/bom_list.php');
    }

    // Only a draft is editable (migration 0049). An approved or rejected BOM is
    // revised back to draft from the view page first.
    if (!bomCanEdit($bom)) {
        setAlert(__('bom_only_draft_edit'), 'danger');
        redirect('modules/transactions/bom_view.php?id=' . $id);
    }

    // Load detail lines with product + unit
    $detailsStmt = $conn->prepare("
        SELECT bd.*, p.name as product_name, p.unit
        FROM bom_details bd
        LEFT JOIN products p ON bd.product_id = p.id
        WHERE bd.bom_id = ?
        ORDER BY bd.id
    ");
    $detailsStmt->execute([$id]);
    $bomDetails = $detailsStmt->fetchAll();

    // Load breakdowns grouped by detail id
    $bdStmt = $conn->prepare("
        SELECT bb.*
        FROM bom_detail_breakdowns bb
        INNER JOIN bom_details bd ON bb.bom_detail_id = bd.id
        WHERE bd.bom_id = ?
        ORDER BY bb.bom_detail_id, bb.sort_order, bb.id
    ");
    $bdStmt->execute([$id]);
    $breakdownsByDetail = [];
    foreach ($bdStmt->fetchAll() as $bd) {
        $breakdownsByDetail[$bd['bom_detail_id']][] = $bd;
    }
    foreach ($bomDetails as &$d) {
        $d['breakdowns'] = $breakdownsByDetail[$d['id']] ?? [];
        $pid = (int)$d['product_id'];
        if ($hasBoqCol && !isset($boqExcessReasons[$pid]) && trim((string)($d['boq_excess_reason'] ?? '')) !== '') {
            $boqExcessReasons[$pid] = $d['boq_excess_reason'];
        }
    }
    unset($d);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    checkCSRF();

    $locationId = (int)$_POST['location_id'];
    $costCentreId = !empty($_POST['cost_centre_id']) ? (int)$_POST['cost_centre_id'] : null;
    $bomDate = dbDate($_POST['bom_date']);
    $remarks = clean($_POST['remarks'] ?? '');
    $title = $hasTitleCol ? clean($_POST['title'] ?? '') : null;
    $editId = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;
    $boqId = ($hasBoqCol && !empty($_POST['boq_id'])) ? (int)$_POST['boq_id'] : null;
    $postedReasons = is_array($_POST['boq_excess_reason'] ?? null) ? $_POST['boq_excess_reason'] : [];

    $products = $_POST['products'] ?? [];
    $totalQuantities = $_POST['total_quantity'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];
    $breakdownLabels = $_POST['breakdown_label'] ?? [];
    $breakdownQtys = $_POST['breakdown_qty'] ?? [];

    // Lines normally arrive packed in one field (see unpackBomBoqItems()).
    $packed = unpackBomBoqItems($_POST['items_json'] ?? null);
    if ($packed !== null) {
        $products = $packed['products'];
        $totalQuantities = $packed['total_quantity'];
        $rates = $packed['rate'];
        $itemRemarks = $packed['item_remarks'];
        $breakdownLabels = $packed['breakdown_label'];
        $breakdownQtys = $packed['breakdown_qty'];
    }
    $truncated = postInputWasTruncated();

    $errors = [];

    if (empty($locationId)) {
        $errors[] = __('please_select_location');
    }
    if (empty($bomDate)) {
        $errors[] = __('please_select_date');
    }

    // Build a normalized list of valid line items + validate breakdown sums.
    $items = [];
    foreach ($products as $i => $productId) {
        $productId = (int)$productId;
        $total = floatval($totalQuantities[$i] ?? 0);
        if ($productId <= 0 || $total <= 0) {
            continue;
        }

        $rate = floatval($rates[$i] ?? 0);
        if ($rate < 0) {
            $rate = 0;
        }

        $labels = $breakdownLabels[$i] ?? [];
        $qtys = $breakdownQtys[$i] ?? [];
        $rows = [];
        $sum = 0;
        foreach ($labels as $k => $label) {
            $label = clean($label);
            $qty = floatval($qtys[$k] ?? 0);
            if ($label === '' && $qty <= 0) {
                continue; // skip blank breakdown rows
            }
            if ($label === '' || $qty <= 0) {
                $errors[] = sprintf(__('bom_breakdown_incomplete'), $productId);
                continue;
            }
            $rows[] = ['label' => $label, 'qty' => $qty];
            $sum += $qty;
        }

        if (empty($rows)) {
            $errors[] = sprintf(__('bom_breakdown_required'), $productId);
        } elseif (abs($sum - $total) > 0.0005) {
            $fmt = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };
            $errors[] = sprintf(__('bom_breakdown_must_sum'), $fmt($sum), $fmt($total));
        }

        $items[] = [
            'product_id' => $productId,
            'total' => $total,
            'rate' => $rate,
            'remark' => clean($itemRemarks[$i] ?? ''),
            'breakdowns' => $rows,
        ];
    }

    if (empty($items)) {
        $errors[] = __('please_add_at_least_one_item');
    }

    // The page gate checked ?id=, but edit_id comes from the POST body: re-check
    // it, so a pending or approved BOM cannot be rewritten by a hand-made POST.
    $existingBoqId = null;
    $approverEdit = false;
    if ($editId) {
        $chk = $conn->prepare("SELECT * FROM boms WHERE id = ?");
        $chk->execute([$editId]);
        $existingBom = $chk->fetch();
        if (!$existingBom || !bomCanEdit($existingBom)) {
            $errors[] = __('bom_only_draft_edit');
        } else {
            $approverEdit = bomIsApproverEdit($existingBom);
            if ($hasBoqCol) {
                $existingBoqId = !empty($existingBom['boq_id']) ? (int)$existingBom['boq_id'] : null;
            }
        }
    }

    // BOQ link: must exist and be in a reachable location. A BOQ the BOM is
    // ALREADY linked to is kept even outside the user's locations (the dropdown
    // keeps it selectable for the same reason).
    $boqBalances = [];
    if ($boqId) {
        $bq = $conn->prepare("SELECT location_id FROM boqs WHERE id = ?");
        $bq->execute([$boqId]);
        $boqLocation = $bq->fetchColumn();
        if ($boqLocation === false) {
            $errors[] = __('boq_not_found');
            $boqId = null;
        } elseif ($boqId !== $existingBoqId && !canAccessLocation((int)$boqLocation)) {
            $errors[] = __('bom_boq_no_access');
            $boqId = null;
        } elseif (($boqLinkErr = boqLinkError($conn, $boqId, $existingBoqId)) !== null) {
            // Only an approved BOQ can be newly linked (migration 0051).
            $errors[] = $boqLinkErr;
            $boqId = null;
        } else {
            $boqBalances = getBoqProductBalances($boqId, $conn, (int)$editId);
        }
    }

    // Going beyond the BOQ balance is allowed, with a written reason per product.
    // Summed per PRODUCT across lines, the same grain as getBoqProductBalances().
    $reasonByProduct = [];
    if ($boqId && !empty($items)) {
        $qtyByProduct = [];
        foreach ($items as $item) {
            $qtyByProduct[$item['product_id']] = ($qtyByProduct[$item['product_id']] ?? 0) + $item['total'];
        }
        $fmtQty = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };
        foreach (bomBoqExcessProducts($qtyByProduct, $boqBalances) as $pid => $o) {
            $reason = cleanText((string)($postedReasons[$pid] ?? ''));
            if ($reason === '') {
                $pn = $conn->prepare("SELECT name FROM products WHERE id = ?");
                $pn->execute([$pid]);
                $errors[] = sprintf(__('bom_boq_over_needs_reason'), $pn->fetchColumn() ?: ('#' . $pid), $fmtQty($o['qty']), $fmtQty(max($o['balance'], 0)));
            } else {
                $reasonByProduct[$pid] = mb_substr($reason, 0, 1000);
            }
        }
    }

    // Only part of the form arrived — any per-line error above is an artefact
    // of the cut, and saving would silently drop the missing lines.
    if ($truncated) {
        $errors = [sprintf(__('bom_form_truncated'), ini_get('max_input_vars'))];
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // title column is included only when it exists (migration 0035).
            $titleSet = $hasTitleCol ? ', title = ?' : '';
            $boqSet = $hasBoqCol ? ', boq_id = ?' : '';
            $approverChanges = [];
            if ($editId) {
                // Re-check INSIDE the transaction: the BOM may have been approved
                // or rejected since the form was loaded, and only a draft (or a
                // pending BOM, for an approver) may be rewritten.
                $lock = $conn->prepare("SELECT * FROM boms WHERE id = ? FOR UPDATE");
                $lock->execute([$editId]);
                $lockedBom = $lock->fetch();
                if (!$lockedBom || !bomCanEdit($lockedBom) || bomIsApproverEdit($lockedBom) !== $approverEdit) {
                    throw new Exception(__('bom_only_draft_edit'));
                }
                $totalsBefore = $approverEdit ? bomProductTotals($conn, $editId) : [];

                $stmt = $conn->prepare("
                    UPDATE boms
                    SET bom_date = ?, location_id = ?, cost_centre_id = ?, remarks = ?{$titleSet}{$boqSet}
                    WHERE id = ?
                ");
                $params = [$bomDate, $locationId, $costCentreId, $remarks];
                if ($hasTitleCol) $params[] = $title;
                if ($hasBoqCol) $params[] = $boqId;
                $params[] = $editId;
                $stmt->execute($params);

                // Remove old details (breakdowns cascade via FK)
                $conn->prepare("DELETE FROM bom_details WHERE bom_id = ?")->execute([$editId]);
                $bomId = $editId;
            } else {
                $bomNumber = getNextNumber('boms', 'bom_number', getSetting('bom_prefix', 'BOM'));
                if ($hasTitleCol) {
                    $stmt = $conn->prepare("
                        INSERT INTO boms (bom_number, title, bom_date, location_id, cost_centre_id, remarks, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$bomNumber, $title, $bomDate, $locationId, $costCentreId, $remarks, getUserId()]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO boms (bom_number, bom_date, location_id, cost_centre_id, remarks, created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$bomNumber, $bomDate, $locationId, $costCentreId, $remarks, getUserId()]);
                }
                $bomId = $conn->lastInsertId();
                // New BOMs are born draft (the column default). The BOQ link is
                // set separately so the INSERT branches above stay untouched.
                if ($hasBoqCol && $boqId) {
                    $conn->prepare("UPDATE boms SET boq_id = ? WHERE id = ?")->execute([$boqId, $bomId]);
                }
            }

            // The over-BOQ reason goes on every row of its product (NULL when
            // the product is within its balance), so it is never lost when the
            // product's lines are aggregated.
            $detailStmt = $hasBoqCol
                ? $conn->prepare("
                    INSERT INTO bom_details (bom_id, product_id, total_quantity, rate, remarks, boq_excess_reason)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")
                : $conn->prepare("
                    INSERT INTO bom_details (bom_id, product_id, total_quantity, rate, remarks)
                    VALUES (?, ?, ?, ?, ?)
                ");
            $breakdownStmt = $conn->prepare("
                INSERT INTO bom_detail_breakdowns (bom_detail_id, label, quantity, sort_order)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                $detailParams = [$bomId, $item['product_id'], $item['total'], $item['rate'], $item['remark']];
                if ($hasBoqCol) $detailParams[] = $reasonByProduct[$item['product_id']] ?? null;
                $detailStmt->execute($detailParams);
                $detailId = $conn->lastInsertId();
                $order = 0;
                foreach ($item['breakdowns'] as $b) {
                    $breakdownStmt->execute([$detailId, $b['label'], $b['qty'], $order++]);
                }
            }

            if ($editId && $approverEdit) {
                $approverChanges = bomDiffProductTotals($totalsBefore, bomProductTotals($conn, $bomId));
            }

            $conn->commit();

            $action = $editId ? 'update' : 'create';
            logAudit('bom', $action, $bomId);
            // An approver's change to a pending BOM: its own greppable action with
            // a ready-to-render change list, shown on the BOM view. A save that
            // changed no quantity, rate or item writes no such row.
            if (!empty($approverChanges)) {
                logAudit('bom', 'edit_by_approver', $bomId, null, ['changes' => $approverChanges]);
                $creatorId = (int)($lockedBom['created_by'] ?? 0);
                if ($creatorId > 0 && $creatorId !== (int)getUserId()) {
                    try {
                        createNotification(
                            $creatorId,
                            'bom_edited_by_approver',
                            'BOM Changed by Approver',
                            'The approver changed quantities on BOM ' . $lockedBom['bom_number'],
                            BASE_URL . 'modules/transactions/bom_view.php?id=' . (int)$bomId,
                            (int)$bomId,
                            'bom'
                        );
                    } catch (Exception $e) {
                        error_log('bom approver-edit notify failed: ' . $e->getMessage());
                    }
                }
            }
            setAlert($editId ? __('bom_updated') : __('bom_created'), 'success');
            redirect('modules/transactions/bom_view.php?id=' . $bomId);

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = sprintf(__('database_error'), $e->getMessage());
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = $e->getMessage();
        }
    }

    // A rejected save used to go to setAlert() — which nothing renders — and
    // then redraw an EMPTY form. Keep what was posted so the user can fix it;
    // the errors are shown inline above the form.
    if (!empty($errors)) {
        // Raw POST, not the clean()ed copies: those are already HTML-escaped
        // and the form escapes again on output.
        $bom['title'] = trim($_POST['title'] ?? '');
        $bom['bom_date'] = $bomDate ?: ($_POST['bom_date'] ?? '');
        $bom['location_id'] = $locationId ?: '';
        $bom['cost_centre_id'] = $costCentreId;
        $bom['boq_id'] = $hasBoqCol ? (int)($_POST['boq_id'] ?? 0) : '';
        $bom['remarks'] = trim($_POST['remarks'] ?? '');
        $boqExcessReasons = [];
        foreach ($postedReasons as $pid => $r) {
            $boqExcessReasons[(int)$pid] = (string)$r;
        }

        $postedIds = array_values(array_filter(array_map('intval', $products)));
        $names = [];
        if ($postedIds) {
            $in = implode(',', array_unique($postedIds));
            foreach ($conn->query("SELECT id, name, unit FROM products WHERE id IN ($in)") as $p) {
                $names[(int)$p['id']] = $p;
            }
        }
        $bomDetails = [];
        foreach ($products as $i => $productId) {
            $productId = (int)$productId;
            if (!isset($names[$productId])) continue;
            $bds = [];
            foreach ($breakdownLabels[$i] ?? [] as $k => $label) {
                $bds[] = ['label' => $label, 'quantity' => $breakdownQtys[$i][$k] ?? ''];
            }
            $bomDetails[] = [
                'product_id' => $productId,
                'product_name' => $names[$productId]['name'],
                'unit' => $names[$productId]['unit'],
                'total_quantity' => $totalQuantities[$i] ?? '',
                'rate' => $rates[$i] ?? '',
                'remarks' => $itemRemarks[$i] ?? '',
                'breakdowns' => $bds,
            ];
        }
    }
}

require_once '../../includes/header.php';

$locations = getUserLocations();
$costCentres = getUserCostCentres();

// BOQs in the user's accessible locations. A BOQ the BOM is already linked to
// but that sits outside those locations is appended, otherwise the select would
// fall back to blank and the next save would silently UNLINK it.
$boqs = [];
if ($hasBoqCol) {
    $boqLocationIds = array_map('intval', array_column($locations, 'id'));
    if (!empty($boqLocationIds)) {
        $boqStmt = $conn->query("
            SELECT q.id, q.boq_number, q.title, q.boq_date, l.name AS location_name
            FROM boqs q
            LEFT JOIN locations l ON q.location_id = l.id
            WHERE q.location_id IN (" . implode(',', $boqLocationIds) . ")
            " . (boqApprovalAvailable($conn) ? "AND q.status = 'approved'" : "") . "
            ORDER BY q.id DESC
        ");
        $boqs = $boqStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $linkedBoqId = (int)($bom['boq_id'] ?? 0);
    if ($linkedBoqId > 0 && !in_array($linkedBoqId, array_map('intval', array_column($boqs, 'id')), true)) {
        $extra = $conn->prepare("
            SELECT q.id, q.boq_number, q.title, q.boq_date, l.name AS location_name
            FROM boqs q
            LEFT JOIN locations l ON q.location_id = l.id
            WHERE q.id = ?
        ");
        $extra->execute([$linkedBoqId]);
        if ($row = $extra->fetch(PDO::FETCH_ASSOC)) {
            array_unshift($boqs, $row);
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-diagram-3"></i>
                    <?php echo $isEdit ? __('edit_bom') : __('new_bom'); ?>
                </h5>
                <div>
                    <a href="bom_list.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-list"></i> <?php _e('list'); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <div class="fw-bold mb-1"><?php _e('bom_save_failed'); ?></div>
                    <ul class="mb-0">
                        <?php foreach (array_unique($errors) as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if ($isEdit && bomIsApproverEdit($bom)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> <?php _e('bom_approver_edit_notice'); ?>
                </div>
                <?php endif; ?>
                <form method="POST" id="bomForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="items_json" id="itemsJson" value="">
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($bom['id']); ?>">
                    <?php endif; ?>

                    <?php if ($hasTitleCol || $hasBoqCol): ?>
                    <div class="row mb-3">
                        <?php if ($hasTitleCol): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('title'); ?></label>
                            <input type="text" name="title" class="form-control" maxlength="255"
                                   value="<?php echo htmlspecialchars($bom['title'] ?? ''); ?>"
                                   placeholder="<?php echo __('optional'); ?>">
                        </div>
                        <?php endif; ?>
                        <?php if ($hasBoqCol): ?>
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-list-check"></i> <?php _e('boq'); ?> <small class="text-muted">(<?php _e('optional'); ?>)</small></label>
                            <select name="boq_id" id="boqId" class="form-select">
                                <option value=""><?php _e('select_boq'); ?></option>
                                <?php foreach ($boqs as $q):
                                    $boqLabel = !empty($q['title']) ? $q['title'] : $q['boq_number'];
                                ?>
                                <option value="<?php echo (int)$q['id']; ?>" <?php echo ((int)($bom['boq_id'] ?? 0) === (int)$q['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($boqLabel . ' — ' . formatDate($q['boq_date']) . ' — ' . ($q['location_name'] ?? '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="boqLegend" class="text-muted d-block mt-1">
                                <i class="bi bi-info-circle"></i> <?php _e('bom_boq_balance_legend'); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('location'); ?></label>
                            <select name="location_id" id="locationId" class="form-select" required>
                                <option value=""><?php _e('select_location'); ?></option>
                                <?= renderTreeOptions($locations, $bom['location_id']) ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('cost_centre'); ?></label>
                            <select name="cost_centre_id" id="costCentreId" class="form-select">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                                <?= renderTreeOptions($costCentres, isset($bom['cost_centre_id']) ? $bom['cost_centre_id'] : null, null, 'name', 'code') ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('bom_date'); ?></label>
                            <input type="date" name="bom_date" id="bomDate" class="form-control"
                                   value="<?php echo htmlspecialchars($bom['bom_date']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('remarks'); ?></label>
                            <textarea name="remarks" class="form-control" rows="1"
                                      placeholder="<?php echo __('optional'); ?>"><?php echo htmlspecialchars($bom['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="text-muted mb-0"><?php _e('bom_items'); ?></h6>
                        <span class="badge bg-light text-dark border">
                            <?php _e('grand_total'); ?>: <span id="grandTotal" class="fw-bold">0.00</span>
                        </span>
                    </div>
                    <div class="small text-muted mb-3">
                        <i class="bi bi-info-circle"></i> <?php _e('bom_rate_note'); ?>
                    </div>

                    <div id="bomItems"></div>

                    <button type="button" class="btn btn-sm btn-primary mt-2" id="addItemBtn" onclick="addItem()">
                        <i class="bi bi-plus"></i> <?php _e('add_item'); ?>
                    </button>
                    <?php
                    // Spreadsheet import. Matches only — the lines land in this
                    // form via addItem() and are saved by the POST handler above.
                    $bbiDoc = 'bom'; $bbiContainer = 'bomItems'; $bbiItemClass = 'bom-item';
                    include __DIR__ . '/../../includes/bom_boq_import_control.php';
                    ?>
                    <div id="bbiFormNote" class="alert alert-success mt-2 d-none"></div>

                    <?php if ($hasBoqCol): ?>
                    <!-- One reason box per product that goes beyond its BOQ balance,
                         built by refreshBoq(). Outside #bomItems on purpose: packItems()
                         disables everything in there, and these are posted as ordinary
                         boq_excess_reason[<product_id>] fields. -->
                    <div id="boqExcessWrap" class="mt-3"></div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-end mt-4">
                        <a href="bom_list.php" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                        <button type="submit" class="btn btn-success" id="bomSaveBtn">
                            <i class="bi bi-check-circle"></i> <?php _e('save_bom'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
const PRODUCT_SEARCH_URL = BASE_URL + 'api/products-search.php';
const PRODUCT_PLACEHOLDER = '<?php echo addslashes(__('select_product')); ?>';
const T = {
    unit: '<?php echo addslashes(__('unit')); ?>',
    qty: '<?php echo addslashes(__('quantity')); ?>',
    total_qty: '<?php echo addslashes(__('total_quantity')); ?>',
    rate: '<?php echo addslashes(__('bom_rate')); ?>',
    rate_hint: '<?php echo addslashes(__('bom_rate_hint')); ?>',
    value: '<?php echo addslashes(__('value')); ?>',
    remark: '<?php echo addslashes(__('remarks')); ?>',
    optional: '<?php echo addslashes(__('optional')); ?>',
    breakdown: '<?php echo addslashes(__('breakdown')); ?>',
    add_breakdown: '<?php echo addslashes(__('add_breakdown')); ?>',
    label: '<?php echo addslashes(__('label')); ?>',
    sum: '<?php echo addslashes(__('sum')); ?>',
    product: '<?php echo addslashes(__('product')); ?>',
    remove: '<?php echo addslashes(__('remove')); ?>',
    must_sum: '<?php echo addslashes(__('bom_breakdown_must_sum_js')); ?>',
    label_ph: '<?php echo addslashes(__('bom_breakdown_label_placeholder')); ?>',
    boq_qty: '<?php echo addslashes(__('boq_qty')); ?>',
    on_other_boms: '<?php echo addslashes(__('bom_on_other_boms')); ?>',
    boq_balance: '<?php echo addslashes(__('boq_balance')); ?>',
    not_in_boq: '<?php echo addslashes(__('bom_not_in_boq')); ?>',
    over_by: '<?php echo addslashes(__('bom_boq_over_by')); ?>',
    reason_label: '<?php echo addslashes(__('bom_boq_excess_reason_label')); ?>',
    reason_ph: '<?php echo addslashes(__('bom_boq_excess_reason_placeholder')); ?>',
    reason_block: '<?php echo addslashes(__('bom_boq_over_needs_reason_block')); ?>'
};

// BOQ balance per product (migration 0049). null = no BOQ selected / loaded.
const BOQ_API = BASE_URL + 'api/boq-balances.php';
const EDIT_BOM_ID = <?php echo $isEdit ? (int)$bom['id'] : 0; ?>;
let boqBalances = null;
// product_id => reason typed so far; survives the boxes being rebuilt.
const boqReasons = <?php echo json_encode((object)$boqExcessReasons, JSON_UNESCAPED_UNICODE); ?>;

// Pre-loaded items for edit mode.
const EXISTING_ITEMS = <?php echo json_encode(array_map(function ($d) {
    return [
        'product_id' => (int)$d['product_id'],
        'product_text' => $d['product_name'],
        'unit' => $d['unit'],
        'total' => (float)$d['total_quantity'],
        'rate' => (float)$d['rate'],
        'remark' => $d['remarks'],
        'breakdowns' => array_map(function ($b) {
            return ['label' => $b['label'], 'qty' => (float)$b['quantity']];
        }, $d['breakdowns'] ?? []),
    ];
}, $bomDetails), JSON_UNESCAPED_UNICODE); ?>;

let itemSeq = 0;

function fmt3(n) {
    if (n === null || n === undefined || isNaN(n)) return '0';
    return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
}

function fmt2(n) {
    if (n === null || n === undefined || isNaN(n)) return '0.00';
    return parseFloat(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function initProductSelect2($el) {
    $el.select2({
        width: '100%',
        placeholder: PRODUCT_PLACEHOLDER,
        allowClear: false,
        minimumInputLength: 1,
        ajax: {
            url: PRODUCT_SEARCH_URL,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term || '',
                    page: params.page || 1,
                    with_cost: 1,
                    location_id: $('#locationId').val() || 0,
                    date: $('#bomDate').val() || ''
                };
            },
            processResults: function (data, params) {
                params.page = params.page || 1;
                return {
                    results: data.results || [],
                    pagination: { more: !!(data.pagination && data.pagination.more) }
                };
            },
            cache: true
        }
    });

    $el.on('select2:select', function (e) {
        const block = $el.closest('.bom-item')[0];
        const data = (e.params && e.params.data) ? e.params.data : {};
        block.querySelector('.unit-cell').textContent = data.unit || '—';
        // Auto-fill rate from average cost (editable).
        if (data.rate !== undefined && data.rate !== null) {
            block.querySelector('.rate-input').value = parseFloat(data.rate) > 0 ? fmt3(data.rate) : '';
        }
        recalcBlock(block);
    });
}

function itemTemplate(idx) {
    return `
    <div class="bom-item card mb-3" data-idx="${idx}">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1">${T.product}</label>
                    <select name="products[${idx}]" class="form-select form-select-sm product-select" required>
                        <option value=""></option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">${T.unit}</label>
                    <div class="unit-cell form-control form-control-sm bg-light text-muted">—</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">${T.total_qty}</label>
                    <input type="number" name="total_quantity[${idx}]" step="0.001" min="0.001"
                           class="form-control form-control-sm total-qty" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" title="${T.rate_hint}">${T.rate} <i class="bi bi-info-circle text-muted"></i></label>
                    <input type="number" name="rate[${idx}]" step="0.01" min="0"
                           class="form-control form-control-sm rate-input" title="${T.rate_hint}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">${T.value}</label>
                    <div class="value-cell form-control form-control-sm bg-light text-end">0.00</div>
                </div>
                <div class="col-md-2 text-end">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger" title="${T.remove}" onclick="removeItem(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>

            <div class="boq-info small mt-1" style="display:none;"></div>

            <div class="row g-2 mt-1">
                <div class="col-md-12">
                    <input type="text" name="item_remarks[${idx}]" class="form-control form-control-sm"
                           placeholder="${T.remark} (${T.optional})">
                </div>
            </div>

            <div class="mt-2 ps-2 border-start border-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small text-muted"><i class="bi bi-diagram-3"></i> ${T.breakdown}</span>
                    <span class="small breakdown-sum-wrap">
                        ${T.sum}: <span class="breakdown-sum fw-bold">0</span>
                    </span>
                </div>
                <table class="table table-sm table-borderless mb-1 breakdown-table">
                    <tbody class="breakdown-rows"></tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addBreakdown(this, ${idx})">
                    <i class="bi bi-plus"></i> ${T.add_breakdown}
                </button>
            </div>
        </div>
    </div>`;
}

function breakdownRowTemplate(idx) {
    return `
    <tr class="breakdown-row">
        <td style="width:55%;">
            <input type="text" name="breakdown_label[${idx}][]" class="form-control form-control-sm breakdown-label"
                   placeholder="${T.label_ph}" required>
        </td>
        <td style="width:35%;">
            <input type="number" name="breakdown_qty[${idx}][]" step="0.001" min="0.001"
                   class="form-control form-control-sm breakdown-qty" placeholder="${T.qty}" required>
        </td>
        <td style="width:10%;" class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeBreakdown(this)">
                <i class="bi bi-x"></i>
            </button>
        </td>
    </tr>`;
}

function addItem(data) {
    const idx = itemSeq++;
    const container = document.getElementById('bomItems');
    container.insertAdjacentHTML('beforeend', itemTemplate(idx));
    const block = container.querySelector(`.bom-item[data-idx="${idx}"]`);

    const $sel = $(block).find('.product-select');
    initProductSelect2($sel);

    if (data) {
        // Hydrate product option + unit + total + rate + remark
        if (data.product_id) {
            const opt = new Option(data.product_text, data.product_id, true, true);
            $sel.append(opt).trigger('change');
        }
        block.querySelector('.unit-cell').textContent = data.unit || '—';
        block.querySelector('.total-qty').value = data.total ? fmt3(data.total) : '';
        block.querySelector('.rate-input').value = data.rate ? fmt3(data.rate) : '';
        block.querySelector('input[name^="item_remarks"]').value = data.remark || '';
        (data.breakdowns || []).forEach(b => addBreakdownRow(block, idx, b));
    }

    if (!data || !(data.breakdowns && data.breakdowns.length)) {
        // Always start with one empty breakdown row.
        addBreakdownRow(block, idx, null);
    }

    recalcBlock(block);
}

function addBreakdown(btn, idx) {
    const block = btn.closest('.bom-item');
    addBreakdownRow(block, idx, null);
}

function addBreakdownRow(block, idx, data) {
    const tbody = block.querySelector('.breakdown-rows');
    tbody.insertAdjacentHTML('beforeend', breakdownRowTemplate(idx));
    const row = tbody.lastElementChild;
    if (data) {
        row.querySelector('.breakdown-label').value = data.label || '';
        row.querySelector('.breakdown-qty').value = data.qty ? fmt3(data.qty) : '';
    }
}

function removeBreakdown(btn) {
    const block = btn.closest('.bom-item');
    const tbody = block.querySelector('.breakdown-rows');
    if (tbody.querySelectorAll('.breakdown-row').length > 1) {
        btn.closest('.breakdown-row').remove();
    } else {
        // Keep at least one row; just clear it.
        btn.closest('.breakdown-row').querySelectorAll('input').forEach(i => i.value = '');
    }
    recalcBlock(block);
}

function removeItem(btn) {
    const container = document.getElementById('bomItems');
    if (container.querySelectorAll('.bom-item').length > 1) {
        const block = btn.closest('.bom-item');
        $(block).find('.product-select').select2('destroy');
        block.remove();
        recalcGrandTotal();
    } else {
        alert('<?php echo addslashes(__('at_least_one_item_required')); ?>');
    }
}

function recalcBlock(block) {
    let sum = 0;
    block.querySelectorAll('.breakdown-qty').forEach(i => {
        const v = parseFloat(i.value);
        if (!isNaN(v)) sum += v;
    });
    const total = parseFloat(block.querySelector('.total-qty').value);
    const sumEl = block.querySelector('.breakdown-sum');
    sumEl.textContent = fmt3(sum);

    const wrap = block.querySelector('.breakdown-sum-wrap');
    if (!isNaN(total) && total > 0) {
        const ok = Math.abs(sum - total) < 0.0005;
        wrap.classList.toggle('text-success', ok);
        wrap.classList.toggle('text-danger', !ok);
    } else {
        wrap.classList.remove('text-success', 'text-danger');
    }

    // Line value = total qty * rate
    const rate = parseFloat(block.querySelector('.rate-input').value);
    const value = (!isNaN(total) && !isNaN(rate)) ? total * rate : 0;
    block.querySelector('.value-cell').textContent = fmt2(value);

    recalcGrandTotal();
}

function recalcGrandTotal() {
    let grand = 0;
    document.querySelectorAll('.bom-item').forEach(block => {
        const total = parseFloat(block.querySelector('.total-qty').value);
        const rate = parseFloat(block.querySelector('.rate-input').value);
        if (!isNaN(total) && !isNaN(rate)) grand += total * rate;
    });
    document.getElementById('grandTotal').textContent = fmt2(grand);
    refreshBoq();
}

function loadBoqBalances() {
    const sel = document.getElementById('boqId');
    if (!sel || !sel.value) {
        boqBalances = null;
        refreshBoq();
        return;
    }
    fetch(BOQ_API + '?boq_id=' + encodeURIComponent(sel.value) + '&exclude_bom_id=' + EDIT_BOM_ID, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { boqBalances = (d && d.success) ? (d.products || {}) : {}; refreshBoq(); })
        .catch(() => { boqBalances = {}; refreshBoq(); });
}

// This BOM's quantity per PRODUCT, summed across its lines — the same grain the
// server checks in bom.php.
function bomQtyByProduct() {
    const qty = {}, names = {};
    document.querySelectorAll('#bomItems .bom-item').forEach(block => {
        const $sel = $(block).find('.product-select');
        const pid = $sel.val();
        if (!pid) return;
        const q = parseFloat(block.querySelector('.total-qty').value);
        qty[pid] = (qty[pid] || 0) + (isNaN(q) ? 0 : q);
        names[pid] = $sel.find('option:selected').text();
    });
    return { qty: qty, names: names };
}

// Products beyond their BOQ balance. A product not on the BOQ at all is only
// flagged, it needs no reason.
function boqOverProducts(qty) {
    const over = {};
    if (!boqBalances) return over;
    for (const pid in qty) {
        const b = boqBalances[pid];
        if (!b || !b.in_boq) continue;
        if (qty[pid] > b.balance + 0.0005) over[pid] = qty[pid] - b.balance;
    }
    return over;
}

function refreshBoq() {
    const wrap = document.getElementById('boqExcessWrap');
    if (!wrap) return;
    const data = bomQtyByProduct();
    const over = boqOverProducts(data.qty);

    document.querySelectorAll('#bomItems .bom-item').forEach(block => {
        const info = block.querySelector('.boq-info');
        const pid = $(block).find('.product-select').val();
        if (!boqBalances || !pid) {
            info.style.display = 'none';
            info.innerHTML = '';
            return;
        }
        const b = boqBalances[pid];
        info.style.display = '';
        if (!b || !b.in_boq) {
            info.className = 'boq-info small mt-1';
            info.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle"></i> ' + T.not_in_boq + '</span>';
            return;
        }
        info.className = 'boq-info small mt-1 ' + (over[pid] !== undefined ? 'text-danger' : 'text-muted');
        info.innerHTML = '<i class="bi bi-list-check"></i> '
            + T.boq_qty + ': <b>' + fmt3(b.boq_qty) + '</b> &middot; '
            + T.on_other_boms + ': <b>' + fmt3(b.on_other) + '</b> &middot; '
            + T.boq_balance + ': <b>' + fmt3(b.balance) + '</b>'
            + (over[pid] !== undefined ? ' <span class="badge bg-danger ms-1">' + T.over_by.replace('%s', fmt3(over[pid])) + '</span>' : '');
    });

    // Reconcile the reason boxes rather than rebuilding them, so a box the user
    // is typing in is never replaced under them.
    wrap.querySelectorAll('.boq-excess-item').forEach(el => {
        const pid = el.dataset.productId;
        boqReasons[pid] = el.querySelector('textarea').value;
        if (over[pid] === undefined) el.remove();
    });
    for (const pid in over) {
        let el = wrap.querySelector('.boq-excess-item[data-product-id="' + pid + '"]');
        if (!el) {
            el = document.createElement('div');
            el.className = 'boq-excess-item mb-2';
            el.dataset.productId = pid;
            el.innerHTML = '<label class="form-label small mb-1 text-danger fw-bold">'
                + '<i class="bi bi-exclamation-triangle"></i> <span class="boq-excess-name"></span> &mdash; ' + T.reason_label
                + ' <span class="boq-excess-badge badge bg-danger ms-1"></span></label>'
                + '<textarea class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>';
            const ta = el.querySelector('textarea');
            ta.name = 'boq_excess_reason[' + pid + ']';
            ta.placeholder = T.reason_ph;
            ta.value = boqReasons[pid] || '';
            wrap.appendChild(el);
        }
        el.querySelector('.boq-excess-name').textContent = data.names[pid] || ('#' + pid);
        el.querySelector('.boq-excess-badge').textContent = T.over_by.replace('%s', fmt3(over[pid]));
    }
}

document.getElementById('bomItems').addEventListener('input', function (e) {
    if (e.target.classList.contains('breakdown-qty') ||
        e.target.classList.contains('total-qty') ||
        e.target.classList.contains('rate-input')) {
        recalcBlock(e.target.closest('.bom-item'));
    }
});

// A line nobody has touched: no product, and every box empty.
function isBlankItem(block) {
    if ($(block).find('.product-select').val()) return false;
    return Array.from(block.querySelectorAll('input')).every(i => i.value.trim() === '');
}

// Drop untouched lines. The server already skips them, but their `required`
// product box sits hidden behind select2, where the browser cannot show its
// bubble — so it blocked Save with no message at all. Keeps one line unless
// allowEmpty (the importer is about to add its own).
function pruneBlankItems(allowEmpty) {
    const blocks = Array.from(document.querySelectorAll('#bomItems .bom-item'));
    let remaining = blocks.length;
    blocks.forEach(block => {
        if ((allowEmpty || remaining > 1) && isBlankItem(block)) {
            $(block).find('.product-select').select2('destroy');
            block.remove();
            remaining--;
        }
    });
    recalcGrandTotal();
}

// Runs on the Save click, i.e. BEFORE the browser's own validation (Enter in a
// field fires this click too). Anything select2 hides must be caught here,
// because the browser cannot focus it to say what is wrong.
document.getElementById('bomSaveBtn').addEventListener('click', function (e) {
    pruneBlankItems(false);

    if (!$('#locationId').val()) {
        e.preventDefault();
        alert('<?php echo addslashes(__('please_select_location')); ?>');
        $('#locationId').select2('open');
        return;
    }

    const noProduct = Array.from(document.querySelectorAll('#bomItems .bom-item'))
        .find(block => !$(block).find('.product-select').val());
    if (noProduct) {
        e.preventDefault();
        noProduct.scrollIntoView({ behavior: 'smooth', block: 'center' });
        alert('<?php echo addslashes(__('bom_line_needs_product')); ?>');
        $(noProduct).find('.product-select').select2('open');
    }
});

document.getElementById('bomForm').addEventListener('submit', function (e) {
    const blocks = document.querySelectorAll('.bom-item');
    let firstError = null;

    blocks.forEach(block => {
        const product = $(block).find('.product-select').val();
        const total = parseFloat(block.querySelector('.total-qty').value);
        if (!product || isNaN(total) || total <= 0) return; // server skips empties

        let sum = 0, hasBreakdown = false;
        block.querySelectorAll('.breakdown-row').forEach(row => {
            const label = row.querySelector('.breakdown-label').value.trim();
            const qty = parseFloat(row.querySelector('.breakdown-qty').value);
            if (label && !isNaN(qty) && qty > 0) { sum += qty; hasBreakdown = true; }
        });

        if (!hasBreakdown || Math.abs(sum - total) >= 0.0005) {
            if (!firstError) firstError = block;
        }
    });

    if (firstError) {
        e.preventDefault();
        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        alert(T.must_sum);
        return false;
    }

    // Beyond the BOQ balance needs a written reason; the server re-checks.
    refreshBoq();
    const missing = Array.from(document.querySelectorAll('#boqExcessWrap textarea'))
        .find(ta => ta.value.trim() === '');
    if (missing) {
        e.preventDefault();
        missing.scrollIntoView({ behavior: 'smooth', block: 'center' });
        missing.focus();
        alert(T.reason_block);
        return false;
    }

    packItems();
});

// Send every line as ONE field. As separate fields a large BOM (185 lines =
// 1,117 inputs) overruns PHP's max_input_vars and the server silently drops
// the tail. Disabled inputs are not submitted, so only the packed copy goes.
function packItems() {
    const lines = Array.from(document.querySelectorAll('#bomItems .bom-item')).map(block => ({
        product: $(block).find('.product-select').val() || '',
        total: block.querySelector('.total-qty').value,
        rate: block.querySelector('.rate-input').value,
        remark: block.querySelector('input[name^="item_remarks"]').value,
        breakdowns: Array.from(block.querySelectorAll('.breakdown-row')).map(row => ({
            label: row.querySelector('.breakdown-label').value,
            qty: row.querySelector('.breakdown-qty').value
        }))
    }));
    document.getElementById('itemsJson').value = JSON.stringify(lines);
    document.querySelectorAll('#bomItems input, #bomItems select').forEach(el => el.disabled = true);
}

// Back-button restore (bfcache) would bring the page back still disabled.
window.addEventListener('pageshow', function () {
    document.getElementById('itemsJson').value = '';
    document.querySelectorAll('#bomItems input, #bomItems select').forEach(el => el.disabled = false);
});

$(document).ready(function () {
    $('#locationId').select2({ width: '100%', placeholder: '<?php echo addslashes(__('select_location')); ?>' });
    $('#costCentreId').select2({ width: '100%', placeholder: '<?php echo addslashes(__('select_cost_centre')); ?>', allowClear: true });
    if (document.getElementById('boqId')) {
        $('#boqId').select2({ width: '100%', placeholder: '<?php echo addslashes(__('select_boq')); ?>', allowClear: true });
        // jQuery handler: select2 fires change through jQuery only.
        $('#boqId').on('change', loadBoqBalances);
    }

    if (EXISTING_ITEMS.length) {
        EXISTING_ITEMS.forEach(addItem);
    } else {
        addItem(null);
    }
    loadBoqBalances();
});
</script>
