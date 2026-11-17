<?php
/**
 * Subscription Status Page
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

// Check login (but don't enforce subscription here - this is where blocked users land)
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

$status = getSubscriptionStatus($conn);
$isBlocked = isset($_GET['blocked']) && $_GET['blocked'] == '1';
$calculation = calculateSubscriptionAmount($status['max_users'], $status['max_locations'], 1);
$paymentInfo = getPaymentInstructions($calculation['monthly_amount']);

$pageTitle = 'Subscription Status';
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
        .subscription-card { border-left: 4px solid; }
        .status-active { border-color: #28a745; }
        .status-warning { border-color: #ffc107; }
        .status-expired { border-color: #dc3545; }
        .status-grace { border-color: #fd7e14; }
        .payment-box { background: #f8f9fa; border-radius: 8px; padding: 20px; }
        .airtel-number { font-size: 1.5rem; font-weight: bold; color: #e4002b; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="<?= BASE_URL ?>"><?= APP_NAME ?></a>
            <?php if (!$isBlocked): ?>
            <a href="<?= BASE_URL ?>" class="btn btn-outline-light btn-sm">
                <i class="bi bi-house"></i> Dashboard
            </a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        <?php if ($isBlocked): ?>
        <div class="alert alert-danger">
            <h4><i class="bi bi-exclamation-triangle"></i> Subscription Expired</h4>
            <p class="mb-0">Your subscription has expired. Please renew to continue using the system.</p>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Current Status -->
            <div class="col-lg-6 mb-4">
                <div class="card subscription-card <?php 
                    if ($status['status'] === 'active' && !$status['warning']) echo 'status-active';
                    elseif ($status['status'] === 'grace_period') echo 'status-grace';
                    elseif ($status['status'] === 'expired') echo 'status-expired';
                    else echo 'status-warning';
                ?>">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-card-checklist"></i> Subscription Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted">Plan</span>
                            <span class="badge bg-<?= $status['is_trial'] ? 'info' : 'primary' ?> fs-6">
                                <?= $status['subscription']['plan_name'] ?? 'N/A' ?>
                                <?= $status['is_trial'] ? '(Trial)' : '' ?>
                            </span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Status</span>
                            <span class="badge bg-<?php
                                if ($status['status'] === 'active') echo 'success';
                                elseif ($status['status'] === 'grace_period') echo 'warning text-dark';
                                else echo 'danger';
                            ?>">
                                <?= ucfirst(str_replace('_', ' ', $status['status'])) ?>
                            </span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Expires</span>
                            <span><?= date('d M Y', strtotime($status['end_date'])) ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Days Remaining</span>
                            <span class="fw-bold <?= $status['days_remaining'] <= 7 ? 'text-danger' : '' ?>">
                                <?= max(0, $status['days_remaining']) ?> days
                            </span>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="border rounded p-3">
                                    <i class="bi bi-people fs-4 text-primary"></i>
                                    <div class="fw-bold"><?= $status['current_users'] ?> / <?= $status['max_users'] ?></div>
                                    <small class="text-muted">Users</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-3">
                                    <i class="bi bi-geo-alt fs-4 text-primary"></i>
                                    <div class="fw-bold"><?= $status['current_locations'] ?> / <?= $status['max_locations'] ?></div>
                                    <small class="text-muted">Locations</small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($status['warning'] || $status['blocked']): ?>
                        <div class="alert alert-<?= $status['blocked'] ? 'danger' : 'warning' ?> mt-3 mb-0">
                            <i class="bi bi-exclamation-circle"></i> <?= $status['message'] ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Upgrade Options -->
                <?php if (!$status['blocked']): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Add More Capacity</h5>
                    </div>
                    <div class="card-body">
                        <a href="upgrade.php?type=user" class="btn btn-outline-primary w-100 mb-2">
                            <i class="bi bi-person-plus"></i> Add 5 Users (+TZS 500/day)
                        </a>
                        <a href="upgrade.php?type=location" class="btn btn-outline-primary w-100">
                            <i class="bi bi-geo-alt-fill"></i> Add Location (+TZS 500/day)
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Payment Information -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="payment-box mb-4">
                            <h6 class="text-muted mb-2">Pay via Airtel Money</h6>
                            <div class="airtel-number mb-2">
                                <i class="bi bi-phone"></i> <?= PAYMENT_AIRTEL_NUMBER ?>
                            </div>
                            <small class="text-muted">Send payment to this number</small>
                        </div>
                        
                        <h6>Monthly Subscription</h6>
                        <table class="table table-sm">
                            <tr>
                                <td>Base Plan (1 user + 1 location)</td>
                                <td class="text-end">TZS <?= number_format(SUBSCRIPTION_DAILY_RATE * SUBSCRIPTION_DAYS_PER_MONTH) ?></td>
                            </tr>
                            <?php if ($calculation['extra_users'] > 0): ?>
                            <tr>
                                <td>Extra Users (<?= $calculation['extra_users'] ?>)</td>
                                <td class="text-end">TZS <?= number_format($calculation['extra_users'] * SUBSCRIPTION_DAILY_RATE * SUBSCRIPTION_DAYS_PER_MONTH) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($calculation['extra_locations'] > 0): ?>
                            <tr>
                                <td>Extra Locations (<?= $calculation['extra_locations'] ?>)</td>
                                <td class="text-end">TZS <?= number_format($calculation['extra_locations'] * SUBSCRIPTION_DAILY_RATE * SUBSCRIPTION_DAYS_PER_MONTH) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="table-primary fw-bold">
                                <td>Total (Monthly)</td>
                                <td class="text-end"><?= formatSubscriptionAmount($calculation['monthly_amount']) ?></td>
                            </tr>
                        </table>
                        <small class="text-muted">* You can pay for multiple months at once</small>
                        
                        <hr>
                        
                        <h6><i class="bi bi-list-ol"></i> Payment Steps</h6>
                        <ol class="small">
                            <?php foreach ($paymentInfo['instructions'] as $step): ?>
                            <li><?= $step ?></li>
                            <?php endforeach; ?>
                        </ol>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            After payment, your subscription will be activated within 24 hours.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Payment History</h5>
            </div>
            <div class="card-body">
                <?php
                $stmt = $conn->query("SELECT * FROM subscription_payments ORDER BY created_at DESC LIMIT 10");
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <?php if (empty($payments)): ?>
                <p class="text-muted text-center">No payment history yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Period</th>
                                <th>Users</th>
                                <th>Locations</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($payment['payment_date'])) ?></td>
                                <td><?= date('d M Y', strtotime($payment['period_start'])) ?> - <?= date('d M Y', strtotime($payment['period_end'])) ?></td>
                                <td><?= $payment['users_count'] ?></td>
                                <td><?= $payment['locations_count'] ?></td>
                                <td><?= formatSubscriptionAmount($payment['amount']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $payment['status'] === 'confirmed' ? 'success' : ($payment['status'] === 'pending' ? 'warning' : 'danger') ?>">
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
