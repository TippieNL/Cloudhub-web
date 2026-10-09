CREATE DATABASE IF NOT EXISTS cloud_file_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cloud_file_hub;

CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(100) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'viewer',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 last_login_at TIMESTAMP NULL DEFAULT NULL,
 -- Two-step verification by email. NULL two_factor_enabled_at means off,
 -- which every account is until its owner turns it on; two_factor_email is
 -- the address its codes go to.
 two_factor_email VARCHAR(254) NULL,
 two_factor_enabled_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Throttle events: password failures ('user', 'ip'), and for two-step
-- verification the codes checked ('verify_*') and code emails sent ('email_*').
-- attempt_key is an HMAC, never a raw username, IP address or email address.
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('user','ip','verify_user','verify_ip','email_user','email_ip','email_address') NOT NULL,
 attempt_key CHAR(64) NOT NULL,
 attempted_at DATETIME NOT NULL,
 INDEX idx_login_attempt_lookup(scope, attempt_key, attempted_at),
 INDEX idx_login_attempt_cleanup(attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS security_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An emailed code waiting to be entered. One row per account and purpose
-- ('login', 'confirm' for proving the current address before a change, 'email'
-- for proving a new address): asking again replaces it, which is what makes an
-- older code stop working, and using it deletes it. code_hash is an HMAC of the
-- code under a server secret, never the code itself. id is random and is what
-- the session holds. Times are UTC, written by PHP.
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

-- One-time recovery codes for an account whose mailbox is out of reach. Only
-- a SHA-256 of each is kept; used_at marks the one that has been spent.
CREATE TABLE IF NOT EXISTS two_factor_recovery_codes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 code_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 UNIQUE KEY uq_two_factor_recovery(user_id, code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_servers (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 type ENUM('local','ftp','sftp','smb','http_api') NOT NULL DEFAULT 'local',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 config JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_storage_active(is_active), INDEX idx_storage_default(is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_metadata (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 server_id INT UNSIGNED NOT NULL,
 file_path TEXT NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 mime_type VARCHAR(190) NULL,
 uploaded_by INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_file_server(server_id), INDEX idx_file_server_path(server_id, file_path(190)), INDEX idx_file_uploader(uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS share_links (
 token CHAR(43) NOT NULL PRIMARY KEY,
 file_path TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at TIMESTAMP NULL DEFAULT NULL,
 INDEX idx_share_expires(expires_at), INDEX idx_share_path(file_path(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files each account has starred. path_hash is SHA-256 of file_path: TEXT
-- cannot carry a whole-value unique key, and a prefix one would treat two long
-- paths sharing their first 190 characters as the same file.
CREATE TABLE IF NOT EXISTS favorites (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 file_path TEXT NOT NULL,
 path_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_favorite_user_path(user_id, path_hash),
 INDEX idx_favorite_user(user_id, created_at),
 -- Renames, moves and deletes find the favorites under a path by prefix.
 INDEX idx_favorite_path(file_path(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Background jobs: long file operations (copying, archiving, extracting,
-- purging, thumbnails, checksums, duplicate scans) run by tools/worker.php --
-- or, where no CLI worker runs, by the request that queued them, after its
-- response. id is random and unguessable. claim_token fences a claim, so a
-- worker whose job was taken over can no longer write to it. revision changes
-- on every update, so rowCount() reports a matched row even on MySQL, which
-- counts changed rows. Times are UTC, written by PHP.
CREATE TABLE IF NOT EXISTS jobs (
 id CHAR(32) NOT NULL PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 type VARCHAR(32) NOT NULL,
 status ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
 label VARCHAR(255) NOT NULL DEFAULT '',
 target VARCHAR(1024) NOT NULL DEFAULT '',
 payload MEDIUMTEXT NOT NULL,
 state MEDIUMTEXT NULL,
 result MEDIUMTEXT NULL,
 progress_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
 progress_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
 progress_unit VARCHAR(10) NOT NULL DEFAULT 'items',
 current_item VARCHAR(1024) NULL,
 error VARCHAR(1000) NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
 claim_token CHAR(32) NULL,
 worker VARCHAR(100) NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 started_at DATETIME NULL,
 heartbeat_at DATETIME NULL,
 finished_at DATETIME NULL,
 INDEX idx_jobs_queue (status, created_at),
 INDEX idx_jobs_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO storage_servers (name,type,is_active,is_default,config)
SELECT 'Local Storage','local',1,1,JSON_OBJECT('path','storage/files')
WHERE NOT EXISTS (SELECT 1 FROM storage_servers);
