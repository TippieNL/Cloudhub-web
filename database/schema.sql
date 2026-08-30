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
 last_login_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('user','ip') NOT NULL,
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
 CONSTRAINT fk_file_metadata_server FOREIGN KEY(server_id) REFERENCES storage_servers(id) ON DELETE CASCADE,
 INDEX idx_file_server(server_id), INDEX idx_file_server_path(server_id, file_path(190)), INDEX idx_file_uploader(uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS share_links (
 token CHAR(43) NOT NULL PRIMARY KEY,
 file_path TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at TIMESTAMP NULL DEFAULT NULL,
 INDEX idx_share_expires(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO storage_servers (name,type,is_active,is_default,config)
SELECT 'Local Storage','local',1,1,JSON_OBJECT('path','storage/files')
WHERE NOT EXISTS (SELECT 1 FROM storage_servers);
