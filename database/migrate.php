<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$dbConfig = require dirname(__DIR__) . '/config/database.php';
$pdo = new PDO($dbConfig['dsn'], $dbConfig['user'], $dbConfig['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function tableExists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}
function columnExists(PDO $pdo, string $table, string $column): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $column]);
    return (bool)$s->fetchColumn();
}
function indexExists(PDO $pdo, string $table, string $index): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $s->execute([$table, $index]);
    return (bool)$s->fetchColumn();
}
function addColumn(PDO $pdo, string $table, string $column, string $definition): void {
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "Added $table.$column\n";
    }
}

// Create missing tables first. Statements are separated deliberately to work on MySQL/MariaDB without a migration framework.
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(100) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 last_login_at TIMESTAMP NULL DEFAULT NULL,
 UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS storage_servers (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 type ENUM('local','ftp','sftp','smb','http_api') NOT NULL DEFAULT 'local',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 config JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS file_metadata (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 server_id INT UNSIGNED NOT NULL,
 file_path TEXT NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 mime_type VARCHAR(190) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS share_links (
 token CHAR(43) NOT NULL PRIMARY KEY,
 file_path TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Phase 4 authentication throttling. LoginRateLimiter queries this table on
// every login attempt, so a database without it makes every login fail with a
// PDOException surfacing as HTTP 500 rather than a friendly error.
$pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('user','ip') NOT NULL,
 attempt_key CHAR(64) NOT NULL,
 attempted_at DATETIME NOT NULL,
 INDEX idx_login_attempt_lookup(scope, attempt_key, attempted_at),
 INDEX idx_login_attempt_cleanup(attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Phase 7 security audit trail.
$pdo->exec("CREATE TABLE IF NOT EXISTS security_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 username VARCHAR(100) NULL,
 event_type VARCHAR(80) NOT NULL,
 outcome VARCHAR(20) NOT NULL DEFAULT 'success',
 ip_address VARCHAR(45) NOT NULL DEFAULT '',
 user_agent VARCHAR(255) NOT NULL DEFAULT '',
 request_id VARCHAR(32) NOT NULL DEFAULT '',
 context_json JSON NULL,
 created_at DATETIME NOT NULL,
 INDEX idx_security_events_created(created_at),
 INDEX idx_security_events_user(user_id,created_at),
 INDEX idx_security_events_type(event_type,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Upgrade legacy tables in place. No existing columns or rows are removed.
addColumn($pdo, 'users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
addColumn($pdo, 'users', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
addColumn($pdo, 'users', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
addColumn($pdo, 'users', 'last_login_at', 'TIMESTAMP NULL DEFAULT NULL');

// Phase 6 authorization. Auth::login() has a fallback for a missing role
// column, but every account degrades to 'viewer' until this exists.
$hadRole = columnExists($pdo, 'users', 'role');
addColumn($pdo, 'users', 'role', "ENUM('viewer','editor','admin') NOT NULL DEFAULT 'viewer' AFTER is_active");
if (!$hadRole) {
    // Pre-Phase-6 installations had a single all-powerful account named admin.
    // Preserve that capability rather than locking the operator out.
    $promoted = $pdo->prepare("UPDATE users SET role = 'admin' WHERE username = ?");
    $promoted->execute(['admin']);
    if ($promoted->rowCount()) echo "Promoted existing admin account to the admin role.\n";
}

addColumn($pdo, 'storage_servers', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
addColumn($pdo, 'storage_servers', 'is_default', 'TINYINT(1) NOT NULL DEFAULT 0');
addColumn($pdo, 'storage_servers', 'config', 'JSON NULL');
addColumn($pdo, 'storage_servers', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
addColumn($pdo, 'storage_servers', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
$pdo->exec("UPDATE storage_servers SET config = JSON_OBJECT() WHERE config IS NULL");

addColumn($pdo, 'file_metadata', 'size', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
addColumn($pdo, 'file_metadata', 'mime_type', 'VARCHAR(190) NULL');
addColumn($pdo, 'file_metadata', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
// Which account an upload came from, so a per-user quota can be computed.
// Nullable on purpose: rows that predate this, and files that arrive by any
// other route, are unattributed rather than wrongly blamed on someone.
addColumn($pdo, 'file_metadata', 'uploaded_by', 'INT UNSIGNED NULL');

addColumn($pdo, 'share_links', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
addColumn($pdo, 'share_links', 'expires_at', 'TIMESTAMP NULL DEFAULT NULL');

if (!indexExists($pdo, 'storage_servers', 'idx_storage_active')) $pdo->exec('ALTER TABLE storage_servers ADD INDEX idx_storage_active (is_active)');
if (!indexExists($pdo, 'storage_servers', 'idx_storage_default')) $pdo->exec('ALTER TABLE storage_servers ADD INDEX idx_storage_default (is_default)');
if (!indexExists($pdo, 'share_links', 'idx_share_expires')) $pdo->exec('ALTER TABLE share_links ADD INDEX idx_share_expires (expires_at)');
if (!indexExists($pdo, 'file_metadata', 'idx_file_server')) $pdo->exec('ALTER TABLE file_metadata ADD INDEX idx_file_server (server_id)');
if (!indexExists($pdo, 'file_metadata', 'idx_file_server_path')) $pdo->exec('ALTER TABLE file_metadata ADD INDEX idx_file_server_path (server_id, file_path(190))');
// Per-user usage is a SUM grouped by this column on every upload attempt.
if (!indexExists($pdo, 'file_metadata', 'idx_file_uploader')) $pdo->exec('ALTER TABLE file_metadata ADD INDEX idx_file_uploader (uploaded_by)');

// Phase 4 removed the predefined account: seeding a known password hash here
// meant every migrated installation carried a working admin/change-me login.
// Administrators are created deliberately instead.
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount === 0) {
    echo "No user accounts exist. Create one with: php tools/create-admin.php admin\n";
}

// A fresh install should have usable local storage. Existing server rows are left untouched.
$count = (int)$pdo->query('SELECT COUNT(*) FROM storage_servers')->fetchColumn();
if ($count === 0) {
    $s = $pdo->prepare('INSERT INTO storage_servers (name, type, is_active, is_default, config) VALUES (?, ?, 1, 1, ?)');
    $s->execute(['Local Storage', 'local', json_encode(['path' => 'storage/files'], JSON_UNESCAPED_SLASHES)]);
    echo "Created default local storage server.\n";
}

echo "Cloud File Hub database migration complete.\n";
