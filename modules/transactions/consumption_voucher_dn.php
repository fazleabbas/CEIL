<?php
/**
 * Consumption Voucher - Delivery Note (Standalone Print View)
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if (!hasPermission('consumption_vouchers', 'view')) {
    setAlert(__('no_permission'), 'danger');
    redirect('index.php');
}

$id = $_GET['id'] ?? 0;

if (empty($id)) {
    setAlert(__('consumption_voucher_not_found'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

// Get voucher header
$stmt = $conn->prepare("
    SELECT
        cv.*,
        l.name as location_name,
        l.address as location_address,
        cc.name as cost_centre_name,
        cc.code as cost_centre_code,
        u.name as created_by_name
    FROM consumption_vouchers cv
    LEFT JOIN locations l ON cv.location_id = l.id
    LEFT JOIN cost_centres cc ON cv.cost_centre_id = cc.id
    LEFT JOIN users u ON cv.created_by = u.id
    WHERE cv.id = ?
");
$stmt->execute([$id]);
$voucher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$voucher) {
    setAlert(__('consumption_voucher_not_found'), 'danger');
    redirect('modules/transactions/consumption_voucher_list.php');
}

// Project Manager / Engineer who authorised the consumption (migration 0046).
// Guarded with ?? so a tenant that has not run the migration renders the
// original three-box sign-off rather than a warning.
$cvPmName = trim((string)($voucher['project_manager_name'] ?? ''));
$cvPmSig  = (string)($voucher['project_manager_signature'] ?? '');
if (!preg_match('#^data:image/(png|jpeg|jpg);base64,[A-Za-z0-9+/=]+$#', $cvPmSig)) {
    $cvPmSig = '';
}

// Get voucher details
$detailsStmt = $conn->prepare("
    SELECT
        cvd.*,
        p.name as product_name,
        p.unit,
        p.barcode,
        pc.name as category_name
    FROM consumption_voucher_details cvd
    JOIN products p ON cvd.product_id = p.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE cvd.voucher_id = ?
    ORDER BY pc.name, p.name
");
$detailsStmt->execute([$id]);
$details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get company info
$companyName = getSetting('company_name', 'Company Name');
$companyAddress = getSetting('company_address', '');
$companyPhone = getSetting('company_phone', '');

$pageTitle = __('delivery_note') . ' - ' . $voucher['voucher_number'];
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
            /* 30% x 3 under space-between is the original layout. A recorded
               project manager adds a fourth box, so the width drops to keep
               all four on one line. */
            width: <?php echo $cvPmName !== '' ? '23%' : '30%'; ?>;
            text-align: center;
        }

        /* The signature sits in a fixed 46px slot and the rule below it loses
           its 50px top margin, so 46 + 4 + 0 lands the project manager rule at
           exactly the same height as the three unsigned ones. Scoped to this
           box so the other three, and every voucher with no project manager,
           are untouched. */
        .dn-signature-box .sig-img {
            height: 46px;
            max-width: 100%;
            object-fit: contain;
            display: block;
            margin: 0 auto 4px auto;
        }

        .dn-signature-box.pm-box .line {
            margin-top: 0;
        }

        .dn-signature-box .sig-name {
            font-size: 11px;
            color: #333;
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
            <a href="<?php echo BASE_URL; ?>modules/transactions/consumption_voucher_list.php" class="btn btn-secondary">
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
            <?php _e('delivery_note'); ?>
        </div>

        <!-- Voucher Info -->
        <div class="dn-meta">
            <div class="dn-meta-item">
                <strong><?php _e('voucher_number_label'); ?></strong> <?php echo htmlspecialchars($voucher['voucher_number']); ?>
            </div>
            <div class="dn-meta-item">
                <strong><?php _e('date_label'); ?></strong> <?php echo formatDate($voucher['voucher_date']); ?>
            </div>
        </div>

        <!-- Location & Cost Centre -->
        <div class="dn-info">
            <div class="dn-info-box">
                <h3><?php _e('location'); ?></h3>
                <p><strong><?php echo htmlspecialchars($voucher['location_name']); ?></strong></p>
                <?php if (!empty($voucher['location_address'])): ?>
                <p><?php echo htmlspecialchars($voucher['location_address']); ?></p>
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
                    <th style="width: 100px;" class="number"><?php _e('quantity'); ?></th>
                    <th style="width: 100px;" class="number"><?php _e('rate'); ?></th>
                    <th style="width: 120px;" class="number"><?php _e('amount'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sn = 0;
                $totalQty = 0;
                $totalAmount = 0;
                $currentCategory = '';
                foreach ($details as $detail):
                    $sn++;
                    $qty = floatval($detail['quantity']);
                    $rate = floatval($detail['rate_per_unit']);
                    $amount = floatval($detail['amount']);
                    $totalQty += $qty;
                    $totalAmount += $amount;

                    if ($currentCategory !== $detail['category_name']):
                        $currentCategory = $detail['category_name'];
                ?>
                <tr class="dn-category-row">
                    <td colspan="6"><?php echo htmlspecialchars($currentCategory ?: __('uncategorized')); ?></td>
                </tr>
                <?php endif; ?>

                <tr>
                    <td class="center"><?php echo $sn; ?></td>
                    <td>
                        <?php echo htmlspecialchars($detail['product_name']); ?>
                        <?php if (!empty($detail['barcode'])): ?>
                        <br><small style="color: #888;"><?php echo htmlspecialchars($detail['barcode']); ?></small>
                        <?php endif; ?>
                        <?php if (!empty($detail['remarks'])): ?>
                        <br><small style="color: #888;"><?php echo htmlspecialchars($detail['remarks']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="center"><?php echo htmlspecialchars($detail['unit']); ?></td>
                    <td class="number"><?php echo formatNumber($qty, 3); ?></td>
                    <td class="number"><?php echo formatCurrency($rate); ?></td>
                    <td class="number"><?php echo formatCurrency($amount); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3"><?php _e('total'); ?></th>
                    <th class="number"><?php echo formatNumber($totalQty, 3); ?></th>
                    <th></th>
                    <th class="number"><?php echo formatCurrency($totalAmount); ?></th>
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
        </div>

        <!-- Signatures -->
        <div class="dn-signatures">
            <div class="dn-signature-box">
                <div class="line"><?php _e('prepared_by'); ?></div>
            </div>
            <div class="dn-signature-box">
                <div class="line"><?php _e('approved_by'); ?></div>
            </div>
            <div class="dn-signature-box">
                <div class="line"><?php _e('received_by_signature'); ?></div>
            </div>
<?php if ($cvPmName !== ''): ?>
            <!-- Project Manager / Engineer. Only emitted when one was recorded,
                 so every voucher without one prints the original three boxes,
                 unchanged. Approved By above is deliberately left as it was. -->
            <div class="dn-signature-box<?php echo $cvPmSig ? ' pm-box' : ''; ?>">
                <?php if ($cvPmSig): ?>
                <img class="sig-img" src="<?php echo htmlspecialchars($cvPmSig); ?>" alt="<?php _e('signature'); ?>">
                <?php endif; ?>
                <div class="line"><?php _e('project_manager'); ?></div>
                <div class="sig-name"><?php echo htmlspecialchars($cvPmName); ?></div>
            </div>
<?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="dn-footer">
            <?php _e('developed_by'); ?> <strong>Fabonic</strong> &middot; www.fabonic.co.tz
        </div>
    </div>
</body>
</html>
