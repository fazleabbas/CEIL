<?php
/**
 * My Signature — self-service page for the logged-in user.
 *
 * Until now `users.signature` (migration 0041) could only be set by someone
 * holding the `users` module edit permission, i.e. an admin, via
 * modules/masters/users_form.php. A normal user therefore had to draw their
 * signature freehand on every single document they signed — painful without a
 * touchscreen.
 *
 * This page lets anyone set their OWN signature once. After that
 * getUserSignature() applies it automatically on every signing screen and no
 * pad is rendered at all — that behaviour already existed and is untouched.
 *
 * SCOPE: every write is `WHERE id = getUserId()`. There is no id parameter, so
 * this page can never reach another user's record and needs no permission gate
 * beyond being logged in (same shape as change_password.php).
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// After the requires — __() lives in functions.php.
$pageTitle = __('my_signature');

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $mode = $_POST['sig_mode'] ?? '';

    if ($mode === 'clear') {
        if (saveUserSignature($conn, getUserId(), null)) {
            logAudit('users', 'clear_own_signature', getUserId());
            $success = true;
        } else {
            $errors[] = __('signature_not_supported');
        }
    } else {
        $signature = null;

        // An uploaded file wins over the pad — it is the more deliberate action,
        // and the pad may still hold a preview of whatever was just uploaded.
        list($uploaded, $uploadError) = processSignatureUpload($_FILES['signature_file'] ?? null);

        if ($uploadError !== null) {
            $errors[] = $uploadError;
        } elseif ($uploaded !== null) {
            $signature = $uploaded;
        } else {
            // Nothing uploaded — fall back to whatever was drawn on the pad.
            $drawn = sanitizeSignatureValue($_POST['signature_data'] ?? null);
            if ($drawn !== null) {
                $signature = $drawn;
            }
        }

        if ($signature === null && empty($errors)) {
            $errors[] = __('signature_nothing_provided');
        }

        if (empty($errors)) {
            if (saveUserSignature($conn, getUserId(), $signature)) {
                logAudit('users', 'update_own_signature', getUserId());
                $success = true;
            } else {
                $errors[] = __('signature_not_supported');
            }
        }
    }
}

// Read back AFTER the save. getUserSignature() caches per request and may have
// been primed by header.php, so query directly.
$currentSig = null;
try {
    $sigStmt = $conn->prepare("SELECT signature FROM users WHERE id = ?");
    $sigStmt->execute([getUserId()]);
    $currentSig = sanitizeSignatureValue((string)$sigStmt->fetchColumn());
} catch (PDOException $e) {
    $currentSig = null;   // pre-0041 tenant
}

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-7">

        <div class="row mb-3">
            <div class="col">
                <h2><i class="bi bi-pen"></i> <?php _e('my_signature'); ?></h2>
                <p class="text-muted mb-0"><?php _e('my_signature_desc'); ?></p>
            </div>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?php _e('signature_saved'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header"><strong><?php _e('current_signature'); ?></strong></div>
            <div class="card-body text-center bg-light">
                <?php if ($currentSig): ?>
                    <img src="<?php echo htmlspecialchars($currentSig); ?>"
                         alt="<?php _e('signature'); ?>" style="max-height: 110px; max-width: 100%;">
                <?php else: ?>
                    <span class="text-muted fst-italic d-block py-4"><?php _e('no_signature_on_file'); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="mySignatureForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="sig_mode" value="set">
            <input type="hidden" name="signature_data" id="signatureData">

            <div class="card mb-3">
                <div class="card-header"><strong><i class="bi bi-upload"></i> <?php _e('upload_signature_image'); ?></strong></div>
                <div class="card-body">
                    <p class="text-muted small"><?php _e('upload_signature_hint'); ?></p>
                    <input type="file" class="form-control" id="signatureFile" name="signature_file"
                           accept="image/png,image/jpeg">
                    <div class="text-center mt-3" id="uploadPreviewWrap" style="display:none;">
                        <img id="uploadPreview" alt="" style="max-height: 110px; max-width: 100%;">
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong><i class="bi bi-pencil"></i> <?php _e('or_draw_signature'); ?></strong></div>
                <div class="card-body">
                    <div style="border: 1px dashed #adb5bd; border-radius: .375rem; position: relative; height: 150px;">
                        <canvas id="mySignatureCanvas" style="width: 100%; height: 150px; touch-action: none;"></canvas>
                        <span id="mySignatureCanvasPlaceholder"
                              style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:#adb5bd; pointer-events:none;">
                            <?php _e('sign_here'); ?>
                        </span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="clearMySig()">
                        <i class="bi bi-eraser"></i> <?php _e('clear'); ?>
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> <?php _e('save'); ?>
                </button>
                <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary"><?php _e('cancel'); ?></a>

                <?php if ($currentSig): ?>
                <button type="submit" class="btn btn-outline-danger float-end"
                        onclick="return confirmClearSignature(this);">
                    <i class="bi bi-trash"></i> <?php _e('remove_signature'); ?>
                </button>
                <?php endif; ?>
            </div>
        </form>

    </div>
</div>

<script src="<?php echo BASE_URL; ?>assets/js/signature_pad.js"></script>
<script>
let mySigPad = null;

document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('mySignatureCanvas');
    if (canvas && window.SignaturePad) {
        mySigPad = new SignaturePad(canvas, { penColor: '#000', lineWidth: 2 });
        canvas.addEventListener('pointerdown', function () {
            const ph = document.getElementById('mySignatureCanvasPlaceholder');
            if (ph) ph.style.display = 'none';
        });
    }
});

function clearMySig() {
    if (mySigPad) mySigPad.clear();
    const ph = document.getElementById('mySignatureCanvasPlaceholder');
    if (ph) ph.style.display = '';
    document.getElementById('signatureData').value = '';
}

// Preview the chosen file, and mirror it into the pad so the page shows one
// consistent "this is what will be saved". The FILE is what actually gets
// stored (the server re-encodes it at 600px); the pad copy is only a preview.
document.getElementById('signatureFile').addEventListener('change', function () {
    const file = this.files && this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('uploadPreview').src = e.target.result;
        document.getElementById('uploadPreviewWrap').style.display = '';
        if (mySigPad) {
            mySigPad.loadImageFit(e.target.result);
            const ph = document.getElementById('mySignatureCanvasPlaceholder');
            if (ph) ph.style.display = 'none';
        }
    };
    reader.readAsDataURL(file);
});

// Serialise the pad only when nothing was uploaded — the server prefers the
// file, and sending a 150px canvas copy of it would just waste the payload.
document.getElementById('mySignatureForm').addEventListener('submit', function () {
    const fileInput = document.getElementById('signatureFile');
    const hasFile = fileInput.files && fileInput.files.length > 0;

    if (!hasFile && mySigPad && !mySigPad.isEmpty()) {
        document.getElementById('signatureData').value = mySigPad.toDataURL();
    }
});

function confirmClearSignature(btn) {
    if (!confirm(<?php echo json_encode(__('confirm_remove_signature')); ?>)) {
        return false;
    }
    // Turn this submit into the clear action.
    btn.form.querySelector('input[name="sig_mode"]').value = 'clear';
    return true;
}
</script>

<?php require_once '../../includes/footer.php'; ?>
