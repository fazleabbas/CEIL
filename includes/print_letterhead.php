<?php
/**
 * Print-only company letterhead.
 *
 * For pages that are screen-first — a normal app page with a Print button —
 * but whose printout is handed to a customer or supplier. The block is hidden
 * on screen so the page looks exactly as it did, and only appears on paper.
 *
 * Pages that already own a printed-document layout (purchase_order_view.php,
 * the *_dn.php delivery notes, transfers_view.php) do NOT use this — they call
 * renderPrintBanner() inside their own letterhead instead, so their existing
 * design is preserved. JS-built print windows use getPrintLetterheadHtml() +
 * getPrintLetterheadCss(), since they never load a stylesheet.
 *
 * Usage:
 *     $printLetterheadTitle = __('ledger_report');   // optional title line
 *     require __DIR__ . '/../../includes/print_letterhead.php';
 *
 * Banner if one is uploaded (Masters -> Company Settings), company
 * name/address/phone text otherwise — the banner artwork already carries them.
 */

// The CSS only has to land once even if a page prints several letterheads.
if (empty($GLOBALS['__printLetterheadCssDone'])) {
    $GLOBALS['__printLetterheadCssDone'] = true;
    ?>
    <style>
    .app-print-letterhead { display: none; }
    @media print {
        .app-print-letterhead {
            display: block !important;
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .app-print-letterhead .app-lh-name { font-size: 20px; font-weight: bold; letter-spacing: .3px; }
        .app-print-letterhead .app-lh-line { font-size: 11px; color: #333; margin-top: 2px; }
        .app-print-letterhead .app-lh-title {
            font-size: 14px; font-weight: bold; letter-spacing: 2px;
            text-transform: uppercase; margin-top: 8px;
        }
    }
    </style>
    <?php
}

$__lhName    = (string)getSetting('company_name', '');
$__lhAddress = (string)getSetting('company_address', '');
$__lhPhone   = (string)getSetting('company_phone', '');
$__lhTitle   = isset($printLetterheadTitle) ? (string)$printLetterheadTitle : '';
?>
<div class="app-print-letterhead">
    <?php if (!renderPrintBanner()): ?>
        <?php if ($__lhName !== ''): ?>
        <div class="app-lh-name"><?php echo htmlspecialchars($__lhName); ?></div>
        <?php endif; ?>
        <?php if ($__lhAddress !== ''): ?>
        <div class="app-lh-line"><?php echo htmlspecialchars($__lhAddress); ?></div>
        <?php endif; ?>
        <?php if ($__lhPhone !== ''): ?>
        <div class="app-lh-line"><?php _e('phone'); ?>: <?php echo htmlspecialchars($__lhPhone); ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($__lhTitle !== ''): ?>
    <div class="app-lh-title"><?php echo htmlspecialchars($__lhTitle); ?></div>
    <?php endif; ?>
</div>
<?php
// Don't leak the title into a second include on the same page.
unset($printLetterheadTitle);
