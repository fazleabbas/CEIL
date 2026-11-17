<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/subscription.php';

$pageTitle = __('user_form');

$isEdit = isset($_GET['id']);
$requiredPermission = $isEdit ? 'edit' : 'create';

if (!hasPermission('users', $requiredPermission)) {
    setAlert('You do not have permission to access this page', 'danger');
    redirect('modules/masters/users.php');
}

// Check subscription limit for new users
if (!$isEdit && !canAddUser($conn)) {
    $status = getSubscriptionStatus($conn);
    setAlert("User limit reached ({$status['current_users']}/{$status['max_users']}). Please upgrade your subscription.", 'warning');
    redirect('modules/subscription/status.php');
}

// Available modules for permissions
$modules = [
    'roles' => __('module_roles'),
    'locations' => __('module_locations'),
    'categories' => __('module_categories'),
    'units' => __('module_units'),
    'products' => __('module_products'),
    'suppliers' => __('module_suppliers'),
    'customers' => __('module_customers'),
    'opening_stocks' => __('module_opening_stocks'),
    'users' => __('module_users'),
    'purchases' => __('module_purchases'),
    'purchase_orders' => __('module_purchase_orders'),
    'sales' => __('module_sales'),
    'payments' => __('module_payments'),
    'receipts' => __('module_receipts'),
    'stock_taking' => __('module_stock_taking'),
    'stock_transfer' => __('module_stock_transfer'),
    'cost_centres' => __('module_cost_centres'),
    'consumption_vouchers' => __('module_consumption_vouchers'),
    'requisition_vouchers' => __('module_requisition_vouchers'),
    'budget' => __('module_budget'),
    'boq' => __('module_boq'),
    'bom' => __('module_bom'),
    'purchase_imports' => __('module_purchase_imports'),
    'journal_vouchers' => __('module_journal_vouchers')
];

// NOTE: the signature upload validator that used to live here is now
// processSignatureUpload() in includes/functions.php, shared with the
// self-service page modules/auth/my_signature.php. Behaviour is unchanged.

$user = [
    'name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'role_id' => '',
    'is_active' => 1,
    'signature' => null
];
$errors = [];
$userPermissions = [];
$userLocations = [];
$userCostCentres = [];

