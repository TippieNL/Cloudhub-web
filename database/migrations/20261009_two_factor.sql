-- CloudHub: SMS two-step verification
--
-- The same changes `php database/migrate.php` makes, for applying by hand --
-- in phpMyAdmin or Adminer, say, on a host without a PHP command line. Select
-- the CloudHub database (cloud_file_hub unless .env says otherwise) first.
--
-- Safe to run more than once: what is already there is left alone. Nothing is
-- dropped or rewritten, and every account starts with two-step verification
-- off. Plain statements only -- no DELIMITER or stored procedure, and no
-- ADD COLUMN IF NOT EXISTS, which MySQL lacks -- so it runs on MySQL and
-- MariaDB alike, and in a web SQL tab as well as the mysql client.

-- 1. A code waiting to be entered: one per account and purpose ('login',
--    'confirm' for the current phone, 'phone' for a new number). code_hash is
--    an HMAC of the code, never the code itself.
CREATE TABLE IF NOT EXISTS two_factor_challenges (
 id CHAR(32) NOT NULL PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 purpose VARCHAR(16) NOT NULL,
 code_hash CHAR(64) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 sent_at DATETIME NULL,
 expires_at DATETIME NULL,
 UNIQUE KEY uq_two_factor_challenge(user_id, purpose),
 INDEX idx_two_factor_challenge_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. One-time recovery codes, kept only as SHA-256.
CREATE TABLE IF NOT EXISTS two_factor_recovery_codes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 code_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 UNIQUE KEY uq_two_factor_recovery(user_id, code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. On users: the number codes go to (E.164), and since when it is on.
--    NULL two_factor_enabled_at means off, which is every account until its
--    owner turns it on. Both nullable and appended, so no row is rewritten.
SET @db := DATABASE();

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_phone');
SET @sql := IF(@has=0, 'ALTER TABLE users ADD COLUMN two_factor_phone VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_enabled_at');
SET @sql := IF(@has=0, 'ALTER TABLE users ADD COLUMN two_factor_enabled_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. login_attempts also throttles codes checked ('verify_*') and texts sent
--    ('sms_*'). The values are appended, so every existing row keeps its
--    meaning. Until this has run, every two-step request is refused -- closed,
--    not open -- while signing in without it works as before. The table is
--    created if this installation predates it.
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('user','ip','verify_user','verify_ip','sms_user','sms_ip','sms_phone') NOT NULL,
 attempt_key CHAR(64) NOT NULL,
 attempted_at DATETIME NOT NULL,
 INDEX idx_login_attempt_lookup(scope, attempt_key, attempted_at),
 INDEX idx_login_attempt_cleanup(attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='login_attempts' AND COLUMN_NAME='scope');
SET @sql := IF(@type IS NOT NULL AND LOCATE('''sms_phone''', @type)=0,
  'ALTER TABLE login_attempts MODIFY scope ENUM(''user'',''ip'',''verify_user'',''verify_ip'',''sms_user'',''sms_ip'',''sms_phone'') NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
