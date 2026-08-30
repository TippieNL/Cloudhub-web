
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

