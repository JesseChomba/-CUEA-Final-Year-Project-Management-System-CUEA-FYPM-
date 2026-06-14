<?php
require_once __DIR__ . '/db.php';

function ensureAuditLogTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
          audit_id int PRIMARY KEY AUTO_INCREMENT,
          user_id int DEFAULT NULL,
          action varchar(120) NOT NULL,
          entity_type varchar(80) DEFAULT NULL,
          entity_id int DEFAULT NULL,
          details text,
          ip_address varchar(64) DEFAULT NULL,
          created_at timestamp DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
          log_id int PRIMARY KEY AUTO_INCREMENT,
          user_id int DEFAULT NULL,
          action varchar(120) NOT NULL,
          timestamp timestamp DEFAULT CURRENT_TIMESTAMP,
          ip_address varchar(64) DEFAULT NULL,
          FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
        )
    ");
}

function auditLog(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void {
    try {
        $db = DB::connect();
        if (!$db->inTransaction()) {
            ensureAuditLogTable($db);
        }
        $stmt = $db->prepare('
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        $activity = $db->prepare('
            INSERT INTO activity_logs (user_id, action, ip_address)
            VALUES (?, ?, ?)
        ');
        $activity->execute([
            $userId,
            $action,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        // Audit logging should not break primary workflows.
    }
}

function activityLog(?int $userId, string $action): void {
    auditLog($userId, $action);
}
