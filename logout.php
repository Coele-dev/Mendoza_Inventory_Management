<?php
// Start a secure user session at the absolute top entry point of the script execution loop
session_start();

// 1. Initialize the session framework
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Unset all session variables
$_SESSION = array();

// 3. Completely destroy the session cookie on the user's browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destroy the session on the server side
session_destroy();

// 5. Redirect securely back to the login screen
header("Location: auth/login.php");
exit;