<?php
$root = (string)env('ROOT_DIR', 'storage/files');

// Compatibility repair: Phase 5/6 shipped ROOT_DIR=storage in .env.example,
// while the application schema and previous builds use storage/files.
// If that legacy value is present and storage/files exists, use the actual
// file store rather than exposing the storage infrastructure directory.
$normalisedRoot = trim(str_replace('\\','/', $root), '/');
if ($normalisedRoot === 'storage' && is_dir(dirname(__DIR__) . '/storage/files')) {
    $root = 'storage/files';
}

if (!str_starts_with($root, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $root)) $root = dirname(__DIR__) . '/' . $root;
$root = rtrim(str_replace('\\','/', $root), '/');
if (!is_dir($root)) mkdir($root, 0775, true);
return [
 'app_env'=>(string)env('APP_ENV','production'), 'app_url'=>(string)env('APP_URL',''), 'root_dir'=>$root,
 'read_only'=>env_bool('READ_ONLY'), 'allow_delete'=>env_bool('ALLOW_DELETE',true), 'allow_overwrite'=>env_bool('ALLOW_OVERWRITE',true),
 'trash_enabled'=>env_bool('TRASH_ENABLED',true), 'trash_retention_days'=>(int)env('TRASH_RETENTION_DAYS',30),
 'storage_limit_gb'=>(float)env('STORAGE_LIMIT_GB',0), 'user_quota_gb'=>(float)env('USER_QUOTA_GB',0),
 'usage_cache_seconds'=>(int)env('USAGE_CACHE_SECONDS',300),
 'https_enabled'=>env_bool('HTTPS_ENABLED'), 'require_https'=>env_bool('REQUIRE_HTTPS',false),
 'trust_proxy'=>env_bool('TRUST_PROXY',false), 'hsts_enabled'=>env_bool('HSTS_ENABLED',false), 'hsts_max_age'=>(int)env('HSTS_MAX_AGE',31536000),
 'session_idle_seconds'=>(int)env('SESSION_IDLE_SECONDS',3600), 'session_absolute_seconds'=>(int)env('SESSION_ABSOLUTE_SECONDS',43200),
 'session_samesite'=>(string)env('SESSION_SAMESITE','Lax'), 'session_rotate_seconds'=>(int)env('SESSION_ROTATE_SECONDS',900),
 'login_rate_window_seconds'=>(int)env('LOGIN_RATE_WINDOW_SECONDS',900), 'login_rate_user_attempts'=>(int)env('LOGIN_RATE_USER_ATTEMPTS',5),
 'login_rate_ip_attempts'=>(int)env('LOGIN_RATE_IP_ATTEMPTS',20), 'login_rate_retention_seconds'=>(int)env('LOGIN_RATE_RETENTION_SECONDS',86400),
 'rate_limit_secret'=>(string)env('RATE_LIMIT_SECRET',''), 'security_event_retention_days'=>(int)env('SECURITY_EVENT_RETENTION_DAYS',90), 'share_expiry_hours'=>(int)env('SHARE_EXPIRY_HOURS',0),
 'max_upload_mb'=>(int)env('MAX_UPLOAD_MB',2048), 'max_upload_files'=>(int)env('MAX_UPLOAD_FILES',20),
 'upload_chunk_mb'=>(int)env('UPLOAD_CHUNK_MB',8), 'upload_retry_count'=>(int)env('UPLOAD_RETRY_COUNT',3),
 'upload_abandon_hours'=>(int)env('UPLOAD_ABANDON_HOURS',24), 'upload_conflict'=>(string)env('UPLOAD_CONFLICT','rename'),
 'upload_staging_dir'=>(string)env('UPLOAD_STAGING_DIR',''),
];
