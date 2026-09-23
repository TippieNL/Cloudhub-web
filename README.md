# Cloud File Hub — PHP 8.2 Migration

This project is a Node-free PHP 8.2+ migration of the supplied React/Express/TypeScript file server. The runtime frontend is HTML/CSS/vanilla JavaScript and the backend uses PHP, PDO, MySQL/MariaDB, filesystem APIs, ZipArchive, PHP session authentication, public share tokens, thumbnails, and WebDAV.

## Install

For Android/KSWEB video thumbnails, no FFmpeg installation is required; compatible videos are decoded by the browser.

1. Point the web server document root at `public/`, and route unknown paths to
   `public/index.php`. Apache needs `mod_rewrite`; for nginx, adapt
   `deploy/nginx-security.conf.example`. This layout keeps `.env`, `config/`,
   `src/`, `database/`, `tools/` and `storage/` outside the web root, which is a
   stronger guarantee than any deny rule. If you must serve the project directory
   itself, Apache's bundled `.htaccess` denies those paths — other servers need
   the equivalent rules configured by hand.
2. Copy `.env.example` to `.env` and change the database credentials.
3. Create/import the database with `database/schema.sql`, then create the first
   administrator with `php tools/create-admin.php admin` — the schema seeds no
   account (see **Login**).
4. Ensure the PHP/web-server user can read/write `storage/` and `storage/.thumbnails/`.
5. Large uploads use the resumable chunk API, so `upload_max_filesize` and `post_max_size` only need to exceed `UPLOAD_CHUNK_MB` (8 MB by default). A practical PHP configuration is `upload_max_filesize=16M` and `post_max_size=20M`. The application-level per-file limit defaults to 5 GB.
6. Development: `php -S 127.0.0.1:8000 -t public`.

## Public share links

Any file can be handed out as a URL that works without an account. Images, GIFs,
video and audio open in a viewer page; everything else downloads.

Three public routes back a token:

| Route | Purpose |
|---|---|
| `/share/{token}` | Viewer page for media, direct download otherwise |
| `/share/{token}/raw` | The bytes, inline and range-capable |
| `/share/{token}/download` | The bytes, as an attachment |

Possession of the token is the credential, so these routes sit outside the
authenticated API guard and no session is started for a visitor — a share link
issues no cookie and leaves no session file behind.

Create, change the lifetime of, and revoke links from the **Share** button on any
file. Lifetimes range from one hour to never; `SHARE_EXPIRY_HOURS` sets the
default for new links. Revoking takes effect immediately. Administrators can list
every live link with `GET /api/shares/list`; creating and revoking a link is
recorded in the audit trail, readable through `GET /api/security/events`.

**A link belongs to its file, not to its path.** Deleting a file — to the
trash or for good, through the API or over WebDAV — revokes its links, so a
file later saved under the same name is never served to someone holding an old
link; moving or renaming the file, or a folder above it, carries its links
along.

A share URL ends in the file's extension, so `.htaccess`, `router.php` and
`deploy/nginx-security.conf.example` route `/share/` before any deny rule: a
shared `notes.log`, `dump.sql` or MPEG-TS `clip.ts` used to be refused with a
403. A deny rule of your own needs the same exemption.

**What a share link does not do.** Only image, video and audio types render
inline. Everything else — documents, archives, and in particular script-capable
content such as HTML and SVG — is sent as an attachment, and the bytes always
carry `X-Content-Type-Options: nosniff` and a `sandbox` CSP, so a shared file can
never execute markup on the application's origin. Shared pages are served
`X-Robots-Tag: noindex, nofollow`.

Shared video and audio are streamed with HTTP byte ranges, so a recipient can
seek without downloading the whole file.

## Thumbnails

Image thumbnails are generated on demand with GD, capped at 300px, encoded as
WebP and cached under `storage/.thumbnails/images`. The cache key includes the
source file's modification time, so replacing a file produces a new key and the
stale entry is simply never read again.

The server has no video decoder. A video's frame is decoded by the first
browser that sees it and posted back to `POST /api/thumbnail/video`, after
which it is served from the same cache as any image — so a video is decoded
once for everyone, rather than re-fetched and re-decoded by every visitor on
every load. Contributed frames are validated as real WebP/JPEG/PNG images,
bounded to 256 KB and 1280px, and re-encoded to WebP before being stored.

