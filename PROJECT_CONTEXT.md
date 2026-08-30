# HTTP + WebDAV File Server — Project Context Document

## Metadata

| Field           | Value                                              |
|-----------------|----------------------------------------------------|
| **Date**        | February 23, 2026                                  |
| **Purpose**     | Full project context for continuity and reference   |
| **Primary Intent** | Production-ready file server with browser UI, WebDAV, thumbnails, and sharing |
| **Tags**        | `file-server`, `webdav`, `react`, `express`, `security` |

---

## 1. Contextual Background

### 1.1 Subject

A self-hosted HTTP + WebDAV File Server that combines a
browser-based file manager with native OS network drive mounting.
Built for users who need a simple, secure way to manage files
remotely through either a web browser or a WebDAV client.

### 1.2 Objectives

- Provide a polished browser UI for file management (browse,
  upload, download, create directories, rename, delete)
- Support full WebDAV protocol for Windows/macOS network drive
  mounting
- Generate and cache image thumbnails for visual file browsing
- Enable secure, shareable public URLs with optional expiration
- Enforce security best practices at every layer

### 1.3 Key Points

- Single-server architecture: Express serves both the REST API
  and the React frontend
- Authentication via HTTP Basic Auth (configurable credentials)
- All file operations respect configurable permissions
  (read-only, delete, overwrite)
- Media files (images, video, audio, PDF) display inline when
  shared; other types download automatically

---

## 2. Architecture

### 2.1 Technology Stack

| Layer          | Technology                                      |
|----------------|-------------------------------------------------|
| Frontend       | React + Vite + Tailwind CSS + shadcn/ui         |
| Backend        | Express.js (TypeScript)                         |
| Authentication | HTTP Basic Authentication                       |
| Storage        | Local filesystem (configurable root directory)  |
| Thumbnails     | sharp (WebP, 300px max, quality 75)             |
| Share Tokens   | In-memory Map with optional TTL                 |
| WebDAV         | Custom handler (PROPFIND, GET, PUT, DELETE, MKCOL, MOVE) |

### 2.2 Project Structure

```
server/
  index.ts          Express server entry point
  routes.ts         Route registration (file API, thumbnails, shares, WebDAV)
  config.ts         Environment config + path sanitization
  auth.ts           Basic Authentication middleware
  fileRoutes.ts     File management REST API endpoints
  thumbnails.ts     Thumbnail generation (GET /api/thumbnail)
  shares.ts         Share token CRUD + public download handler
  webdav.ts         WebDAV protocol handler
  vite.ts           Vite dev server setup (DO NOT MODIFY)
  static.ts         Static file serving (production)

client/src/
  App.tsx            Main app with auth, routing, theme
  lib/api.ts         Auth utilities, file helpers, isThumbnailable
  lib/queryClient.ts React Query client with auth headers
  pages/
    file-manager.tsx File manager UI with thumbnails + share
  components/
    upload-dialog.tsx    File upload dialog
    theme-provider.tsx   Light/dark theme toggle

shared/
  schema.ts          Shared TypeScript types (FileItem, ServerConfig)

storage/             Default file storage root directory
storage/.thumbnails/ Cached thumbnail WebP files
```

---

## 3. Features (Confirmed & Implemented)

### 3.1 File Manager UI

- [x] Directory browsing with breadcrumb navigation
- [x] File upload (multipart, 100MB limit, 20 files max)
- [x] Single file download
- [x] Multi-file ZIP download
- [x] Directory creation
- [x] File/directory rename
- [x] File/directory deletion (configurable)
- [x] Image thumbnail previews in file listing
- [x] Light/dark theme toggle
- [x] Responsive layout

### 3.2 WebDAV Protocol

- [x] PROPFIND (directory listing with properties)
- [x] GET (file download)
- [x] PUT (file upload/overwrite)
- [x] DELETE (file/directory removal)
- [x] MKCOL (directory creation)
- [x] MOVE (rename/move)
- [x] Compatible with Windows Map Network Drive
- [x] Compatible with macOS Finder Connect to Server

### 3.3 Thumbnail System

- [x] On-demand WebP generation (300px max dimension)
- [x] MD5-based cache filenames (basename + path + mtime)
- [x] Cached in `storage/.thumbnails/`
- [x] `.thumbnails` directory hidden from UI listings
- [x] Concurrency limit (3 simultaneous generations)
- [x] Supported formats: JPG, JPEG, PNG, GIF, WebP, BMP, TIFF
- [x] Frontend blob URL management with proper cleanup
- [x] AbortController for cancelled fetch requests

### 3.4 File Sharing

- [x] Create shareable public URLs (no auth required to access)
- [x] 32-byte cryptographically random tokens
- [x] Optional TTL expiration (configurable default via env)
- [x] Automatic expired token cleanup (hourly)
- [x] Revoke individual share links
- [x] List all active share links
- [x] Deduplication (reuses existing token for same file)
- [x] Inline display for media types (images, video, audio, PDF,
      text); attachment download for other types

---

## 4. API Reference

### 4.1 File Management (auth required)

| Method | Endpoint                  | Description                |
|--------|---------------------------|----------------------------|
| GET    | `/api/files/config`       | Server configuration       |
| GET    | `/api/files/list?path=`   | List directory contents    |
| GET    | `/api/files/download?path=` | Download single file     |
| POST   | `/api/files/download-zip` | Download multiple as ZIP   |
| POST   | `/api/files/upload`       | Upload files (multipart)   |
| POST   | `/api/files/mkdir`        | Create directory           |
| DELETE | `/api/files/delete`       | Delete file/directory      |
| POST   | `/api/files/rename`       | Rename/move file           |

