<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

$pageTitle = __('users_management');

if (!hasPermission('users', 'view')) {
    setAlert(__('error'), 'danger');
    redirect('');
}

// Handle Delete
if (isset($_GET['delete']) && hasPermission('users', 'delete')) {
    $id = (int)$_GET['delete'];
    
    // Cannot delete own account
    if ($id == getUserId()) {
        setAlert(__('cannot_delete_own_account'), 'danger');
        redirect('modules/masters/users.php');
    }
    
    // Cannot delete default admin (always protected, plus extra demo protection)
    if ($id == 1) {
        setAlert(__('cannot_delete_admin'), 'danger');
        redirect('modules/masters/users.php');
    }
    
    try {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$id])) {
            // Permissions and locations will be deleted automatically (CASCADE)
            logAudit('users', 'delete', $id);
            setAlert(__('user_deleted'), 'success');
        }
    } catch (PDOException $e) {
        setAlert(__('error_deleting_user'), 'danger');
    }
    
    redirect('modules/masters/users.php');
}

require_once '../../includes/header.php';

// Get all users
$search = $_GET['search'] ?? '';
$page = $_GET['page'] ?? 1;

$whereClause = '';
$params = [];

if ($search) {
    $whereClause = "WHERE u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM users u $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];

$pagination = paginate($totalRecords, $page);

$stmt = $conn->prepare("
    SELECT 
        u.*,
        r.name as role_name,
        (SELECT COUNT(*) FROM user_locations WHERE user_id = u.id) as location_count,
        (SELECT COUNT(*) FROM user_permissions WHERE user_id = u.id) as permission_count
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    $whereClause
    ORDER BY u.id ASC
    LIMIT {$pagination['records_per_page']} OFFSET {$pagination['offset']}
");
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<div class="row mb-3">
    <div class="col-md-6">
        <h2><i class="bi bi-person-badge"></i> <?php _e('users_management'); ?></h2>
    </div>
    <div class="col-md-6 text-end">
        <?php if (hasPermission('users', 'create')): ?>
        <a href="users_form.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> <?php _e('add_new_user'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search Bar -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="" class="row g-2">
            <div class="col-md-8">
                <input type="text" name="search" class="form-control" 
                       placeholder="<?php _e('search_users'); ?>" 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-4">
                <div class="btn-group w-100">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> <?php _e('search'); ?>
                    </button>
                    <?php if ($search): ?>
                    <a href="users.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> <?php _e('clear'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($users)): ?>
        <div class="empty-state">
            <i class="bi bi-person-badge"></i>
            <h5><?php _e('no_users_found'); ?></h5>
            <p><?php _e('start_adding_users'); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover" id="usersTable">
                <thead>
                    <tr>
                        <th><?php _e('sr_no'); ?></th>
                        <th><?php _e('name'); ?></th>
                        <th><?php _e('username'); ?></th>
                        <th><?php _e('email'); ?></th>
                        <th><?php _e('role'); ?></th>
                        <th><?php _e('locations'); ?></th>
                        <th><?php _e('permissions'); ?></th>
                        <th><?php _e('last_login'); ?></th>
                        <th><?php _e('status'); ?></th>
                        <th class="no-export"><?php _e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $srNo = 1; foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo $srNo++; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                            <?php if ($user['id'] == getUserId()): ?>
                            <span class="badge bg-info ms-1"><?php _e('you'); ?></span>
                            <?php endif; ?>
                            <?php if ($user['id'] == 1): ?>
                            <span class="badge bg-warning text-dark ms-1"><?php _e('admin'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                        <td>
                            <span class="badge bg-primary">
                                <?php echo htmlspecialchars($user['role_name']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user['role_name'] == 'Admin'): ?>
                            <span class="badge bg-success"><?php _e('all_locations'); ?></span>
                            <?php else: ?>
                            <span class="badge bg-info"><?php echo sprintf(__('location_count'), $user['location_count']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['role_name'] == 'Admin'): ?>
                            <span class="badge bg-success"><?php _e('full_access'); ?></span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><?php echo sprintf(__('module_count'), $user['permission_count']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            if ($user['last_login']): 
                                echo formatDateTime($user['last_login']);
                            else:
                                echo '<span class="text-muted">' . __('never') . '</span>';
                            endif;
                            ?>
                        </td>
                        <td>
                            <?php if ($user['is_active']): ?>
                                <span class="badge bg-success"><?php _e('active'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?php _e('inactive'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="no-export">
                            <div class="btn-group btn-group-sm">
                                <?php if (hasPermission('users', 'edit')): ?>
                                <a href="users_form.php?_t=<?=time();?>&id=<?php echo $user['id']; ?>" 
                                   class="btn btn-outline-primary" title="<?php _e('edit'); ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (hasPermission('users', 'delete') && $user['id'] != getUserId() && $user['id'] != 1): ?>
                                <a href="?delete=<?php echo $user['id']; ?>" 
                                   class="btn btn-outline-danger confirm-delete" title="<?php _e('delete'); ?>">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php if ($pagination['has_prev']): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $pagination['prev_page']; ?>&search=<?php echo urlencode($search); ?>">
                        <?php _e('previous'); ?>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                <li class="page-item <?php echo $i == $pagination['current_page'] ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <?php if ($pagination['has_next']): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $pagination['next_page']; ?>&search=<?php echo urlencode($search); ?>">
                        <?php _e('next'); ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        
        <!-- Export Button -->
        <div class="text-center mt-3">
            <button onclick="exportTableToCSV('usersTable', 'users.csv')" class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> <?php _e('export_csv'); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