`GET /api/files/list` reports `hasThumbnail` for each video, so the browser
knows whether to fetch a cached frame or decode one, without having to ask and
treat the failure as an answer. Requesting a video thumbnail that has not been
generated yet returns `404`.

Thumbnail URLs carry the file's modification time and are therefore immutable,
so they are served `Cache-Control: private,max-age=31536000,immutable` with an
`ETag` for the reload case. Images use native `loading="lazy"` and declare
intrinsic dimensions, and video frames are decoded through an
`IntersectionObserver` two at a time.

Read-only endpoints release the PHP session lock as soon as authorization has
been decided. Without that, PHP's exclusive per-session file lock serialises
every request in a gallery no matter how many workers are free — the single
largest cost in loading a folder of images.

Images whose header declares more than 50 megapixels get no thumbnail (`422`).
GD holds a decoded image at up to four bytes a pixel whatever the file's size,
so a 285 KB PNG declaring 10000x10000 pixels took one request to 704 MB — and
`memory_limit` does not see the system libgd's allocations, so it is no guard.
JPEG thumbnails are turned by the photo's EXIF orientation, so a portrait phone
photo stands upright in the grid as it does when opened.

To clear the cache, delete `storage/.thumbnails/images`; it is rebuilt on
demand.

## Tests

```bash
php tests/run.php
```

The suite is plain PHP check scripts — no framework or Composer install is
needed. A script fails the run if it exits non-zero or emits any
warning/notice. The database-schema checks read `database/migrate.php` rather
than connecting, so no MySQL server is required.

## Required PHP extensions

`pdo`, `pdo_mysql`, `fileinfo`, `json`, `mbstring`; `zip` for multi-file ZIP downloads; `gd` for image thumbnails. OpenSSL is recommended. Remote storage protocols may additionally require `ftp`, `ssh2`, cURL, or an OS SMB client when those adapters are enabled.

## Migration map

- `server/index.ts`, `routes.ts` → `public/index.php` front controller/router.
- `server/config.ts` → `config/config.php` + `.env`.
- `server/auth.ts` → `src/Services/Auth.php` session authentication with CSRF protection.
- `server/fileRoutes.ts` → file API routes in `public/index.php` + `FileService`.
- `server/webdav.ts` → `src/Services/WebDav.php`.
- `server/shares.ts` → MySQL-backed `share_links` routes. Unlike the original in-memory Map, links survive restarts.
- `server/thumbnails.ts` → browser-decoded video frames plus GD/WebP image thumbnails and `.thumbnails` cache.
- `server/storage.ts`, `shared/schema.ts` → PDO repository + `database/schema.sql`.
- `client/src/App.tsx`, `file-manager.tsx`, `upload-dialog.tsx` → `views/pages/app.php`, `public/assets/js/app.js`, `public/assets/css/app.css`.
- React Query/Wouter/Radix/shadcn/Tailwind/Vite → native fetch, History/URL routing, HTML controls, deployable CSS; no Node build is required.

## Behaviour implemented differently

Share links are stored in MySQL instead of memory, improving restart persistence. The React component tree is replaced with server-delivered HTML and browser JavaScript. Image thumbnail generation uses GD. Video thumbnails are generated client-side with the browser's native video decoder and Canvas; the server only provides authenticated range-capable media streaming. WebDAV remains filesystem-backed and keeps the original methods: OPTIONS, PROPFIND, GET, PUT, DELETE, MKCOL and MOVE.

## Current limitation

The supplied project contains FTP/SFTP/SMB/HTTP storage-adapter logic. Server records, activation/default management, local file management, uploads, sharing, thumbnails and WebDAV are migrated. Protocol-specific remote browse/test/upload implementations require the corresponding PHP extension or system client and are not enabled by default in this portable build; the UI identifies configured remote servers rather than pretending those transports work without their runtime dependencies.


## Subdirectory installation

The application detects its own URL base path, so it runs at the web root (`/`)
or in a subdirectory such as `/Cloud-File-Hub-PHP/` with no configuration. API
requests, assets, in-app navigation and generated share links all follow the
detected base path.

Two entry points exist, and the one that runs decides where static assets are
requested from (see `Http::assetBase()`):

- **`public/index.php`** — used when the document root is `public/`. Preferred:
  it keeps `.env`, `config/`, `src/`, `database/` and `storage/` outside the web
  root entirely.
- **`index.php`** in the project root — used when the project directory itself
  is the document root, for example a shared host that serves a subdirectory.

