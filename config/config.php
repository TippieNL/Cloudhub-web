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
 // Application cache; see .env.example and CloudHub\Helpers\Cache.
 'cache_driver'=>(string)env('CACHE_DRIVER','files'), 'cache_path'=>(string)env('CACHE_PATH',''),
 'cache_ttl_seconds'=>(int)env('CACHE_TTL_SECONDS',30), 'cache_min_compute_ms'=>(int)env('CACHE_MIN_COMPUTE_MS',50),
 'cache_redis_host'=>(string)env('CACHE_REDIS_HOST','127.0.0.1'), 'cache_redis_port'=>(int)env('CACHE_REDIS_PORT',6379),
 'cache_redis_password'=>(string)env('CACHE_REDIS_PASSWORD',''), 'cache_redis_database'=>(int)env('CACHE_REDIS_DATABASE',0),
 'https_enabled'=>env_bool('HTTPS_ENABLED'), 'require_https'=>env_bool('REQUIRE_HTTPS',false),
 'trust_proxy'=>env_bool('TRUST_PROXY',false), 'hsts_enabled'=>env_bool('HSTS_ENABLED',false), 'hsts_max_age'=>(int)env('HSTS_MAX_AGE',31536000),
 // 0 disables the timeout. The window below then governs how long a session
 // may live: it bounds both the cookie and PHP's session garbage collector, so
 // that "never expire" does not mean session files accumulate for ever.
 'session_idle_seconds'=>(int)env('SESSION_IDLE_SECONDS',0), 'session_absolute_seconds'=>(int)env('SESSION_ABSOLUTE_SECONDS',0),
 'session_lifetime_days'=>(int)env('SESSION_LIFETIME_DAYS',30),
 'session_samesite'=>(string)env('SESSION_SAMESITE','Lax'), 'session_rotate_seconds'=>(int)env('SESSION_ROTATE_SECONDS',900),
 'login_rate_window_seconds'=>(int)env('LOGIN_RATE_WINDOW_SECONDS',900), 'login_rate_user_attempts'=>(int)env('LOGIN_RATE_USER_ATTEMPTS',5),
 'login_rate_ip_attempts'=>(int)env('LOGIN_RATE_IP_ATTEMPTS',20), 'login_rate_retention_seconds'=>(int)env('LOGIN_RATE_RETENTION_SECONDS',86400),
 'rate_limit_secret'=>(string)env('RATE_LIMIT_SECRET',''), 'security_event_retention_days'=>(int)env('SECURITY_EVENT_RETENTION_DAYS',90), 'share_expiry_hours'=>(int)env('SHARE_EXPIRY_HOURS',0),
 // Upload policy, not a technical ceiling: the browser sends each file through
 // the resumable chunk protocol one at a time, so neither number is bound by
 // post_max_size. MAX_UPLOAD_FILES=0 means no limit on how many may be queued
 // at once. Above 2047 MB needs a 64-bit PHP build -- StorageDiagnostics warns
 // when it is not.
 'max_upload_mb'=>(int)env('MAX_UPLOAD_MB',5120), 'max_upload_files'=>(int)env('MAX_UPLOAD_FILES',150),
 'upload_chunk_mb'=>(int)env('UPLOAD_CHUNK_MB',8), 'upload_retry_count'=>(int)env('UPLOAD_RETRY_COUNT',3),
 'upload_abandon_hours'=>(int)env('UPLOAD_ABANDON_HOURS',24), 'upload_conflict'=>(string)env('UPLOAD_CONFLICT','rename'),
 'upload_staging_dir'=>(string)env('UPLOAD_STAGING_DIR',''),
 // Duplicate finder. The minimum size exists because every empty file is
 // identical to every other one; the scan budget keeps a slice inside
 // max_execution_time on a phone; the file cap is reported as `truncated`
 // rather than quietly shortening the answer.
 'duplicate_min_bytes'=>(int)env('DUPLICATE_MIN_BYTES',1024),
 'duplicate_scan_seconds'=>(int)env('DUPLICATE_SCAN_SECONDS',8),
 'duplicate_max_files'=>(int)env('DUPLICATE_MAX_FILES',50000),
];
