<?php
/**
 * ============================================================
 * WhatsApp CRM - Database Connection (PDO)
 * ============================================================
 * Singleton PDO connection for MySQL
 */

// Database credentials - UPDATE THESE
define('DB_HOST', 'localhost');
define('DB_NAME', 'whatsapp_crm');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');
define('DB_PORT', 3306);

/**
 * Get PDO database connection (singleton pattern)
 * @return PDO
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In production, log error and show generic message
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die('Database connection failed: ' . $e->getMessage());
            } else {
                error_log('[DB ERROR] ' . $e->getMessage());
                die('Service temporarily unavailable. Please try again later.');
            }
        }
    }

    return $pdo;
}

/**
 * Quick query helper - returns all rows
 */
function dbQuery(string $sql, array $params = []): array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Quick query helper - returns single row
 */
function dbQueryOne(string $sql, array $params = []): ?array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Quick insert helper - returns last insert ID
 */
function dbInsert(string $sql, array $params = []): int {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return (int) getDB()->lastInsertId();
}

/**
 * Quick update/delete helper - returns affected rows
 */
function dbExecute(string $sql, array $params = []): int {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
