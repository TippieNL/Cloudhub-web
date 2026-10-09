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

## Application cache (phpFastCache)

phpFastCache reads its entries back with `unserialize()`, which instantiates
objects, so write access to the cache directory is code execution as the web
server. `CloudHub\Helpers\Cache` therefore refuses a `CACHE_PATH` inside
`ROOT_DIR` (where account holders upload) or inside `public/`, resolving
symlinks and comparing case-insensitively as Android shared storage does; the
storage report names the refusal. The default, `storage/.cache/phpfastcache`,
is outside both and is denied over HTTP, as are `vendor/` and
`composer.json`/`composer.lock`.

phpFastCache's defaults are overridden where they would widen that surface:
the directory is not named after the `Host` header (which let any client make
it create directories), there is no fallback to the shared system temp
directory, directories are created `0775` rather than `0777`, and entries are
written to a temporary file and renamed into place.

A listing entry holds names, sizes and times, which every signed-in account can
already list — CloudHub has no per-folder permissions — and a favorites entry is
keyed to its account. Nothing is cached for public share links.

## Two-step verification by email

An account can require, after its password, a six-digit code emailed to an
address its owner has proven. It is off until the owner turns it on; accounts
that leave it off sign in exactly as before. README.md describes the feature
and its setup. It replaced the earlier text-message codes: see **Moving from
text-message codes** below.

### Where it is enforced

- **Signing in.** `Auth::login()` signs a session in only for an account
  without two-step verification. For one with it, a correct password empties
  the session, gives it a new ID and CSRF token, and records which account is
  waiting for a code — but sets no `user_id`. Every route decides "signed in"
  from `user_id` alone (`Auth::user()`), so the API, WebDAV, the player, share
  management and logout answer such a session `401` exactly as they answer one
  that never signed in. Only `Auth::finishSecondFactor()`, called after the code
  or a recovery code is checked, sets it — and it can only upgrade a session
  that proved the password in the last 15 minutes, re-reads the account (one
  disabled meanwhile is not signed in), and regenerates the ID again.
- **There is no other way in.** CloudHub has no remember-me token, password
  reset by email, API key or Basic authentication; WebDAV uses the session;
  share links never sign anyone in. Sessions are long-lived instead, so when an
  account turns two-step verification on, every one of its sessions that did
  not pass a second factor is ended at its next account re-check (within a
  minute) — another browser, the Android app, or whoever else had the
  password. A session records `two_factor_verified_at` when it passes a code, a
  recovery code, or proves the address while turning the feature on. A
  password change by an administrator does not turn two-step verification off.
- **CSRF.** `/api/auth/two-factor/*` sit outside the signed-in guard, as login
  does, and check the session's CSRF token and Fetch Metadata themselves. The
  settings routes are behind the guard like every other change.
