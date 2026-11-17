<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Handle language switch
if (isset($_GET['lang'])) {
    setLang($_GET['lang']);
}

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('');
}

$error = '';

// One-shot flag set by enforceSessionTimeout() in includes/functions.php, so
// the user is told WHY they landed back here instead of silently losing their
// place. Read once, then cleared.
$sessionExpired = !empty($_SESSION['session_expired']);
unset($_SESSION['session_expired']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = clean($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = __('username_required') . ' / ' . __('password_required');
    } else {
        try {
            $stmt = $conn->prepare("
                SELECT u.*, r.name as role_name 
                FROM users u
                INNER JOIN roles r ON u.role_id = r.id
                WHERE u.username = ? AND u.is_active = 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Set session data
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_data'] = [
                    'name' => $user['name'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role_id' => $user['role_id'],
                    'role_name' => $user['role_name']
                ];
                
                // Update last login
                $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                // Log audit
                logAudit('auth', 'login', $user['id']);
                
                // Redirect to dashboard
                redirect('');
            } else {
                $error = __('login_failed');
                logAudit('auth', 'failed_login', null, ['username' => $username]);
            }
        } catch (PDOException $e) {
            $error = __('error');
            error_log($e->getMessage());
        }
    }
}

$currentLang = getCurrentLang();
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php _e('login'); ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 1rem;
        }
        
        .login-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 1rem 3rem rgba(0,0,0,0.175);
            padding: 2rem;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header .logo {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 0.5rem;
        }
        
        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .login-header p {
            color: #6c757d;
            margin-bottom: 0;
        }
        
        .form-control {
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border-radius: 0.5rem;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-login {
            padding: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 0.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(102, 126, 234, 0.3);
        }
        
        .input-group-text {
            background-color: transparent;
            border-right: none;
        }
        
        .form-control {
            border-left: none;
        }
        
        .form-control:focus + .input-group-text {
            border-color: #667eea;
        }
        
        .lang-switcher {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
        
        .lang-switcher .btn {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
            border-radius: 50px;
        }
        
        .lang-switcher .btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        @media (max-width: 576px) {
            .login-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Language Switcher -->
    <div class="lang-switcher">
        <a href="?lang=en" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'en' ? 'active' : ''; ?>">EN</a>
        <a href="?lang=sw" class="btn btn-outline-light btn-sm <?php echo $currentLang === 'sw' ? 'active' : ''; ?>">SW</a>
    </div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <i class="bi bi-box-seam"></i>
                </div>
                <h1><?php echo APP_NAME; ?></h1>
                <p><?php _e('login_subtitle'); ?></p>
            </div>
            
            <?php if ($sessionExpired): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-clock-history"></i> <?php _e('session_expired_message'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="username" class="form-label"><?php _e('username'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>
                        <input 
                            type="text" 
                            class="form-control" 
                            id="username" 
                            name="username" 
                            placeholder="<?php _e('username'); ?>"
                            value="<?php echo isset($_POST['username']) ? clean($_POST['username']) : ''; ?>"
                            required 
                            autofocus
                        >
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label"><?php _e('password'); ?></label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>
                        <input 
                            type="password" 
                            class="form-control" 
                            id="password" 
                            name="password" 
                            placeholder="<?php _e('password'); ?>"
                            required
                        >
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login">
                    <i class="bi bi-box-arrow-in-right"></i> <?php _e('sign_in'); ?>
                </button>
            </form>
            
            <?php if (defined('DEMO_MODE') && DEMO_MODE): ?>
            <div class="text-center mt-4">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> <?php _e('username'); ?>: admin | <?php _e('password'); ?>: admin123
                </small>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="text-center mt-3">
            <small class="text-white">
                &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>
            </small>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alert = document.querySelector('.alert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 150);
            }
        }, 5000);
    </script>
</body>
</html>
