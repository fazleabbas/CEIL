<?php
/**
 * Subscription Admin Panel (Backend Management)
 * Access: Password protected - only system owner
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/subscription.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Master password for subscription admin (change this!)
define('SUBSCRIPTION_ADMIN_PASSWORD', 'FIS@2026#Admin');

// Check if already authenticated for this session
$isAdminAuthenticated = isset($_SESSION['subscription_admin_auth']) && $_SESSION['subscription_admin_auth'] === true;

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['subscription_admin_auth']);
    header('Location: ' . BASE_URL);
    exit;
}

// Handle password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if ($_POST['admin_password'] === SUBSCRIPTION_ADMIN_PASSWORD) {
        $_SESSION['subscription_admin_auth'] = true;
        $isAdminAuthenticated = true;
    } else {
        $authError = 'Invalid password. Access denied.';
    }
}

// Show password form if not authenticated
if (!$isAdminAuthenticated):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Access - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-dark">
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-4">
                <div class="card shadow">
                    <div class="card-header bg-danger text-white text-center">
                        <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Subscription Admin</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($authError)): ?>
                        <div class="alert alert-danger py-2">
                            <i class="bi bi-x-circle"></i> <?= $authError ?>
                        </div>
                        <?php endif; ?>
                        
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Admin Password</label>
                                <input type="password" name="admin_password" class="form-control" required autofocus placeholder="Enter admin password">
                            </div>
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-unlock"></i> Access Admin Panel
                            </button>
                        </form>
                        
                        <div class="text-center mt-3">
                            <a href="<?= BASE_URL ?>" class="text-muted small">
                                <i class="bi bi-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
exit;
endif;

// --- Admin is authenticated, continue with admin panel ---

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'renew':
            $users = max(1, (int)$_POST['users']);
            $locations = max(1, (int)$_POST['locations']);
            $months = max(1, (int)$_POST['months']);
            $transactionRef = clean($_POST['transaction_ref']);
            
            $result = renewSubscription($conn, $users, $locations, $months, $transactionRef, getUserData('id'));
            if ($result['success']) {
                $message = "Subscription renewed for {$months} month(s)! New subscription ID: {$result['subscription_id']}";
            } else {
                $error = $result['error'];
            }
            break;
            
        case 'confirm_upgrade':
            $upgradeId = (int)$_POST['upgrade_id'];
            $transactionRef = clean($_POST['transaction_ref']);
            
            $result = confirmUpgrade($conn, $upgradeId, $transactionRef, getUserData('id'));
            if ($result['success']) {
                $message = "Upgrade confirmed successfully!";
            } else {
                $error = $result['error'];
            }
            break;
            
        case 'extend':
            $days = max(1, (int)$_POST['days']);
            $stmt = $conn->prepare("UPDATE subscriptions SET end_date = DATE_ADD(end_date, INTERVAL ? DAY) WHERE is_active = 1");
            $stmt->execute([$days]);
            $message = "Subscription extended by {$days} days.";
            break;
            
        case 'update_limits':
            $maxUsers = max(1, (int)$_POST['max_users']);
            $maxLocations = max(1, (int)$_POST['max_locations']);
            $stmt = $conn->prepare("UPDATE subscriptions SET max_users = ?, max_locations = ? WHERE is_active = 1");
            $stmt->execute([$maxUsers, $maxLocations]);
            $message = "Limits updated: {$maxUsers} users, {$maxLocations} locations.";
            break;
    }
}

$status = getSubscriptionStatus($conn);
$pendingUpgrades = getPendingUpgrades($conn);
$calculation = calculateSubscriptionAmount($status['max_users'], $status['max_locations'], 1);

$pageTitle = 'Subscription Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="bi bi-shield-lock"></i> <?= APP_NAME ?> - Subscription Admin
            </a>
            <div>
                <a href="<?= BASE_URL ?>" class="btn btn-outline-light btn-sm me-2">
                    <i class="bi bi-house"></i> Dashboard
                </a>
                <a href="?logout=1" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= clean($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle"></i> <?= clean($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Current Status -->
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Current Status</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <td>Plan</td>
                                <td class="fw-bold"><?= $status['subscription']['plan_name'] ?? 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td>Status</td>
                                <td>
                                    <span class="badge bg-<?= $status['status'] === 'active' ? 'success' : ($status['status'] === 'grace_period' ? 'warning' : 'danger') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $status['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Trial</td>
                                <td><?= $status['is_trial'] ? 'Yes' : 'No' ?></td>
                            </tr>
                            <tr>
                                <td>Start Date</td>
                                <td><?= $status['subscription']['start_date'] ?? 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td>End Date</td>
                                <td><?= $status['end_date'] ?? 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td>Days Remaining</td>
                                <td class="fw-bold text-<?= $status['days_remaining'] <= 7 ? 'danger' : 'success' ?>">
                                    <?= $status['days_remaining'] ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Users</td>
                                <td><?= $status['current_users'] ?> / <?= $status['max_users'] ?></td>
                            </tr>
                            <tr>
                                <td>Locations</td>
                                <td><?= $status['current_locations'] ?> / <?= $status['max_locations'] ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <!-- Extend Subscription -->
                        <form method="post" class="mb-3">
                            <input type="hidden" name="action" value="extend">
                            <label class="form-label">Extend Subscription</label>
                            <div class="input-group">
                                <input type="number" name="days" class="form-control" value="30" min="1">
                                <span class="input-group-text">days</span>
                                <button type="submit" class="btn btn-success">Extend</button>
                            </div>
                        </form>
                        
                        <hr>
                        
                        <!-- Update Limits -->
                        <form method="post">
                            <input type="hidden" name="action" value="update_limits">
                            <label class="form-label">Update Limits</label>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-people"></i></span>
                                        <input type="number" name="max_users" class="form-control" value="<?= $status['max_users'] ?>" min="1">
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                        <input type="number" name="max_locations" class="form-control" value="<?= $status['max_locations'] ?>" min="1">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Update Limits</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- New Subscription -->
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Renew Subscription</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" id="renewForm">
                            <input type="hidden" name="action" value="renew">
                            
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <label class="form-label">Users</label>
                                    <input type="number" name="users" id="renewUsers" class="form-control" value="<?= $status['max_users'] ?>" min="1" onchange="updateRenewAmount()">
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Locations</label>
                                    <input type="number" name="locations" id="renewLocations" class="form-control" value="<?= $status['max_locations'] ?>" min="1" onchange="updateRenewAmount()">
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Months</label>
                                    <select name="months" id="renewMonths" class="form-select" onchange="updateRenewAmount()">
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>"><?= $m ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Transaction Reference</label>
                                <input type="text" name="transaction_ref" class="form-control" required placeholder="e.g., MP240118xxxx">
                            </div>
                            
                            <div class="alert alert-info py-2">
                                <small>
                                    <strong>Monthly:</strong> TZS <span id="monthlyAmount"><?= number_format($calculation['monthly_amount']) ?></span><br>
                                    <strong>Total:</strong> TZS <span id="totalAmount" class="fw-bold"><?= number_format($calculation['total_amount']) ?></span>
                                </small>
                            </div>
                            
                            <button type="submit" class="btn btn-warning w-100" onclick="return confirm('Create new subscription?')">
                                <i class="bi bi-check-circle"></i> Renew Subscription
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Pending Upgrades -->
        <?php if (!empty($pendingUpgrades)): ?>
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Pending Upgrade Requests</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Amount</th>
                                <th>Days</th>
                                <th>Requested</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingUpgrades as $upgrade): ?>
                            <tr>
                                <td><?= $upgrade['id'] ?></td>
                                <td><span class="badge bg-secondary"><?= ucfirst($upgrade['upgrade_type']) ?></span></td>
                                <td><?= $upgrade['quantity'] ?></td>
                                <td><?= formatSubscriptionAmount($upgrade['prorata_amount']) ?></td>
                                <td><?= $upgrade['remaining_days'] ?></td>
                                <td><?= date('d M Y H:i', strtotime($upgrade['created_at'])) ?></td>
                                <td>
                                    <form method="post" class="d-flex gap-1">
                                        <input type="hidden" name="action" value="confirm_upgrade">
                                        <input type="hidden" name="upgrade_id" value="<?= $upgrade['id'] ?>">
                                        <input type="text" name="transaction_ref" class="form-control form-control-sm" placeholder="Txn Ref" required style="width: 120px;">
                                        <button type="submit" class="btn btn-success btn-sm">Confirm</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Payment History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-receipt"></i> Payment History</h5>
            </div>
            <div class="card-body">
                <?php
                $stmt = $conn->query("
                    SELECT p.*, u.name as confirmed_by_name 
                    FROM subscription_payments p 
                    LEFT JOIN users u ON p.confirmed_by = u.id 
                    ORDER BY p.created_at DESC LIMIT 20
                ");
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <?php if (empty($payments)): ?>
                <p class="text-muted text-center">No payment history.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Period</th>
                                <th>Users</th>
                                <th>Locations</th>
                                <th>Amount</th>
                                <th>Ref</th>
                                <th>Status</th>
                                <th>Confirmed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                                <td><?= date('d M Y', strtotime($p['period_start'])) ?> - <?= date('d M Y', strtotime($p['period_end'])) ?></td>
                                <td><?= $p['users_count'] ?></td>
                                <td><?= $p['locations_count'] ?></td>
                                <td><?= formatSubscriptionAmount($p['amount']) ?></td>
                                <td><code><?= clean($p['transaction_ref']) ?></code></td>
                                <td><span class="badge bg-<?= $p['status'] === 'confirmed' ? 'success' : 'warning' ?>"><?= ucfirst($p['status']) ?></span></td>
                                <td><?= clean($p['confirmed_by_name'] ?? '-') ?></td>
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
    <script>
    const DAILY_RATE = <?= SUBSCRIPTION_DAILY_RATE ?>;
    const DAYS_PER_MONTH = <?= SUBSCRIPTION_DAYS_PER_MONTH ?>;
    
    function updateRenewAmount() {
        const users = parseInt(document.getElementById('renewUsers').value) || 1;
        const locations = parseInt(document.getElementById('renewLocations').value) || 1;
        const months = parseInt(document.getElementById('renewMonths').value) || 1;
        
        // Base unit + extra users + extra locations
        const totalUnits = 1 + Math.max(0, users - 1) + Math.max(0, locations - 1);
        const monthly = totalUnits * DAILY_RATE * DAYS_PER_MONTH;
        const total = monthly * months;
        
        document.getElementById('monthlyAmount').textContent = monthly.toLocaleString();
        document.getElementById('totalAmount').textContent = total.toLocaleString();
    }
    </script>
</body>
</html>
