<?php
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    
    // Log logout activity
    logActivity($user['id'], 'User logged out');
    
    // Clear all session data
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

// Redirect to login page
header("Location: login.php");
exit();
?>