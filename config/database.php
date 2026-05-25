<?php
/**
 * DATABASE ENVIRONMENT CONFIGURATION
 * Using the ?: fallback operator ensures that if getenv() returns false 
 * (as it does on local XAMPP), it perfectly falls back to local credentials.
 */
$host = getenv('MYSQLHOST') ?: 'localhost';
$port = getenv('MYSQLPORT') ?: '3306';
$name = getenv('MYSQLDATABASE') ?: 'inventory_management_db';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: ''; // XAMPP default password is blank

define('DB_HOST', $host);
define('DB_PORT', $port);
define('DB_NAME', $name);
define('DB_USER', $user);
define('DB_PASS', $pass);

try {
    // Construct the Data Source Name (DSN) string
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    
    // Safety performance configurations
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throws code exceptions instantly if a query crashes
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Converts database read rows into clean array keys
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Disables emulation to force real SQL sanitization
    ];

    // Initialize the PDO connection instance variable
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

} catch (PDOException $e) {
    // Log the actual detailed error to your server's secret error log
    error_log("Database connection failure: " . $e->getMessage());
    
    // TEMPORARILY DISABLED: Showing the full error message for debugging
    die("DATABASE CONNECTION ERROR: " . $e->getMessage());
}
