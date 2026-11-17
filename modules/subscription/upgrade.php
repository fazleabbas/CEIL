<?php
/**
 * Subscription Upgrade Page (Pro-rata)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

// Check if subscription is blocked
$status = getSubscriptionStatus($conn);
if ($status['blocked']) {
    header('Location: status.php?blocked=1');
    exit;
}

$type = isset($_GET['type']) && in_array($_GET['type'], ['user', 'location']) ? $_GET['type'] : 'user';
// Users are added in blocks of 5, locations are added individually
$quantity = $type === 'user' ? 5 : (isset($_GET['qty']) ? max(1, (int)$_GET['qty']) : 1);
// For billing: 5 users = 1 unit, 1 location = 1 unit
$billingUnits = $type === 'user' ? 1 : $quantity;

$prorata = calculateProRata($conn, $billingUnits);
$paymentInfo = getPaymentInstructions($prorata['prorata_amount']);

$success = false;
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $result = createUpgradeRequest($conn, $type, $quantity);
        if ($result['success']) {
            $success = true;
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Add ' . ucfirst($type);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .payment-box { background: #f8f9fa; border-radius: 8px; padding: 20px; }
        .airtel-number { font-size: 1.5rem; font-weight: bold; color: #e4002b; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="<?= BASE_URL ?>"><?= APP_NAME ?></a>
            <a href="status.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left"></i> Back to Status
            </a>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <?php if ($success): ?>
                <div class="card border-success">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        <h3 class="mt-3">Upgrade Request Submitted</h3>
                        <p class="text-muted">
                            Your request to add <?= $quantity ?> <?= $type ?>(s) has been submitted.<br>
                            Please complete the payment below.
                        </p>
                        
                        <div class="payment-box text-start mt-4">
                            <h5 class="mb-3">Payment Details</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Amount to Pay:</strong></p>
                                    <h3 class="text-success"><?= formatSubscriptionAmount($prorata['prorata_amount']) ?></h3>
                                    <small class="text-muted">
                                        <?= $quantity ?> <?= $type ?>(s) × <?= $prorata['remaining_days'] ?> days × TZS <?= $type === 'user' ? '500 (per 5 users)' : '500' ?>
                                    </small>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Pay to (Airtel Money):</strong></p>
                                    <div class="airtel-number"><?= PAYMENT_AIRTEL_NUMBER ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-4">
                            <i class="bi bi-info-circle"></i> 
                            After payment confirmation, your <?= $type ?> limit will be increased within 24 hours.
                        </div>
                        
                        <a href="status.php" class="btn btn-primary mt-3">
                            <i class="bi bi-arrow-left"></i> Back to Subscription Status
                        </a>
                    </div>
                </div>
                
                <?php else: ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-<?= $type === 'user' ? 'person-plus' : 'geo-alt-fill' ?>"></i>
                            Add <?= ucfirst($type) ?>(s)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?= clean($error) ?></div>
                        <?php endif; ?>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Pro-rata Billing:</strong> You only pay for the remaining days in your current subscription period.
                        </div>
                        
                        <form method="get" class="mb-4">
                            <input type="hidden" name="type" value="<?= $type ?>">
                            <div class="row align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select" onchange="this.form.submit()">
                                        <option value="user" <?= $type === 'user' ? 'selected' : '' ?>>User</option>
                                        <option value="location" <?= $type === 'location' ? 'selected' : '' ?>>Location</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Quantity</label>
                                    <select name="qty" class="form-select" onchange="this.form.submit()">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?= $i ?>" <?= $quantity === $i ? 'selected' : '' ?>><?= $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-secondary w-100">Calculate</button>
                                </div>
                            </div>
                        </form>
                        
                        <hr>
                        
                        <h5>Cost Calculation</h5>
                        <table class="table">
                            <tr>
                                <td>Subscription ends on</td>
                                <td class="text-end fw-bold"><?= date('d M Y', strtotime($prorata['end_date'])) ?></td>
                            </tr>
                            <tr>
                                <td>Remaining days</td>
                                <td class="text-end fw-bold"><?= $prorata['remaining_days'] ?> days</td>
                            </tr>
                            <tr>
                                <td>Daily rate <?= $type === 'user' ? 'per 5 users' : 'per location' ?></td>
                                <td class="text-end"><?= formatSubscriptionAmount($prorata['daily_rate']) ?></td>
                            </tr>
                            <tr>
                                <td>Quantity</td>
                                <td class="text-end"><?= $quantity ?> <?= $type ?>(s)<?= $type === 'user' ? ' (1 block)' : '' ?></td>
                            </tr>
                            <tr class="table-success">
                                <td><strong>Total Pro-rata Amount</strong></td>
                                <td class="text-end"><strong><?= formatSubscriptionAmount($prorata['prorata_amount']) ?></strong></td>
                            </tr>
                        </table>
                        
                        <div class="payment-box mt-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-muted">Pay via Airtel Money</h6>
                                    <div class="airtel-number">
                                        <i class="bi bi-phone"></i> <?= PAYMENT_AIRTEL_NUMBER ?>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <h6 class="text-muted">Amount</h6>
                                    <h3 class="text-success"><?= formatSubscriptionAmount($prorata['prorata_amount']) ?></h3>
                                </div>
                            </div>
                        </div>
                        
                        <form method="post" class="mt-4">
                            <?= csrfField() ?>
                            <input type="hidden" name="type" value="<?= $type ?>">
                            <input type="hidden" name="quantity" value="<?= $quantity ?>">
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-check-circle"></i> Submit Upgrade Request
                                </button>
                                <a href="status.php" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
