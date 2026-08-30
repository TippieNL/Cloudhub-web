CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('user','ip') NOT NULL,
 attempt_key CHAR(64) NOT NULL,
 attempted_at DATETIME NOT NULL,
 INDEX idx_login_attempt_lookup(scope, attempt_key, attempted_at),
 INDEX idx_login_attempt_cleanup(attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