For Apache, enable `mod_rewrite` and `AllowOverride All` so the bundled
`.htaccess` can route clean URLs and deny the application's internals. For nginx,
adapt `deploy/nginx-security.conf.example`. Any server must send unknown paths to
the front controller, otherwise clean URLs — including `/share/<token>` — will
404.

For PHP's built-in server, from inside the project directory:

```bash
php -S 0.0.0.0:8000 -t public      # open http://localhost:8000/
```

or, to serve the project from a parent directory as a subdirectory install:

```bash
php -S 0.0.0.0:8000 router.php     # open http://localhost:8000/
```

## Login

Authentication is database-backed and uses PHP sessions. **No account is created
for you.** Since Phase 4 the schema deliberately seeds no user, so create the
first administrator yourself:

```bash
php tools/create-admin.php admin
```

The tool prompts for a password (minimum 12 characters), stores an Argon2id hash
where available, and gives the account the `admin` role. Run it again with the
same username to reset that password. A role can be passed as a second
argument — `php tools/create-admin.php alice editor` — which is how non-admin
accounts were created before the Users screen existed.

## Moving, copying and searching

Select one or more items and use **Move** or **Copy**, or pick *Move to…* /
*Copy to…* from a file's ⋮ menu. Either way the destination is chosen by
browsing folders rather than typing a path.

`POST /api/files/move` and `POST /api/files/copy` take a list of source paths
and one destination folder. A failure on one item does not abandon the rest:
every source is attempted and the failures come back named, so twenty files
selected at once cannot report a bare success over a partial result. Neither
route overwrites silently — with `ALLOW_OVERWRITE=false` a name clash is a
409, otherwise the arriving item is given a `(2)` suffix. A folder cannot be
moved into itself, and moving an item into the folder it is already in is
refused; copying into the same folder is allowed, because that is how you
duplicate something. A copy is new bytes, so it has to fit the storage limit
and the copier's quota before it is made (`507` otherwise, per item).

`POST /api/files/rename` follows the same rule: it never replaces what already
has the name. With `ALLOW_OVERWRITE=false` a clash is a 409; otherwise the item
takes a free name and the response's `message` says so. Renaming a file to its
own name, or changing only its case on case-insensitive storage, is not a
clash.

The search box has two modes. **This folder** filters the listing already on
screen, so it stays instant. **All folders** calls `GET /api/files/search`,
which walks the tree below the folder you are in. That walk is bounded twice
over — by the number of results and by the number of entries examined — and
says so when it stops early, rather than silently returning a short list.

## Trash

Deleting moves an item to a trash inside the storage root; the **Trash**
screen restores it or removes it for good. This is on by default; set
`TRASH_ENABLED=false` to delete permanently instead, and
`TRASH_RETENTION_DAYS` (default 30, `0` to disable expiry) to say how long
items are kept. Expired entries are dropped as later deletions happen.

The trash keeps one directory per deletion, holding the item under its own
name plus a small metadata file recording where it came from, who deleted it
and when. Restore never overwrites: if something has since taken the original
name the item is restored beside it with a suffix, and a parent folder that
was deleted too is recreated. A restored item is charged again to whoever
uploaded it: the trash entry keeps its ledger rows (`attribution.json`, beside
the metadata and never listed), where it used to come back attributed to
nobody — upload, trash, restore stepped round any quota.

The trash lives at `.trash` inside the storage root, so moving a file into it
is always a same-filesystem rename — instant, and impossible to half-finish.
That directory is reserved: it is never listed, never searched, and cannot be
addressed through any file route, so the trash cannot be browsed or emptied
except through `/api/trash`. Listing the trash needs only read access;
restoring and purging need write access, like any other change to the store.

## Accounts and roles

Administrators manage accounts from the **Users** screen: create and delete
them, set the role, enable and disable them, and reset a password. Every
signed-in user can change their own password from the **Password** button,
which requires their current one.

| Role | Can |
|---|---|
| `viewer` | Browse, preview, download (including bulk ZIP) and create share links |
| `editor` | Also upload, rename, move and delete |
| `admin` | Also manage storage servers and accounts, and read the audit trail |

The API behind the screen is `/api/users` (administrator-only) plus
`POST /api/users/me/password` (any signed-in user). Password hashes are never
returned by any of them.

