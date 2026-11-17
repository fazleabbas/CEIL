<?php
/**
 * Subscription Management Functions
 */

// Subscription constants
define('SUBSCRIPTION_DAILY_RATE', 500); // TZS per user/location per day
define('SUBSCRIPTION_DEFAULT_USERS', 5); // Default users included in base price
define('SUBSCRIPTION_TRIAL_DAYS', 14);
define('SUBSCRIPTION_GRACE_DAYS', 5);
define('SUBSCRIPTION_DAYS_PER_MONTH', 30);
define('PAYMENT_AIRTEL_NUMBER', '0786 110 021');
define('PAYMENT_AIRTEL_NAME', 'Airtel Money');

/**
 * Check if current user is subscription admin
 * Admin = user with ID 1 or role_id 1 (first user/admin role)
 */
function isSubscriptionAdmin() {
    $userId = getUserData('id');
    $roleId = getUserData('role_id');
    return $userId == 1 || $roleId == 1;
}

/**
 * Get current subscription details
 */
function getSubscription($conn) {
    $stmt = $conn->query("SELECT * FROM subscriptions WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get subscription status with calculated fields
 */
function getSubscriptionStatus($conn) {
    $subscription = getSubscription($conn);
    
    if (!$subscription) {
        return [
            'valid' => false,
            'status' => 'no_subscription',
            'message' => 'No active subscription found',
            'blocked' => true
        ];
    }
    
    $today = new DateTime();
    $endDate = new DateTime($subscription['end_date']);
    $graceEndDate = clone $endDate;
    $graceEndDate->modify('+' . $subscription['grace_days'] . ' days');
    
    $daysRemaining = (int) $today->diff($endDate)->format('%R%a');
    $graceDaysRemaining = (int) $today->diff($graceEndDate)->format('%R%a');
    
    $status = [
        'subscription' => $subscription,
        'days_remaining' => $daysRemaining,
        'grace_days_remaining' => $graceDaysRemaining,
        'end_date' => $subscription['end_date'],
        'grace_end_date' => $graceEndDate->format('Y-m-d'),
        'is_trial' => (bool) $subscription['is_trial'],
        'max_users' => (int) $subscription['max_users'],
        'max_locations' => (int) $subscription['max_locations'],
    ];
    
    // Check current usage
    $status['current_users'] = getCurrentUserCount($conn);
    $status['current_locations'] = getCurrentLocationCount($conn);
    
    if ($daysRemaining >= 0) {
        $status['valid'] = true;
        $status['status'] = 'active';
        $status['blocked'] = false;
        
        if ($daysRemaining <= 7) {
            $status['message'] = "Subscription expires in {$daysRemaining} days. Please renew.";
            $status['warning'] = true;
        } else {
            $status['message'] = "Subscription active. {$daysRemaining} days remaining.";
            $status['warning'] = false;
        }
    } elseif ($graceDaysRemaining >= 0) {
        $status['valid'] = true;
        $status['status'] = 'grace_period';
        $status['blocked'] = false;
        $status['warning'] = true;
        $status['message'] = "GRACE PERIOD: {$graceDaysRemaining} days left. Pay immediately to avoid service interruption.";
    } else {
        $status['valid'] = false;
        $status['status'] = 'expired';
        $status['blocked'] = true;
        $status['message'] = "Subscription expired. Please renew to continue using the system.";
    }
    
    return $status;
}

/**
 * Check if system is blocked due to subscription
 */
function isSubscriptionBlocked($conn) {
    $status = getSubscriptionStatus($conn);
    return $status['blocked'];
}

/**
 * Get current active user count
 */
function getCurrentUserCount($conn) {
    $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    return (int) $stmt->fetchColumn();
}

/**
 * Get current active location count
 */
function getCurrentLocationCount($conn) {
    $stmt = $conn->query("SELECT COUNT(*) FROM locations WHERE is_active = 1");
    return (int) $stmt->fetchColumn();
}

/**
 * Check if can add more users
 */
function canAddUser($conn) {
    $status = getSubscriptionStatus($conn);
    return $status['current_users'] < $status['max_users'];
}

/**
 * Check if can add more locations
 */
function canAddLocation($conn) {
    $status = getSubscriptionStatus($conn);
    return $status['current_locations'] < $status['max_locations'];
}

/**
 * Calculate pro-rata amount for mid-subscription upgrade
 */
function calculateProRata($conn, $additionalUnits = 1) {
    $subscription = getSubscription($conn);
    if (!$subscription) {
        return ['error' => 'No active subscription'];
    }
    
    $today = new DateTime();
    $endDate = new DateTime($subscription['end_date']);
    $remainingDays = max(0, (int) $today->diff($endDate)->format('%a'));
    
    $dailyRate = $subscription['daily_rate'];
    $proRataAmount = $remainingDays * $dailyRate * $additionalUnits;
    
    return [
        'remaining_days' => $remainingDays,
        'daily_rate' => $dailyRate,
        'additional_units' => $additionalUnits,
        'prorata_amount' => $proRataAmount,
        'end_date' => $subscription['end_date']
    ];
}

/**
 * Calculate subscription amount for given months
 * Base price includes 1 location + 5 users
 * Additional locations: 500/day each
 * Additional users: 500/day per 5 users
 */
function calculateSubscriptionAmount($users = 5, $locations = 1, $months = 1) {
    // Base covers 1 location + 5 users (1 unit = 500/day = 15,000/month)
    $baseUnits = 1;
    $extraUserBlocks = max(0, floor(($users - SUBSCRIPTION_DEFAULT_USERS) / 5)); // Each 5 users = 1 unit
    $extraLocations = max(0, $locations - 1);
    
    $totalUnits = $baseUnits + $extraUserBlocks + $extraLocations;
    $monthlyAmount = $totalUnits * SUBSCRIPTION_DAILY_RATE * SUBSCRIPTION_DAYS_PER_MONTH;
    $totalAmount = $monthlyAmount * $months;
    
    return [
        'users' => $users,
        'locations' => $locations,
        'months' => $months,
        'base_units' => $baseUnits,
        'default_users' => SUBSCRIPTION_DEFAULT_USERS,
        'extra_user_blocks' => $extraUserBlocks,
        'extra_locations' => $extraLocations,
        'total_units' => $totalUnits,
        'daily_rate' => SUBSCRIPTION_DAILY_RATE,
        'daily_total' => $totalUnits * SUBSCRIPTION_DAILY_RATE,
        'monthly_amount' => $monthlyAmount,
        'total_amount' => $totalAmount
    ];
}

/**
 * Create upgrade request
 */
function createUpgradeRequest($conn, $type, $quantity = 1) {
    $subscription = getSubscription($conn);
    if (!$subscription) {
        return ['success' => false, 'error' => 'No active subscription'];
    }
    
    $prorata = calculateProRata($conn, $quantity);
    
    $stmt = $conn->prepare("
        INSERT INTO subscription_upgrades 
        (subscription_id, upgrade_type, quantity, prorata_amount, remaining_days)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $subscription['id'],
        $type,
        $quantity,
        $prorata['prorata_amount'],
        $prorata['remaining_days']
    ]);
    
    return [
        'success' => true,
        'upgrade_id' => $conn->lastInsertId(),
        'prorata' => $prorata
    ];
}

/**
 * Get payment instructions
 */
function getPaymentInstructions($amount) {
    return [
        'method' => PAYMENT_AIRTEL_NAME,
        'number' => PAYMENT_AIRTEL_NUMBER,
        'amount' => $amount,
        'instructions' => [
            "1. Dial *150*60#",
            "2. Select 'Send Money'",
            "3. Enter number: " . PAYMENT_AIRTEL_NUMBER,
            "4. Enter amount: TZS " . number_format($amount),
            "5. Confirm the transaction",
            "6. Save the transaction reference",
            "7. Submit the reference in the system for confirmation"
        ]
    ];
}

/**
 * Format subscription amount for display
 */
function formatSubscriptionAmount($amount) {
    return 'TZS ' . number_format($amount, 0);
}

/**
 * Enforce subscription check - call this on protected pages
 */
function enforceSubscription($conn) {
    $status = getSubscriptionStatus($conn);
    
    if ($status['blocked']) {
        // Redirect to subscription page
        header('Location: ' . BASE_URL . 'modules/subscription/status.php?blocked=1');
        exit;
    }
    
    return $status;
}

/**
 * Get pending upgrade requests
 */
function getPendingUpgrades($conn) {
    $stmt = $conn->query("
        SELECT * FROM subscription_upgrades 
        WHERE payment_status = 'pending' 
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Confirm upgrade request (admin function)
 */
function confirmUpgrade($conn, $upgradeId, $transactionRef, $confirmedBy) {
    $conn->beginTransaction();
    
    try {
        // Get upgrade details
        $stmt = $conn->prepare("SELECT * FROM subscription_upgrades WHERE id = ?");
        $stmt->execute([$upgradeId]);
        $upgrade = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$upgrade) {
            throw new Exception('Upgrade request not found');
        }
        
        // Update upgrade status
        $stmt = $conn->prepare("
            UPDATE subscription_upgrades 
            SET payment_status = 'confirmed', transaction_ref = ?, confirmed_by = ?, confirmed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$transactionRef, $confirmedBy, $upgradeId]);
        
        // Update subscription limits
        $field = $upgrade['upgrade_type'] === 'user' ? 'max_users' : 'max_locations';
        $stmt = $conn->prepare("
            UPDATE subscriptions 
            SET {$field} = {$field} + ? 
            WHERE id = ?
        ");
        $stmt->execute([$upgrade['quantity'], $upgrade['subscription_id']]);
        
        $conn->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        $conn->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Renew subscription (admin function)
 * @param int $months Number of months to subscribe
 */
function renewSubscription($conn, $users, $locations, $months, $transactionRef, $confirmedBy) {
    $conn->beginTransaction();
    
    try {
        $calculation = calculateSubscriptionAmount($users, $locations, $months);
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$months} months"));
        
        // Deactivate old subscriptions
        $conn->exec("UPDATE subscriptions SET is_active = 0");
        
        // Create new subscription
        $stmt = $conn->prepare("
            INSERT INTO subscriptions 
            (plan_name, max_users, max_locations, daily_rate, start_date, end_date, is_trial, is_active)
            VALUES ('Standard', ?, ?, ?, ?, ?, 0, 1)
        ");
        $stmt->execute([
            $users,
            $locations,
            SUBSCRIPTION_DAILY_RATE,
            $startDate,
            $endDate
        ]);
        $subscriptionId = $conn->lastInsertId();
        
        // Record payment
        $stmt = $conn->prepare("
            INSERT INTO subscription_payments 
            (subscription_id, amount, transaction_ref, payment_date, period_start, period_end, 
             users_count, locations_count, confirmed_by, confirmed_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'confirmed')
        ");
        $stmt->execute([
            $subscriptionId,
            $calculation['total_amount'],
            $transactionRef,
            $startDate,
            $startDate,
            $endDate,
            $users,
            $locations,
            $confirmedBy
        ]);
        
        $conn->commit();
        return ['success' => true, 'subscription_id' => $subscriptionId];
        
    } catch (Exception $e) {
        $conn->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
