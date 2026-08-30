-- CloudHub Phase 6.5 schema repair
-- Run this once on the cloud_file_hub database if `users.role` is missing.

SET @db := DATABASE();
SET @has_role := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='role'
);
SET @sql := IF(
  @has_role=0,
  "ALTER TABLE users ADD COLUMN role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'viewer' AFTER is_active",
  "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE users SET role='admin' WHERE username='admin';
