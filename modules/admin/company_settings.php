<?php
/**
 * Company Settings — Admin-only page.
 *
 * Edits the branding keys in the `settings` table that outward-facing printed
 * documents use for their letterhead: company_name / company_address /
 * company_phone, plus company_banner (an uploaded letterhead image).
 *
 * The name/address/phone keys were read by the voucher printables long before
 * this page existed but had no UI anywhere — they could only be changed with
 * direct SQL. They stay as the FALLBACK letterhead: when a banner is uploaded
 * it replaces those text lines on every printout, because the artwork already
 * carries them.
 *
 * Unlike smtp_settings.php (fetch + JSON to a sync API) this is a plain
 * POST-to-self form with CSRF and enctype=multipart/form-data — the same shape
 * as the signature upload in modules/masters/users_form.php, because a file
 * upload is involved.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('company_settings');

// Admin-only gate (matches smtp_settings.php).
if (getUserData('role_name') !== 'Admin') {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

/**
 * Turn an uploaded banner image into the base64 data URL stored in
 * settings.company_banner (see migration 0042).
 *
 * Mirrors processSignatureUpload() in includes/functions.php: trust the
 * bytes not the extension, downscale with GD, re-encode. The one difference is
 * the output format — a signature is a small line drawing that stays tiny as a
 * PNG, whereas a full-colour letterhead does not, so anything without
 * transparency is written as JPEG. A 1.2 MB banner lands around 100 KB that
 * way instead of ~400 KB, and this string is embedded in every printout.
 *
 * @return array [dataUrl|null, error|null] — both null means "no file sent".
 */
function companyBanner_processUpload($file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, __('banner_upload_failed') . ' (error code ' . (int)$file['error'] . ')'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, __('banner_upload_failed')];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return [null, __('banner_too_large')];
    }

    // Trust the bytes, not the extension.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return [null, __('banner_invalid_type')];
    }

    $maxWidth = 1200;   // ~185 dpi across a printed letterhead; keeps the row small
    $dataUrl  = null;

    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);

            // Resize first, preserving any alpha, so the transparency test below
            // runs over the smaller image.
            if ($w > $maxWidth) {
                $newH = max(1, (int)round($h * ($maxWidth / $w)));
                $dst  = imagecreatetruecolor($maxWidth, $newH);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $maxWidth, $newH, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newH, $w, $h);
                imagedestroy($src);
                $src = $dst;
            } else {
                imagealphablending($src, false);
                imagesavealpha($src, true);
            }

            // PNG only pays for itself when the image ACTUALLY has transparency
            // — re-encoding a cut-out as JPEG would fill it with a solid box.
            // Testing the real pixels rather than just "the file is a PNG"
            // matters a lot here: a full-colour letterhead saved as an opaque
            // PNG is ~6x bigger than the same artwork as JPEG, and this string
            // is embedded in every document the app prints.
            $keepPng = companyBanner_hasTransparency($src);

            if (!$keepPng) {
                // Paper is white — flatten onto white, not JPEG's default black.
                $flat = imagecreatetruecolor(imagesx($src), imagesy($src));
                imagefilledrectangle($flat, 0, 0, imagesx($src), imagesy($src), imagecolorallocate($flat, 255, 255, 255));
                imagealphablending($flat, true);
                imagecopy($flat, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                imagedestroy($src);
                $src = $flat;
            }

            ob_start();
            $ok    = $keepPng ? imagepng($src) : imagejpeg($src, null, 85);
            $bytes = ob_get_clean();
            imagedestroy($src);

            if ($ok && $bytes !== '') {
                $mime    = $keepPng ? 'image/png' : 'image/jpeg';
                $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }
        }
    }

    if ($dataUrl === null) {
        // No GD (or it choked) — keep the original bytes as-is.
        $mime    = ($info[2] === IMAGETYPE_PNG) ? 'image/png' : 'image/jpeg';
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
    }

    if (sanitizeImageDataUrl($dataUrl) === null) {
        return [null, __('banner_too_large')];
    }

    return [$dataUrl, null];
}

/**
 * Does this GD image actually contain a non-opaque pixel?
 *
 * Called on the already-downscaled image (max 1200px wide), and returns on the
 * FIRST transparent pixel — so a cut-out logo answers almost immediately and
 * only a fully opaque image pays for the whole scan, once, at upload time.
 */
