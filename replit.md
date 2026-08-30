# HTTP + WebDAV File Server

## Overview
A production-ready HTTP + WebDAV File Server built with Node.js, Express, and React. Features a browser-based file manager UI, full WebDAV protocol support, image thumbnail generation with caching, and secure shareable public URLs.

## Architecture
- **Frontend**: React + Vite + Tailwind CSS + shadcn/ui components
- **Backend**: Express.js with file management API and WebDAV handler
- **Database**: PostgreSQL (Neon-backed) via drizzle-orm for storage server management
- **Authentication**: Basic Authentication (configured via env vars)
- **Storage**: Local filesystem (default) + multi-server support (Local, FTP, SFTP, SMB/CIFS, HTTP API)
- **Thumbnails**: sharp library for on-demand WebP generation, cached in .thumbnails/
- **Shares**: In-memory token store with optional TTL expiration
- **Error Handling**: Centralized AppError system with structured JSON responses, environment-aware stack traces, React Error Boundary

## Project Structure
```
server/
  index.ts        - Express server entry, global error handler, process-level exception handlers
  routes.ts       - Route registration (mounts file API, thumbnails, shares, WebDAV)
  config.ts       - Environment config + path sanitization
  auth.ts         - Basic Authentication middleware (structured error responses)
  errors.ts       - AppError class, error codes, factory helpers (badRequest, notFound, forbidden, etc.)
  logger.ts       - Structured logger with sensitive field sanitization, env-aware output
  fileRoutes.ts   - File management REST API endpoints
  thumbnails.ts   - Thumbnail generation route (GET /api/thumbnail)
  shares.ts       - Share token CRUD + public download handler
  webdav.ts       - WebDAV protocol handler
  storage.ts      - Database storage interface (server CRUD + file metadata via drizzle-orm)
  adapters.ts     - StorageAdapter pattern (Local/FTP/SFTP/SMB/HTTP API) with upload/delete/test/listFiles
  serverRoutes.ts - Server management API routes (CRUD, test, toggle, set-default, browse)
  vite.ts         - Vite dev server setup
  static.ts       - Static file serving (production)

client/src/
  App.tsx          - Main app with auth, routing, theme, Error Boundary wrappers
  lib/api.ts       - Auth utilities, file helpers, isThumbnailable
  lib/queryClient.ts - React Query client with structured error parsing
  lib/constants.ts - Shared UI constants (FILE_ICON_MAP, SERVER_TYPE_LABELS, SERVER_TYPE_BADGE_CLASSES)
  lib/errorHandler.ts - Client-side AppError, parseApiError, getErrorMessage, handleError
  pages/file-manager.tsx - File manager UI with thumbnails + share
  pages/server-management.tsx - Storage server management admin page
  pages/server-browser.tsx - Remote server file browser UI
  components/error-boundary.tsx - React Error Boundary with fallback UI
  components/upload-dialog.tsx - File upload dialog with server selector
  components/theme-provider.tsx - Light/dark theme toggle

shared/
  schema.ts       - Shared types + Drizzle ORM tables (storageServers, fileMetadata)

storage/           - Default file storage root directory
storage/.thumbnails/ - Cached thumbnail WebP files
```

## Error Handling Architecture

