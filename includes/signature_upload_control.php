<?php
/**
 * "Upload image" control for a signature pad.
 *
 * Not everyone has a touchscreen, and drawing a signature with a mouse is
 * miserable. This lets the signer pick a PNG/JPG of their signature instead;
 * it is drawn INTO the existing canvas, so every page's existing
 * `pad.toDataURL()` submit code, every API sanitiser and every *_signature
 * column keeps working untouched — nothing downstream knows the difference.
 *
 * Include it directly after a <canvas>, inside the `else` branch of the
 * `if ($myMasterSig): <img> ... else: <canvas> ... endif` block every signing
 * screen already has — i.e. it only ever appears for signers who have NO
 * signature on file, which is exactly who needs it.
 *
 * Usage:
 *     $sigUploadCanvasId = 'approveSignatureCanvas';
 *     require __DIR__ . '/../../includes/signature_upload_control.php';
 *
 * Optional, set before the require:
 *     $sigUploadPlaceholderId = 'approveSignaturePlaceholder';  // "Sign here" hint to hide
 *     $sigUploadOfferSave     = false;                          // hide the save checkbox
 *
 * The checkbox posts to api/my-signature-sync.php so the signer only ever has
 * to do this once — after that getUserSignature() applies it automatically and
 * no pad is rendered at all.
 */

if (empty($sigUploadCanvasId)) {
    return;
}

$sigUploadId     = preg_replace('/[^A-Za-z0-9_]/', '', $sigUploadCanvasId);
$sigUploadOffer  = !isset($sigUploadOfferSave) || $sigUploadOfferSave;

// Emitted once per request no matter how many pads are on the page
// (transfers.php has two).
if (empty($GLOBALS['__sigUploadHelperDone'])) {
    $GLOBALS['__sigUploadHelperDone'] = true;
    ?>
    <script>
    /**
     * Wire an "Upload image" button to the SignaturePad bound to a canvas.
     * The pad is looked up lazily on click via SignaturePad.forCanvas(), never
     * cached — screens like the requisition approval modal re-create their pad
     * every time the modal opens, so a cached reference would go stale.
     */
    function initSignatureUpload(canvasId, fileInputId, statusId, saveBoxId, placeholderId) {
        var input = document.getElementById(fileInputId);
        if (!input) return;

        input.addEventListener('change', function () {
            var file = this.files && this.files[0];
            var status = document.getElementById(statusId);
            if (!file) return;

            function fail(msg) {
                if (status) {
                    status.className = 'text-danger small mt-1';
                    status.textContent = msg;
                }
                input.value = '';
            }

            // Same rules the server enforces in processSignatureUpload(), so a
            // bad file is rejected here instead of after a round trip.
            if (file.type !== 'image/png' && file.type !== 'image/jpeg') {
                return fail(<?php echo json_encode(__('signature_invalid_type')); ?>);
            }
            if (file.size > 1024 * 1024) {
                return fail(<?php echo json_encode(__('signature_too_large')); ?>);
            }

            var pad = window.SignaturePad && SignaturePad.forCanvas(canvasId);
            if (!pad) {
                return fail(<?php echo json_encode(__('signature_pad_not_ready')); ?>);
            }

            var reader = new FileReader();
            reader.onload = function (e) {
                pad.loadImageFit(e.target.result, function (ok) {
                    if (!ok) {
                        return fail(<?php echo json_encode(__('signature_invalid_type')); ?>);
                    }
                    if (status) {
                        status.className = 'text-success small mt-1';
                        status.textContent = <?php echo json_encode(__('signature_loaded')); ?>;
                    }
                    // Hide the "Sign here" hint the pages draw over the canvas.
                    var ph = placeholderId && document.getElementById(placeholderId);
                    if (ph) ph.style.display = 'none';

                    // Persist to the user's master record so this is a one-off.
                    // Best effort: the document being signed must never fail
                    // because the profile copy could not be written.
                    var box = saveBoxId && document.getElementById(saveBoxId);
                    if (box && box.checked) {
                        fetch(BASE_URL + 'api/my-signature-sync.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'save',
                                csrf_token: window.__SIG_CSRF || '',
                                signature: pad.toDataURL()
                            })
                        }).then(function (r) { return r.json(); }).then(function (res) {
                            if (status && res && res.success) {
                                status.textContent = <?php echo json_encode(__('signature_loaded_and_saved')); ?>;
                            }
                        }).catch(function () { /* signing continues regardless */ });
                    }
                });
            };
            reader.onerror = function () { fail(<?php echo json_encode(__('signature_invalid_type')); ?>); };
            reader.readAsDataURL(file);
        });
    }
    window.__SIG_CSRF = <?php echo json_encode(generateCSRF()); ?>;
    </script>
    <?php
}
?>
<div class="mt-2">
    <input type="file" id="<?php echo $sigUploadId; ?>_file" accept="image/png,image/jpeg" class="d-none">
    <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="document.getElementById('<?php echo $sigUploadId; ?>_file').click()">
        <i class="bi bi-upload"></i> <?php _e('upload_signature_image'); ?>
    </button>
    <?php if ($sigUploadOffer): ?>
    <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" value="1" checked
               id="<?php echo $sigUploadId; ?>_save">
        <label class="form-check-label small text-muted" for="<?php echo $sigUploadId; ?>_save">
            <?php _e('save_signature_to_profile'); ?>
        </label>
    </div>
    <?php endif; ?>
    <div id="<?php echo $sigUploadId; ?>_status" class="small mt-1"></div>
</div>
<script>
initSignatureUpload(
    <?php echo json_encode($sigUploadCanvasId); ?>,
    <?php echo json_encode($sigUploadId . '_file'); ?>,
    <?php echo json_encode($sigUploadId . '_status'); ?>,
    <?php echo $sigUploadOffer ? json_encode($sigUploadId . '_save') : 'null'; ?>,
    <?php echo !empty($sigUploadPlaceholderId) ? json_encode($sigUploadPlaceholderId) : 'null'; ?>
);
</script>
<?php
// Don't leak settings into the next include on the same page.
unset($sigUploadCanvasId, $sigUploadOfferSave, $sigUploadPlaceholderId);
