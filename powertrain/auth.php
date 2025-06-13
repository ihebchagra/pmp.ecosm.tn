<?php
session_start();

function require_login($redirect_if_not_logged_in = '/index.php', $required_domain = 'utm.tn') {
    if (!isset($_SESSION['user'])) {
        header("Location: $redirect_if_not_logged_in");
        exit;
    }
    if (
        !isset($_SESSION['user']['hd'])
        /* || !str_ends_with($_SESSION['user']['hd'], $required_domain) */
    ) {
        session_destroy();
        header("Location: $redirect_if_not_logged_in?error=org");
        exit;
    }
}

function require_guest($redirect_if_logged_in = '/dashboard.php') {
    if (isset($_SESSION['user'])) {
        header("Location: $redirect_if_logged_in");
        exit;
    }
}

// Polyfill for str_ends_with if using PHP < 8.0
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $length = strlen($needle);
        return $length === 0 || (substr($haystack, -$length) === $needle);
    }
}
