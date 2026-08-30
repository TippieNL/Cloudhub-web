# CloudHub Security

## Phase 2 — Core security foundation

This build does **not** claim production readiness. It establishes reusable
security controls required by later hardening phases.

### Sessions and authentication
PHP strict session mode and cookie-only sessions are enabled. Session cookies
are HttpOnly, use configurable SameSite policy, and become Secure on HTTPS.
Sessions have configurable inactivity and absolute lifetimes. Login regenerates
the session ID and rotates the CSRF token; logout destroys the session and
expires its cookie. Existing password hashes are transparently migrated using
PHP `PASSWORD_DEFAULT` when `password_needs_rehash()` requires it.

### CSRF
Authenticated state-changing API/WebDAV requests require the session-bound
`X-CSRF-Token`. Explicit cross-site Fetch Metadata requests are rejected before
token validation.

### HTTP security
Responses receive CSP, `X-Content-Type-Options`, frame protection,
`Referrer-Policy`, and a restrictive `Permissions-Policy`. The inline bootstrap
script uses a per-request CSP nonce.

HSTS is disabled by default. Enable it only after production HTTPS is working.

### Production HTTPS / reverse proxy
For an internet deployment, use HTTPS and set `REQUIRE_HTTPS=true`.
`X-Forwarded-Proto` is ignored unless `TRUST_PROXY=true`. Only enable
`TRUST_PROXY` when clients cannot bypass the trusted reverse proxy.

### Remaining risks
Per-resource authorization, shared-root isolation, deeper filesystem/symlink
hardening, WebDAV authorization, upload content isolation, rate limiting,
security logging, secret handling and full security regression tests remain
future phases.


## Phase 3 — Filesystem hardening

All client-supplied file paths now pass through a strict virtual-root boundary.
`..` traversal is rejected rather than silently normalised. Encoded traversal,
NUL/control characters, Windows drive paths, UNC-style paths and symlink
traversal are rejected. Existing resources are canonicalised with `realpath()`
and proven to remain beneath `ROOT_DIR`.

Mutation destinations require an already existing canonical parent directory;
the API no longer recursively creates arbitrary parent paths as a side effect.
The storage root itself cannot be deleted. Directory listings omit symlinks.

WebDAV now uses the same strict path boundary for GET/PROPFIND/PUT/DELETE/MKCOL/
MOVE. PUT writes to a temporary sibling and renames it into place, and MOVE
validates both source and destination through FileService.

`storage/.htaccess` denies direct HTTP access under Apache. An Nginx hardening
example is provided under `deploy/`. For production, storing user files outside
the web document root remains strongly recommended.

### Still outstanding

Phase 3 hardens containment but does not create per-user file ownership/ACLs.
The current single shared storage root therefore remains unsuitable for a
multi-user trust model until the authorization phase is completed.


## Phase 4 — Authentication hardening

Login failures are throttled in MySQL using separate per-username and per-IP
windows. Stored throttle keys are HMACs rather than raw usernames/IP addresses.
`REMOTE_ADDR` is authoritative unless trusted-proxy mode is explicitly enabled.

Successful authentication continues to regenerate the session ID and now
periodically rotates authenticated session IDs. Password hashes prefer Argon2id
when the PHP build provides it and transparently migrate older hashes after a
successful login.

The default database schema no longer inserts a predefined administrator hash.
New deployments must create the administrator explicitly with
`php tools/create-admin.php admin`. Existing accounts are not deleted by the
migration.

Existing installations must run:
`database/migrations/20260725_phase4_auth_hardening.sql`.

Rate limiting is application-level protection, not a substitute for reverse
proxy/firewall throttling on an internet-facing deployment.


## Phase 5 — API hardening

JSON APIs now use bounded request parsing, enforce JSON media types, reject
malformed/non-object JSON, and validate sensitive fields by type, length and
range. Bulk ZIP selections are capped at 500 paths. API errors use stable codes
and random request IDs. Unexpected server errors are logged with the request ID
without exposing exception details to clients. JSON responses use
`Cache-Control: no-store`; unknown `/api/*` routes return JSON 404 responses.


## Phase 6 — Authorization hardening

CloudHub now has `viewer`, `editor`, and `admin` roles. Viewers can read the
shared storage root. Editors can mutate files, upload and create shares.
Administrators can additionally access storage-server configuration and share
token administration. State-changing API/WebDAV requests require both CSRF and
write capability.

Resumable upload sessions are bound to the authenticated user ID; another
account cannot resume, append, complete or cancel that staged upload.

This remains a shared-root role model, not per-file/per-folder ACL isolation.


## Phase 7 — Security audit trail

Security-relevant events are persisted to `security_events`. Records contain
request correlation, actor identity where available, outcome and minimal
redacted context. Passwords, CSRF values, share tokens and credential-like
fields are excluded. Use `tools/cleanup-security-events.php` to enforce the
configured retention period.

## Phase 8 — Uploaded-content and deployment hardening

CloudHub treats uploaded files as untrusted content. HTML/script-capable text is
not rendered inline from the application origin. Public shares send `nosniff`
and a sandbox CSP, and filenames are header-sanitised. Apache deny rules cover
application internals plus common secret, backup, SQL, log and INI artefacts.

For an internet-facing NAS, keep `storage/files` outside the web document root
where practical and terminate traffic with HTTPS.
