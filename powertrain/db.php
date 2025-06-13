<?php
function get_db() {
    static $db = null;
    if ($db === null) {
        $config = require __DIR__ . '/config.php';
        $dbconf = $config['db'];
        $db = new PDO(
            "pgsql:host={$dbconf['host']};port={$dbconf['port']};dbname={$dbconf['dbname']}",
            $dbconf['user'],
            $dbconf['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }
    return $db;
}
