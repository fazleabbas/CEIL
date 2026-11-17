<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$isEdit = isset($_GET['id']) && !empty($_GET['id']);
$pageTitle = $isEdit ? __('edit_boq') : __('new_boq');

if (!hasPermission('boq', $isEdit ? 'edit' : 'create')) {
    setAlert(__('no_permission'), 'danger');
    redirect('');
}

// Whether the optional `title` column exists (migration 0035). Guards all title
// reads/writes so the page still works if the migration hasn't run yet.
$hasTitleCol = (bool)$conn->query("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'boqs'
      AND COLUMN_NAME = 'title'
")->fetchColumn();

// Initialize BOQ data
$boq = [
    'id' => '',
    'title' => '',
    'boq_date' => date('Y-m-d'),
    'location_id' => '',
    'cost_centre_id' => '',
    'remarks' => '',
];
$boqDetails = [];

// If editing, load existing BOQ
if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM boqs WHERE id = ?");
    $stmt->execute([$id]);
    $boq = $stmt->fetch();

    if (!$boq) {
        setAlert(__('boq_not_found'), 'danger');
        redirect('modules/transactions/boq_list.php');
    }

    // Only a draft can be edited (migration 0051).
    if (!boqCanEdit($boq)) {
        setAlert(__('boq_only_draft_edit'), 'danger');
        redirect('modules/transactions/boq_view.php?id=' . $id);
    }

    // Load detail lines with product + unit
    $detailsStmt = $conn->prepare("
        SELECT bd.*, p.name as product_name, p.unit
        FROM boq_details bd
        LEFT JOIN products p ON bd.product_id = p.id
        WHERE bd.boq_id = ?
        ORDER BY bd.id
    ");
    $detailsStmt->execute([$id]);
    $boqDetails = $detailsStmt->fetchAll();

    // Load breakdowns grouped by detail id
    $bdStmt = $conn->prepare("
        SELECT bb.*
        FROM boq_detail_breakdowns bb
        INNER JOIN boq_details bd ON bb.boq_detail_id = bd.id
        WHERE bd.boq_id = ?
        ORDER BY bb.boq_detail_id, bb.sort_order, bb.id
    ");
    $bdStmt->execute([$id]);
    $breakdownsByDetail = [];
    foreach ($bdStmt->fetchAll() as $bd) {
        $breakdownsByDetail[$bd['boq_detail_id']][] = $bd;
    }
    foreach ($boqDetails as &$d) {
        $d['breakdowns'] = $breakdownsByDetail[$d['id']] ?? [];
    }
    unset($d);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    checkCSRF();

    $locationId = (int)$_POST['location_id'];
    $costCentreId = !empty($_POST['cost_centre_id']) ? (int)$_POST['cost_centre_id'] : null;
    $boqDate = dbDate($_POST['boq_date']);
    $remarks = clean($_POST['remarks'] ?? '');
    $title = $hasTitleCol ? clean($_POST['title'] ?? '') : null;
    $editId = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    $products = $_POST['products'] ?? [];
    $totalQuantities = $_POST['total_quantity'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];
    $breakdownLabels = $_POST['breakdown_label'] ?? [];
    $breakdownQtys = $_POST['breakdown_qty'] ?? [];

    // Lines normally arrive packed in one field (see unpackBomBoqItems()).
    $packed = unpackBomBoqItems($_POST['items_json'] ?? null);
    if ($packed !== null) {
        $products = $packed['products'];
        $totalQuantities = $packed['total_quantity'];
        $itemRemarks = $packed['item_remarks'];
        $breakdownLabels = $packed['breakdown_label'];
        $breakdownQtys = $packed['breakdown_qty'];
    }
    $truncated = postInputWasTruncated();

    $errors = [];

    if (empty($locationId)) {
        $errors[] = __('please_select_location');
    }
    if (empty($boqDate)) {
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
                $errors[] = sprintf(__('boq_breakdown_incomplete'), $productId);
                continue;
            }
            $rows[] = ['label' => $label, 'qty' => $qty];
            $sum += $qty;
        }

        if (empty($rows)) {
            $errors[] = sprintf(__('boq_breakdown_required'), $productId);
        } elseif (abs($sum - $total) > 0.0005) {
            $fmt = function ($n) { return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.'); };
            $errors[] = sprintf(__('boq_breakdown_must_sum'), $fmt($sum), $fmt($total));
        }

        $items[] = [
            'product_id' => $productId,
            'total' => $total,
            'remark' => clean($itemRemarks[$i] ?? ''),
            'breakdowns' => $rows,
        ];
    }

    if (empty($items)) {
        $errors[] = __('please_add_at_least_one_item');
    }

    // The page gate checked ?id=, but edit_id comes from the POST body: re-check
    // it, so a pending or approved BOQ cannot be rewritten by a hand-made POST.
    if ($editId) {
        $chk = $conn->prepare("SELECT * FROM boqs WHERE id = ?");
        $chk->execute([$editId]);
        $existingBoq = $chk->fetch();
        if (!$existingBoq || !boqCanEdit($existingBoq)) {
            $errors[] = __('boq_only_draft_edit');
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

            // It may have been sent for approval since the check above.
            if ($editId && boqApprovalAvailable($conn)) {
                $lock = $conn->prepare("SELECT status FROM boqs WHERE id = ? FOR UPDATE");
                $lock->execute([$editId]);
                if ($lock->fetchColumn() !== 'draft') {
                    throw new Exception(__('boq_only_draft_edit'));
                }
            }

            // title column is included only when it exists (migration 0035).
            $titleSet = $hasTitleCol ? ', title = ?' : '';
            if ($editId) {
                $stmt = $conn->prepare("
                    UPDATE boqs
                    SET boq_date = ?, location_id = ?, cost_centre_id = ?, remarks = ?{$titleSet}
                    WHERE id = ?
                ");
                $params = [$boqDate, $locationId, $costCentreId, $remarks];
                if ($hasTitleCol) $params[] = $title;
                $params[] = $editId;
                $stmt->execute($params);

                // Remove old details (breakdowns cascade via FK)
                $conn->prepare("DELETE FROM boq_details WHERE boq_id = ?")->execute([$editId]);
                $boqId = $editId;
            } else {
                $boqNumber = getNextNumber('boqs', 'boq_number', getSetting('boq_prefix', 'BOQ'));
                if ($hasTitleCol) {
                    $stmt = $conn->prepare("
                        INSERT INTO boqs (boq_number, title, boq_date, location_id, cost_centre_id, remarks, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$boqNumber, $title, $boqDate, $locationId, $costCentreId, $remarks, getUserId()]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO boqs (boq_number, boq_date, location_id, cost_centre_id, remarks, created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$boqNumber, $boqDate, $locationId, $costCentreId, $remarks, getUserId()]);
                }
                $boqId = $conn->lastInsertId();
            }

            $detailStmt = $conn->prepare("
                INSERT INTO boq_details (boq_id, product_id, total_quantity, remarks)
                VALUES (?, ?, ?, ?)
            ");
            $breakdownStmt = $conn->prepare("
                INSERT INTO boq_detail_breakdowns (boq_detail_id, label, quantity, sort_order)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                $detailStmt->execute([$boqId, $item['product_id'], $item['total'], $item['remark']]);
                $detailId = $conn->lastInsertId();
                $order = 0;
                foreach ($item['breakdowns'] as $b) {
                    $breakdownStmt->execute([$detailId, $b['label'], $b['qty'], $order++]);
                }
            }

            $conn->commit();

            $action = $editId ? 'update' : 'create';
            logAudit('boq', $action, $boqId);
            setAlert($editId ? __('boq_updated') : __('boq_created'), 'success');
            redirect('modules/transactions/boq_view.php?id=' . $boqId);

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
        $boq['title'] = trim($_POST['title'] ?? '');
        $boq['boq_date'] = $boqDate ?: ($_POST['boq_date'] ?? '');
        $boq['location_id'] = $locationId ?: '';
        $boq['cost_centre_id'] = $costCentreId;
        $boq['remarks'] = trim($_POST['remarks'] ?? '');

        $postedIds = array_values(array_filter(array_map('intval', $products)));
        $names = [];
        if ($postedIds) {
            $in = implode(',', array_unique($postedIds));
            foreach ($conn->query("SELECT id, name, unit FROM products WHERE id IN ($in)") as $p) {
                $names[(int)$p['id']] = $p;
            }
        }
        $boqDetails = [];
        foreach ($products as $i => $productId) {
            $productId = (int)$productId;
            if (!isset($names[$productId])) continue;
            $bds = [];
            foreach ($breakdownLabels[$i] ?? [] as $k => $label) {
                $bds[] = ['label' => $label, 'quantity' => $breakdownQtys[$i][$k] ?? ''];
            }
            $boqDetails[] = [
                'product_id' => $productId,
                'product_name' => $names[$productId]['name'],
                'unit' => $names[$productId]['unit'],
                'total_quantity' => $totalQuantities[$i] ?? '',
                'remarks' => $itemRemarks[$i] ?? '',
                'breakdowns' => $bds,
            ];
        }
    }
}

require_once '../../includes/header.php';

$locations = getUserLocations();
$costCentres = getUserCostCentres();

// Build the data the JS template uses for rebuilding rows.
?>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-rulers"></i>
                    <?php echo $isEdit ? __('edit_boq') : __('new_boq'); ?>
                </h5>
                <div>
                    <a href="boq_list.php" class="btn btn-sm btn-secondary">
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
                <form method="POST" id="boqForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="items_json" id="itemsJson" value="">
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($boq['id']); ?>">
                    <?php endif; ?>

                    <?php if ($hasTitleCol): ?>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php _e('title'); ?></label>
                            <input type="text" name="title" class="form-control" maxlength="255"
                                   value="<?php echo htmlspecialchars($boq['title'] ?? ''); ?>"
                                   placeholder="<?php echo __('optional'); ?>">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('location'); ?></label>
                            <select name="location_id" id="locationId" class="form-select" required>
                                <option value=""><?php _e('select_location'); ?></option>
                                <?= renderTreeOptions($locations, $boq['location_id']) ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('cost_centre'); ?></label>
                            <select name="cost_centre_id" id="costCentreId" class="form-select">
                                <option value=""><?php _e('select_cost_centre'); ?></option>
                                <?= renderTreeOptions($costCentres, isset($boq['cost_centre_id']) ? $boq['cost_centre_id'] : null, null, 'name', 'code') ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required"><?php _e('boq_date'); ?></label>
                            <input type="date" name="boq_date" id="boqDate" class="form-control"
                                   value="<?php echo htmlspecialchars($boq['boq_date']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php _e('remarks'); ?></label>
                            <textarea name="remarks" class="form-control" rows="1"
                                      placeholder="<?php echo __('optional'); ?>"><?php echo htmlspecialchars($boq['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <h6 class="text-muted mb-3"><?php _e('boq_items'); ?></h6>

                    <div id="boqItems"></div>

                    <button type="button" class="btn btn-sm btn-primary mt-2" id="addItemBtn" onclick="addItem()">
                        <i class="bi bi-plus"></i> <?php _e('add_item'); ?>
                    </button>
                    <?php
                    // Spreadsheet import. Matches only — the lines land in this
                    // form via addItem() and are saved by the POST handler above.
                    $bbiDoc = 'boq'; $bbiContainer = 'boqItems'; $bbiItemClass = 'boq-item';
                    include __DIR__ . '/../../includes/bom_boq_import_control.php';
                    ?>
                    <div id="bbiFormNote" class="alert alert-success mt-2 d-none"></div>

                    <div class="d-flex justify-content-end mt-4">
                        <a href="boq_list.php" class="btn btn-secondary me-2"><?php _e('cancel'); ?></a>
                        <button type="submit" class="btn btn-success" id="boqSaveBtn">
                            <i class="bi bi-check-circle"></i> <?php _e('save_boq'); ?>
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
    remark: '<?php echo addslashes(__('remarks')); ?>',
    optional: '<?php echo addslashes(__('optional')); ?>',
    breakdown: '<?php echo addslashes(__('breakdown')); ?>',
    add_breakdown: '<?php echo addslashes(__('add_breakdown')); ?>',
    label: '<?php echo addslashes(__('label')); ?>',
    sum: '<?php echo addslashes(__('sum')); ?>',
    product: '<?php echo addslashes(__('product')); ?>',
    remove: '<?php echo addslashes(__('remove')); ?>',
    must_sum: '<?php echo addslashes(__('boq_breakdown_must_sum_js')); ?>',
    label_ph: '<?php echo addslashes(__('boq_breakdown_label_placeholder')); ?>'
};

// Pre-loaded items for edit mode.
const EXISTING_ITEMS = <?php echo json_encode(array_map(function ($d) {
    return [
        'product_id' => (int)$d['product_id'],
        'product_text' => $d['product_name'],
        'unit' => $d['unit'],
        'total' => (float)$d['total_quantity'],
        'remark' => $d['remarks'],
        'breakdowns' => array_map(function ($b) {
            return ['label' => $b['label'], 'qty' => (float)$b['quantity']];
        }, $d['breakdowns'] ?? []),
    ];
}, $boqDetails), JSON_UNESCAPED_UNICODE); ?>;

let itemSeq = 0;

function fmt3(n) {
    if (n === null || n === undefined || isNaN(n)) return '0';
    return parseFloat(n).toFixed(3).replace(/\.?0+$/, '');
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
                return { q: params.term || '', page: params.page || 1 };
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
        const block = $el.closest('.boq-item')[0];
        const unit = (e.params && e.params.data && e.params.data.unit) ? e.params.data.unit : '';
        block.querySelector('.unit-cell').textContent = unit || '—';
    });
}

function itemTemplate(idx) {
    return `
    <div class="boq-item card mb-3" data-idx="${idx}">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
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
                <div class="col-md-4">
                    <label class="form-label small mb-1">${T.remark}</label>
                    <input type="text" name="item_remarks[${idx}]" class="form-control form-control-sm"
                           placeholder="${T.optional}">
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-sm btn-danger" title="${T.remove}" onclick="removeItem(this)">
                        <i class="bi bi-trash"></i>
                    </button>
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
    const container = document.getElementById('boqItems');
    container.insertAdjacentHTML('beforeend', itemTemplate(idx));
    const block = container.querySelector(`.boq-item[data-idx="${idx}"]`);

    const $sel = $(block).find('.product-select');
    initProductSelect2($sel);

    if (data) {
        // Hydrate product option + unit + total + remark
        if (data.product_id) {
            const opt = new Option(data.product_text, data.product_id, true, true);
            $sel.append(opt).trigger('change');
        }
        block.querySelector('.unit-cell').textContent = data.unit || '—';
        block.querySelector('.total-qty').value = data.total ? fmt3(data.total) : '';
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
    const block = btn.closest('.boq-item');
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
    const block = btn.closest('.boq-item');
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
    const container = document.getElementById('boqItems');
    if (container.querySelectorAll('.boq-item').length > 1) {
        const block = btn.closest('.boq-item');
        $(block).find('.product-select').select2('destroy');
        block.remove();
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
}

document.getElementById('boqItems').addEventListener('input', function (e) {
    if (e.target.classList.contains('breakdown-qty') || e.target.classList.contains('total-qty')) {
        recalcBlock(e.target.closest('.boq-item'));
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
    const blocks = Array.from(document.querySelectorAll('#boqItems .boq-item'));
    let remaining = blocks.length;
    blocks.forEach(block => {
        if ((allowEmpty || remaining > 1) && isBlankItem(block)) {
            $(block).find('.product-select').select2('destroy');
            block.remove();
            remaining--;
        }
    });
}

// Runs on the Save click, i.e. BEFORE the browser's own validation (Enter in a
// field fires this click too). Anything select2 hides must be caught here,
// because the browser cannot focus it to say what is wrong.
document.getElementById('boqSaveBtn').addEventListener('click', function (e) {
    pruneBlankItems(false);

    if (!$('#locationId').val()) {
        e.preventDefault();
        alert('<?php echo addslashes(__('please_select_location')); ?>');
        $('#locationId').select2('open');
        return;
    }

    const noProduct = Array.from(document.querySelectorAll('#boqItems .boq-item'))
        .find(block => !$(block).find('.product-select').val());
    if (noProduct) {
        e.preventDefault();
        noProduct.scrollIntoView({ behavior: 'smooth', block: 'center' });
        alert('<?php echo addslashes(__('bom_line_needs_product')); ?>');
        $(noProduct).find('.product-select').select2('open');
    }
});

document.getElementById('boqForm').addEventListener('submit', function (e) {
    const blocks = document.querySelectorAll('.boq-item');
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

    packItems();
});

// Send every line as ONE field. As separate fields a large BOQ overruns PHP's
// max_input_vars and the server silently drops the tail. Disabled inputs are
// not submitted, so only the packed copy goes.
function packItems() {
    const lines = Array.from(document.querySelectorAll('#boqItems .boq-item')).map(block => ({
        product: $(block).find('.product-select').val() || '',
        total: block.querySelector('.total-qty').value,
        remark: block.querySelector('input[name^="item_remarks"]').value,
        breakdowns: Array.from(block.querySelectorAll('.breakdown-row')).map(row => ({
            label: row.querySelector('.breakdown-label').value,
            qty: row.querySelector('.breakdown-qty').value
        }))
    }));
    document.getElementById('itemsJson').value = JSON.stringify(lines);
    document.querySelectorAll('#boqItems input, #boqItems select').forEach(el => el.disabled = true);
}

// Back-button restore (bfcache) would bring the page back still disabled.
window.addEventListener('pageshow', function () {
    document.getElementById('itemsJson').value = '';
    document.querySelectorAll('#boqItems input, #boqItems select').forEach(el => el.disabled = false);
});

$(document).ready(function () {
    $('#locationId').select2({ width: '100%', placeholder: '<?php echo addslashes(__('select_location')); ?>' });
    $('#costCentreId').select2({ width: '100%', placeholder: '<?php echo addslashes(__('select_cost_centre')); ?>', allowClear: true });

    if (EXISTING_ITEMS.length) {
        EXISTING_ITEMS.forEach(addItem);
    } else {
        addItem(null);
    }
});
</script>
