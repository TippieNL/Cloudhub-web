-- CloudHub: two-step verification by email
--
-- The same changes `php database/migrate.php` makes, for applying by hand --
-- in phpMyAdmin or Adminer, say, on a host without a PHP command line. Select
-- the CloudHub database (cloud_file_hub unless .env says otherwise) first.
--
-- For any database: one that has never had two-step verification, and one
-- set up for its text-message codes (by the SQL that came before this).
-- Safe to run more than once: what is already there is left alone. No account
-- or setting is deleted -- only throttle rows of text messages, an hour of
-- history at most. Every account starts with two-step verification off,
-- except one that turned on text-message codes: it stays on (see 5). Plain
-- statements only -- no DELIMITER or stored procedure, and no ADD COLUMN IF
-- NOT EXISTS, which MySQL lacks -- so it runs on MySQL and MariaDB alike, and
-- in a web SQL tab as well as the mysql client.

-- 1. A code waiting to be entered: one per account and purpose ('login',
--    'confirm' for the current address, 'email' for a new one). code_hash is
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

-- 3. On users: the address codes go to, and since when it is on. NULL
--    two_factor_enabled_at means off, which is every account until its owner
--    turns it on. Both nullable and appended, so no row is rewritten.
SET @db := DATABASE();

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_email');
SET @sql := IF(@has=0, 'ALTER TABLE users ADD COLUMN two_factor_email VARCHAR(254) NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='two_factor_enabled_at');
SET @sql := IF(@has=0, 'ALTER TABLE users ADD COLUMN two_factor_enabled_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. login_attempts also throttles codes checked ('verify_*') and code emails
--    sent ('email_*'). Until this has run, every two-step request is refused
--    -- closed, not open -- while signing in without it works as before. The
--    table is created if this installation predates it.
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('user','ip','verify_user','verify_ip','email_user','email_ip','email_address') NOT NULL,
 attempt_key CHAR(64) NOT NULL,
 attempted_at DATETIME NOT NULL,
 INDEX idx_login_attempt_lookup(scope, attempt_key, attempted_at),
 INDEX idx_login_attempt_cleanup(attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='login_attempts' AND COLUMN_NAME='scope');

--    4a. Set up for text-message codes: add the email values, keeping the old.
SET @sql := IF(@type IS NOT NULL AND LOCATE('''sms_', @type)>0 AND LOCATE('''email_address''', @type)=0,
  'ALTER TABLE login_attempts MODIFY scope ENUM(''user'',''ip'',''verify_user'',''verify_ip'',''sms_user'',''sms_ip'',''sms_phone'',''email_user'',''email_ip'',''email_address'') NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

--    4b. Drop the text-message throttle rows, then their values. If 4c fails
--        (strict mode, and a server still on the old code wrote such a row
--        meanwhile), run this file again; everything works in between.
SET @sql := IF(@type IS NOT NULL AND LOCATE('''sms_', @type)>0,
  'DELETE FROM login_attempts WHERE scope IN (''sms_user'',''sms_ip'',''sms_phone'')',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

--    4c. Every database ends with the same values.
SET @sql := IF(@type IS NOT NULL AND (LOCATE('''sms_', @type)>0 OR LOCATE('''email_address''', @type)=0),
  'ALTER TABLE login_attempts MODIFY scope ENUM(''user'',''ip'',''verify_user'',''verify_ip'',''email_user'',''email_ip'',''email_address'') NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Left by text-message codes, if they were set up: users.two_factor_phone.
--    Nothing reads it any more; it is kept so this file deletes no data. An
--    account that had text-message codes on keeps two-step verification on
--    and, with no address yet, finishes signing in with a recovery code until
--    its owner adds one under Security -- or an administrator resets it. These
--    are the accounts in that position:
--
--      SELECT username FROM users
--        WHERE two_factor_enabled_at IS NOT NULL AND two_factor_email IS NULL;
--
--    Once that is empty, or you have decided about those accounts, the old
--    numbers can go (this cannot be undone):
--
--      ALTER TABLE users DROP COLUMN two_factor_phone;