### 4.2 Thumbnails (auth required)

| Method | Endpoint              | Description                    |
|--------|-----------------------|--------------------------------|
| GET    | `/api/thumbnail?path=` | Get or generate image thumbnail |

### 4.3 Shares (auth required for management)

| Method | Endpoint              | Description               |
|--------|-----------------------|---------------------------|
| POST   | `/api/shares/create`  | Create share link          |
| DELETE | `/api/shares/revoke`  | Revoke share link          |
| GET    | `/api/shares/list`    | List active share links    |

### 4.4 Public Access (no auth)

| Method | Endpoint          | Description                      |
|--------|-------------------|----------------------------------|
| GET    | `/share/:token`   | Access shared file via token     |

### 4.5 WebDAV

| Method    | Endpoint     | Description            |
|-----------|--------------|------------------------|
| PROPFIND  | `/webdav/*`  | Directory listing      |
| GET       | `/webdav/*`  | File download          |
| PUT       | `/webdav/*`  | File upload/overwrite  |
| DELETE    | `/webdav/*`  | Delete file/directory  |
| MKCOL     | `/webdav/*`  | Create directory       |
| MOVE      | `/webdav/*`  | Rename/move            |

---

## 5. Configuration

### 5.1 Environment Variables

| Variable             | Default      | Description                          |
|----------------------|--------------|--------------------------------------|
| `PORT`               | `5000`       | Server listening port                |
| `ROOT_DIR`           | `./storage`  | File storage root directory          |
| `AUTH_USER`          | `admin`      | Basic auth username                  |
| `AUTH_PASS`          | `admin`      | Basic auth password                  |
| `READ_ONLY`          | `false`      | Disable all write operations         |
| `ALLOW_DELETE`       | `true`       | Allow file/directory deletion        |
| `ALLOW_OVERWRITE`    | `true`       | Allow file overwrite on upload       |
| `HTTPS_ENABLED`      | `false`      | Enable HSTS header                   |
| `SHARE_EXPIRY_HOURS` | `0`          | Default share link expiry (0 = never)|

### 5.2 Secrets

| Secret            | Purpose                              |
|-------------------|--------------------------------------|
| `SESSION_SECRET`  | Session management (available in env)|

---

## 6. Security Measures

### 6.1 Path & Filesystem Protection

- URI decoding + segment filtering + root containment with
  trailing separator prevents path traversal
- `path.basename` stripping + root containment verification
  sanitizes upload filenames
- `isSymlinkEscapingRoot` checks on all read/write/delete/move
  paths and ZIP generation prevent symlink escape

### 6.2 Authentication & Rate Limiting

- Timing-safe credential comparison via `crypto.timingSafeEqual`
- Rate limiting: 100 requests per 15 minutes on `/api` and
  `/webdav` via `express-rate-limit`
- Startup warning when default credentials are in use

### 6.3 HTTP Headers

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- Conditional `Strict-Transport-Security` (when HTTPS enabled)
- `X-Powered-By` header removed

### 6.4 Upload Limits

- 100MB maximum file size (per file)
- 20 files maximum per upload request (multer)

### 6.5 Output Safety

- Content-Disposition header injection prevention (filename
  sanitization)
- Config API does not expose server `rootDir`
- Share tokens: 32-byte `crypto.randomBytes`, unguessable

---

## 7. Decisions & Rationale

| Decision                           | Rationale                                   |
|------------------------------------|---------------------------------------------|
| In-memory share store              | Simplicity; no database dependency needed   |
| MD5-based thumbnail cache keys     | Deterministic, includes mtime for invalidation |
| Blob URL approach for thumbnails   | Required to send auth headers with image requests |
| Concurrency limit on thumbnails    | Prevents CPU/memory spikes from sharp       |
| Inline Content-Disposition for media | Users expect images/video to display, not download |
| Hourly expired token cleanup       | Balance between memory and CPU overhead     |

---

## 8. Client Connection Guide

### Windows

Map Network Drive to: `http://hostname:port/webdav/`

### macOS

Finder > Go > Connect to Server > `http://hostname:port/webdav/`

---

## 9. Running the Project

```bash
npm run dev    # Development with hot reload
npm run build  # Production build
npm start      # Production server
```

---

## 10. Known Limitations

- Share tokens are stored in memory; they are lost on server
  restart
- Thumbnail generation uses sharp which requires native
  binaries (handled by Nix on Replit)
- No multi-user support; single set of credentials
- WebDAV does not support LOCK/UNLOCK (some clients may warn)

---

## 11. Version Control

| Version | Date           | Description                                       |
|---------|----------------|---------------------------------------------------|
| 1.0     | Feb 2026       | Initial build: file manager UI, REST API, WebDAV  |
| 1.1     | Feb 2026       | Security audit: 9 vulnerability fixes             |
| 1.2     | Feb 2026       | Thumbnail generation with caching                 |
| 1.3     | Feb 2026       | Share token system with public URLs               |
| 1.4     | Feb 2026       | Thumbnail memory leak fixes (AbortController, blob cleanup) |
| 1.5     | Feb 23, 2026   | Inline display for shared media files (images, video, audio, PDF) |