Every request that is not a read — anything but `GET`, `HEAD`, `OPTIONS` and
WebDAV's `PROPFIND` — needs the CSRF token and the `editor` role, WebDAV's
`MKCOL` and `MOVE` included. The guard used to list `POST`, `PUT`, `PATCH` and
`DELETE`, so a viewer could create folders and move any file over another.

Guards that cannot be bypassed by editing the page: you cannot delete your own
account, you cannot remove your own administrator access, and the last enabled
administrator cannot be disabled, demoted or deleted — otherwise nobody could
reach these settings again.

Role and enabled changes reach a signed-in user's existing session within a
minute; disabling an account ends its session rather than waiting for a sign
out. The check is throttled to roughly one query per user per minute so that a
gallery's worth of thumbnail requests does not each incur one.

Passwords are stored using PHP-compatible password hashes and are re-hashed to
the preferred algorithm on the next successful login. The browser no longer
receives a `WWW-Authenticate` header, so native Basic Auth popups are not used.

## Storage and quotas

Administrators get a **Storage** screen: what the store holds, how much of the
disk is free, what is reclaimable from the trash, a breakdown by folder and by
kind of file, the largest files, and how much each account has uploaded.

Measuring means walking the whole tree, so the figure is deliberately not
recomputed per request: it is cached for `USAGE_CACHE_SECONDS` (default 300),
the screen says how old it is, and **Recalculate** forces a fresh measurement.
The cache lives outside the storage root, so it is neither listed, searched,
nor counted in the number it holds. Bounding the walk was rejected — a
dashboard that stops counting early reports a number that is simply wrong.

Two optional limits, both `0` (unlimited) by default, checked on every way
bytes arrive — `POST /api/uploads/init` before a single byte is staged, and
likewise a copy, a WebDAV `PUT` and the legacy multipart upload:

| Setting | Caps |
|---|---|
| `STORAGE_LIMIT_GB` | the whole file store, measured from disk |
| `USER_QUOTA_GB` | what one account has uploaded |

Refusals come back as `507 INSUFFICIENT_STORAGE` and say where the caller
stands (`You have used 4.1 GB of your 5 GB quota`) — 5xx messages are
otherwise hidden, but a caller cannot act on what they are not told.

### What the per-account figure counts

The per-user quota needs to know who uploaded what, and nothing recorded that:
`file_metadata` was declared in `schema.sql` from the beginning and referenced
by no PHP at all. It is now an upload ledger, with an added nullable
`uploaded_by` column (`php database/migrate.php` adds it to an existing
installation).

It counts **bytes an account uploaded through CloudHub that are still on
disk**, by any route — the resumable upload, the multipart upload and WebDAV
`PUT`. Moves, renames, copies, deletes, trashing and restores all keep it in
step: trashing frees the quota, and a restore charges the uploader again. Files
that predate the feature, or that arrive by a change made directly on disk, are
unattributed: they count towards `STORAGE_LIMIT_GB` but towards nobody's
personal quota. A periodic sweep drops
rows whose file has since disappeared, so the ledger converges instead of
requiring every write in the system to remember it.

A copy is charged to whoever made it, not to the original uploader — a quota
avoided by uploading one file and copying it a hundred times is not a quota.

Every ledger operation fails open. A bookkeeping problem means "the quota does
not bind", never "a legitimate upload is refused".

## Upgrading an older Cloud File Hub database (v5)

Do not re-import `schema.sql` over an existing installation. Copy your existing `.env` into this release, back up the database, then run:

```bash
php database/migrate.php
```

The migration creates missing tables/columns/indexes without deleting existing
rows, and creates a local storage server only when there are no storage-server
rows. It covers every schema change through Phase 8 — including the
`login_attempts` and `security_events` tables and the `users.role` column — so
the files in `database/migrations/` do not need to be applied separately. An
existing account named `admin` is promoted to the `admin` role the first time
the role column is added.

The migration creates no user accounts. If the database has none, create one
with `php tools/create-admin.php admin`.


## Portable routing fix (v6)

The UI and API now use the root `index.php` front controller with a `route` query parameter. This means a subdirectory install such as `/Cloud-File-Hub-PHP/` works even when URL rewriting is unavailable, including PHP's built-in server started from a parent directory. Apache clean URLs remain supported by `.htaccess`.

From the project directory, use `php -S 0.0.0.0:8000 router.php`. Prefer it over a
bare `php -S 0.0.0.0:8000` from the parent directory: the built-in server does not
read `.htaccess`, so only `router.php` applies the rules that keep `.env`, `config/`,
`src/` and `database/` from being served.