- **Failing closed.** A database error while reading whether an account needs a
  code fails the sign-in. Whether a code is needed rests on
  `users.two_factor_enabled_at` alone, so an account that turned on text-message
  codes still needs a second step on a server that has the new code but has
  not run the migration yet. Only a database without that column at all —
  where nobody can have turned it on — reads as "off". With no working mail
  server, a code cannot be sent and the account can finish signing in only
  with a recovery code; a failed or timed-out email never signs anyone in. A
  throttle that cannot record its own event (an unmigrated `login_attempts`
  ENUM under MySQL's non-strict mode) refuses rather than waving requests
  through.

### Codes

- Six digits from `random_int()`. Stored as `HMAC-SHA256(TWO_FACTOR_SECRET,
  challenge id | code)`: a copy of the database alone is not enough to recover a
  live code. Without `TWO_FACTOR_SECRET` the key is derived from
  `RATE_LIMIT_SECRET`, or failing that from `APP_URL` — still outside the
  database, but set a real one.
- One challenge per account and purpose (sign-in; proving the current address;
  proving a new address). Asking for a new code replaces the old one, and a
  later sign-in replaces an earlier one's code. The session holds the
  challenge's random id, so one session's code is no use to another.
- Valid for `TWO_FACTOR_CODE_TTL_SECONDS` (300 by default).
- `TWO_FACTOR_MAX_ATTEMPTS` (5) wrong guesses spend it. The attempt is a
  compare-and-set taken *before* the code is compared, so parallel guesses can
  never compare more codes than the limit.
- Used once: using it deletes the row in one compare-and-set, so of several
  requests racing with the right code exactly one succeeds (tested with five
  concurrent requests over HTTP).
- Each email says what the code is for ("to finish signing in", "to turn off
  two-step verification"), so a code nobody asked for stands out. The code is
  in the body only, never the subject, which phones show on a locked screen.

### Rate limits

Kept in `login_attempts`, keyed by HMAC as the password throttle is. A slot is
inserted and then counted, so concurrent requests cannot all slip under a limit;
a refused request gives its slot back, so asking again while refused does not
extend the wait.

| Limit | Default |
|---|---|
| code emails per account, per address (each) | 5 per hour |
| code emails per client address | 20 per hour |
| a new code for the same challenge | 60 s after the last (a compare-and-set: five requests at once send one email) |
| wrong codes or recovery codes per account | 10 per hour |
| wrong codes or recovery codes per client address | 30 per hour |
| wrong guesses per code | 5 |
| wrong current password when changing settings | counts against the password throttle |

An email the server refused gives its slots back (nothing was sent); an outage
keeps them, since a timeout can follow a message that was in fact accepted.
With these defaults an attacker who already has the password can test at most
ten codes an hour — and each code they cause goes to the owner's mailbox.

### Changing it, and recovery

- Every change asks for the current password, throttled like a sign-in, so a
  borrowed session cannot be used to guess it.
- Turning it on, or moving to a new address, proves the new address with a code
  sent to it; a recovery code cannot stand in for that.
- Turning it off and replacing the recovery codes also need the current address
  (or a recovery code). So does a change of address, unless this session proved
  the current one in the last ten minutes — otherwise "change the address, then
  turn it off" would make the mailbox optional for turning it off. A change
  begun while the feature was off cannot complete if it was turned on elsewhere
  meanwhile.
- The old address is emailed when the address changes or the feature is turned
  off.
- Recovery codes: ten, 16 characters from a 31-letter alphabet (79 bits), shown
  once, stored as SHA-256 bound to the account, each spent by one
  compare-and-set. Replacing them retires the old ones.
- An administrator can reset another account's two-step verification after
  re-entering their own password; it is audited and emailed to the owner. Not
  their own: that would bypass the mailbox. `tools/reset-two-factor.php` is the
  server operator's last resort.

### Enumeration and privacy

Nothing about two-step verification is revealed before a correct password: an
unknown account, a disabled one and a wrong password all get the same answer,
as before. Several accounts may share an address, so enrolling reveals nothing
about who else uses it. Answers, emails about changes and the audit trail show
at most a masked address, `k•••@example.com`.

The address itself is stored in `users.two_factor_email` — CloudHub needs it to
send codes — and is never returned in full by any API, written to a log or put
in the audit trail. Treat database backups as personal data. Challenge rows
hold no address; they are deleted when used and pruned after a day.

Codes, recovery codes, addresses and SMTP credentials never reach
`logs/php-error.log`: a failed email is logged by step and SMTP status only
(`smtp: RCPT TO refused (550 5.1.x)`) — a server's own reply text often quotes
the address, so it is dropped — and a bad setting by its name, never its
value. There is no development outbox that writes codes to a file; to try the
feature locally, point it at a local catcher such as Mailpit. The audit trail
records `auth.login` (`second_factor`, then `success` with the method),
`auth.two_factor` failures, `two_factor.email`, `two_factor.enable`,
`.email_change`, `.disable`, `.recovery_codes`, `.reauth` and `.admin_reset`.

### The mail server

`SMTP_*` settings live in `.env` only and never reach a browser. The connection
uses TLS 1.2 or later — STARTTLS on 587 (`SMTP_ENCRYPTION=tls`) or TLS from the
first byte on 465 (`ssl`) — and always verifies the certificate against
`SMTP_HOST`; there is no setting that turns verification off. A server that
does not offer STARTTLS when it was asked for gets nothing: not the
credentials, not the message. `SMTP_ENCRYPTION=none` is accepted only for a
relay on this machine or a private network. PHP builds without a CA bundle
(common on Android/KSWEB) need `SMTP_CA_FILE`; without it, verification fails
and nothing is sent. Addresses are validated and refused if they carry line
breaks or angle brackets, so none can add an SMTP command or a header, and
every message is plain text.

Your mail provider, and anyone who can read the mailbox, sees every code. Use
an account dedicated to sending, with an app password rather than the main
password, and keep `.env` readable by the web server only.

### What email does not protect against

An emailed code stops someone who has only the password. It does not stop:

- **someone who can read the mailbox** — a reused or phished email password, a
  shared or synced device, an auto-forwarding rule. Email is often protected
  less well than the account it guards: turn on the mail provider's own
  two-step verification;
- **real-time phishing**, where a fake sign-in page relays the password and the
  code as the victim types them;
- **a stolen session cookie** (a second factor protects signing in, not a
  session that already exists);
- an **administrator** or the server's operator, who can reset it.

Keep recovery codes offline, and consider an authenticator app or passkeys in a
future version for accounts that need more.

### Moving from text-message codes

`php database/migrate.php` (or `database/migrations/20261009_two_factor_email.sql`)
adds `users.two_factor_email`, and swaps the `sms_*` throttle values of
`login_attempts.scope` for `email_*` ones after deleting their rows — an hour
of throttle history, nothing else. It deletes no account data and turns no
account's two-step verification off: an account that had text-message codes on
stays on with no address, so it can only finish signing in with a recovery
code, then add an address under **Security** — or an administrator resets it.
The script lists how many such accounts there are.

`users.two_factor_phone` is left in place, unused, so the update deletes
nothing. The numbers are personal data with no purpose left; once every such
account has an address (or was reset), remove them:

```sql
SELECT username FROM users WHERE two_factor_enabled_at IS NOT NULL AND two_factor_email IS NULL;
ALTER TABLE users DROP COLUMN two_factor_phone;   -- cannot be undone
```

### Production checklist

- Run `php database/migrate.php`.
- Serve over HTTPS with `REQUIRE_HTTPS=true`; set `APP_URL` to the https address.
- Set `TWO_FACTOR_SECRET` (and `RATE_LIMIT_SECRET`) to long random values.
- Configure `SMTP_*` and `MAIL_FROM_ADDRESS`; send a test by turning two-step
  verification on for your own account. Set up SPF/DKIM for the sender's
  domain, or codes land in spam.
- Review the defaults in `.env.example`; watch `two_factor.email` and
  `auth.two_factor` events in the audit trail, and `[mail]` lines in
  `logs/php-error.log`.