function companyBanner_hasTransparency($img) {
    $w = imagesx($img);
    $h = imagesy($img);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            // Alpha lives in bits 24-30 of the packed colour; 0 = fully opaque.
            if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) !== 0) {
                return true;
            }
        }
    }

    return false;
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();

    $companyName    = clean($_POST['company_name'] ?? '');
    $companyAddress = clean($_POST['company_address'] ?? '');
    $companyPhone   = clean($_POST['company_phone'] ?? '');
    $removeBanner   = isset($_POST['remove_banner']);
    // Multi-line, stored raw and escaped on output (same as the PO columns
    // these pre-fill — see migration 0048).
    $poPaymentTerms  = cleanText($_POST['po_default_payment_terms'] ?? '');
    $poDeliveryTerms = cleanText($_POST['po_default_delivery_terms'] ?? '');

    if ($companyName === '') {
        $errors[] = __('company_name') . ' ' . __('is_required');
    }

    // Idle logout, in minutes. 0 disables it. Capped at 1440 (24h) so a typo
    // can't set a timeout so large it is indistinguishable from "off" while
    // still looking enabled.
    $sessionTimeoutInput = trim((string)($_POST['session_timeout_minutes'] ?? ''));
    $sessionTimeoutValue = ($sessionTimeoutInput === '') ? 15 : (int)$sessionTimeoutInput;
    if ($sessionTimeoutValue < 0 || $sessionTimeoutValue > 1440) {
        $errors[] = __('session_timeout_invalid');
    }

    // Three-state, same as the signature upload: keep / set / clear.
    $bannerAction = 'keep';
    $bannerValue  = null;

    if ($removeBanner) {
        $bannerAction = 'clear';
    } else {
        list($uploaded, $uploadError) = companyBanner_processUpload($_FILES['company_banner'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        } elseif ($uploaded !== null) {
            $bannerAction = 'set';
            $bannerValue  = $uploaded;
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Upsert — updateSetting() in functions.php is UPDATE-only and would
            // silently no-op for a key that has no row yet.
            $upsert = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            $upsert->execute(['company_name', $companyName]);
            $upsert->execute(['company_address', $companyAddress]);
            $upsert->execute(['company_phone', $companyPhone]);
            $upsert->execute(['session_timeout_minutes', (string)$sessionTimeoutValue]);
            $upsert->execute(['po_default_payment_terms', $poPaymentTerms]);
            $upsert->execute(['po_default_delivery_terms', $poDeliveryTerms]);

            if ($bannerAction === 'set') {
                $upsert->execute(['company_banner', $bannerValue]);
            } elseif ($bannerAction === 'clear') {
                $upsert->execute(['company_banner', null]);
            }

            $conn->commit();

            logAudit('settings', 'update_company', getUserId());
            $success = true;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            // A banner bigger than the column means migration 0042 has not run.
            $errors[] = __('error') . ': ' . $e->getMessage();
        }
    }
}

// Read the current values back with a direct query, NOT getSetting() /
// getCompanyBanner(): both hold a static cache for the life of the request, and
// either may already have been primed before the save above ran — which would
// redisplay the form with the pre-save values right after a successful save.
$company = ['name' => '', 'address' => '', 'phone' => ''];
$currentBanner = null;

// Default 15 minutes when the key has no row yet — same default
// getSessionTimeoutMinutes() applies, so the form always shows what is actually
// in force rather than a blank.
$sessionTimeout = 15;

$poTerms = ['payment' => '', 'delivery' => ''];

$keyMap = ['company_name' => 'name', 'company_address' => 'address', 'company_phone' => 'phone'];
$read = $conn->query("
    SELECT setting_key, setting_value FROM settings
    WHERE setting_key IN ('company_name','company_address','company_phone','company_banner','session_timeout_minutes',
                          'po_default_payment_terms','po_default_delivery_terms')
");
while ($row = $read->fetch(PDO::FETCH_ASSOC)) {
    if ($row['setting_key'] === 'company_banner') {
        $currentBanner = sanitizeImageDataUrl((string)$row['setting_value']);
    } elseif ($row['setting_key'] === 'session_timeout_minutes') {
        if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
            $sessionTimeout = max(0, (int)$row['setting_value']);
        }
    } elseif ($row['setting_key'] === 'po_default_payment_terms') {
        $poTerms['payment'] = (string)$row['setting_value'];
    } elseif ($row['setting_key'] === 'po_default_delivery_terms') {
        $poTerms['delivery'] = (string)$row['setting_value'];
    } elseif (isset($keyMap[$row['setting_key']])) {
        $company[$keyMap[$row['setting_key']]] = (string)$row['setting_value'];
    }
}

// A just-submitted value that failed validation must survive the redisplay.
if (!empty($errors)) {
    $company['name']    = $_POST['company_name'] ?? $company['name'];
    $company['address'] = $_POST['company_address'] ?? $company['address'];
    $company['phone']   = $_POST['company_phone'] ?? $company['phone'];
    $poTerms['payment']  = $_POST['po_default_payment_terms'] ?? $poTerms['payment'];
    $poTerms['delivery'] = $_POST['po_default_delivery_terms'] ?? $poTerms['delivery'];
    if (isset($_POST['session_timeout_minutes'])) {
        $sessionTimeout = (int)$_POST['session_timeout_minutes'];
    }
}

require_once '../../includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-8">
        <h2><i class="bi bi-building-gear"></i> <?php _e('company_settings'); ?></h2>
        <p class="text-muted mb-0"><?php _e('company_settings_desc'); ?></p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> <?php _e('settings_saved'); ?>
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

