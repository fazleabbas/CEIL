<?php
/**
 * SMTP Settings — Admin-only page.
 *
 * Edits the smtp_* keys in the `settings` table. Includes a "Send Test"
 * button that delivers a small Fabonic-branded confirmation email so the
 * admin can verify credentials before relying on automatic notifications.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = 'SMTP Settings';

// Admin-only gate (matches the can_approve gate pattern used elsewhere).
if (getUserData('role_name') !== 'Admin') {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('');
}

require_once '../../includes/header.php';

$smtp = [
    'enabled'    => (int)getSetting('smtp_enabled', 0),
    'host'       => (string)getSetting('smtp_host', ''),
    'port'       => (string)getSetting('smtp_port', '587'),
    'encryption' => (string)getSetting('smtp_encryption', 'tls'),
    'username'   => (string)getSetting('smtp_username', ''),
    'password'   => (string)getSetting('smtp_password', ''),
    'from_email' => (string)getSetting('smtp_from_email', ''),
    'from_name'  => (string)getSetting('smtp_from_name', ''),
    'app_base_url' => (string)getSetting('app_base_url', ''),
];
?>

<div class="row mb-3">
    <div class="col-md-8">
        <h2><i class="bi bi-envelope-gear"></i> SMTP &amp; Email Settings</h2>
        <p class="text-muted mb-0">Configure the outgoing mail server used for in-app notification emails (requisition approvals, etc.).</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <form id="smtpForm">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="smtpEnabled" <?= $smtp['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="smtpEnabled">
                            <strong>Enable SMTP email sending</strong>
                            <br><small class="text-muted">When off, only bell-icon notifications are created — no emails are sent. Existing flows keep working.</small>
                        </label>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">SMTP Host *</label>
                            <input type="text" class="form-control" id="smtpHost" value="<?= htmlspecialchars($smtp['host']) ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Port *</label>
                            <input type="number" class="form-control" id="smtpPort" value="<?= htmlspecialchars($smtp['port']) ?>" placeholder="587">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Encryption</label>
                            <select class="form-select" id="smtpEncryption">
                                <option value="tls"  <?= $smtp['encryption'] === 'tls'  ? 'selected' : '' ?>>TLS (STARTTLS, 587)</option>
                                <option value="ssl"  <?= $smtp['encryption'] === 'ssl'  ? 'selected' : '' ?>>SSL (SMTPS, 465)</option>
                                <option value="none" <?= $smtp['encryption'] === 'none' ? 'selected' : '' ?>>None (25)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="smtpUsername" value="<?= htmlspecialchars($smtp['username']) ?>" placeholder="you@example.com" autocomplete="off">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="smtpPassword" value="<?= htmlspecialchars($smtp['password']) ?>" placeholder="••••••••" autocomplete="new-password">
                            <small class="text-muted">App password recommended for Gmail.</small>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">From Email *</label>
                            <input type="email" class="form-control" id="smtpFromEmail" value="<?= htmlspecialchars($smtp['from_email']) ?>" placeholder="notifications@yourdomain.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">From Name</label>
                            <input type="text" class="form-control" id="smtpFromName" value="<?= htmlspecialchars($smtp['from_name']) ?>" placeholder="FIS Notifications">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Public Base URL <small class="text-muted">(optional)</small></label>
                        <input type="url" class="form-control" id="appBaseUrl" value="<?= htmlspecialchars($smtp['app_base_url']) ?>" placeholder="https://yourcompany.fis.co.tz/">
                        <small class="text-muted">Used to build absolute links in email bodies. Leave blank to use the URL the server currently runs on.</small>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div>
                            <button type="button" class="btn btn-outline-secondary" onclick="sendTest()">
                                <i class="bi bi-send-check"></i> Save &amp; Send Test
                            </button>
                            <small class="text-muted ms-2">Saves first, then sends a test email to your account.</small>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="saveSettings()">
                            <i class="bi bi-check-circle"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <strong><i class="bi bi-info-circle"></i> Notes</strong>
            </div>
            <div class="card-body small text-muted">
                <ul class="ps-3 mb-2">
                    <li>If SMTP is disabled or misconfigured, the app falls back to bell-icon notifications only — nothing breaks.</li>
                    <li>For Gmail, generate an <em>App Password</em> at <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com</a>. Regular passwords won't work.</li>
                    <li>For Microsoft 365, use port <strong>587</strong> with TLS.</li>
                    <li>The test email is sent to the address logged in (your current account).</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
const SMTP_SYNC_URL = BASE_URL + 'api/smtp_settings-sync.php';

function collect() {
    return {
        smtp_enabled:    document.getElementById('smtpEnabled').checked ? 1 : 0,
        smtp_host:       document.getElementById('smtpHost').value.trim(),
        smtp_port:       document.getElementById('smtpPort').value.trim(),
        smtp_encryption: document.getElementById('smtpEncryption').value,
        smtp_username:   document.getElementById('smtpUsername').value.trim(),
        smtp_password:   document.getElementById('smtpPassword').value,
        smtp_from_email: document.getElementById('smtpFromEmail').value.trim(),
        smtp_from_name:  document.getElementById('smtpFromName').value.trim(),
        app_base_url:    document.getElementById('appBaseUrl').value.trim()
    };
}

async function saveSettings() {
    try {
        const res = await fetch(SMTP_SYNC_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save', data: collect() })
        });
        const j = await res.json();
        if (!j.success) throw new Error(j.error || 'Failed to save');
        showAlert('Settings saved.', 'success');
    } catch (e) {
        showAlert(e.message, 'danger');
    }
}

async function sendTest() {
    try {
        // Save first so the test uses the latest values.
        const saveRes = await fetch(SMTP_SYNC_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save', data: collect() })
        });
        const saveJson = await saveRes.json();
        if (!saveJson.success) throw new Error(saveJson.error || 'Failed to save before test');

        const res = await fetch(SMTP_SYNC_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send_test' })
        });
        const j = await res.json();
        if (!j.success) throw new Error(j.error || 'Failed to send test');
        showAlert('Saved. Test email sent to ' + (j.sent_to || 'your account') + ' — check your inbox (and spam folder).', 'success');
    } catch (e) {
        showAlert(e.message, 'danger');
    }
}

function showAlert(msg, type) {
    document.querySelectorAll('.smtp-toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = 'smtp-toast alert alert-' + type + ' alert-dismissible fade show';
    t.style.cssText = 'position:fixed; top:80px; right:20px; z-index:9999; min-width:340px; box-shadow:0 4px 8px rgba(0,0,0,0.2);';
    t.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 6500);
}
</script>

<?php require_once '../../includes/footer.php'; ?>
