<?php
/**
 * Script to reset admin password from .env values.
 */
require_once 'includes/db.php';

try {
    $db = DB::connect();
    
    $email = envValue('ADMIN_RESET_EMAIL', '');
    $newPassword = envValue('ADMIN_RESET_PASSWORD', '');

    if ($email === '' || $newPassword === '') {
        echo "ERROR: ADMIN_RESET_EMAIL and ADMIN_RESET_PASSWORD must be set in .env.\n";
        exit(1);
    }
    
    // We are using PASSWORD_BCRYPT with a cost of 12 for hashing passwords
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    
    $stmt = $db->prepare('UPDATE users SET password = ? WHERE email = ?');
    $stmt->execute([$hashedPassword, $email]);
    
    if ($stmt->rowCount() > 0) {
        echo "SUCCESS: Password for $email has been reset from .env.\n";
    } else {
        echo "WARNING: Could not find user with email $email. Make sure you have imported the schema.\n";
        
        // Let's check what users are in the database
        $check = $db->query('SELECT email FROM users');
        $users = $check->fetchAll(PDO::FETCH_COLUMN);
        echo "Users in DB: " . implode(", ", $users) . "\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