### Structured API Error Response Format
All API errors return:
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Human-readable message",
    "details": [{"field": "email", "message": "Invalid format"}],
    "stack": "... (development only)"
  }
}
```

### Error Codes
`VALIDATION_ERROR`, `NOT_FOUND`, `UNAUTHORIZED`, `FORBIDDEN`, `CONFLICT`, `INTERNAL_ERROR`, `RATE_LIMITED`, `BAD_REQUEST`, `SERVICE_UNAVAILABLE`

### Server-Side Error Flow
1. Route handlers throw `AppError` instances (via factory helpers: `badRequest()`, `notFound()`, `forbidden()`, etc.)
2. Zod validation errors are caught and mapped to `VALIDATION_ERROR` with field-level details
3. Global error middleware in `index.ts` catches all errors, logs via structured logger, returns JSON
4. Development: includes stack traces in response; Production: hides stack traces, logs full details
5. `process.on('uncaughtException')` and `process.on('unhandledRejection')` prevent silent crashes

### Client-Side Error Flow
1. `queryClient.ts` → `throwIfResNotOk` parses structured error response via `parseApiError()`
2. Per-mutation `onError` handlers display contextual toast messages using `getErrorMessage()`
3. `getErrorMessage()` maps status codes and error codes to user-friendly strings
4. `ErrorBoundary` component wraps the app to prevent blank screens on uncaught React errors
5. `window.onunhandledrejection` handler shows toasts for uncaught promise rejections
6. Toast auto-dismiss after 5 seconds

### Logger (server/logger.ts)
- Development: human-readable console output with timestamps
- Production: structured JSON to stdout/stderr
- Sensitive fields automatically redacted: password, privateKey, apiKey, authorization, secret, token
- Log levels: info, warn, error
- Request logger: method, path, status, duration, sanitized body

## Environment Variables
- PORT - Server port (default: 5000)
- NODE_ENV - Environment mode (development/production)
- ROOT_DIR - Storage root directory (default: ./storage)
- AUTH_USER - Basic auth username (default: admin)
- AUTH_PASS - Basic auth password (default: admin)
- READ_ONLY - Read-only mode (default: false)
- ALLOW_DELETE - Allow file deletion (default: true)
- ALLOW_OVERWRITE - Allow file overwrite (default: true)
- HTTPS_ENABLED - HTTPS mode (default: false)
- SHARE_EXPIRY_HOURS - Default share link expiry (default: 0 = never)

## API Endpoints
- GET /api/files/config - Server configuration
- GET /api/files/list?path= - List directory contents
- GET /api/files/download?path= - Download file
- POST /api/files/download-zip - Download multiple as ZIP
- POST /api/files/upload - Upload files (multipart)
- POST /api/files/mkdir - Create directory
- DELETE /api/files/delete - Delete file/directory
- POST /api/files/rename - Rename/move file
- GET /api/thumbnail?path= - Get/generate image thumbnail (auth required)
- POST /api/shares/create - Create share link (auth required)
- DELETE /api/shares/revoke - Revoke share link (auth required)
- GET /api/shares/list - List active share links (auth required)
- GET /share/:token - Public file download via share token (no auth)

### Storage Server Management (auth required)
- GET /api/servers - List all storage servers
- GET /api/servers/active - List active servers only
- GET /api/servers/:id - Get server details
- POST /api/servers - Create storage server
- PUT /api/servers/:id - Update storage server
- DELETE /api/servers/:id - Delete storage server
- POST /api/servers/:id/test - Test server connection
- POST /api/servers/:id/toggle - Toggle active status
- POST /api/servers/:id/set-default - Set as default server
- GET /api/servers/:id/browse?path= - Browse files on a storage server

## WebDAV Endpoints
- /webdav/* - Full WebDAV support (PROPFIND, GET, PUT, DELETE, MKCOL, MOVE)

## Running
```
npm run dev    # Development with hot reload
npm run build  # Production build
npm start      # Production server
```

## Security Measures (Feb 2026)
- Path traversal prevention: URI decoding + segment filtering + root containment with trailing separator
- Upload filename sanitization: path.basename stripping + root containment verification
- Symlink escape protection: isSymlinkEscapingRoot checks on all read/write/delete/move paths and ZIP generation
- Timing-safe credential comparison via crypto.timingSafeEqual
- Rate limiting: 100 requests per 15 minutes via express-rate-limit on /api and /webdav
- Security headers: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, conditional HSTS
- X-Powered-By header removed
- Upload size limits: 100MB per file, 20 files per request (multer)
- Content-Disposition header injection prevention
- Config API no longer exposes server rootDir
- Default credential warning at startup
- Share tokens: 32-byte crypto.randomBytes, unguessable, optional TTL expiration
- Thumbnail route: validates image extensions, path sanitization, symlink checks
- No stack traces in production error responses
- Sensitive fields redacted from logs (password, apiKey, privateKey, authorization, secret, token)

## WebDAV Client Connection
Windows: Map network drive to http://hostname:port/webdav/
macOS: Finder > Go > Connect to Server > http://hostname:port/webdav/
