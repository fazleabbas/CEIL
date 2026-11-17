<?php
/**
 * HTML email templates used by the in-app notification helpers.
 *
 * Each function returns a complete <html>...</html> document. Layout uses
 * inline styles + nested tables for maximum mail-client compatibility,
 * and includes a Fabonic-branded footer at the bottom of every message.
 */

/**
 * Resolve a sensible absolute base URL for links inside emails.
 * Priority: app_base_url setting -> BASE_URL constant (if absolute) ->
 * server-derived scheme://host/. Always returns a string ending with '/'.
 */
function emailBaseUrl() {
    $configured = trim((string)getSetting('app_base_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/';
    }

    if (defined('BASE_URL') && BASE_URL && preg_match('#^https?://#i', BASE_URL)) {
        return rtrim(BASE_URL, '/') . '/';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = $scheme . '://' . $host . '/';
    if (defined('BASE_URL') && BASE_URL) {
        $base .= ltrim(BASE_URL, '/');
        if (substr($base, -1) !== '/') $base .= '/';
    }
    return $base;
}

/**
 * Convert an in-app relative or absolute link into a fully-qualified URL
 * suitable for clicking from an email client.
 */
function emailAbsoluteLink($link) {
    if (!is_string($link) || $link === '') return emailBaseUrl();
    if (preg_match('#^https?://#i', $link)) return $link;
    return emailBaseUrl() . ltrim($link, '/');
}

/**
 * Standard Fabonic-branded footer reused by every notification email.
 */
function emailFooterHtml() {
    $year = date('Y');
    return '
        <tr>
            <td style="background: #1a1a2e; padding: 25px; text-align: center;">
                <p style="margin: 0 0 6px; color: rgba(255,255,255,0.95); font-size: 14px; font-weight: 600; letter-spacing: 0.5px;">Powered by Fabonic</p>
                <p style="margin: 0 0 10px; color: rgba(255,255,255,0.75); font-size: 12px;">Fabonic Ltd &middot; Dar es Salaam, Tanzania</p>
                <p style="margin: 0; color: rgba(255,255,255,0.65); font-size: 12px;">
                    <a href="mailto:info@fabonic.co.tz" style="color: rgba(255,255,255,0.85); text-decoration: none;">info@fabonic.co.tz</a>
                    &nbsp;&middot;&nbsp;
                    <a href="https://fabonic.co.tz" style="color: rgba(255,255,255,0.85); text-decoration: none;">fabonic.co.tz</a>
                </p>
                <p style="margin: 12px 0 0; color: rgba(255,255,255,0.45); font-size: 11px;">&copy; ' . $year . ' Fabonic Ltd. All rights reserved.</p>
            </td>
        </tr>';
}

/**
 * Render the line-items table for a requisition. Used in both
 * pending-approval and approved emails. Expects $items as a list of
 * arrays with keys: product_name, unit, requested_quantity, approved_quantity (optional), remarks.
 */
function emailRequisitionItemsTable($items, $showApproved = false) {
    if (empty($items) || !is_array($items)) {
        return '<p style="color:#888; font-size:13px; margin:0;">No line items.</p>';
    }

    $rows = '';
    $i = 0;
    foreach ($items as $it) {
        $i++;
        $name = htmlspecialchars($it['product_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $unit = htmlspecialchars($it['unit'] ?? '', ENT_QUOTES, 'UTF-8');
        $reqQty = isset($it['requested_quantity']) ? rtrim(rtrim(number_format((float)$it['requested_quantity'], 3, '.', ''), '0'), '.') : '0';
        $remarks = htmlspecialchars($it['remarks'] ?? '', ENT_QUOTES, 'UTF-8');
        $approvedCell = '';
        if ($showApproved) {
            $aq = isset($it['approved_quantity']) && $it['approved_quantity'] !== null
                ? rtrim(rtrim(number_format((float)$it['approved_quantity'], 3, '.', ''), '0'), '.')
                : '-';
            $approvedCell = '<td style="padding:8px 10px; border-bottom:1px solid #eee; font-size:13px; text-align:right; color:#198754; font-weight:600;">' . $aq . '</td>';
        }
        $rows .= '<tr>
            <td style="padding:8px 10px; border-bottom:1px solid #eee; font-size:13px; color:#666; width:32px;">' . $i . '</td>
            <td style="padding:8px 10px; border-bottom:1px solid #eee; font-size:13px; color:#333;">' . $name . '</td>
            <td style="padding:8px 10px; border-bottom:1px solid #eee; font-size:13px; color:#666; text-align:right;">' . $reqQty . ' ' . $unit . '</td>
            ' . $approvedCell . '
            <td style="padding:8px 10px; border-bottom:1px solid #eee; font-size:12px; color:#888;">' . $remarks . '</td>
        </tr>';
    }

    $approvedHeader = $showApproved
        ? '<th style="padding:10px; background:#f1f3f5; font-size:12px; text-align:right; color:#198754; border-bottom:1px solid #dee2e6;">Approved</th>'
        : '';

    return '
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dee2e6; border-radius:6px; overflow:hidden;">
        <thead>
            <tr>
                <th style="padding:10px; background:#f1f3f5; font-size:12px; text-align:left; color:#495057; border-bottom:1px solid #dee2e6;">#</th>
                <th style="padding:10px; background:#f1f3f5; font-size:12px; text-align:left; color:#495057; border-bottom:1px solid #dee2e6;">Product</th>
                <th style="padding:10px; background:#f1f3f5; font-size:12px; text-align:right; color:#495057; border-bottom:1px solid #dee2e6;">Requested</th>
                ' . $approvedHeader . '
                <th style="padding:10px; background:#f1f3f5; font-size:12px; text-align:left; color:#495057; border-bottom:1px solid #dee2e6;">Remarks</th>
            </tr>
        </thead>
        <tbody>' . $rows . '</tbody>
    </table>';
}

/**
 * Renders the "pending approval" email body sent to approvers.
 *
 * @param array $requisition Full requisition row (with destination_location_name,
 *                           cost_centre_name, created_by_name, voucher_number,
 *                           voucher_date, remarks, details[])
 * @param array $recipient   ['name' => string]
 * @return string Complete HTML document
 */
function renderRequisitionApprovalEmail($requisition, $recipient = []) {
    $voucherNumber = htmlspecialchars($requisition['voucher_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $voucherDate   = htmlspecialchars($requisition['voucher_date'] ?? '', ENT_QUOTES, 'UTF-8');
    $destination   = htmlspecialchars($requisition['destination_location_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $costCentre    = htmlspecialchars($requisition['cost_centre_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $createdBy     = htmlspecialchars($requisition['created_by_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $remarks       = htmlspecialchars($requisition['remarks'] ?? '', ENT_QUOTES, 'UTF-8');
    $recipientName = htmlspecialchars($recipient['name'] ?? 'there', ENT_QUOTES, 'UTF-8');

    $viewUrl = emailAbsoluteLink('modules/transactions/requisition_voucher_view.php?id=' . (int)($requisition['id'] ?? 0));

    $itemsTable = emailRequisitionItemsTable($requisition['details'] ?? [], false);
    $remarksBlock = $remarks !== ''
        ? '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;"><tr><td style="padding:12px 14px; background:#fff8e1; border-left:4px solid #ffc107; border-radius:4px; font-size:13px; color:#664d03;"><strong>Remarks:</strong><br>' . nl2br($remarks) . '</td></tr></table>'
        : '';

    return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Requisition Pending Approval</title></head>
<body style="margin:0; padding:0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Arial, sans-serif; background:#f4f4f4;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4; padding:20px 0;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:640px; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.08);">
    <tr>
        <td style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); padding: 26px 30px;">
            <h1 style="color:#fff; margin:0; font-size:22px; font-weight:600;">Requisition Pending Approval</h1>
            <p style="color:rgba(255,255,255,0.95); margin:8px 0 0; font-size:14px;">Voucher ' . $voucherNumber . '</p>
        </td>
    </tr>
    <tr>
        <td style="padding: 28px 30px 8px;">
            <p style="font-size:15px; color:#333; margin:0 0 14px;">Hi ' . $recipientName . ',</p>
            <p style="font-size:14px; color:#555; margin:0 0 22px; line-height:1.5;">
                A new requisition voucher has been submitted and is waiting for your approval.
                Please review the details below and action it at your earliest convenience.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa; border-radius:8px; padding:6px 0; margin-bottom:18px;">
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666;" width="40%">Voucher Number</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; font-weight:600;">' . $voucherNumber . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Voucher Date</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $voucherDate . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Destination Location</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $destination . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Cost Centre</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $costCentre . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Prepared By</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $createdBy . '</td>
                </tr>
            </table>

            <h3 style="font-size:14px; color:#333; margin:0 0 10px;">Items</h3>
            ' . $itemsTable . '
            ' . $remarksBlock . '

            <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 8px;">
                <tr>
                    <td align="center">
                        <a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '"
                           style="display:inline-block; padding:12px 28px; background:#fd7e14; color:#fff; text-decoration:none; border-radius:50px; font-weight:600; font-size:14px;">
                            Review &amp; Approve
                        </a>
                    </td>
                </tr>
            </table>

            <p style="font-size:12px; color:#888; text-align:center; margin:10px 0 24px;">
                Or open this link directly: <br>
                <a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#0d6efd; word-break:break-all;">' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '</a>
            </p>
        </td>
    </tr>
    ' . emailFooterHtml() . '
</table>
</td></tr>
</table>
</body></html>';
}

/**
 * Renders the "approved" email body sent to the creator + watchers.
 *
 * @param array $requisition Full requisition row (with destination_location_name,
 *                           cost_centre_name, approved_by_name, voucher_number,
 *                           voucher_date, remarks, details[])
 * @param array $recipient   ['name' => string, 'role' => 'creator'|'watcher']
 * @return string Complete HTML document
 */
function renderRequisitionApprovedEmail($requisition, $recipient = []) {
    $voucherNumber = htmlspecialchars($requisition['voucher_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $voucherDate   = htmlspecialchars($requisition['voucher_date'] ?? '', ENT_QUOTES, 'UTF-8');
    $destination   = htmlspecialchars($requisition['destination_location_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $costCentre    = htmlspecialchars($requisition['cost_centre_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $approvedBy    = htmlspecialchars($requisition['approved_by_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $recipientName = htmlspecialchars($recipient['name'] ?? 'there', ENT_QUOTES, 'UTF-8');
    $role          = $recipient['role'] ?? 'watcher';

    $viewUrl = emailAbsoluteLink('modules/transactions/requisition_voucher_view.php?id=' . (int)($requisition['id'] ?? 0));

    $intro = ($role === 'creator')
        ? 'Good news — your requisition voucher has been approved. You can now proceed with the related stock transfer.'
        : 'A requisition voucher you are watching has been approved. This is an informational notification — no action is required from you.';

    // Show approved column if any detail has approved_quantity set.
    $hasApproved = false;
    foreach (($requisition['details'] ?? []) as $d) {
        if (isset($d['approved_quantity']) && $d['approved_quantity'] !== null) { $hasApproved = true; break; }
    }
    $itemsTable = emailRequisitionItemsTable($requisition['details'] ?? [], $hasApproved);

    return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Requisition Approved</title></head>
<body style="margin:0; padding:0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Arial, sans-serif; background:#f4f4f4;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4; padding:20px 0;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:640px; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.08);">
    <tr>
        <td style="background: linear-gradient(135deg, #198754 0%, #146c43 100%); padding: 26px 30px;">
            <h1 style="color:#fff; margin:0; font-size:22px; font-weight:600;">Requisition Approved</h1>
            <p style="color:rgba(255,255,255,0.95); margin:8px 0 0; font-size:14px;">Voucher ' . $voucherNumber . '</p>
        </td>
    </tr>
    <tr>
        <td style="padding: 28px 30px 8px;">
            <p style="font-size:15px; color:#333; margin:0 0 14px;">Hi ' . $recipientName . ',</p>
            <p style="font-size:14px; color:#555; margin:0 0 22px; line-height:1.5;">' . $intro . '</p>

            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa; border-radius:8px; padding:6px 0; margin-bottom:18px;">
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666;" width="40%">Voucher Number</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; font-weight:600;">' . $voucherNumber . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Voucher Date</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $voucherDate . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Destination Location</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $destination . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Cost Centre</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $costCentre . '</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px; font-size:13px; color:#666; border-top:1px solid #eaecef;">Approved By</td>
                    <td style="padding:10px 16px; font-size:13px; color:#222; border-top:1px solid #eaecef;">' . $approvedBy . '</td>
                </tr>
            </table>

            <h3 style="font-size:14px; color:#333; margin:0 0 10px;">Items</h3>
            ' . $itemsTable . '

            <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 8px;">
                <tr>
                    <td align="center">
                        <a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '"
                           style="display:inline-block; padding:12px 28px; background:#198754; color:#fff; text-decoration:none; border-radius:50px; font-weight:600; font-size:14px;">
                            View Requisition
                        </a>
                    </td>
                </tr>
            </table>

            <p style="font-size:12px; color:#888; text-align:center; margin:10px 0 24px;">
                Or open this link directly: <br>
                <a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#0d6efd; word-break:break-all;">' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '</a>
            </p>
        </td>
    </tr>
    ' . emailFooterHtml() . '
</table>
</td></tr>
</table>
</body></html>';
}

/**
 * Simple plain-text "this is a test" email used by the SMTP settings page
 * Send Test button to verify credentials.
 */
function renderSmtpTestEmail($recipientName = 'there') {
    $name = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>SMTP Test Email</title></head>
<body style="margin:0; padding:0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Arial, sans-serif; background:#f4f4f4;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4; padding:20px 0;"><tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.08);">
    <tr><td style="background:#0d6efd; padding:22px 28px;">
        <h1 style="color:#fff; margin:0; font-size:20px;">SMTP Test Email</h1>
    </td></tr>
    <tr><td style="padding:24px 28px;">
        <p style="font-size:15px; color:#333; margin:0 0 12px;">Hi ' . $name . ',</p>
        <p style="font-size:14px; color:#555; line-height:1.5; margin:0 0 12px;">
            If you can read this, your SMTP configuration is working correctly.
            Notifications from FIS will be delivered to recipients using these settings.
        </p>
        <p style="font-size:13px; color:#888; margin:0;">Sent at ' . date('d M Y, H:i:s') . '</p>
    </td></tr>
    ' . emailFooterHtml() . '
</table>
</td></tr></table>
</body></html>';
}
