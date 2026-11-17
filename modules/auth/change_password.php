<?php
$pageTitle = 'Change Password';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        setAlert('All fields are required', 'danger');
    } elseif (strlen($newPassword) < 6) {
        setAlert('New password must be at least 6 characters', 'danger');
    } elseif ($newPassword !== $confirmPassword) {
        setAlert('New password and confirm password do not match', 'danger');
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([getUserId()]);
        $user = $stmt->fetch();
        
        if (password_verify($currentPassword, $user['password'])) {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            
            if ($updateStmt->execute([$hashedPassword, getUserId()])) {
                // Demo protection: Reset user ID 1 password back to admin123
                if (DEMO_MODE && getUserId() == 1) {
                    $demoPassword = password_hash('admin123', PASSWORD_DEFAULT);
                    $conn->prepare("UPDATE users SET password = ? WHERE id = 1")->execute([$demoPassword]);
                }
                logAudit('users', 'password_changed', getUserId());
                setAlert('Password changed successfully', 'success');
                redirect('');
            } else {
                setAlert('Failed to change password', 'danger');
            }
        } else {
            setAlert('Current password is incorrect', 'danger');
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-key"></i> Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label required">Current Password</label>
                        <input 
                            type="password" 
                            class="form-control" 
                            id="current_password" 
                            name="current_password" 
                            required
                        >
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label required">New Password</label>
                        <input 
                            type="password" 
                            class="form-control" 
                            id="new_password" 
                            name="new_password" 
                            minlength="6"
                            required
                        >
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label required">Confirm New Password</label>
                        <input 
                            type="password" 
                            class="form-control" 
                            id="confirm_password" 
                            name="confirm_password" 
                            minlength="6"
                            required
                        >
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Change Password
                        </button>
                        <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
