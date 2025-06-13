<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../powertrain/config.php';

// Dynamic redirect URI based on environment
if (
    (
        !empty($_SERVER['HTTP_HOST']) &&
        (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)
    )
    ||
    (
        !empty($_SERVER['SERVER_NAME']) &&
        (strpos($_SERVER['SERVER_NAME'], 'localhost') !== false || strpos($_SERVER['SERVER_NAME'], '127.0.0.1') !== false)
    )
) {
    $redirect_uri = 'http://localhost:8888/callback.php';
} else {
    $redirect_uri = 'https://pmp.ecosm.tn/callback.php';
}

$client = new Google_Client();
$client->setClientId($config['oauth2']['id']);
$client->setClientSecret($config['oauth2']['secret']);
$client->setRedirectUri($redirect_uri);

if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (isset($token['error'])) {
        echo "Error fetching access token: " . htmlspecialchars($token['error_description']);
        exit;
    }

    $client->setAccessToken($token);
    $oauth2 = new Google_Service_Oauth2($client);
    $userinfo = $oauth2->userinfo->get();

    // Store user info in session (including hosted domain)
    $_SESSION['user'] = [
        'name' => $userinfo->name,
        'email' => $userinfo->email,
        'picture' => $userinfo->picture,
        'hd' => $userinfo->hd ?? null,
    ];

    // Redirect to dashboard (auth.php will check domain)
    header('Location: dashboard.php');
    exit;
} else {
    echo 'No code received.';
}
?>
