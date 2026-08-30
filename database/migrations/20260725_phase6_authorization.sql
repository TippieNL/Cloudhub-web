ALTER TABLE users
  ADD COLUMN role ENUM('viewer','editor','admin') NOT NULL DEFAULT 'viewer' AFTER is_active;
UPDATE users SET role='admin' WHERE username='admin';