## Upload system

The file manager uses an in-application upload dialog rather than opening a hidden file input directly. The dialog shows the current target directory, selected files and sizes, configured limits, upload progress, transferred bytes, and explicit success/error states.

Upload progress is implemented with `XMLHttpRequest` because its `upload.onprogress` event exposes bytes transferred while the request body is being sent. Authentication remains session-based and the request includes the application's CSRF token. After a successful upload the current directory is refreshed automatically.

Both layers validate uploads. Browser-side checks provide immediate feedback for `MAX_UPLOAD_FILES` and `MAX_UPLOAD_MB`; `public/index.php` repeats those checks and handles PHP's native `UPLOAD_ERR_*` conditions. Server validation is authoritative. The effective PHP configuration (`upload_max_filesize` and `post_max_size`) must be large enough for the limits configured in `.env`.

Relevant files:

- `views/pages/app.php` — upload dialog markup and configured limit values.
- `public/assets/js/app.js` — selection validation, progress events, status messages, cancellation, and directory refresh.
- `public/assets/css/app.css` — responsive dialog, progress, and success/error presentation.
- `public/index.php` — upload endpoint and server-side validation/error translation.


## Navigation URL handling

Application page links use the installation base directory rather than exposing
`index.php` in the browser URL. For example, a subdirectory installation uses
`/Cloud-File-Hub-PHP/` for Files and query-string routes for other application
pages. The front controller remains an internal implementation detail.

Legacy requests to `/index.php` and `/public/index.php` are normalized to the
Files route for compatibility with cached links and older package versions.
API requests continue to use the portable `route` query parameter so the
application works when installed in a subdirectory without clean-URL rewriting.


## Integrated file previews

The Files view now includes an authenticated preview dialog.

Supported inline previews:

- Images: JPEG, PNG, GIF, WebP, BMP and AVIF where supported by the browser. SVG
  is fetched as text and drawn through an `<img>` from a Blob, where nothing in
  it can run.
- Video: MP4, WebM, OGV, MOV and M4V where the browser has a compatible codec; thumbnails are generated client-side without FFmpeg.
- Audio: MP3, WAV, OGG, M4A, AAC and FLAC where browser codec support is available.
- PDF: embedded using the browser PDF viewer.
- Text/source: common text, web, PHP, SQL, configuration and source-code extensions.

The `/api/files/preview` endpoint uses the same authenticated filesystem
sanitisation as downloads and only permits MIME types suitable for inline
display. Text and source files — JSON, XML, HTML, SVG, scripts, configuration
— are served as `text/plain` with `nosniff`, so markup previews as its source
and can never execute on this origin. The dialog asks for the first 512 KB with
a `Range` header and says when a file was cut short, rather than downloading a
multi-gigabyte log to show its start.

Downloads are handed to the browser's own download manager, which streams them
to disk; the page used to read the whole file into memory first. A `HEAD`
request goes first, so a missing file is reported rather than saved as a file
holding an error, and names outside ASCII travel in `filename*`.

Existing image thumbnails continue to use the on-disk thumbnail cache. Full
media is loaded only after the user requests a preview.


## Checking what the storage can do

When uploads are slow, or a deployment cannot write where it expects to, the
answer is usually in what the filesystem underneath actually does. Two ways to
ask, both producing the same report:

```text
php tools/storage-check.php          facts only
php tools/storage-check.php 32       and measure 32 MB of throughput
```

```text
GET /api/system/storage              facts only, administrator-only
GET /api/system/storage?measure=1&mb=16
```

It reports, for ROOT_DIR, the upload staging directory, the thumbnail cache and
`logs/`: whether PHP can genuinely create and remove files there (a real probe,
not `is_writable()`, which Android shared storage answers unreliably), how many
entries each holds, the PHP version and SAPI, the request-size limits with a
warning when one sits at or below the chunk size, the deployed commit, and
whether finishing an upload is an instant rename or a whole-file copy.

**Prefer the endpoint on Android.** A KSWEB install may not expose PHP CLI at
all, and where a shell is available it is often a different PHP — Termux, for
instance — which would report its own `php.ini`, user and permissions rather
than the ones actually serving uploads.

The endpoint is administrator-only and reports directory entry counts, never
filenames. `?measure=1` writes and reads a probe file, so it is opt-in and `mb`
is capped; without it the call is cheap.

