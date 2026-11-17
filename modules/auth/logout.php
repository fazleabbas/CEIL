<?php
/**
 * Sign out.
 *
 * ?expired=1 marks this as an automatic idle logout (sent by the client-side
 * timer in includes/header.php) rather than the user clicking Logout, so the
 * login page can say which one it was.
 */
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$wasIdleLogout = isset($_GET['expired']) && $_GET['expired'] === '1';

// Log audit before destroying session
if (isLoggedIn()) {
    logAudit('auth', $wasIdleLogout ? 'session_timeout' : 'logout', getUserId());
}

// NOTE: no session_start() here. includes/functions.php already started the
// session, and calling it again emits "A session had already been started" —
// which, with display_errors=1 (config/database.php), prints BEFORE the
// Location header below and can break the redirect outright.
session_unset();

// Drop the cookie too, otherwise the browser keeps presenting the dead session
// id and PHP just hands it a fresh empty session under the same id.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}

session_destroy();

// A brand-new session purely to carry the one-shot notice to the login page.
session_start();
session_regenerate_id(true);
if ($wasIdleLogout) {
    $_SESSION['session_expired'] = 1;
}

redirect('modules/auth/login.php');