if ($isEdit) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        setAlert('User not found', 'danger');
        redirect('modules/masters/users.php');
    }
    
    // Get user permissions
    $permStmt = $conn->prepare("SELECT module_name, can_view, can_create, can_edit, can_delete, can_import, can_approve, can_verify FROM user_permissions WHERE user_id = ?");
    $permStmt->execute([$id]);
    while ($perm = $permStmt->fetch()) {
        $userPermissions[$perm['module_name']] = [
            'view' => $perm['can_view'],
            'create' => $perm['can_create'],
            'edit' => $perm['can_edit'],
            'delete' => $perm['can_delete'],
            'import' => $perm['can_import'] ?? 0,
            'approve' => $perm['can_approve'] ?? 0,
            'verify' => $perm['can_verify'] ?? 0
        ];
    }

    // Before migration 0050 a user may still hold the old single Reports right.
    // Show it as every report ticked, so saving this form keeps their access.
    if (!empty($userPermissions['reports']['view'])) {
        foreach (array_keys(getReportRights()) as $reportKey) {
            $userPermissions['report_' . $reportKey]['view'] = 1;
        }
    }
    
    // Get user locations
    $locStmt = $conn->prepare("SELECT location_id FROM user_locations WHERE user_id = ?");
    $locStmt->execute([$id]);
    $userLocations = $locStmt->fetchAll(PDO::FETCH_COLUMN);

    // Get user cost centres
    $ccStmt = $conn->prepare("SELECT cost_centre_id FROM user_cost_centres WHERE user_id = ?");
    $ccStmt->execute([$id]);
    $userCostCentres = $ccStmt->fetchAll(PDO::FETCH_COLUMN);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    checkCSRF();
    
    $name = clean($_POST['name']);
    $username = clean($_POST['username']);
    $email = clean($_POST['email']);
    $phone = clean($_POST['phone']);
    $roleId = (int)$_POST['role_id'];
    $password = $_POST['password'] ?? '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    // Demo protection: Prevent deactivating user ID 1
    if (DEMO_MODE && $isEdit && $id == 1) {
        $isActive = 1;
    }
    $permissions = $_POST['permissions'] ?? [];
    $locations = $_POST['locations'] ?? [];
    $costCentres = $_POST['cost_centres'] ?? [];

    // Signature: only touched when a new file is uploaded or Remove is ticked.
    // Otherwise the stored one is left exactly as it is — same "never wipe an
    // existing signature by accident" rule the voucher forms follow.
    $signatureAction = 'keep';
    $signatureValue  = null;
    list($uploadedSig, $sigError) = processSignatureUpload($_FILES['signature_file'] ?? null);
    if ($sigError !== null) {
        $errors[] = $sigError;
    } elseif ($uploadedSig !== null) {
        $signatureAction = 'set';
        $signatureValue  = $uploadedSig;
    } elseif (!empty($_POST['remove_signature'])) {
        $signatureAction = 'clear';
    }

    // Validation
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    
    if (empty($username)) {
        $errors[] = 'Username is required';
    }
    
    if (empty($roleId)) {
        $errors[] = 'Please select a role';
    }
    
    // Password validation (only for new users or if password is provided)
    if (!$isEdit && empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    // Email validation
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    // Check duplicate username
    if (empty($errors)) {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $checkStmt->execute([$username, $isEdit ? $id : 0]);
        if ($checkStmt->fetch()) {
            $errors[] = 'Username already exists';
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            if ($isEdit) {
                // Update user
                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    // Demo protection: Don't allow changing user ID 1 password
                    if (DEMO_MODE && $id == 1) {
                        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
                    }
                    $stmt = $conn->prepare("
                        UPDATE users 
                        SET name = ?, username = ?, email = ?, phone = ?, 
                            role_id = ?, password = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $username, $email, $phone, $roleId, $hashedPassword, $isActive, $id]);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE users 
                        SET name = ?, username = ?, email = ?, phone = ?, 
                            role_id = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $username, $email, $phone, $roleId, $isActive, $id]);
                }
                
                // Delete existing permissions, locations and cost centres
                $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$id]);
                $conn->prepare("DELETE FROM user_locations WHERE user_id = ?")->execute([$id]);
                $conn->prepare("DELETE FROM user_cost_centres WHERE user_id = ?")->execute([$id]);
                
                $userId = $id;
                logAudit('users', 'update', $id);
                $message = 'User updated successfully';
            } else {
                // Create user
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                    INSERT INTO users (name, username, email, phone, role_id, password, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $username, $email, $phone, $roleId, $hashedPassword, $isActive]);
                
                $userId = $conn->lastInsertId();
                logAudit('users', 'create', $userId);
                $message = 'User created successfully';
            }
            
            // Signature is written separately so the INSERT/UPDATE branches
            // above stay untouched. Guarded: a tenant that has not run
            // migration 0041 yet just skips it instead of failing the save.
            if ($signatureAction !== 'keep') {
                try {
                    $sigStmt = $conn->prepare("UPDATE users SET signature = ? WHERE id = ?");
                    $sigStmt->execute([$signatureAction === 'set' ? $signatureValue : null, $userId]);
                } catch (PDOException $e) {
                    // No signature column on this tenant — nothing to store.
                }
            }

            // Insert permissions (only if not Admin)
            $roleStmt = $conn->prepare("SELECT name FROM roles WHERE id = ?");
            $roleStmt->execute([$roleId]);
            $roleName = $roleStmt->fetchColumn();
            
            if ($roleName != 'Admin' && !empty($permissions)) {
                $permStmt = $conn->prepare("
                    INSERT INTO user_permissions (user_id, module_name, can_view, can_create, can_edit, can_delete, can_import, can_approve, can_verify)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($permissions as $module => $actions) {
                    $permStmt->execute([
                        $userId,
                        $module,
                        isset($actions['view']) ? 1 : 0,
                        isset($actions['create']) ? 1 : 0,
                        isset($actions['edit']) ? 1 : 0,
                        isset($actions['delete']) ? 1 : 0,
                        isset($actions['import']) ? 1 : 0,
                        isset($actions['approve']) ? 1 : 0,
                        isset($actions['verify']) ? 1 : 0
                    ]);
                }
            }
            
            // Insert locations (only if not Admin)
            if ($roleName != 'Admin' && !empty($locations)) {
                $locStmt = $conn->prepare("INSERT INTO user_locations (user_id, location_id) VALUES (?, ?)");
                foreach ($locations as $locationId) {
                    $locStmt->execute([$userId, $locationId]);
                }
            }

            // Insert cost centres (only if not Admin)
            if ($roleName != 'Admin' && !empty($costCentres)) {
                $ccStmt = $conn->prepare("INSERT INTO user_cost_centres (user_id, cost_centre_id) VALUES (?, ?)");
                foreach ($costCentres as $costCentreId) {
                    $ccStmt->execute([$userId, $costCentreId]);
                }
            }

            $conn->commit();
            setAlert($message, 'success');
            redirect('modules/masters/users.php?_t=' . time());
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get roles
$rolesStmt = $conn->query("SELECT id, name FROM roles WHERE is_active = 1 ORDER BY name");
$roles = $rolesStmt->fetchAll();

// Get locations
$locationsStmt = $conn->query("SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name");
$locations = $locationsStmt->fetchAll();

// Get cost centres
$costCentresStmt = $conn->query("SELECT id, code, name FROM cost_centres WHERE is_active = 1 ORDER BY name");
$allCostCentres = $costCentresStmt->fetchAll();

require_once '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-person-badge"></i>
                    <?php echo $isEdit ? __('edit_user') : __('add_new_user'); ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    
                    <!-- Basic Information -->
                    <h6 class="border-bottom pb-2 mb-3"><?php _e('basic_information'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label required"><?php _e('full_name'); ?></label>
                            <input 
                                type="text" 
                                class="form-control" 
                                id="name" 
                                name="name" 
                                value="<?php echo htmlspecialchars($user['name']); ?>"
                                required
                                autofocus
                            >
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label required"><?php _e('username'); ?></label>
                            <input 
                                type="text" 
                                class="form-control" 
                                id="username" 
                                name="username" 
                                value="<?php echo htmlspecialchars($user['username']); ?>"
                                required
                            >
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label"><?php _e('email'); ?></label>
                            <input 
                                type="email" 
                                class="form-control" 
                                id="email" 
                                name="email" 
                                value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                            >
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label"><?php _e('phone'); ?></label>
                            <input 
                                type="tel" 
                                class="form-control" 
                                id="phone" 
                                name="phone" 
                                value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                            >
                        </div>
                    </div>
                    
                    <!-- Role & Password -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('role_and_security'); ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="role_id" class="form-label required"><?php _e('role'); ?></label>
                            <select class="form-select" id="role_id" name="role_id" required onchange="togglePermissions()">
                                <option value=""><?php _e('select_role'); ?></option>
                                <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>"
                                        data-role-name="<?php echo $role['name']; ?>"
                                        <?php echo $user['role_id'] == $role['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label <?php echo !$isEdit ? 'required' : ''; ?>">
                                <?php echo $isEdit ? __('password_leave_blank') : __('password'); ?>
                            </label>
                            <input 
                                type="password" 
                                class="form-control" 
                                id="password" 
                                name="password" 
                                minlength="6"
                                <?php echo !$isEdit ? 'required' : ''; ?>
                            >
                            <small class="text-muted"><?php _e('password_min_chars'); ?></small>
                        </div>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input 
                            type="checkbox" 
                            class="form-check-input" 
                            id="is_active" 
                            name="is_active" 
                            <?php echo $user['is_active'] ? 'checked' : ''; ?>
                        >
                        <label class="form-check-label" for="is_active">
                            <?php _e('active'); ?>
                        </label>
                    </div>

                    <!-- Signature on file -->
                    <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('signature'); ?></h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <?php $currentSig = $user['signature'] ?? null; ?>
                            <div class="border rounded p-2 mb-2 text-center bg-light" style="min-height: 90px;">
                                <?php if (!empty($currentSig)): ?>
                                    <img src="<?php echo htmlspecialchars($currentSig); ?>"
                                         alt="<?php _e('signature'); ?>"
                                         style="max-height: 80px; max-width: 100%;">
                                <?php else: ?>
                                    <span class="text-muted fst-italic d-block py-4"><?php _e('no_signature_on_file'); ?></span>
                                <?php endif; ?>
                            </div>
                            <input type="file" class="form-control" id="signature_file" name="signature_file"
                                   accept="image/png,image/jpeg">
                            <small class="text-muted"><?php _e('signature_upload_hint'); ?></small>

                            <?php if (!empty($currentSig)): ?>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="remove_signature" name="remove_signature" value="1">
                                <label class="form-check-label" for="remove_signature"><?php _e('remove_signature'); ?></label>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="alert alert-info small mb-0">
                                <i class="bi bi-info-circle"></i> <?php _e('signature_master_note'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Permissions Matrix -->
                    <div id="permissionsSection">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('module_permissions'); ?></h6>
                        <p class="text-muted small">
                            <i class="bi bi-info-circle"></i> <?php _e('admin_full_access_note'); ?>
                        </p>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;"><?php _e('module'); ?></th>
                                        <th class="text-center" style="width: 10%;">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input check-all" data-action="view"> <?php _e('view'); ?>
                                            </label>
                                        </th>
                                        <th class="text-center" style="width: 10%;">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input check-all" data-action="create"> <?php _e('create'); ?>
                                            </label>
                                        </th>
                                        <th class="text-center" style="width: 10%;">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input check-all" data-action="edit"> <?php _e('edit'); ?>
                                            </label>
                                        </th>
                                        <th class="text-center" style="width: 10%;">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input check-all" data-action="delete"> <?php _e('delete'); ?>
                                            </label>
                                        </th>
                                        <th class="text-center" style="width: 10%;">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input check-all" data-action="import"> <?php _e('import'); ?>
                                            </label>
                                        </th>
                                        <th class="text-center" style="width: 9%;">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input check-all" data-action="approve"> <?php _e('approve'); ?>
                                            </label>
                                        </th>
                                        <th class="text-center" style="width: 9%;">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input check-all" data-action="verify"> <?php _e('verify', 'Verify'); ?>
                                            </label>
                                        </th>
                                        <th class="text-center" style="width: 7%;">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()"><?php _e('all'); ?></button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($modules as $module => $label): ?>
                                    <tr>
                                        <td><strong><?php echo $label; ?></strong></td>
                                        <?php foreach (['view', 'create', 'edit', 'delete', 'import', 'approve', 'verify'] as $action): ?>
                                        <td class="text-center">
                                            <input 
                                                type="checkbox" 
                                                class="form-check-input permission-check"
                                                name="permissions[<?php echo $module; ?>][<?php echo $action; ?>]"
                                                data-action="<?php echo $action; ?>"
                                                <?php echo isset($userPermissions[$module][$action]) && $userPermissions[$module][$action] ? 'checked' : ''; ?>
                                            >
                                        </td>
                                        <?php endforeach; ?>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-select-row" onclick="selectRow(this)">
                                                <i class="bi bi-check-all"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-light">
                                        <td colspan="9"><strong><?php _e('module_reports'); ?></strong></td>
                                    </tr>
                                    <?php foreach (getReportRights() as $reportKey => $reportLabelKey):
                                        $reportModule = 'report_' . $reportKey;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php _e($reportLabelKey); ?></strong>
                                            <?php if ($reportKey === 'stock_report'): ?>
                                            <div class="text-muted small"><?php _e('report_right_stock_report_includes'); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <input
                                                type="checkbox"
                                                class="form-check-input permission-check"
                                                name="permissions[<?php echo $reportModule; ?>][view]"
                                                data-action="view"
                                                <?php echo !empty($userPermissions[$reportModule]['view']) ? 'checked' : ''; ?>
                                            >
                                        </td>
                                        <td colspan="6"></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-select-row" onclick="selectRow(this)">
                                                <i class="bi bi-check-all"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Location Access -->
                    <div id="locationsSection">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('location_access'); ?></h6>
                        <p class="text-muted small">
                            <i class="bi bi-info-circle"></i> <?php _e('admin_locations_note'); ?>
                        </p>

                        <?php if (!empty($locations)): ?>
                        <div class="row g-2 mb-2 align-items-center">
                            <div class="col-md-5">
                                <input type="text" class="form-control form-control-sm" id="search_locations" placeholder="Search locations by name...">
                            </div>
                            <div class="col-md-4">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="show_selected_locations">
                                    <label class="form-check-label" for="show_selected_locations">Show selected only</label>
                                </div>
                            </div>
                            <div class="col-md-3 text-md-end">
                                <span class="badge bg-secondary" id="count_locations"></span>
                            </div>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="select_all_locations">
                            <label class="form-check-label fw-bold" for="select_all_locations">
                                Select All Locations <small class="text-muted fw-normal">(applies to filtered list)</small>
                            </label>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <?php foreach ($locations as $location): ?>
                            <div class="col-md-4 mb-2 location-item" data-search="<?php echo htmlspecialchars(strtolower($location['name'])); ?>">
                                <div class="form-check">
                                    <input 
                                        class="form-check-input location-check" 
                                        type="checkbox" 
                                        name="locations[]" 
                                        value="<?php echo $location['id']; ?>"
                                        id="location_<?php echo $location['id']; ?>"
                                        <?php echo in_array($location['id'], $userLocations) ? 'checked' : ''; ?>
                                    >
                                    <label class="form-check-label" for="location_<?php echo $location['id']; ?>">
                                        <?php echo htmlspecialchars($location['name']); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (empty($locations)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <?php _e('no_locations_warning'); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Cost Centre Access -->
                    <div id="costCentresSection">
                        <h6 class="border-bottom pb-2 mb-3 mt-4"><?php _e('cost_centre_access'); ?></h6>
                        <p class="text-muted small">
                            <i class="bi bi-info-circle"></i> <?php _e('admin_cost_centres_note'); ?>
                        </p>

                        <?php if (!empty($allCostCentres)): ?>
                        <div class="row g-2 mb-2 align-items-center">
                            <div class="col-md-5">
                                <input type="text" class="form-control form-control-sm" id="search_cost_centres" placeholder="Search cost centres by code or name...">
                            </div>
                            <div class="col-md-4">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="show_selected_cost_centres">
                                    <label class="form-check-label" for="show_selected_cost_centres">Show selected only</label>
                                </div>
                            </div>
                            <div class="col-md-3 text-md-end">
                                <span class="badge bg-secondary" id="count_cost_centres"></span>
                            </div>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="select_all_cost_centres">
                            <label class="form-check-label fw-bold" for="select_all_cost_centres">
                                Select All Cost Centres <small class="text-muted fw-normal">(applies to filtered list)</small>
                            </label>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <?php foreach ($allCostCentres as $cc): ?>
                            <div class="col-md-4 mb-2 cost-centre-item" data-search="<?php echo htmlspecialchars(strtolower($cc['code'] . ' ' . $cc['name'])); ?>">
                                <div class="form-check">
                                    <input
                                        class="form-check-input cost-centre-check"
                                        type="checkbox"
                                        name="cost_centres[]"
                                        value="<?php echo $cc['id']; ?>"
                                        id="cost_centre_<?php echo $cc['id']; ?>"
                                        <?php echo in_array($cc['id'], $userCostCentres) ? 'checked' : ''; ?>
                                    >
                                    <label class="form-check-label" for="cost_centre_<?php echo $cc['id']; ?>">
                                        <strong><?php echo htmlspecialchars($cc['code']); ?></strong> - <?php echo htmlspecialchars($cc['name']); ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (empty($allCostCentres)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <?php _e('no_cost_centres_warning'); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i>
                            <?php echo $isEdit ? __('update_user') : __('create_user'); ?>
                        </button>
                        <a href="users.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> <?php _e('cancel'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle permissions and locations based on role
function togglePermissions() {
    const roleSelect = document.getElementById('role_id');
    const selectedOption = roleSelect.options[roleSelect.selectedIndex];
    const roleName = selectedOption.dataset.roleName;
    
    const permissionsSection = document.getElementById('permissionsSection');
    const locationsSection = document.getElementById('locationsSection');
    const costCentresSection = document.getElementById('costCentresSection');

    if (roleName === 'Admin') {
        permissionsSection.style.display = 'none';
        locationsSection.style.display = 'none';
        if (costCentresSection) costCentresSection.style.display = 'none';

        // Uncheck all permissions, locations and cost centres for admin
        document.querySelectorAll('.permission-check').forEach(cb => cb.checked = false);
        document.querySelectorAll('.location-check').forEach(cb => cb.checked = false);
        document.querySelectorAll('.cost-centre-check').forEach(cb => cb.checked = false);

        const selAllLoc = document.getElementById('select_all_locations');
        const selAllCc = document.getElementById('select_all_cost_centres');
        if (selAllLoc) selAllLoc.checked = false;
        if (selAllCc) selAllCc.checked = false;
    } else {
        permissionsSection.style.display = 'block';
        locationsSection.style.display = 'block';
        if (costCentresSection) costCentresSection.style.display = 'block';
    }
}

// Wire a searchable, filterable access group (search box + "show selected only"
// toggle + live count + filter-aware "select all").
function setupAccessGroup(cfg) {
    const searchInput = document.getElementById(cfg.searchId);
    const selectedOnly = document.getElementById(cfg.selectedOnlyId);
    const countEl = document.getElementById(cfg.countId);
    const master = document.getElementById(cfg.masterId);
    const items = Array.from(document.querySelectorAll(cfg.itemSelector));
    const checks = Array.from(document.querySelectorAll(cfg.checkSelector));
    if (checks.length === 0) return;
    const total = checks.length;

    // Checkboxes whose item row is currently visible (not filtered out)
    const visibleChecks = () => items
        .filter(it => it.style.display !== 'none')
        .map(it => it.querySelector('input[type="checkbox"]'))
        .filter(Boolean);

    const updateCount = () => {
        if (!countEl) return;
        const selected = checks.filter(cb => cb.checked).length;
        countEl.textContent = selected + ' selected of ' + total;
    };

    const syncMaster = () => {
        if (!master) return;
        const vis = visibleChecks();
        master.checked = vis.length > 0 && vis.every(cb => cb.checked);
    };

    const applyFilter = () => {
        const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const selOnly = selectedOnly ? selectedOnly.checked : false;
        items.forEach(it => {
            const cb = it.querySelector('input[type="checkbox"]');
            const text = it.getAttribute('data-search') || '';
            const matchesSearch = !q || text.indexOf(q) !== -1;
            const matchesSelected = !selOnly || (cb && cb.checked);
            it.style.display = (matchesSearch && matchesSelected) ? '' : 'none';
        });
        syncMaster();
    };

    if (searchInput) searchInput.addEventListener('input', applyFilter);
    if (selectedOnly) selectedOnly.addEventListener('change', applyFilter);

    if (master) {
        master.addEventListener('change', function() {
            visibleChecks().forEach(cb => cb.checked = master.checked);
            updateCount();
        });
    }

    checks.forEach(cb => cb.addEventListener('change', function() {
        updateCount();
        syncMaster();
        // In "show selected only" mode, unchecking should immediately hide the row
        if (selectedOnly && selectedOnly.checked) applyFilter();
    }));

    // Initial state on page load (handles edit mode where some are pre-checked)
    updateCount();
    applyFilter();
}

// Select all checkboxes in a row
function selectRow(btn) {
    const row = btn.closest('tr');
    const checkboxes = row.querySelectorAll('.permission-check');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(cb => cb.checked = !allChecked);
}

// Select all permissions for a specific action
document.querySelectorAll('.check-all').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const action = this.dataset.action;
        const checked = this.checked;
        
        document.querySelectorAll(`.permission-check[data-action="${action}"]`).forEach(cb => {
            cb.checked = checked;
        });
    });
});

// Select all checkboxes
function selectAll() {
    const allChecked = Array.from(document.querySelectorAll('.permission-check')).every(cb => cb.checked);
    document.querySelectorAll('.permission-check').forEach(cb => cb.checked = !allChecked);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    togglePermissions();
    setupAccessGroup({
        searchId: 'search_locations',
        selectedOnlyId: 'show_selected_locations',
        countId: 'count_locations',
        masterId: 'select_all_locations',
        itemSelector: '.location-item',
        checkSelector: '.location-check'
    });
    setupAccessGroup({
        searchId: 'search_cost_centres',
        selectedOnlyId: 'show_selected_cost_centres',
        countId: 'count_cost_centres',
        masterId: 'select_all_cost_centres',
        itemSelector: '.cost-centre-item',
        checkSelector: '.cost-centre-check'
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