<form method="POST" action="" enctype="multipart/form-data">
    <?php echo csrfField(); ?>

    <div class="row">
        <div class="col-lg-8">

            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="bi bi-image"></i> <?php _e('print_banner'); ?></strong>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        <?php _e('print_banner_help'); ?>
                    </p>

                    <div class="mb-3">
                        <label class="form-label"><?php _e('current_banner'); ?></label>
                        <div class="border rounded p-3 text-center bg-light">
                            <?php if ($currentBanner): ?>
                                <img src="<?php echo htmlspecialchars($currentBanner); ?>"
                                     alt="<?php _e('print_banner'); ?>"
                                     id="bannerPreview"
                                     style="max-width: 100%; max-height: 160px;">
                            <?php else: ?>
                                <img src="" alt="" id="bannerPreview" style="max-width: 100%; max-height: 160px; display: none;">
                                <span class="text-muted" id="bannerEmpty"><i class="bi bi-image"></i> <?php _e('no_banner_uploaded'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="bannerInput"><?php _e('upload_banner'); ?></label>
                        <input type="file" class="form-control" id="bannerInput" name="company_banner"
                               accept="image/png,image/jpeg">
                        <div class="form-text"><?php _e('upload_banner_hint'); ?></div>
                    </div>

                    <?php if ($currentBanner): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="removeBanner" name="remove_banner">
                        <label class="form-check-label" for="removeBanner">
                            <?php _e('remove_banner'); ?>
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="bi bi-card-text"></i> <?php _e('company_details'); ?></strong>
                </div>
                <div class="card-body">
                    <p class="text-muted small"><?php _e('company_details_help'); ?></p>

                    <div class="mb-3">
                        <label class="form-label"><?php _e('company_name'); ?> *</label>
                        <input type="text" class="form-control" name="company_name" required
                               value="<?php echo htmlspecialchars($company['name']); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php _e('company_address'); ?></label>
                        <input type="text" class="form-control" name="company_address"
                               value="<?php echo htmlspecialchars($company['address']); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php _e('company_phone'); ?></label>
                        <input type="text" class="form-control" name="company_phone"
                               value="<?php echo htmlspecialchars($company['phone']); ?>">
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="bi bi-clock-history"></i> <?php _e('session_timeout'); ?></strong>
                </div>
                <div class="card-body">
                    <p class="text-muted small"><?php _e('session_timeout_help'); ?></p>

                    <div class="mb-2" style="max-width: 260px;">
                        <label class="form-label"><?php _e('session_timeout_minutes'); ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="session_timeout_minutes"
                                   min="0" max="1440" step="1"
                                   value="<?php echo (int)$sessionTimeout; ?>">
                            <span class="input-group-text"><?php _e('minutes'); ?></span>
                        </div>
                    </div>
                    <div class="form-text"><?php _e('session_timeout_zero_hint'); ?></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <strong><i class="bi bi-file-earmark-text"></i> <?php _e('po_default_terms'); ?></strong>
                </div>
                <div class="card-body">
                    <p class="text-muted small"><?php _e('po_default_terms_help'); ?></p>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label"><?php _e('payment_terms'); ?></label>
                            <textarea class="form-control" name="po_default_payment_terms" rows="3"><?php echo htmlspecialchars($poTerms['payment']); ?></textarea>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label"><?php _e('delivery_terms'); ?></label>
                            <textarea class="form-control" name="po_default_delivery_terms" rows="3"><?php echo htmlspecialchars($poTerms['delivery']); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> <?php _e('save'); ?>
                </button>
                <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary"><?php _e('cancel'); ?></a>
            </div>

        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><strong><i class="bi bi-printer"></i> <?php _e('where_banner_appears'); ?></strong></div>
                <div class="card-body">
                    <ul class="small mb-0 ps-3">
                        <li><?php _e('purchase_order'); ?></li>
                        <li><?php _e('delivery_note'); ?> (<?php _e('stock_transfer'); ?>)</li>
                        <li><?php _e('delivery_note'); ?> (<?php _e('requisition_voucher'); ?>)</li>
                        <li><?php _e('delivery_note'); ?> (<?php _e('consumption_voucher'); ?>)</li>
                        <li><?php _e('sale'); ?> / <?php _e('receipt'); ?></li>
                        <li><?php _e('customers'); ?> &amp; <?php _e('suppliers'); ?> — <?php _e('balance_breakup'); ?></li>
                        <li><?php _e('ledger_report'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Live preview so the admin sees the banner before saving.
document.getElementById('bannerInput').addEventListener('change', function () {
    const file = this.files && this.files[0];
    if (!file) return;

    const preview = document.getElementById('bannerPreview');
    const empty   = document.getElementById('bannerEmpty');
    const reader  = new FileReader();

    reader.onload = function (e) {
        preview.src = e.target.result;
        preview.style.display = '';
        if (empty) empty.style.display = 'none';
    };
    reader.readAsDataURL(file);

    // Uploading and removing at the same time is contradictory — picking a file
    // wins, since that is the more deliberate action.
    const remove = document.getElementById('removeBanner');
    if (remove) remove.checked = false;
});

const removeBox = document.getElementById('removeBanner');
if (removeBox) {
    removeBox.addEventListener('change', function () {
        if (this.checked) document.getElementById('bannerInput').value = '';
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
