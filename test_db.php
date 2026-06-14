<?php
/**
 * DB Connection Tester
 */
require_once 'includes/db.php';
require_once 'includes/helpers.php';

try {
    $db = DB::connect();
    echo "SUCCESS: Connected to the database successfully!\n";
    
    $stmt = $db->query("SELECT VERSION() as version");
    $row = $stmt->fetch();
    echo "MySQL Version: " . $row['version'] . "\n";
    
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables found: " . implode(", ", $tables) . "\n";

} catch (Exception $e) {
    echo "ERROR: Could not connect to the database.\n";
    echo "Message: " . $e->getMessage() . "\n";
}
