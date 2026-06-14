<?php
/**
 * CUEA FYPM – Database Connection (PDO Singleton)
 * Database: cuea_fypm
 */

require_once __DIR__ . '/env.php';
loadEnv();

class DB {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance === null) {
            $host   = envValue('DB_HOST', 'localhost');
            $dbname = envValue('DB_NAME', 'cuea_fypm');
            $user   = envValue('DB_USER', 'root');
            $pass   = envValue('DB_PASS', '');

            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'status'  => 500,
                    'message' => 'Database connection failed.',
                    'data'    => []
                ]);
                exit;
            }
        }
        return self::$instance;
    }
}