## Duplicate finder

Finds photos and videos that are stored more than once. Matches are
**byte-identical only**: a match is never a judgement call, so deleting one copy
cannot lose a file that was merely similar. A resized or re-encoded copy is a
different file and is not reported, and perceptual matching is not possible for
video in this application at all, which has no server-side decoder and extracts
video frames in the browser.

Almost none of the work happens. Files are grouped by exact byte size first,
then by a hash of their first and last 64 KB, and only what survives both is
read in full. Measured on 440 files across 92.5 MB, size grouping alone
discarded 356 of them before a single byte was read.

### Protocol

Scanning is a poll loop, because hashing a media library does not finish inside
one request on a phone:

1. `POST /api/duplicates/scan` with `{"path": "/", "restart": true}` starts a
   scan and does one bounded slice of the work.
2. `POST /api/duplicates/scan` with `{"path": "/"}` continues it. Repeat while
   the response has `"done": false`.
3. `GET /api/duplicates/scan` returns the last result without doing any work.
4. `DELETE /api/duplicates/scan` discards the saved scan state.

Each slice runs for `DUPLICATE_SCAN_SECONDS` and returns:

```json
{
  "path": "/", "done": false, "truncated": false,
  "scanned": 440, "candidates": 84, "hashed": 120, "computed": 118, "toHash": 164,
  "duplicateFiles": 40, "reclaimable": 8798208,
  "startedAt": "2026-09-02T14:57:54+00:00", "finishedAt": null,
  "groups": [
    { "bytes": 220000, "count": 2, "reclaimable": 220000,
      "files": [ { "path": "/Photos/p1.jpg", "bytes": 220000, "mtime": 1788360664 } ] }
  ]
}
```

`scanned` counts media files walked and `candidates` how many shared a size with
another file; `hashed` is progress through those and `computed` how many digests
were actually calculated rather than reused from cache, so a re-scan of an
unchanged library reports `computed: 0`. `reclaimable` is what deleting the
extra copies would free — group size × (copies − 1), never × copies.
`truncated` is true when the walk hit `DUPLICATE_MAX_FILES`, so a client can say
the result is partial rather than quietly under-reporting.

Digests are cached by path, size and mtime. A cached digest is only reused when
the file is strictly older than the moment it was hashed, because mtime has
one-second granularity and a file rewritten in the same second would otherwise
keep a stale digest — which in this feature means reporting two files as
identical when they are not. No mtime-keyed cache can detect a write that
preserves the timestamp; deleting `storage/.cache/duplicate-hashes.json` forces
a full re-read.

### Permissions

Reading a finished scan needs only read access. **Starting one requires an
editor account**, because a scan walks the whole store and reads files — the
same on-demand expense `/api/storage/me` declines to hand every account. A
viewer can therefore see what a scan found but cannot trigger one.

### Deleting duplicates

There is no bulk-delete endpoint. Clients call the ordinary
`DELETE /api/files/delete` once per file, which moves each to the trash, honours
`ALLOW_DELETE`, forgets the ledger row and writes the audit entry. Keeping one
copy per group is a client-side affordance and the server does not enforce it:
deleting every copy is something the file list already allows, so the scan adds
no capability that did not exist.

### Other clients

The endpoints are plain JSON with no browser-specific behaviour, and the
Android build (`TippieNL/Cloudhub-2`) already has what it needs to call them:
its OkHttp client keeps the session cookie jar and sends `X-CSRF-Token` on
mutating requests. Authenticate with `POST /api/auth/login`, which returns
`csrfToken` and sets the session cookie; `GET /api/auth/status` returns the
token again for a restored session. A native client does not send
`Sec-Fetch-Site: cross-site`, so the cross-site guard does not apply to it.

`GET /api/files/config` publishes `duplicateMinBytes`, `duplicateScanSeconds`
and `duplicateMaxFiles` alongside the upload limits, so a client reads them from
the server rather than keeping its own copy of the defaults.

### Relevant environment settings

```ini
DUPLICATE_MIN_BYTES=1024
DUPLICATE_SCAN_SECONDS=8
DUPLICATE_MAX_FILES=50000
```

Files below `DUPLICATE_MIN_BYTES` are skipped: every empty file is identical to
every other one, which would otherwise produce a single enormous and useless
group.


## Resumable large-file uploads

