<?php
/**
 * Requisition Voucher - Delivery Note (Standalone Print View)
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if (!hasPermission('requisition_vouchers', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = $_GET['id'] ?? 0;

if (empty($id)) {
    setAlert(__('requisition_voucher_not_found'), 'danger');
    redirect('modules/transactions/requisition_voucher_list.php');
}

// Get voucher header
$stmt = $conn->prepare("
    SELECT
        rv.*,
        l.name as destination_location_name,
        l.address as destination_location_address,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name,
        ua.name as approved_by_name
    FROM requisition_vouchers rv
    LEFT JOIN locations l ON rv.destination_location_id = l.id
    LEFT JOIN cost_centres cc ON rv.cost_centre_id = cc.id
    LEFT JOIN users u ON rv.created_by = u.id
    LEFT JOIN users ua ON rv.approved_by = ua.id
    WHERE rv.id = ?
");
$stmt->execute([$id]);
$voucher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$voucher) {
    setAlert(__('requisition_voucher_not_found'), 'danger');
    redirect('modules/transactions/requisition_voucher_list.php');
}

// Get voucher details
$detailsStmt = $conn->prepare("
    SELECT
        rvd.*,
        p.name as product_name,
        p.unit,
        p.barcode,
        pc.name as category_name
    FROM requisition_voucher_details rvd
    JOIN products p ON rvd.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE rvd.requisition_id = ?
    ORDER BY pc.name, p.name
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

// Determine if we should show the transferred column
$showTransferred = in_array($voucher['status'], ['approved', 'closed']);

// Get company info
$companyName = getSetting('company_name', 'Company Name');
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');

$pageTitle = __('requisition_voucher') . ' - ' . $voucher['voucher_number'];
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; padding: 15mm; }
            .print-container { max-width: 100%; }
        }

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

        .dn-header p {
            margin: 3px 0;
            font-size: 13px;
            color: #555;
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

        .dn-info-box.full-width {
            width: 100%;
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
            min-width: 100px;
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

        .dn-table tfoot th {
            background: #f5f5f5;
        }

        .dn-table tfoot th.number {
            text-align: right;
        }

        .dn-category-row td {
            background: #e9ecef;
            font-weight: bold;
            font-size: 12px;
            padding: 4px 8px;
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

        .dn-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            font-size: 11px;
            color: #666;
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
            <a href="<?php echo BASE_URL; ?>modules/transactions/requisition_voucher_list.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> <?php _e('back'); ?>
            </a>
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
            <?php _e('requisition_voucher'); ?>
        </div>

        <!-- Voucher Info -->
        <div class="dn-meta">
            <div class="dn-meta-item">
                <strong><?php _e('voucher_number_label'); ?></strong> <?php echo htmlspecialchars($voucher['voucher_number']); ?>
            </div>
            <div class="dn-meta-item">
                <strong><?php _e('date_label'); ?></strong> <?php echo formatDate($voucher['voucher_date']); ?>
            </div>
            <div class="dn-meta-item">
                <strong><?php _e('status'); ?>:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $voucher['status']))); ?>
            </div>
        </div>

        <!-- Destination Location & Cost Centre -->
        <div class="dn-info">
            <div class="dn-info-box">
                <h3><?php _e('destination_location'); ?></h3>
                <p><strong><?php echo htmlspecialchars($voucher['destination_location_name']); ?></strong></p>
                <?php if (!empty($voucher['destination_location_address'])): ?>
                <p><?php echo htmlspecialchars($voucher['destination_location_address']); ?></p>
                <?php endif; ?>
            </div>
            <?php if (!empty($voucher['cost_centre_name'])): ?>
            <div class="dn-info-box">
                <h3><?php _e('cost_centre'); ?></h3>
                <p><strong><?php echo htmlspecialchars($voucher['cost_centre_code'] . ' - ' . $voucher['cost_centre_name']); ?></strong></p>
            </div>
            <?php else: ?>
            <div class="dn-info-box">
                <h3><?php _e('created_by'); ?></h3>
                <p><strong><?php echo htmlspecialchars($voucher['created_by_name']); ?></strong></p>
                <p><?php echo formatDateTime($voucher['created_at']); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Items Table -->
        <table class="dn-table">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th><?php _e('product'); ?></th>
                    <th style="width: 80px;"><?php _e('unit'); ?></th>
                    <th style="width: 120px;" class="number">
                        <?php
                        // Once approved, the DN reflects what is being / has been dispatched
                        // (approved_quantity if set, else requested). Pre-approval it remains the request.
                        echo (in_array($voucher['status'] ?? '', ['approved', 'closed'], true))
                            ? 'Approved Quantity'
                            : __('requested_quantity');
                        ?>
                    </th>
                    <?php if ($showTransferred): ?>
                    <th style="width: 120px;" class="number"><?php _e('transferred_so_far'); ?></th>
                    <?php endif; ?>
                    <th><?php _e('remarks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sn = 0;
                $totalRequestedQty = 0;
                $totalTransferredQty = 0;
                $currentCategory = '';
                $colSpan = $showTransferred ? 6 : 5;
                foreach ($details as $detail):
                    $sn++;
                    $requestedQty = floatval($detail['requested_quantity']);
                    // Effective qty for display = approved when set (post-migration 0023),
                    // else requested. COALESCE in PHP so legacy rows render identically.
                    $effectiveQty = (isset($detail['approved_quantity']) && $detail['approved_quantity'] !== null)
                        ? floatval($detail['approved_quantity'])
                        : $requestedQty;
                    $transferredQty = floatval($detail['transferred_quantity']);
                    $totalRequestedQty += $effectiveQty;
                    $totalTransferredQty += $transferredQty;

                    if ($currentCategory !== $detail['category_name']):
                        $currentCategory = $detail['category_name'];
                ?>
                <tr class="dn-category-row">
                    <td colspan="<?php echo $colSpan; ?>"><?php echo htmlspecialchars($currentCategory ?: __('uncategorized')); ?></td>
                </tr>
                <?php endif; ?>

                <tr>
                    <td class="center"><?php echo $sn; ?></td>
                    <td>
                        <?php echo htmlspecialchars($detail['product_name']); ?>
                        <?php if (!empty($detail['barcode'])): ?>
                        <br><small style="color: #888;"><?php echo htmlspecialchars($detail['barcode']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="center"><?php echo htmlspecialchars($detail['unit']); ?></td>
                    <td class="number"><?php echo formatNumber($effectiveQty, 3); ?></td>
                    <?php if ($showTransferred): ?>
                    <td class="number"><?php echo formatNumber($transferredQty, 3); ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars($detail['remarks'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3"><?php _e('total'); ?></th>
                    <th class="number"><?php echo formatNumber($totalRequestedQty, 3); ?></th>
                    <?php if ($showTransferred): ?>
                    <th class="number"><?php echo formatNumber($totalTransferredQty, 3); ?></th>
                    <?php endif; ?>
                    <th></th>
                </tr>
            </tfoot>
        </table>

        <!-- Remarks -->
        <?php if (!empty($voucher['remarks'])): ?>
        <div class="dn-remarks">
            <h4><?php _e('remarks'); ?></h4>
            <p><?php echo htmlspecialchars($voucher['remarks']); ?></p>
        </div>
        <?php endif; ?>

        <!-- Timestamps -->
        <div style="font-size: 12px; margin-bottom: 20px;">
            <strong><?php _e('created_by'); ?>:</strong> <?php echo htmlspecialchars($voucher['created_by_name'] ?: '-'); ?>
            &nbsp;&mdash;&nbsp; <?php echo formatDateTime($voucher['created_at']); ?>
            <?php if (!empty($voucher['approved_by_name'])): ?>
            <br><strong><?php _e('approved_by'); ?>:</strong> <?php echo htmlspecialchars($voucher['approved_by_name']); ?>
            &nbsp;&mdash;&nbsp; <?php echo formatDateTime($voucher['approved_at']); ?>
            <?php endif; ?>
        </div>

        <!-- Signatures -->
        <style>
            .dn-signature-box .sig-img { max-height: 60px; max-width: 100%; display: block; margin: 0 auto 4px auto; }
            .dn-signature-box .sig-name { font-size: 11px; color: #333; }
        </style>
        <div class="dn-signatures">
            <div class="dn-signature-box">
                <?php if (!empty($voucher['prepared_by_signature'])): ?>
                    <img class="sig-img" src="<?php echo htmlspecialchars($voucher['prepared_by_signature']); ?>" alt="Prepared By Signature">
                <?php endif; ?>
                <div class="line"><?php _e('requested_by'); ?></div>
                <?php if (!empty($voucher['prepared_by_signature']) && !empty($voucher['created_by_name'])): ?>
                    <div class="sig-name"><?php echo htmlspecialchars($voucher['created_by_name']); ?></div>
                <?php endif; ?>
            </div>
            <div class="dn-signature-box">
                <?php if (!empty($voucher['approved_by_signature'])): ?>
                    <img class="sig-img" src="<?php echo htmlspecialchars($voucher['approved_by_signature']); ?>" alt="Approved By Signature">
                <?php endif; ?>
                <div class="line"><?php _e('approved_by'); ?></div>
                <?php if (!empty($voucher['approved_by_signature']) && !empty($voucher['approved_by_name'])): ?>
                    <div class="sig-name"><?php echo htmlspecialchars($voucher['approved_by_name']); ?></div>
                <?php endif; ?>
            </div>
            <div class="dn-signature-box">
                <div class="line"><?php _e('received_by_signature'); ?></div>
            </div>
        </div>

        <!-- Footer -->
        <div class="dn-footer">
            <?php _e('developed_by'); ?> <strong>Fabonic</strong> &middot; www.fabonic.co.tz
        </div>
    </div>

    <script>
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
