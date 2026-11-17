<?php
/**
 * View/Print Stock Transfer - Delivery Note
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if (!hasPermission('stock_transfer', 'view')) {
    setAlert(__('no_permission'), 'error');
    redirect('index.php');
}

$transferId = $_GET['id'] ?? 0;

if (empty($transferId)) {
    setAlert(__('transfer_not_found'), 'error');
    redirect('modules/transactions/transfers_management.php');
}

// Get transfer details
$stmt = $conn->prepare("
    SELECT t.*,
           fl.name as from_location_name, fl.address as from_address,
           tl.name as to_location_name, tl.address as to_address,
           cc.name as cost_centre_name, cc.code as cost_centre_code,
           cu.name as created_by_name,
           du.name as dispatched_by_name,
           ru.name as received_by_name,
           rv.voucher_number as requisition_number
    FROM stock_transfers t
    LEFT JOIN locations fl ON t.from_location_id = fl.id
    LEFT JOIN locations tl ON t.to_location_id = tl.id
    LEFT JOIN cost_centres cc ON t.cost_centre_id = cc.id
    LEFT JOIN users cu ON t.created_by = cu.id
    LEFT JOIN users du ON t.dispatched_by = du.id
    LEFT JOIN users ru ON t.received_by = ru.id
    LEFT JOIN requisition_vouchers rv ON t.requisition_id = rv.id
    WHERE t.id = ?
");
$stmt->execute([$transferId]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    setAlert(__('transfer_not_found'), 'error');
    redirect('modules/transactions/transfers_management.php');
}

// Get transfer items
$itemsStmt = $conn->prepare("
    SELECT td.*, p.name as product_name, p.unit, p.barcode
    FROM stock_transfer_details td
    INNER JOIN products p ON td.product_id = p.id
    WHERE td.transfer_id = ?
    ORDER BY td.id
");
$itemsStmt->execute([$transferId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// If this is a reverse transfer, fetch the original's number for a link.
// Guarded so pre-migration-0039 tenants (no column) are unaffected.
$reverseOfNumber = null;
if (!empty($transfer['reverse_of_transfer_id'])) {
    try {
        $roStmt = $conn->prepare("SELECT transfer_number FROM stock_transfers WHERE id = ?");
        $roStmt->execute([$transfer['reverse_of_transfer_id']]);
        $reverseOfNumber = $roStmt->fetchColumn();
    } catch (PDOException $e) {
        $reverseOfNumber = null;
    }
}

// Get company info
$companyName = getSetting('company_name', 'Company Name');
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');

$pageTitle = __('delivery_note') . ' - ' . $transfer['transfer_number'];
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <style>

        .print-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: white;
        }

        .dn-header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .dn-header h1 {
            font-size: 24px;
            margin: 0 0 5px 0;
        }

        .dn-header h2 {
            font-size: 18px;
            color: #555;
            margin: 0;
        }

        .dn-title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            background: #f5f5f5;
            padding: 10px;
            margin: 20px 0;
            border: 1px solid #ddd;
        }

        .dn-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .dn-info-box {
            width: 48%;
            border: 1px solid #ddd;
            padding: 10px;
        }

        .dn-info-box h3 {
            margin: 0 0 10px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
            color: #555;
        }

        .dn-info-box p {
            margin: 5px 0;
            font-size: 13px;
        }

        .dn-info-box strong {
            display: inline-block;
            width: 80px;
        }

        .dn-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .dn-meta-item {
            padding: 5px 10px;
            background: #f9f9f9;
            border: 1px solid #ddd;
        }

        .dn-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .dn-table th, .dn-table td {
            border: 1px solid #333;
            padding: 8px;
            text-align: left;
        }

        .dn-table th {
            background: #f5f5f5;
            font-weight: bold;
        }

        .dn-table td.number {
            text-align: right;
        }

        .dn-table td.center {
            text-align: center;
        }

        .dn-totals {
            width: 300px;
            margin-left: auto;
            margin-bottom: 20px;
        }

        .dn-totals table {
            width: 100%;
            border-collapse: collapse;
        }

        .dn-totals td {
            padding: 5px 10px;
            border: 1px solid #ddd;
        }

        .dn-totals td:last-child {
            text-align: right;
            font-weight: bold;
        }

        .dn-remarks {
            border: 1px solid #ddd;
            padding: 10px;
            margin-bottom: 20px;
            min-height: 50px;
        }

        .dn-remarks h4 {
            margin: 0 0 5px 0;
            font-size: 13px;
        }

        .dn-signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }

        .dn-signature-box {
            width: 30%;
            text-align: center;
        }

        .dn-signature-box .line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-draft { background: #ffc107; color: #000; }
        .status-in_transit { background: #17a2b8; color: #fff; }
        .status-received { background: #28a745; color: #fff; }
        .status-cancelled { background: #dc3545; color: #fff; }

        .btn-toolbar {
            margin-bottom: 20px;
            text-align: center;
        }

        .btn-toolbar .btn {
            margin: 0 5px;
        }

        .shortage-row {
            background: #fff3cd;
        }

        /* Toolbar Buttons */
        .btn-toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: #0d6efd;
            color: #fff;
        }
        .btn-primary:hover {
            background: #0b5ed7;
        }

        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }
        .btn-secondary:hover {
            background: #5c636a;
        }

        .btn-warning {
            background: #ffc107;
            color: #000;
        }
        .btn-warning:hover {
            background: #ffca2c;
        }
        /* ------------------------------------------------------------------
           PRINT: keep an ordinary delivery note on ONE page.

           Everything here is inside @media print — the screen layout is
           deliberately untouched. The old block only hid the toolbar, so a
           one-line note still ran to a second page that carried nothing but
           the repeated table footer and the signatures. The wasted height came
           from three places: the letterhead's 55mm cap, ~25mm of page margin
           (15mm of body padding stacked ON TOP of the browser's own default),
           and a 20px gap under every single block.
           ------------------------------------------------------------------ */
        @media print {
            .no-print { display: none !important; }

            /* One margin, not two: @page replaces the browser default so the
               body padding is no longer added on top of it. */
            @page { size: A4 portrait; margin: 10mm; }
            body { margin: 0; padding: 0; font-size: 12px; }

            .print-container { max-width: 100%; padding: 0; }

            /* The letterhead is the single tallest block on the page. */
            .print-container .app-letterhead-banner { max-height: 28mm; }

            .dn-header { padding-bottom: 6px; margin-bottom: 8px; }
            .dn-header h1 { font-size: 18px; }
            .dn-header p { margin: 2px 0; font-size: 12px; }

            .dn-title { font-size: 15px; padding: 5px; margin: 8px 0; }

            .dn-meta { margin-bottom: 8px; font-size: 11px; }
            /* The cost-centre / requisition / reverse-of rows carry an inline
               margin-top, which only !important can shrink. */
            .dn-meta[style] { margin-top: 6px !important; }
            .dn-meta-item { padding: 3px 6px; }

            .dn-info { margin-bottom: 8px; }
            .dn-info-box { padding: 6px; }
            .dn-info-box h3 { margin: 0 0 4px 0; padding-bottom: 3px; font-size: 12px; }
            .dn-info-box p { margin: 1px 0; font-size: 11px; }
            /* 80px wrapped "Vehicle Number:" onto two lines. */
            .dn-info-box strong { width: 110px; }

            .dn-table { margin-bottom: 8px; font-size: 11px; line-height: 1.2; }
            .dn-table th, .dn-table td { padding: 2px 5px; }
            /* Every product cell carries a barcode sub-line, so a few px here
               is worth ~1 extra item on the page. */
            .dn-table td small { font-size: 9px; }
            /* A row splitting across the break is what dragged the repeated
               table footer onto page 2. */
            .dn-table tr { page-break-inside: avoid; }

            .dn-remarks { padding: 6px; margin-bottom: 8px; min-height: 0; }
            .dn-remarks h4 { margin-bottom: 3px; }
            .dn-remarks p { margin: 2px 0; }

            .dn-signatures { margin-top: 12px; page-break-inside: avoid; }
            /* Specificity beats the .dn-signatures .sig-img rule further down
               the file, which would otherwise win on source order. */
            .print-container .dn-signatures .sig-img { max-height: 50px; }

            .dn-footer { margin-top: 10px !important; padding-top: 6px !important; }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- Toolbar -->
        <div class="btn-toolbar no-print">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> <?php _e('print'); ?>
            </button>
            <a href="<?php echo BASE_URL; ?>modules/transactions/transfers_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <?php if ($transfer['status'] === 'draft' && empty($transfer['reverse_of_transfer_id'])): ?>
            <a href="<?php echo BASE_URL; ?>modules/transactions/transfers.php?id=<?php echo $transferId; ?>" class="btn btn-warning">
                <i class="fas fa-edit"></i> <?php _e('edit'); ?>
            </a>
            <?php endif; ?>
            <?php if ($transfer['status'] === 'received' && empty($transfer['reverse_of_transfer_id'])
                      && hasPermission('stock_transfer', 'create') && canAccessLocation($transfer['to_location_id'])): ?>
            <a href="<?php echo BASE_URL; ?>modules/transactions/transfer_reverse.php?from=<?php echo $transferId; ?>" class="btn btn-warning">
                <i class="fas fa-undo"></i> <?php _e('reverse_transfer'); ?>
            </a>
            <?php endif; ?>
        </div>

        <!-- Header -->
        <div class="dn-header">
            <?php /* An uploaded banner (Masters -> Company Settings) already carries the
                    address and phone, so it REPLACES these text lines. With no banner set
                    renderPrintBanner() prints nothing and this header is unchanged. */ ?>
            <?php if (!renderPrintBanner()): ?>
            <h1><?php echo htmlspecialchars($companyName); ?></h1>
            <?php if ($companyAddress): ?>
            <p><?php echo htmlspecialchars($companyAddress); ?></p>
            <?php endif; ?>
            <?php if ($companyPhone): ?>
            <p><?php _e('phone'); ?>: <?php echo htmlspecialchars($companyPhone); ?></p>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Title -->
        <div class="dn-title">
            <?php _e('delivery_note'); ?> / <?php _e('stock_transfer'); ?>
            <span class="status-badge status-<?php echo $transfer['status']; ?>">
                <?php _e('status_' . $transfer['status']); ?>
            </span>
        </div>

        <!-- Transfer Info -->
        <div class="dn-meta">
            <div class="dn-meta-item">
                <strong><?php _e('dn_number'); ?>:</strong> <?php echo htmlspecialchars($transfer['transfer_number']); ?>
            </div>
            <div class="dn-meta-item">
                <strong><?php _e('transfer_date'); ?>:</strong> <?php echo formatDate($transfer['transfer_date']); ?>
            </div>
            <div class="dn-meta-item">
                <strong><?php _e('status'); ?>:</strong> <?php _e('status_' . $transfer['status']); ?>
            </div>
        </div>

        <!-- From/To Locations -->
        <div class="dn-info">
            <div class="dn-info-box">
                <h3><?php _e('from_location'); ?></h3>
                <p><strong><?php echo htmlspecialchars($transfer['from_location_name']); ?></strong></p>
                <?php if ($transfer['from_address']): ?>
                <p><?php echo htmlspecialchars($transfer['from_address']); ?></p>
                <?php endif; ?>
            </div>
            <div class="dn-info-box">
                <h3><?php _e('to_location'); ?></h3>
                <p><strong><?php echo htmlspecialchars($transfer['to_location_name']); ?></strong></p>
                <?php if ($transfer['to_address']): ?>
                <p><?php echo htmlspecialchars($transfer['to_address']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cost Centre -->
        <?php if (!empty($transfer['cost_centre_id'])): ?>
        <div class="dn-meta" style="margin-top: 10px;">
            <div class="dn-meta-item">
                <strong><i class="bi bi-building"></i> <?php _e('cost_centre'); ?>:</strong>
                <?php echo htmlspecialchars($transfer['cost_centre_code'] . ' - ' . $transfer['cost_centre_name']); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reverse Of (this document is a reverse transfer) -->
        <?php if (!empty($transfer['reverse_of_transfer_id'])): ?>
        <div class="dn-meta" style="margin-top: 10px;">
            <div class="dn-meta-item" style="background:#e2e3e5;">
                <strong><i class="bi bi-arrow-counterclockwise"></i> <?php _e('reverse_of'); ?>:</strong>
                <a href="<?php echo BASE_URL; ?>modules/transactions/transfers_view.php?id=<?php echo $transfer['reverse_of_transfer_id']; ?>">
                    <?php echo htmlspecialchars($reverseOfNumber ?: ('#' . $transfer['reverse_of_transfer_id'])); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- From Requisition -->
        <?php if (!empty($transfer['requisition_id'])): ?>
        <div class="dn-meta" style="margin-top: 10px;">
            <div class="dn-meta-item">
                <strong><i class="bi bi-clipboard-check"></i> <?php _e('from_requisition'); ?>:</strong>
                <a href="<?php echo BASE_URL; ?>modules/transactions/requisition_voucher_view.php?id=<?php echo $transfer['requisition_id']; ?>">
                    <?php echo htmlspecialchars($transfer['requisition_number']); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Vehicle/Driver Info -->
        <?php if ($transfer['vehicle_number'] || $transfer['driver_name']): ?>
        <div class="dn-info">
            <div class="dn-info-box" style="width: 100%;">
                <h3><?php _e('transport_details'); ?></h3>
                <p><strong><?php _e('vehicle_number'); ?>:</strong> <?php echo htmlspecialchars($transfer['vehicle_number'] ?: '-'); ?></p>
                <p><strong><?php _e('driver_name'); ?>:</strong> <?php echo htmlspecialchars($transfer['driver_name'] ?: '-'); ?></p>
                <p><strong><?php _e('driver_phone'); ?>:</strong> <?php echo htmlspecialchars($transfer['driver_phone'] ?: '-'); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Items Table -->
        <table class="dn-table">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th><?php _e('product'); ?></th>
                    <th style="width: 80px;"><?php _e('unit'); ?></th>
                    <th style="width: 100px;" class="number"><?php _e('quantity'); ?></th>
                    <?php if ($transfer['status'] !== 'draft'): ?>
                    <th style="width: 100px;" class="number"><?php _e('dispatched'); ?></th>
                    <?php endif; ?>
                    <?php if ($transfer['status'] === 'received'): ?>
                    <th style="width: 100px;" class="number"><?php _e('received'); ?></th>
                    <th style="width: 100px;" class="number"><?php _e('shortage'); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $sn = 0;
                $totalDispatched = 0;
                $totalReceived = 0;
                $totalShortage = 0;
                // No rate / amount: this page is the delivery note handed to
                // whoever carries or receives the goods, so it shows quantities only.
                foreach ($items as $item):
                    $sn++;
                    $dispatched = floatval($item['dispatched_quantity']);
                    $received = floatval($item['received_quantity'] ?? 0);
                    $shortage = floatval($item['shortage_quantity'] ?? 0);

                    $totalDispatched += $dispatched;
                    $totalReceived += $received;
                    $totalShortage += $shortage;

                    $rowClass = ($shortage > 0) ? 'shortage-row' : '';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td class="center"><?php echo $sn; ?></td>
                    <td>
                        <?php echo htmlspecialchars($item['product_name']); ?>
                        <?php if ($item['barcode']): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($item['barcode']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="center"><?php echo htmlspecialchars($item['unit']); ?></td>
                    <td class="number"><?php echo formatNumber($dispatched); ?></td>
                    <?php if ($transfer['status'] !== 'draft'): ?>
                    <td class="number"><?php echo formatNumber($dispatched); ?></td>
                    <?php endif; ?>
                    <?php if ($transfer['status'] === 'received'): ?>
                    <td class="number"><?php echo formatNumber($received); ?></td>
                    <td class="number"><?php echo $shortage > 0 ? formatNumber($shortage) : '-'; ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3"><?php _e('total'); ?></th>
                    <th class="number"><?php echo formatNumber($totalDispatched); ?></th>
                    <?php if ($transfer['status'] !== 'draft'): ?>
                    <th class="number"><?php echo formatNumber($totalDispatched); ?></th>
                    <?php endif; ?>
                    <?php if ($transfer['status'] === 'received'): ?>
                    <th class="number"><?php echo formatNumber($totalReceived); ?></th>
                    <th class="number"><?php echo $totalShortage > 0 ? formatNumber($totalShortage) : '-'; ?></th>
                    <?php endif; ?>
                </tr>
            </tfoot>
        </table>

        <!-- Shortage Summary -->
        <?php if ($transfer['status'] === 'received' && $totalShortage > 0): ?>
        <div class="dn-remarks" style="background: #fff3cd;">
            <h4><?php _e('shortage_summary'); ?></h4>
            <p><strong><?php _e('total_shortage'); ?>:</strong> <?php echo formatNumber($totalShortage); ?> <?php _e('units'); ?></p>
        </div>
        <?php endif; ?>

        <!-- Remarks -->
        <?php if (!empty($transfer['remarks'])): ?>
        <div class="dn-remarks">
            <h4><?php _e('remarks'); ?></h4>
            <p><?php echo htmlspecialchars($transfer['remarks']); ?></p>
        </div>
        <?php endif; ?>

        <!-- Cancellation Reason -->
        <?php if ($transfer['status'] === 'cancelled' && !empty($transfer['cancel_reason'])): ?>
        <div class="dn-remarks" style="background: #f8d7da; border-color: #dc3545;">
            <h4><?php _e('cancel_reason'); ?></h4>
            <p><?php echo nl2br(htmlspecialchars($transfer['cancel_reason'])); ?></p>
        </div>
        <?php endif; ?>

        <!-- Timestamps -->
        <div class="dn-info" style="font-size: 12px;">
            <div>
                <strong><?php _e('created_by'); ?>:</strong> <?php echo htmlspecialchars($transfer['created_by_name'] ?: '-'); ?>
                <br><?php echo formatDateTime($transfer['created_at']); ?>
            </div>
            <?php if ($transfer['dispatched_by']): ?>
            <div>
                <strong><?php _e('dispatched_by'); ?>:</strong> <?php echo htmlspecialchars($transfer['dispatched_by_name'] ?: '-'); ?>
                <br><?php echo formatDateTime($transfer['dispatched_at']); ?>
            </div>
            <?php endif; ?>
            <?php if ($transfer['received_by']): ?>
            <div>
                <strong><?php _e('received_by'); ?>:</strong> <?php echo htmlspecialchars($transfer['received_by_name'] ?: '-'); ?>
                <br><?php echo formatDateTime($transfer['received_at']); ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Signatures (only render boxes that have captured signatures) -->
        <?php
        $hasPreparedSig = !empty($transfer['prepared_by_signature']);
        $hasDispatchedSig = !empty($transfer['dispatched_by_signature']);
        $hasReceivedSig = !empty($transfer['received_by_signature']);
        if ($hasPreparedSig || $hasDispatchedSig || $hasReceivedSig):
        ?>
        <style>
            .dn-signatures .sig-img {
                max-height: 70px;
                max-width: 100%;
                display: block;
                margin: 0 auto 4px auto;
            }
            .dn-signatures .sig-meta {
                border-top: 1px solid #333;
                padding-top: 5px;
                font-size: 12px;
            }
            .dn-signatures .sig-meta .sig-name {
                font-weight: bold;
            }
            .dn-signatures .sig-meta .sig-date {
                color: #666;
                font-size: 11px;
            }
        </style>
        <div class="dn-signatures">
            <?php if ($hasPreparedSig): ?>
            <div class="dn-signature-box">
                <img class="sig-img" src="<?php echo htmlspecialchars($transfer['prepared_by_signature']); ?>" alt="Prepared By Signature">
                <div class="sig-meta">
                    <div><?php _e('prepared_by'); ?></div>
                    <div class="sig-name"><?php echo htmlspecialchars($transfer['created_by_name'] ?: '-'); ?></div>
                    <div class="sig-date"><?php echo formatDateTime($transfer['created_at']); ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($hasDispatchedSig): ?>
            <div class="dn-signature-box">
                <img class="sig-img" src="<?php echo htmlspecialchars($transfer['dispatched_by_signature']); ?>" alt="Dispatched By Signature">
                <div class="sig-meta">
                    <div><?php _e('dispatched_by'); ?></div>
                    <div class="sig-name"><?php echo htmlspecialchars($transfer['dispatched_by_name'] ?: '-'); ?></div>
                    <div class="sig-date"><?php echo $transfer['dispatched_at'] ? formatDateTime($transfer['dispatched_at']) : ''; ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($hasReceivedSig): ?>
            <div class="dn-signature-box">
                <img class="sig-img" src="<?php echo htmlspecialchars($transfer['received_by_signature']); ?>" alt="Received By Signature">
                <div class="sig-meta">
                    <div><?php _e('received_by'); ?></div>
                    <div class="sig-name"><?php echo htmlspecialchars($transfer['received_by_name'] ?: '-'); ?></div>
                    <div class="sig-date"><?php echo $transfer['received_at'] ? formatDateTime($transfer['received_at']) : ''; ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="dn-footer" style="text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 11px; color: #666;">
            <?php _e('developed_by'); ?> <strong>Fabonic</strong> &middot; www.fabonic.co.tz
        </div>
    </div>
</body>
</html>