Cloud File Hub now uploads files in configurable chunks rather than one large
`multipart/form-data` request. The default application limits are **5 GB per
file** (`MAX_UPLOAD_MB=5120`) and **150 files per batch**
(`MAX_UPLOAD_FILES=150`, where `0` means no limit); the default chunk size is
8 MB.

The protocol is:

1. `POST /api/uploads/init` creates or resumes a staging session.
2. `PUT /api/uploads/chunk` sends one raw chunk at the server-confirmed offset.
3. `GET /api/uploads/status` returns the confirmed byte offset for recovery.
4. `POST /api/uploads/complete` validates the byte count and moves the staged
   file into its destination.
5. `DELETE /api/uploads/cancel` removes an explicitly cancelled staging upload.

Transient chunk failures are retried with exponential backoff. Before a retry,
the browser re-queries the server offset, so a lost HTTP response does not cause
the same bytes to be appended twice. Retrying the same file selection resumes
from the staged offset while the staging session still exists.

### Conflict handling

`UPLOAD_CONFLICT` can be `rename`, `overwrite`, or `reject`. The upload dialog
also exposes this choice per upload batch. `rename` is the default and produces
names such as `video (1).mp4`. `overwrite` still respects `ALLOW_OVERWRITE`.

The legacy multipart `POST /api/files/upload` takes the same rule as a
`conflict` form field, defaulting to `rename`; it used to move the upload
straight over an existing file of that name.

### Abandoned upload cleanup

Incomplete data is stored under the application-owned `storage/uploads` directory by default. Sessions older than
`UPLOAD_ABANDON_HOURS` (24 hours by default) are removed automatically whenever
a new upload starts. Administrators may also invoke the authenticated
`POST /api/uploads/cleanup` endpoint. For low-traffic installations, a scheduled
request or CLI maintenance task can invoke cleanup periodically.

### Relevant environment settings

```ini
MAX_UPLOAD_MB=5120
MAX_UPLOAD_FILES=150
UPLOAD_CHUNK_MB=8
UPLOAD_RETRY_COUNT=3
UPLOAD_ABANDON_HOURS=24
UPLOAD_CONFLICT=rename
```

`MAX_UPLOAD_MB=5120` is an application policy limit, not a requirement to allow
5 GB PHP request bodies. Keep PHP request limits modestly above the configured
chunk size.

At the default of 5120 MB a **64-bit PHP build is required**, not merely
recommended: `filesize()`, `fseek()` and the offsets the chunk protocol writes
at all go through PHP's signed integer, so a 32-bit runtime accepts a file past
2 GB and then mis-handles it. `tools/storage-check.php` (and
`GET /api/storage/check`) reports this as a warning when the build cannot meet
the configured limit. The destination filesystem must also support files that
large -- FAT32, which some removable cards still use, caps a single file at 4 GB.

`MAX_UPLOAD_FILES` bounds how many files may be queued in one batch; `0` removes
the bound. It applies to the browser's resumable uploads. The legacy
`POST /api/files/upload` multipart route is separately bound by PHP's own
`max_file_uploads` (20 by default), which silently truncates `$_FILES` past that
count -- so that route refuses a request reaching the ceiling rather than
reporting success for the files that survived.


## v10.1 upload staging repair

Resumable upload staging is no longer derived from `ROOT_DIR`. It defaults to
`storage/uploads`, keeping temporary upload state separate from the served file
tree. Set `UPLOAD_STAGING_DIR` to an absolute writable directory to override it.

Metadata is written atomically through a temporary file and rename. Corrupt
metadata left by an interrupted request is automatically discarded when the
same upload is initialised again. The service also performs a real write probe
so permission failures are reported before chunk transfer begins.

The PHP/web-server user must have write permission to both `storage/uploads`
and the configured file-server destination.


## v10.2 Android shared-storage compatibility

Upload staging now tests actual create/write/delete operations instead of
requiring Unix-style `is_writable()` behaviour or advisory `flock()` support.
Metadata temporary files are written without `LOCK_EX` and are still committed
using an atomic rename. This supports PHP installations under Android paths such
as `/storage/emulated/0/htdocs`.


## v10.3 large video/audio preview streaming

The authenticated preview endpoint now implements HTTP byte-range streaming for
large media. Browsers may request only the sections they need for metadata,
playback and seeking instead of forcing PHP to transmit the entire file.

Supported behaviour includes:

