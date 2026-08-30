<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>File Server</title>
    <link rel="icon" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/favicon.png">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/css/app.css?v=<?= (int)@filemtime(dirname(__DIR__, 2).'/public/assets/css/app.css') ?>">
</head>
<body>
    <div id="login" class="overlay">
        <form id="login-form" class="dialog">
            <h2>File Server</h2>
            <p>Enter your credentials to access the file server.</p>
            <label>Username<input id="username" autocomplete="username" required></label>
            <label>Password<input id="password" type="password" autocomplete="current-password" required></label>
            <p id="login-error" class="error"></p>
            <button>Sign In</button>
        </form>
    </div>
    <header>
        <strong>◉ File Server</strong>
        <nav>
            <a href="<?= htmlspecialchars($frontController, ENT_QUOTES) ?>" data-route="/">Files</a>
            <a href="<?= htmlspecialchars($frontController, ENT_QUOTES) ?>?route=%2Fservers" data-route="/servers">Servers</a>
            <a href="<?= htmlspecialchars($frontController, ENT_QUOTES) ?>?route=%2Ftrash" data-route="/trash">Trash</a>
            <a href="<?= htmlspecialchars($frontController, ENT_QUOTES) ?>?route=%2Fbrowse" data-route="/browse">Browse</a>
            <a id="nav-users" href="<?= htmlspecialchars($frontController, ENT_QUOTES) ?>?route=%2Fusers" data-route="/users" hidden>Users</a>
            <a id="nav-storage" href="<?= htmlspecialchars($frontController, ENT_QUOTES) ?>?route=%2Fstorage" data-route="/storage" hidden>Storage</a>
        </nav>
        <div class="header-actions">
            <button id="theme" aria-label="Toggle dark mode">◐</button>
            <button id="change-password" type="button">Password</button>
            <button id="logout">Log out</button>
        </div>
    </header>
    <main>
        <section id="files-page">
            <div class="file-toolbar">
                <div id="breadcrumbs" class="breadcrumbs" aria-label="Current folder"></div>
                <div class="toolbar-actions">
                    <button id="mkdir">New folder</button>
                    <button id="upload-btn" class="primary-button" type="button">Upload</button>
                    <button id="zip">Download selected</button>
                    <button id="delete-selected" type="button">Delete selected</button>
                    <button id="refresh">Refresh</button>
                </div>
            </div>
            <div class="file-controls">
                <input id="search" type="search" placeholder="Search files">
                <div class="search-scope" role="group" aria-label="Search scope">
                    <button id="scope-folder" type="button" class="active">This folder</button>
                    <button id="scope-all" type="button">All folders</button>
                </div>
                <select id="sort-files" aria-label="Sort files">
                    <option value="name-asc">Name A–Z</option>
                    <option value="name-desc">Name Z–A</option>
                    <option value="date-desc">Newest</option>
                    <option value="date-asc">Oldest</option>
                    <option value="size-desc">Largest</option>
                    <option value="size-asc">Smallest</option>
                </select>
                <div class="view-switch" aria-label="File view">
                    <button id="grid-view" type="button" title="Grid view">▦</button>
                    <button id="list-view" type="button" title="List view">☷</button>
                </div>
            </div>
            <div id="selection-bar" class="selection-bar" hidden>
                <strong id="selection-count">0 selected</strong>
                <button id="select-all" type="button">Select all</button>
                <button id="clear-selection" type="button">Clear</button>
                <button id="selection-download" type="button">Download</button>
                <button id="selection-move" type="button">Move</button>
                <button id="selection-copy" type="button">Copy</button>
                <button id="selection-delete" type="button">Delete</button>
            </div>
            <p id="search-status" class="muted" role="status" aria-live="polite" hidden></p>
            <div id="file-list" class="file-grid" aria-live="polite"></div>
        </section>

        <!-- Upload dialog -->
        <div id="upload-overlay" class="modal-overlay" hidden>
            <form id="upload-form" class="upload-dialog" novalidate>
                <div class="modal-heading">
                    <div>
                        <h2>Upload files</h2>
                        <p>Upload one or more files to <strong id="upload-target">Root</strong>.</p>
                    </div>
                    <button id="upload-close" class="icon-button" type="button" aria-label="Close upload dialog">×</button>
                </div>
                <label id="upload-dropzone" class="file-picker upload-dropzone" for="upload-input">
                    <span class="file-picker-title">Drop files here or choose files</span>
                    <span class="file-picker-help">Select up to <?= (int)$config['max_upload_files'] ?> files. Maximum <?= (int)$config['max_upload_mb'] >= 1024 ? number_format($config['max_upload_mb']/1024, 0).' GB' : (int)$config['max_upload_mb'].' MB' ?> per file. Large files are uploaded in resumable chunks.</span>
                    <input id="upload-input" name="files[]" type="file" multiple>
                </label>
                <div id="upload-selection" class="upload-selection" aria-live="polite">No files selected.</div>
                <div id="upload-queue" class="upload-queue" aria-live="polite"></div>
                <label class="upload-conflict">If a filename already exists
                    <select id="upload-conflict">
                        <option value="rename">Keep both (rename new file)</option>
                        <option value="overwrite">Replace existing file</option>
                        <option value="reject">Stop and report conflict</option>
                    </select>
                </label>
                <div id="upload-progress-wrap" class="upload-progress-wrap" hidden>
                    <div class="progress-header">
                        <span id="upload-progress-label">Preparing upload…</span>
                        <strong id="upload-progress-percent">0%</strong>
                    </div>
                    <progress id="upload-progress" max="100" value="0">0%</progress>
                    <div id="upload-progress-bytes" class="muted"></div>
                </div>
                <div id="upload-message" class="status-message" role="status" aria-live="polite" hidden></div>
                <div class="modal-actions">
                    <button id="upload-cancel" type="button">Cancel</button>
                    <button id="upload-submit" class="primary-button" type="submit" disabled>Upload</button>
                </div>
            </form>
        </div>

        <div id="confirm-overlay" class="modal-overlay" hidden>
            <form id="confirm-dialog" class="confirm-dialog">
                <h2 id="confirm-title">Confirm action</h2>
                <p id="confirm-message"></p>
                <div class="modal-actions">
                    <button id="confirm-cancel" type="button">Cancel</button>
                    <button id="confirm-ok" class="danger-button" type="submit">Confirm</button>
                </div>
            </form>
        </div>
        <div id="input-overlay" class="modal-overlay" hidden>
            <form id="input-dialog" class="confirm-dialog">
                <h2 id="input-title">Enter value</h2>
                <label id="input-label">Name<input id="dialog-input" required></label>
                <div class="modal-actions">
                    <button id="input-cancel" type="button">Cancel</button>
                    <button class="primary-button" type="submit">Save</button>
                </div>
            </form>
        </div>
        <div id="share-overlay" class="modal-overlay" hidden>
            <div id="share-dialog" class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="share-title">
                <div class="modal-heading">
                    <div>
                        <h2 id="share-title">Share link</h2>
                        <p>Anyone with this link can view <strong id="share-file-name">this file</strong> without signing in.</p>
                    </div>
                    <button id="share-close" class="icon-button" type="button" aria-label="Close share dialog">&times;</button>
                </div>

                <label class="share-field">Change expiry
                    <select id="share-expiry">
                        <option value="">Keep current</option>
                        <option value="0">Never</option>
                        <option value="1">In 1 hour</option>
                        <option value="24">In 24 hours</option>
                        <option value="168">In 7 days</option>
                        <option value="720">In 30 days</option>
                    </select>
                </label>

                <div id="share-result" class="share-result" hidden>
                    <label class="share-field">Shareable URL
                        <input id="share-url" type="text" readonly spellcheck="false">
                    </label>
                    <p id="share-expiry-note" class="muted"></p>
                </div>

                <div id="share-message" class="status-message" role="status" aria-live="polite" hidden></div>

                <div class="modal-actions">
                    <button id="share-revoke" class="danger-button" type="button" hidden>Revoke</button>
                    <a id="share-open" class="share-open-link" href="#" target="_blank" rel="noopener noreferrer" hidden>Open</a>
                    <button id="share-copy" class="primary-button" type="button" disabled>Copy link</button>
                </div>
            </div>
        </div>

        <div id="password-overlay" class="modal-overlay" hidden>
            <form id="password-dialog" class="confirm-dialog">
                <div class="modal-heading">
                    <div>
                        <h2>Change your password</h2>
                        <p>Signing in elsewhere is unaffected until those sessions expire.</p>
                    </div>
                    <button id="password-close" class="icon-button" type="button" aria-label="Close">&times;</button>
                </div>
                <label class="share-field">Current password
                    <input id="password-current" type="password" autocomplete="current-password" required>
                </label>
                <label class="share-field">New password
                    <input id="password-new" type="password" autocomplete="new-password" minlength="12" required>
                </label>
                <div id="password-message" class="status-message" role="status" aria-live="polite" hidden></div>
                <div class="modal-actions">
                    <button id="password-cancel" type="button">Cancel</button>
                    <button class="primary-button" type="submit">Change password</button>
                </div>
            </form>
        </div>
        <div id="picker-overlay" class="modal-overlay" hidden>
            <div id="picker-dialog" class="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="picker-title">
                <div class="modal-heading">
                    <div>
                        <h2 id="picker-title">Choose a folder</h2>
                        <p id="picker-summary">Select where the items should go.</p>
                    </div>
                    <button id="picker-close" class="icon-button" type="button" aria-label="Close">&times;</button>
                </div>
                <div id="picker-crumbs" class="breadcrumbs" aria-label="Destination folder"></div>
                <div id="picker-list" class="picker-list" aria-live="polite"></div>
                <div class="modal-actions">
                    <button id="picker-cancel" type="button">Cancel</button>
                    <button id="picker-ok" class="primary-button" type="button">Move here</button>
                </div>
            </div>
        </div>
        <div id="file-context" class="context-menu" hidden role="menu"></div>

        <section id="servers-page" hidden>
            <div class="toolbar">
                <h2>Storage servers</h2>
                <button id="add-server">Add server</button>
            </div>
            <div id="server-list"></div>
            <form id="server-form" class="panel" hidden>
                <h3>Server</h3>
                <input name="name" placeholder="Name" required>
                <select name="type">
                    <option>local</option>
                    <option>ftp</option>
                    <option>sftp</option>
                    <option>smb</option>
                    <option>http_api</option>
                </select>
                <textarea name="config" rows="8" placeholder='{"basePath":"/srv/files"}' required></textarea>
                <label><input type="checkbox" name="isActive" checked> Active</label>
                <label><input type="checkbox" name="isDefault"> Default</label>
                <button>Save</button>
                <button type="button" id="cancel-server">Cancel</button>
            </form>
        </section>
        <section id="users-page" hidden>
            <div class="toolbar">
                <h2>Users</h2>
                <button id="add-user" type="button">Add user</button>
            </div>
            <p class="muted">Viewers can browse and download. Editors can also upload, rename and delete. Administrators additionally manage storage servers and accounts.</p>
            <div id="user-list"></div>
            <form id="user-form" class="panel" hidden>
                <h3 id="user-form-title">New user</h3>
                <input id="user-username" name="username" placeholder="Username" autocomplete="off" required>
                <input id="user-password" name="password" type="password" placeholder="Password (minimum 12 characters)" autocomplete="new-password">
                <select id="user-role" name="role">
                    <option value="viewer">Viewer — browse and download</option>
                    <option value="editor">Editor — also upload and delete</option>
                    <option value="admin">Administrator — full access</option>
                </select>
                <label><input id="user-active" type="checkbox" name="isActive" checked> Account enabled</label>
                <div id="user-form-message" class="status-message" role="status" aria-live="polite" hidden></div>
                <button id="user-save">Save</button>
                <button type="button" id="cancel-user">Cancel</button>
            </form>
        </section>
        <section id="trash-page" hidden>
            <div class="toolbar">
                <h2>Trash</h2>
                <button id="empty-trash" type="button" class="danger-button" hidden>Empty trash</button>
            </div>
            <p id="trash-note" class="muted"></p>
            <div id="trash-list"></div>
        </section>
        <section id="storage-page" hidden>
            <div class="toolbar">
                <h2>Storage</h2>
                <button id="recalculate-usage" type="button">Recalculate</button>
            </div>
            <p id="usage-note" class="muted"></p>
            <div id="usage-summary"></div>
            <div id="usage-detail" class="usage-columns"></div>
        </section>
        <section id="browse-page" hidden>
            <h2>Remote server browser</h2>
            <p>The PHP migration preserves server configuration and upload targets. Remote browsing depends on the corresponding PHP extension/client being installed.</p>
            <div id="active-servers"></div>
        </section>
    </main>

    <div id="toast"></div>

    <script nonce="<?= htmlspecialchars(\CloudHub\Services\Security::cspNonce(), ENT_QUOTES) ?>">
        window.CLOUDHUB_BASE = <?= json_encode($basePath, JSON_UNESCAPED_SLASHES) ?>;
        window.CLOUDHUB_FRONT = <?= json_encode($frontController, JSON_UNESCAPED_SLASHES) ?>;
        window.CLOUDHUB_ROUTE = <?= json_encode($path, JSON_UNESCAPED_SLASHES) ?>;
        window.CLOUDHUB_SHARE_EXPIRY_HOURS = <?= (int)$config['share_expiry_hours'] ?>;
        window.CLOUDHUB_UPLOAD_LIMITS = <?= json_encode([
            'maxFiles' => $config['max_upload_files'],
            'maxMb' => $config['max_upload_mb'],
            'chunkMb' => $config['upload_chunk_mb'],
            'retryCount' => $config['upload_retry_count'],
            'conflict' => $config['upload_conflict']
        ], JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>/assets/js/app.js?v=<?= (int)@filemtime(dirname(__DIR__, 2).'/public/assets/js/app.js') ?>"></script>
</body>
</html>
