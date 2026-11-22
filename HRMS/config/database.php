<?php
/**
 * Database connection settings.
 * Change the values below to match YOUR local MySQL/MariaDB (see README section 6).
 * Default XAMPP: user "root" with an EMPTY password. If you set a root password
 * in XAMPP, put it in DB_PASS.
 */
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'hrms_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Set to true only while developing. Keep false so PHP errors are not shown to users.
define('APP_DEBUG', false);
define('APP_TIMEZONE', 'Asia/Kolkata');

date_default_timezone_set(APP_TIMEZONE);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (PDOException $ex) {
        error_log('HRMS DB connection failed: ' . $ex->getMessage());
        http_response_code(500);
        exit('Database connection failed. Open config/database.php and check the settings (see README, "Troubleshooting").');
    }
    return $pdo;
}