- `GET` and `HEAD` preview requests.
- `Accept-Ranges: bytes`.
- Single `Range: bytes=start-end` requests.
- Open-ended and suffix byte ranges.
- `206 Partial Content` with `Content-Range`.
- `416 Range Not Satisfiable` for invalid ranges.
- Bounded 1 MiB PHP streaming buffers.
- Early termination when the client disconnects.

This improves large MP4/video and audio previews while preserving the existing
authenticated path validation and MIME restrictions.

An open-ended range for inline media is answered with at most 8 MB, which HTTP
allows and every player handles by asking for the next piece. PHP's built-in
server serves one request at a time, and one playing film used to hold it
against every other request. Attachments are never shortened: a download
manager resuming one reads a short answer as the rest of the file.


## v11 modern file-management UX

The Files screen now supports drag-and-drop upload selection, a visible upload
queue with per-file progress, persistent grid/list views, persistent sorting,
multi-select bulk download/delete controls, contextual file menus, improved
breadcrumbs, and application-owned confirmation/input dialogs. File cards also
support desktop right-click menus and double-click open/preview behaviour.

The existing resumable 2 GB upload protocol and HTTP range media streaming are
unchanged; the new interface sits on top of those stable v10.3 APIs.


## v11.1 upload UI/retry fixes

- File-toolbar buttons now use a consistent control height on mobile.
- Recoverable chunk retries no longer display the alarming
  "Connection interrupted" message while an upload is successfully continuing.
- During a transient retry, the active queue item displays "Retrying chunk…".
- Genuine failures are still reported by the upload error state after the retry
  budget is exhausted; resumable upload behaviour is unchanged.


## v12 Phase 2 — Core security foundation

Centralized HTTP/browser security policy, hardened sessions, session expiry,
password rehash migration, stronger CSRF checks, nonce-based CSP/security
headers, and explicit production HTTPS/reverse-proxy/HSTS configuration have
been added. See `SECURITY.md`.

This phase does not claim production readiness.


## v12 Phase 3 — Filesystem hardening

Strict storage-root containment, traversal rejection, symlink denial, safer
mutation destinations, root deletion protection and hardened WebDAV path
handling have been added. See `SECURITY.md` and
`deploy/nginx-security.conf.example`.


## v12 Phase 4 — Authentication hardening

Adds database-backed login throttling, per-user and per-IP attempt limits,
HMAC-pseudonymised throttle keys, periodic authenticated session-ID rotation,
Argon2id preference/automatic password rehashing, and removes the predefined
admin account from fresh-install schema.

Existing installations pick this up from `php database/migrate.php`.
Administrators are created with `php tools/create-admin.php admin`.


## v12 Phase 5 — API hardening

Adds bounded JSON parsing, media-type enforcement, validation helpers, bulk
request limits, stable JSON errors/request IDs, safe 5xx responses and
predictable API route handling.


## v12 Phase 6 — Authorization hardening

Adds viewer/editor/admin roles, capability enforcement and per-user resumable
upload-session ownership. Existing installations pick this up from
`php database/migrate.php`.


## v12 Phase 6.1 — basePath hotfix

Restores `Http::basePath()`, which is required by `public/index.php` when
CloudHub is installed in a subdirectory such as `/Cloud-File-Hub-PHP`.
A regression test covers root, subdirectory, and `/public` entry-point paths.


## v12 Phase 7 — Security audit trail

Adds a database-backed `security_events` audit trail with request IDs, user,
event type, outcome, IP, user agent and redacted JSON context. Login success/
failure, logout and key file mutations are recorded. Administrators can query
recent events through `/api/security/events`. Audit logging is best-effort and
does not break the primary operation if logging itself fails.

## v12 Phase 8 — Uploaded-content and deployment hardening

User-controlled active content is no longer rendered inline. HTML and other
script-capable text types download as attachments; inline preview is restricted
to media, PDF and plain text. Public share responses add `nosniff` and a
restrictive sandbox CSP. Apache rules additionally deny common backup, SQL,
log, INI and development/documentation artefacts.

When the project directory itself is the document root, `.htaccess` and
`router.php` also refuse every dot-segment — `/.git/config` and `.gitignore`
used to be served — except `/.well-known/`, which ACME certificate renewal
answers from, and the Node/React trees this port sits beside (`client/`,
`server/`, `shared/`, `script/`, `node_modules/`) with their tool configs.

Existing installations pick this up from `php database/migrate.php`.
