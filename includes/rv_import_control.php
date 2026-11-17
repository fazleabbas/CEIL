<?php
/**
 * "Import from Excel" control for the Requisition Voucher form.
 *
 * Include it on ONE line next to the form's Add Row button. It renders a button
 * + modal and posts the chosen sheet to api/requisition-items-import.php, which
 * MATCHES ONLY — it writes nothing. The matched lines are handed to the page's
 * OWN addRow(), so an imported row is indistinguishable from a typed one and
 * the form's existing validation and Save do the rest.
 *
 * Modelled on includes/bom_boq_import_control.php, which is deliberately NOT
 * reused: that partial calls addItem() and understands breakdowns and rates,
 * neither of which a requisition has. Keeping them apart means BOM and BOQ
 * cannot be disturbed by anything done here.
 *
 * REQUIRES from the host page:
 *   addRow(data)                    - data = {product_id, product_text, qty, remark}
 *   #requisitionItems               - the items tbody, rows of class .item-row
 *   destinationLocked               - items stay disabled until a destination is chosen
 * OPTIONAL (used when present):
 *   fetchFamilyStockForProducts(ids), updateAllAvailableCells(), updateAllBomCells()
 *
 * Nothing here runs before DOMContentLoaded: jQuery and Bootstrap load from the
 * CDN in includes/footer.php, i.e. AFTER this inline script.
 */
?>
<button type="button" class="btn btn-sm btn-outline-success" id="rviOpenBtn">
    <i class="bi bi-file-earmark-excel"></i> <?php _e('rv_import_button'); ?>
</button>

