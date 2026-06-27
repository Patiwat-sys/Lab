<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (currentUser()) {
    logActivity('LOGOUT', 'auth', 'User signed out');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

session_start();
flash('notice', 'You have been logged out');
header('Location: login.php');
exit;
