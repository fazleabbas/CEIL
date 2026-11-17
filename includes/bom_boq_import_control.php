<?php
/**
 * Shared "Import from Excel" control for the BOM and BOQ forms.
 *
 * Include it on ONE line next to each form's Add Item button, after setting:
 *   $bbiDoc       'bom' | 'boq'   — which document (picks the API branch)
 *   $bbiContainer 'bomItems' | 'boqItems'
 *   $bbiItemClass 'bom-item' | 'boq-item'
 *
 * It renders a button + modal and posts the chosen sheet to
 * api/bom-boq-import.php, which MATCHES ONLY — it writes nothing. The matched
 * lines are handed to the page's OWN addItem(), the same function the edit path
 * uses to rehydrate a saved document, so an imported row is indistinguishable
 * from a typed one and the form's existing validation and Save do the rest.
 *
 * Nothing here runs before DOMContentLoaded: jQuery and Bootstrap load from the
 * CDN in includes/footer.php, i.e. AFTER this inline script. Handlers are bound
 * on DOMContentLoaded and bootstrap.Modal is only touched on click.
 */

$bbiDoc       = isset($bbiDoc) && $bbiDoc === 'boq' ? 'boq' : 'bom';
$bbiContainer = $bbiContainer ?? ($bbiDoc . 'Items');
$bbiItemClass = $bbiItemClass ?? ($bbiDoc . '-item');
$bbiHasRate   = ($bbiDoc === 'bom');
?>
<button type="button" class="btn btn-sm btn-outline-success mt-2 ms-1" id="bbiOpenBtn">
    <i class="bi bi-file-earmark-excel"></i> <?php _e('bom_import_button'); ?>
</button>

