<?php
require_once __DIR__ . '/../powertrain/auth.php';
require_guest();

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
$client->addScope('email');
$client->addScope('profile');
$client->setHostedDomain('utm.tn'); // Optional UX hint

$auth_url = $client->createAuthUrl();

header('Location: ' . $auth_url );
exit;
?>