<div class="modal fade" id="rviModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-excel"></i> <?php _e('rv_import_title'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2"><?php _e('rv_import_intro'); ?></p>

        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered small mb-1">
            <thead class="table-light">
              <tr>
                <th><?php _e('bom_import_col_product_id'); ?></th>
                <th><?php _e('bom_import_col_product'); ?></th>
                <th><?php _e('bom_import_col_qty'); ?></th>
                <th><?php _e('bom_import_col_remarks'); ?></th>
              </tr>
            </thead>
          </table>
          <div class="small text-muted"><?php _e('rv_import_col_help'); ?></div>
        </div>

        <a href="<?php echo BASE_URL; ?>api/requisition-items-import.php?action=template"
           class="btn btn-sm btn-outline-secondary mb-3">
            <i class="bi bi-download"></i> <?php _e('bom_import_download_template'); ?>
        </a>

        <div class="mb-3">
          <label class="form-label"><?php _e('bom_import_file_label'); ?></label>
          <input type="file" class="form-control" id="rviFile" accept=".xlsx,.csv">
        </div>

        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="rviReplace">
          <label class="form-check-label" for="rviReplace">
            <?php _e('rv_import_replace'); ?>
            <span class="d-block text-muted small"><?php _e('rv_import_replace_help'); ?></span>
          </label>
        </div>

        <div id="rviResult"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
        <button type="button" class="btn btn-primary" id="rviRunBtn">
            <i class="bi bi-upload"></i> <?php _e('bom_import_run'); ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    const RVI = {
        url: '<?php echo BASE_URL; ?>api/requisition-items-import.php',
        t: {
            choose:    '<?php echo addslashes(__('bom_import_choose_file')); ?>',
            reading:   '<?php echo addslashes(__('bom_import_reading')); ?>',
            run:       '<?php echo addslashes(__('bom_import_run')); ?>',
            summary:   '<?php echo addslashes(__('bom_import_result_summary')); ?>',
            skipped:   '<?php echo addslashes(__('bom_import_skipped_title')); ?>',
            addAnyway: '<?php echo addslashes(__('bom_import_add_anyway')); ?>',
            nothing:   '<?php echo addslashes(__('bom_import_nothing')); ?>',
            added:     '<?php echo addslashes(__('bom_import_added')); ?>',
            failed:    '<?php echo addslashes(__('bom_import_failed')); ?>',
            row:       '<?php echo addslashes(__('bom_import_row')); ?>',
            merged:    '<?php echo addslashes(__('rv_import_merged')); ?>',
            lockFirst: '<?php echo addslashes(__('rv_import_lock_destination_first')); ?>'
        }
    };

    // The lang strings are authored for sprintf() on the PHP side and reused
    // verbatim here.
    function fill(tpl, values) {
        let i = 0;
        return String(tpl)
            .replace(/%(\d+)\$[ds]/g, (_, n) => values[parseInt(n, 10) - 1])
            .replace(/%[ds]/g, () => values[i++]);
    }
    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s === null || s === undefined ? '' : String(s);
        return d.innerHTML;
    }

    /**
     * Drop every row currently in the form. select2 must be destroyed FIRST —
     * clearing innerHTML alone leaves select2's own detached DOM behind, which
     * is why the page's removeRow() destroys it too.
     */
    function clearRows() {
        const tbody = document.getElementById('requisitionItems');
        if (!tbody) return;
        tbody.querySelectorAll('.item-row').forEach(row => {
            const $sel = window.jQuery ? jQuery(row).find('.product-select') : null;
            if ($sel && $sel.length && $sel.data('select2')) $sel.select2('destroy');
        });
        tbody.innerHTML = '';
    }

    /**
     * Drop rows that are still completely empty — the blank starter row the
     * form always renders. Without this an import always leaves a stray blank
     * line at the top.
     */
    function dropEmptyRows() {
        const tbody = document.getElementById('requisitionItems');
        if (!tbody) return;
        tbody.querySelectorAll('.item-row').forEach(row => {
            const sel = row.querySelector('.product-select');
            const qty = row.querySelector('.qty-input');
            if (!sel || !qty) return;
            if (!sel.value && !qty.value) {
                const $sel = window.jQuery ? jQuery(sel) : null;
                if ($sel && $sel.data('select2')) $sel.select2('destroy');
                row.remove();
            }
        });
    }

    function applyRows(rows) {
        if (document.getElementById('rviReplace').checked) clearRows();
        dropEmptyRows();

        rows.forEach(r => addRow(r));

        // The form expects at least one row to exist.
        const tbody = document.getElementById('requisitionItems');
        if (tbody && !tbody.querySelectorAll('.item-row').length) addRow();

        if (typeof renumberRows === 'function') renumberRows();

        // The Available lookup is bound to select2:select, which does NOT fire
        // for a programmatic selection. Batch-fetch instead — the same thing
        // the form does for pre-loaded rows in edit mode, and one request
        // rather than one per row.
        const ids = rows.map(r => parseInt(r.product_id, 10)).filter(Boolean);
        if (ids.length && typeof fetchFamilyStockForProducts === 'function') {
            fetchFamilyStockForProducts(ids).then(function () {
                if (typeof updateAllAvailableCells === 'function') updateAllAvailableCells();
            });
        }
        if (typeof updateAllBomCells === 'function') updateAllBomCells();
    }

    function renderResult(data) {
        const box = document.getElementById('rviResult');
        const rows = data.rows || [];
        const skipped = data.skipped || [];
        const st = data.stats || {};
        let html = '';

        if (!rows.length) {
            html += '<div class="alert alert-warning mb-2">' + esc(RVI.t.nothing) + '</div>';
        } else {
            const filled = (st.used_rows !== undefined) ? st.used_rows : st.sheet_rows;
            html += '<div class="alert alert-info mb-2">' +
                    esc(fill(RVI.t.summary, [st.lines, filled]));
            if (st.merged_rows) {
                html += '<div class="small mt-1">' + esc(fill(RVI.t.merged, [st.merged_rows])) + '</div>';
            }
            html += '</div>';
        }

        if (skipped.length) {
            html += '<div class="alert alert-warning mb-2"><strong>' +
                    esc(fill(RVI.t.skipped, [skipped.length])) + '</strong>' +
                    '<div class="table-responsive mt-2" style="max-height:240px;overflow:auto;">' +
                    '<table class="table table-sm table-bordered small mb-0"><tbody>';
            skipped.forEach(s => {
                html += '<tr><td class="text-nowrap">' + esc(fill(RVI.t.row, [s.row])) + '</td>' +
                        '<td>' + esc(s.value) + '</td>' +
                        '<td>' + esc(s.reason) + '</td></tr>';
            });
            html += '</tbody></table></div></div>';
        }

        if (rows.length && skipped.length) {
            // Something was dropped — make the user look before it lands.
            html += '<button type="button" class="btn btn-warning" id="rviApplyBtn">' +
                    esc(fill(RVI.t.addAnyway, [rows.length])) + '</button>';
        }
        box.innerHTML = html;

        if (rows.length && !skipped.length) {
            finish(rows); // clean sheet: straight into the form
            return;
        }
        const applyBtn = document.getElementById('rviApplyBtn');
        if (applyBtn) applyBtn.addEventListener('click', () => finish(rows));
    }

    function finish(rows) {
        applyRows(rows);
        const modalEl = document.getElementById('rviModal');
        const inst = bootstrap.Modal.getInstance(modalEl);
        if (inst) inst.hide();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const openBtn = document.getElementById('rviOpenBtn');
        const runBtn  = document.getElementById('rviRunBtn');
        if (!openBtn || !runBtn) return;

        openBtn.addEventListener('click', function () {
            // addRow() refuses to run until a destination is locked, so say so
            // here rather than letting the import silently add nothing.
            if (typeof destinationLocked !== 'undefined' && !destinationLocked) {
                alert(RVI.t.lockFirst);
                return;
            }
            document.getElementById('rviResult').innerHTML = '';
            new bootstrap.Modal(document.getElementById('rviModal')).show();
        });

        runBtn.addEventListener('click', function () {
            const fileEl = document.getElementById('rviFile');
            const box = document.getElementById('rviResult');
            if (!fileEl.files || !fileEl.files.length) {
                box.innerHTML = '<div class="alert alert-danger mb-0">' + esc(RVI.t.choose) + '</div>';
                return;
            }

            const fd = new FormData();
            fd.append('file', fileEl.files[0]);

            runBtn.disabled = true;
            runBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + esc(RVI.t.reading);
            box.innerHTML = '';

            fetch(RVI.url + '?action=parse', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json().catch(() => { throw new Error('HTTP ' + r.status); }))
            .then(data => {
                if (!data.success) throw new Error(data.error || 'HTTP error');
                renderResult(data);
            })
            .catch(err => {
                box.innerHTML = '<div class="alert alert-danger mb-0">' +
                                esc(fill(RVI.t.failed, [err.message])) + '</div>';
            })
            .finally(() => {
                runBtn.disabled = false;
                runBtn.innerHTML = '<i class="bi bi-upload"></i> ' + esc(RVI.t.run);
            });
        });
    });
})();
</script>