<div class="modal fade" id="bbiModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-excel"></i> <?php _e('bom_import_title'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2"><?php _e('bom_import_intro'); ?></p>

        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered small mb-1">
            <thead class="table-light">
              <tr>
                <th><?php _e('bom_import_col_product_id'); ?></th>
                <th><?php _e('bom_import_col_product'); ?></th>
                <th><?php _e('bom_import_col_label'); ?></th>
                <th><?php _e('bom_import_col_qty'); ?></th>
                <?php if ($bbiHasRate): ?><th><?php _e('bom_import_col_rate'); ?></th><?php endif; ?>
                <th><?php _e('bom_import_col_remarks'); ?></th>
              </tr>
            </thead>
          </table>
          <div class="small text-muted"><?php _e('bom_import_col_help'); ?></div>
        </div>

        <a href="<?php echo BASE_URL; ?>api/bom-boq-import.php?action=template&doc=<?php echo $bbiDoc; ?>"
           class="btn btn-sm btn-outline-secondary mb-3">
            <i class="bi bi-download"></i> <?php _e('bom_import_download_template'); ?>
        </a>

        <div class="mb-3">
          <label class="form-label"><?php _e('bom_import_file_label'); ?></label>
          <input type="file" class="form-control" id="bbiFile" accept=".xlsx,.csv">
        </div>

        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="bbiReplace">
          <label class="form-check-label" for="bbiReplace">
            <?php _e('bom_import_replace'); ?>
            <span class="d-block text-muted small"><?php _e('bom_import_replace_help'); ?></span>
          </label>
        </div>

        <div id="bbiResult"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('close'); ?></button>
        <button type="button" class="btn btn-primary" id="bbiRunBtn">
            <i class="bi bi-upload"></i> <?php _e('bom_import_run'); ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    const BBI = {
        url:       '<?php echo BASE_URL; ?>api/bom-boq-import.php',
        doc:       '<?php echo $bbiDoc; ?>',
        container: '<?php echo $bbiContainer; ?>',
        itemClass: '<?php echo $bbiItemClass; ?>',
        t: {
            choose:   '<?php echo addslashes(__('bom_import_choose_file')); ?>',
            reading:  '<?php echo addslashes(__('bom_import_reading')); ?>',
            run:      '<?php echo addslashes(__('bom_import_run')); ?>',
            summary:  '<?php echo addslashes(__('bom_import_result_summary')); ?>',
            skipped:  '<?php echo addslashes(__('bom_import_skipped_title')); ?>',
            addAnyway:'<?php echo addslashes(__('bom_import_add_anyway')); ?>',
            nothing:  '<?php echo addslashes(__('bom_import_nothing')); ?>',
            added:    '<?php echo addslashes(__('bom_import_added')); ?>',
            failed:   '<?php echo addslashes(__('bom_import_failed')); ?>',
            row:      '<?php echo addslashes(__('bom_import_row')); ?>'
        }
    };

    // Simple %d / %s / %1$d style substitution — the lang strings are authored
    // for sprintf() on the PHP side and reused verbatim here.
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
     * clearing innerHTML alone leaves select2's own detached DOM and data
     * behind, which is why the page's removeItem() destroys it too.
     */
    function clearRows() {
        const c = document.getElementById(BBI.container);
        if (!c) return;
        c.querySelectorAll('.' + BBI.itemClass).forEach(block => {
            const $sel = window.jQuery ? jQuery(block).find('.product-select') : null;
            if ($sel && $sel.length && $sel.data('select2')) $sel.select2('destroy');
        });
        c.innerHTML = '';
    }

    function applyRows(rows) {
        if (document.getElementById('bbiReplace').checked) {
            clearRows();
        } else if (typeof pruneBlankItems === 'function') {
            // A new form opens with one empty line. Left in place above the
            // imported ones, its required product box silently blocked Save.
            pruneBlankItems(true);
        }
        rows.forEach(r => addItem(r));

        // The form expects at least one block to exist.
        const c = document.getElementById(BBI.container);
        if (c && !c.querySelectorAll('.' + BBI.itemClass).length) addItem();

        if (typeof recalcGrandTotal === 'function') recalcGrandTotal();
    }

    function renderResult(data) {
        const box = document.getElementById('bbiResult');
        const rows = data.rows || [];
        const skipped = data.skipped || [];
        const st = data.stats || {};
        let html = '';

        if (!rows.length) {
            html += '<div class="alert alert-warning mb-2">' + esc(BBI.t.nothing) + '</div>';
        } else {
            // used_rows, NOT sheet_rows: the template now carries the whole
            // product catalogue, so "from 3945 sheet rows" would be noise.
            // Older cached responses have no used_rows — fall back.
            const filled = (st.used_rows !== undefined) ? st.used_rows : st.sheet_rows;
            html += '<div class="alert alert-info mb-2">' +
                    esc(fill(BBI.t.summary, [st.lines, filled])) + '</div>';
        }

        if (skipped.length) {
            html += '<div class="alert alert-warning mb-2"><strong>' +
                    esc(fill(BBI.t.skipped, [skipped.length])) + '</strong>' +
                    '<div class="table-responsive mt-2" style="max-height:240px;overflow:auto;">' +
                    '<table class="table table-sm table-bordered small mb-0"><tbody>';
            skipped.forEach(s => {
                html += '<tr><td class="text-nowrap">' + esc(fill(BBI.t.row, [s.row])) + '</td>' +
                        '<td>' + esc(s.value) + '</td>' +
                        '<td>' + esc(s.reason) + '</td></tr>';
            });
            html += '</tbody></table></div></div>';
        }

        if (rows.length && skipped.length) {
            // Something was dropped — make the user look before it lands.
            html += '<button type="button" class="btn btn-warning" id="bbiApplyBtn">' +
                    esc(fill(BBI.t.addAnyway, [rows.length])) + '</button>';
        }
        box.innerHTML = html;

        if (rows.length && !skipped.length) {
            // Clean sheet: nothing to review here, put it straight in the form.
            finish(rows);
            return;
        }
        const applyBtn = document.getElementById('bbiApplyBtn');
        if (applyBtn) applyBtn.addEventListener('click', () => finish(rows));
    }

    function finish(rows) {
        applyRows(rows);
        const modalEl = document.getElementById('bbiModal');
        const inst = bootstrap.Modal.getInstance(modalEl);
        if (inst) inst.hide();
        const note = document.getElementById('bbiFormNote');
        if (note) {
            note.textContent = fill(BBI.t.added, [rows.length]);
            note.classList.remove('d-none');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const openBtn = document.getElementById('bbiOpenBtn');
        const runBtn  = document.getElementById('bbiRunBtn');
        if (!openBtn || !runBtn) return;

        openBtn.addEventListener('click', function () {
            document.getElementById('bbiResult').innerHTML = '';
            new bootstrap.Modal(document.getElementById('bbiModal')).show();
        });

        runBtn.addEventListener('click', function () {
            const fileEl = document.getElementById('bbiFile');
            const box = document.getElementById('bbiResult');
            if (!fileEl.files || !fileEl.files.length) {
                box.innerHTML = '<div class="alert alert-danger mb-0">' + esc(BBI.t.choose) + '</div>';
                return;
            }

            const fd = new FormData();
            fd.append('file', fileEl.files[0]);

            runBtn.disabled = true;
            runBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + esc(BBI.t.reading);
            box.innerHTML = '';

            fetch(BBI.url + '?action=parse&doc=' + BBI.doc, {
                method: 'POST', body: fd, credentials: 'same-origin'
            })
            .then(r => r.json().catch(() => { throw new Error('HTTP ' + r.status); }))
            .then(data => {
                if (!data.success) throw new Error(data.error || 'HTTP error');
                renderResult(data);
            })
            .catch(err => {
                box.innerHTML = '<div class="alert alert-danger mb-0">' +
                                esc(fill(BBI.t.failed, [err.message])) + '</div>';
            })
            .finally(() => {
                runBtn.disabled = false;
                runBtn.innerHTML = '<i class="bi bi-upload"></i> ' + esc(BBI.t.run);
            });
        });
    });
})();
</script>
