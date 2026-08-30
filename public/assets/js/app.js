const BASE = (window.CLOUDHUB_BASE || '').replace(/\/$/, '');
const FRONT = window.CLOUDHUB_FRONT || (BASE ? `${BASE}/` : '/');
const appUrl = p => {
    const raw = p.startsWith('/') ? p : '/' + p;
    const q = raw.indexOf('?');
    const route = q >= 0 ? raw.slice(0, q) : raw;
    const query = q >= 0 ? raw.slice(q + 1) : '';
    return `${FRONT}?route=${encodeURIComponent(route)}${query ? '&' + query : ''}`;
};
/** Per-tab memory of the open folder, so returning from the player lands back there. */
const rememberedPath = (() => {
    try { return sessionStorage.getItem('cfh_path') || '/'; } catch { return '/'; }
})();

const S = {
    path: rememberedPath,
    files: [],
    selected: new Set(),
    view: localStorage.getItem('cfh_view') || 'grid',
    sort: localStorage.getItem('cfh_sort') || 'name-asc',
    // 'folder' filters what is already on screen; 'all' asks the server to
    // walk the tree. Results live separately from S.files so leaving a search
    // restores the folder listing without another request.
    scope: 'folder',
    results: null
};
const $ = s => document.querySelector(s);
const toast = m => {
    const t = $('#toast');
    t.textContent = m;
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 2200);
};

async function api(url, opt = {}) {
    url = appUrl(url);
    opt.headers = { ...(opt.headers || {}) };
    const method = (opt.method || 'GET').toUpperCase();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && S.csrf) opt.headers['X-CSRF-Token'] = S.csrf;
    if (opt.body && !(opt.body instanceof FormData)) {
        opt.headers['Content-Type'] = 'application/json';
        opt.body = JSON.stringify(opt.body);
    }
    const r = await fetch(url, { ...opt, credentials: 'same-origin' });
    if (r.status === 401) {
        $('#login').style.display = 'flex';
        throw Error('Authentication required');
    }
    if (!r.ok) {
        let m = `HTTP ${r.status}`, code = 'HTTP_ERROR', requestId = '';
        try {
            const d = await r.json();
            m = d.error?.message || m;
            code = d.error?.code || code;
            requestId = d.requestId || '';
        } catch {}
        const e = Error(m);
        e.code = code;
        e.status = r.status;
        e.requestId = requestId;
        throw e;
    }
    return r;
}

async function login(u, p) {
    const r = await fetch(appUrl('/api/auth/login'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: u, password: p })
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw Error(d.error?.message || 'Sign in failed');
    S.csrf = d.csrfToken || '';
    S.role = d.user?.role || 'viewer';
    $('#nav-users').hidden = S.role !== 'admin';
    $('#nav-storage').hidden = S.role !== 'admin';
    $('#login').style.display = 'none';
    await route();
}

$('#login-form').addEventListener('submit', async e => {
    e.preventDefault();
    try {
        await login($('#username').value, $('#password').value);
        $('#login-error').textContent = '';
        $('#password').value = '';
    } catch (x) {
        $('#login-error').textContent = x.message;
    }
});

$('#logout').addEventListener('click', async () => {
    try {
        await api('/api/auth/logout', { method: 'POST' });
    } catch {}
    S.csrf = '';
    $('#login').style.display = 'flex';
});

$('#theme').addEventListener('click', () => {
    document.documentElement.classList.toggle('dark');
    localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
});
if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');

function fmt(n) {
    if (n < 1024) return `${n} B`;
    if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
    if (n < 1073741824) return `${(n / 1048576).toFixed(1)} MB`;
    return `${(n / 1073741824).toFixed(1)} GB`;
}

function crumbs() {
    const parts = S.path.split('/').filter(Boolean);
    let cur = '';
    const items = [`<button data-p="/">Root</button>`];
    for (const part of parts) {
        cur += '/' + part;
        items.push(`<span aria-hidden="true">›</span><button data-p="${esc(cur)}">${esc(part)}</button>`);
    }
    $('#breadcrumbs').innerHTML = items.join('');
    $('#breadcrumbs').querySelectorAll('button').forEach(b => {
        b.addEventListener('click', () => loadFiles(b.dataset.p));
    });
}

async function loadFiles(p = S.path) {
    S.selected.clear();
    S.results = null;
    let response;
    try {
        response = await api(`/api/files/list?path=${encodeURIComponent(p)}`);
    } catch (e) {
        // A remembered folder can be renamed or deleted between visits. Falling
        // back to the root beats leaving the file list empty; anything else
        // (no session, server error) is the caller's to handle.
        if (p === '/' || e.status === 401) throw e;
        toast('That folder is no longer available');
        p = '/';
        response = await api('/api/files/list?path=%2F');
    }
    S.path = p;
    try { sessionStorage.setItem('cfh_path', p); } catch {}
    S.files = await response.json();
    crumbs();
    renderSearchStatus();
    renderFiles();
}

/** Human label for the folder holding a path, for search results spanning folders. */
function parentLabel(path) {
    const parent = path.substring(0, path.lastIndexOf('/'));
    return parent === '' ? 'Root' : parent;
}

/** Whatever the grid is currently showing: a folder listing or search results. */
function currentEntries() {
    return S.results ? S.results.entries : S.files;
}

function sortedFiles() {
    // Server results are already filtered by the query; re-filtering them
    // locally would drop matches that live under a matching folder name.
    const q = $('#search').value.trim().toLowerCase();
    const files = S.results ? [...S.results.entries] : S.files.filter(f => f.name.toLowerCase().includes(q));
    const [key, dir] = S.sort.split('-');
    const factor = dir === 'asc' ? 1 : -1;
    return files.sort((a, b) => {
        if (a.isDirectory !== b.isDirectory) return a.isDirectory ? -1 : 1;
        if (key === 'size') return ((a.size || 0) - (b.size || 0)) * factor;
        if (key === 'date') return (new Date(a.modified) - new Date(b.modified)) * factor;
        return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }) * factor;
    });
}

function updateSelectionUI() {
    const n = S.selected.size, bar = $('#selection-bar');
    bar.hidden = n === 0;
    $('#selection-count').textContent = `${n} selected`;
    document.querySelectorAll('[data-sel]').forEach(c => c.checked = S.selected.has(decodeURIComponent(c.dataset.sel)));
    document.querySelectorAll('.file').forEach(card => card.classList.toggle('selected', S.selected.has(decodeURIComponent(card.dataset.path))));
}

function toggleSelection(path, checked) {
    checked ? S.selected.add(path) : S.selected.delete(path);
    updateSelectionUI();
}


/**
 * Generate video thumbnails entirely in the browser.
 *
 * No FFmpeg, PHP video decoder or server-side frame extraction is required.
 * The browser fetches a short/seekable media stream, decodes one frame with
 * its native video codec and paints that frame into a Canvas.
 */
/**
 * Frames already decoded in this tab, keyed by path and modification time.
 *
 * renderFiles() runs again on every search keystroke, sort change and view
 * toggle. Revoking these each time meant every visible video was decoded from
 * scratch on each of those, so the cache is kept for the life of the tab and
 * only entries for files that changed are dropped.
 */
const videoThumbCache = new Map();

function videoThumbKey(file) {
    return `${file.path}|${file.modified || ''}`;
}

function releaseStaleVideoThumbs(validKeys) {
    for (const [key, url] of videoThumbCache) {
        if (validKeys.has(key)) continue;
        URL.revokeObjectURL(url);
        videoThumbCache.delete(key);
    }
}

/**
 * Try the server-side thumbnail cache for a video.
 *
 * Resolves true when a cached frame was displayed. A 415 simply means nobody
 * has contributed one yet, so the caller decodes the video instead.
 */
function loadCachedVideoThumb(button, image) {
    return new Promise(resolve => {
        const probe = new Image();
        probe.decoding = 'async';
        probe.onload = () => {
            if (!document.contains(button)) return resolve(true);
            image.src = probe.src;
            image.removeAttribute('hidden');
            button.querySelector('.video-thumb-status')?.remove();
            resolve(true);
        };
        probe.onerror = () => resolve(false);
        probe.src = button.dataset.thumbSrc;
    });
}

function waitForVideoEvent(video, eventName, timeoutMs = 15000) {
    return new Promise((resolve, reject) => {
        let settled = false;
        const cleanup = () => {
            video.removeEventListener(eventName, onEvent);
            video.removeEventListener('error', onError);
            clearTimeout(timer);
        };
        const finish = (fn, value) => {
            if (settled) return;
            settled = true;
            cleanup();
            fn(value);
        };
        const onEvent = () => finish(resolve);
        const onError = () => finish(reject, new Error('Browser could not decode the video'));
        const timer = setTimeout(() => finish(reject, new Error('Video thumbnail timed out')), timeoutMs);
        video.addEventListener(eventName, onEvent, { once: true });
        video.addEventListener('error', onError, { once: true });
    });
}

async function captureVideoFrame(button) {
    const source = button.dataset.videoThumb;
    const image = button.querySelector('img');
    if (!source || !image || !document.contains(button)) return;

    // Already decoded in this tab: paint it straight in.
    const cacheKey = button.dataset.thumbKey;
    const cached = cacheKey && videoThumbCache.get(cacheKey);
    if (cached) {
        image.src = cached;
        image.removeAttribute('hidden');
        button.querySelector('.video-thumb-status')?.remove();
        return;
    }

    // Then the server's cache, but only when the listing said there is a frame
    // to fetch. Asking blindly meant every uncached video produced a failed
    // request and a console error, plus a wasted round trip.
    if (button.dataset.hasThumb === '1' && await loadCachedVideoThumb(button, image)) return;

    const status = button.querySelector('.video-thumb-status');
    const video = document.createElement('video');
    // 'auto' pulls the whole file down; metadata plus a seek fetches only the
    // ranges needed to decode the one frame we want.
    video.preload = 'metadata';
    video.muted = true;
    video.playsInline = true;
    video.controls = false;
    video.disablePictureInPicture = true;
    video.src = source;

    try {
        video.load();
        if (video.readyState < HTMLMediaElement.HAVE_METADATA) {
            await waitForVideoEvent(video, 'loadedmetadata');
        }

        if (!video.videoWidth || !video.videoHeight) {
            throw new Error('Video dimensions are unavailable');
        }

        const duration = Number.isFinite(video.duration) ? video.duration : 0;
        const target = duration > 0
            ? Math.min(Math.max(duration * 0.08, 0.25), Math.max(0, duration - 0.05))
            : 0;

        if (target > 0.05) {
            await new Promise((resolve, reject) => {
                let settled = false;
                const cleanup = () => {
                    video.removeEventListener('seeked', onSeeked);
                    video.removeEventListener('error', onError);
                };
                const onSeeked = () => {
                    if (settled) return;
                    settled = true;
                    cleanup();
                    resolve();
                };
                const onError = () => {
                    if (settled) return;
                    settled = true;
                    cleanup();
                    reject(new Error('Video seek failed'));
                };
                video.addEventListener('seeked', onSeeked, { once: true });
                video.addEventListener('error', onError, { once: true });
                try {
                    video.currentTime = target;
                } catch (error) {
                    onError();
                }
            });
        } else if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
            await waitForVideoEvent(video, 'loadeddata');
        }

        const maxWidth = 480;
        const width = Math.min(video.videoWidth, maxWidth);
        const height = Math.max(1, Math.round(width * video.videoHeight / video.videoWidth));
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d', { alpha: false });
        if (!context) throw new Error('Canvas is unavailable');

        context.drawImage(video, 0, 0, width, height);

        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob(value => value ? resolve(value) : reject(new Error('Canvas encoding failed')), 'image/webp', 0.82);
        }).catch(async () => new Promise((resolve, reject) => {
            canvas.toBlob(value => value ? resolve(value) : reject(new Error('Canvas encoding failed')), 'image/jpeg', 0.82);
        }));

        const objectUrl = URL.createObjectURL(blob);
        if (cacheKey) videoThumbCache.set(cacheKey, objectUrl);
        if (!document.contains(button)) {
            if (!cacheKey) URL.revokeObjectURL(objectUrl);
            return;
        }

        image.src = objectUrl;
        image.removeAttribute('hidden');
        if (status) status.remove();

        // Hand the frame to the server so nobody -- including this tab on its
        // next visit -- has to download and decode the video again.
        persistVideoThumbnail(decodeURIComponent(button.dataset.thumbPath || ''), blob);
    } catch (error) {
        /*
         * Canvas extraction is not available in every Android WebView.
         * Keep a native <video> fallback showing the same decoded frame.
         */
        try {
            video.controls = false;
            video.style.width = '100%';
            video.style.height = '100%';
            video.style.objectFit = 'cover';
            video.style.pointerEvents = 'none';
            button.replaceChild(video, image);
            if (status) status.remove();
            if (Number.isFinite(video.duration) && video.duration > 0) {
                video.currentTime = Math.min(video.duration * 0.08, Math.max(0, video.duration - 0.05));
            }
        } catch {
            if (status) status.textContent = 'Video thumbnail unavailable';
            console.warn('Cloud File Hub video thumbnail:', error);
        }
    } finally {
        if (video.parentNode !== button) {
            video.removeAttribute('src');
            video.load();
        }
    }
}

/**
 * Send a decoded frame to the server's thumbnail cache.
 *
 * Best effort by design: a failure here costs nothing but a regenerated frame
 * next time, so it must never disturb the gallery.
 */
async function persistVideoThumbnail(path, blob) {
    if (!path || !blob || blob.size > 240000) return;
    try {
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result));
            reader.onerror = () => reject(reader.error);
            reader.readAsDataURL(blob);
        });
        await api('/api/thumbnail/video', { method: 'POST', body: { path, image: dataUrl } });
    } catch {
        // Ignored on purpose.
    }
}

function initVideoThumbnails() {
    const buttons = [...document.querySelectorAll('.thumb-preview.video-thumb[data-video-thumb]')];
    if (!buttons.length) return;

    const queue = [];
    let active = 0;
    const concurrency = 2;

    const pump = () => {
        while (active < concurrency && queue.length) {
            const button = queue.shift();
            if (!button || !document.contains(button)) continue;
            active++;
            captureVideoFrame(button)
                .catch(error => console.warn('Cloud File Hub video thumbnail:', error))
                .finally(() => {
                    active--;
                    pump();
                });
        }
    };

    const enqueue = button => {
        if (button.dataset.thumbnailQueued === '1') return;
        button.dataset.thumbnailQueued = '1';
        queue.push(button);
        pump();
    };

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                observer.unobserve(entry.target);
                enqueue(entry.target);
            }
        }, { rootMargin: '240px' });

        buttons.forEach(button => observer.observe(button));
    } else {
        buttons.forEach(enqueue);
    }
}

/** Render files in either responsive grid or compact list mode. */
function renderFiles() {
    const list = $('#file-list');
    const imageExt = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif']);
    const videoExt = new Set(['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v', 'avi', 'mkv', 'mpeg', 'mpg', '3gp', '3g2', 'ts', 'm2ts', 'mts']);
    const audioExt = new Set(['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac']);
    list.className = S.view === 'list' ? 'file-list-view' : 'file-grid';
    list.innerHTML = sortedFiles().map(f => {
        const encoded = encodeURIComponent(f.path);
        const ext = (f.name.includes('.') ? f.name.split('.').pop() : '').toLowerCase();
        // "Play" on a text file reads like a bug. Label the action for what it
        // actually does with this file.
        const playable = videoExt.has(ext) || audioExt.has(ext);
        const preview = f.isDirectory ? '' : `<button data-preview="${encoded}">${playable ? 'Play' : 'Preview'}</button>`;
        const thumbnailUrl = appUrl('/api/thumbnail?path=' + encodeURIComponent(f.path) + '&v=' + encodeURIComponent(f.modified || ''));
        let thumb = '<span class="file-icon">📄</span>';

        if (f.isDirectory) {
            thumb = '<span class="folder-icon">📁</span>';
        } else if (imageExt.has(ext)) {
            thumb = `<button class="thumb-preview" data-preview="${encoded}" aria-label="Preview ${esc(f.name)}">
                <img src="${thumbnailUrl}" alt="" width="300" height="300" loading="lazy" decoding="async" fetchpriority="low">
            </button>`;
        } else if (videoExt.has(ext)) {
            const streamUrl = appUrl('/api/files/stream?path=' + encodeURIComponent(f.path));
            // The server serves a cached video frame from the same endpoint as
            // image thumbnails once one has been contributed, so try that first
            // and only fall back to decoding the video in the browser.
            thumb = `<button class="thumb-preview video-thumb" data-preview="${encoded}" data-video-thumb="${streamUrl}" data-thumb-key="${esc(videoThumbKey(f))}" data-thumb-path="${encoded}" data-thumb-src="${thumbnailUrl}" data-has-thumb="${f.hasThumbnail ? 1 : 0}" aria-label="Play ${esc(f.name)}">
                <img alt="" width="300" height="300" loading="lazy" decoding="async" fetchpriority="low">
                <span class="video-thumb-status" aria-hidden="true">Generating thumbnail…</span>
                <span class="video-thumb-play" aria-hidden="true">▶</span>
            </button>`;
        } else if (audioExt.has(ext)) {
            thumb = '<span class="file-icon">🎵</span>';
        }

        return `<article class="file" data-path="${encoded}" tabindex="0">
        <div class="file-select"><input type="checkbox" data-sel="${encoded}" aria-label="Select ${esc(f.name)}"></div>
        <div class="thumb">${thumb}</div>
        <div class="file-info"><div class="name">${esc(f.name)}</div><div class="meta">${f.isDirectory ? 'Folder' : fmt(f.size)} · ${new Date(f.modified).toLocaleString()}${S.results ? ` · in ${esc(parentLabel(f.path))}` : ''}</div></div>
        <div class="actions">${f.isDirectory ? `<button data-open="${encoded}">Open</button>` : `${preview}<button data-down="${encoded}">Download</button><button data-share="${encoded}">Share</button>`}<button data-menu="${encoded}" aria-label="More actions">⋮</button></div>
        </article>`;
    }).join('');

    // Images still use the authenticated server-side image thumbnail endpoint.
    document.querySelectorAll('.thumb-preview:not(.video-thumb) img').forEach(img => {
        img.addEventListener('error', () => {
            const button = img.closest('.thumb-preview');
            if (!button) return;
            button.replaceWith(Object.assign(document.createElement('span'), {
                className: 'file-icon',
                textContent: '🖼️'
            }));
        }, { once: true });
    });

    // Frames for files no longer listed can go; the rest stay cached so a
    // search keystroke or sort change does not re-decode them.
    releaseStaleVideoThumbs(new Set(currentEntries().map(videoThumbKey)));

    initVideoThumbnails();

    document.querySelectorAll('[data-open]').forEach(b => {
        b.addEventListener('click', e => {
            e.stopPropagation();
            loadFiles(decodeURIComponent(b.dataset.open));
        });
    });
    document.querySelectorAll('[data-preview]').forEach(b => {
        b.addEventListener('click', e => {
            e.stopPropagation();
            openPreview(decodeURIComponent(b.dataset.preview));
        });
    });
    document.querySelectorAll('[data-down]').forEach(b => {
        b.addEventListener('click', e => {
            e.stopPropagation();
            download(decodeURIComponent(b.dataset.down));
        });
    });
    document.querySelectorAll('[data-share]').forEach(b => {
        b.addEventListener('click', e => {
            e.stopPropagation();
            share(decodeURIComponent(b.dataset.share));
        });
    });
    document.querySelectorAll('[data-menu]').forEach(b => {
        b.addEventListener('click', e => {
            e.stopPropagation();
            showContextMenu(decodeURIComponent(b.dataset.menu), e.clientX, e.clientY);
        });
    });
    document.querySelectorAll('[data-sel]').forEach(c => {
        c.addEventListener('change', () => toggleSelection(decodeURIComponent(c.dataset.sel), c.checked));
    });
    document.querySelectorAll('.file').forEach(card => {
        card.addEventListener('contextmenu', e => {
            e.preventDefault();
            showContextMenu(decodeURIComponent(card.dataset.path), e.clientX, e.clientY);
        });
        card.addEventListener('dblclick', () => {
            const p = decodeURIComponent(card.dataset.path);
            const f = currentEntries().find(x => x.path === p);
            f?.isDirectory ? loadFiles(p) : openPreview(p);
        });
    });
    updateSelectionUI();
}

/**
 * Opens the integrated file preview dialog.
 */
async function openPreview(path) {
    const name = path.split('/').pop() || path;
    const ext = (name.includes('.') ? name.split('.').pop() : '').toLowerCase();
    const url = appUrl('/api/files/preview?path=' + encodeURIComponent(path));
    const imageExt = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif']);
    const videoExt = new Set(['mp4', 'webm', 'ogv', 'mov', 'm4v']);
    const audioExt = new Set(['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac']);
    const textExt = new Set([
        'txt', 'md', 'log', 'json', 'xml', 'csv', 'html', 'htm', 'css', 'js',
        'mjs', 'ts', 'tsx', 'jsx', 'php', 'sql', 'ini', 'env', 'yml', 'yaml',
        'sh', 'bat', 'ps1', 'py', 'java', 'kt', 'c', 'h', 'cpp', 'hpp'
    ]);

    let body = '';
    if (imageExt.has(ext)) body = `<img class="preview-image" src="${url}" alt="${esc(name)}">`;
    else if (videoExt.has(ext)) body = `<div class="preview-unsupported preview-media-gate"><div class="preview-file-icon">🎬</div><p>Video playback is restricted to the cfh-player.</p><button data-open-player>Play</button><button data-preview-download>Download file</button></div>`;
    else if (audioExt.has(ext)) body = `<div class="preview-audio-wrap"><div class="preview-file-icon">🎵</div><audio class="preview-audio" src="${url}" controls preload="metadata"></audio></div>`;
    else if (ext === 'pdf') body = `<iframe class="preview-frame" src="${url}" title="${esc(name)}"></iframe>`;
    else if (textExt.has(ext)) {
        body = '<div class="preview-loading">Loading preview…</div>';
    } else {
        body = `<div class="preview-unsupported"><div class="preview-file-icon">📄</div><p>No inline preview is available for this file type.</p><button data-preview-download>Download file</button></div>`;
    }

    showPreviewDialog(name, body);

    if (textExt.has(ext)) {
        try {
            const response = await api('/api/files/preview?path=' + encodeURIComponent(path));
            const text = await response.text();
            const limit = 500000;
            const shown = text.length > limit ? text.slice(0, limit) : text;
            $('#preview-body').innerHTML = `<pre class="preview-text">${esc(shown)}${text.length > limit ? '\n\n[Preview truncated at 500 KB]' : ''}</pre>`;
        } catch (error) {
            $('#preview-body').innerHTML = `<div class="preview-error"><strong>Preview failed</strong><p>${esc(error.message)}</p></div>`;
        }
    }

    const openPlayerBtn = document.querySelector('[data-open-player]');
    if (openPlayerBtn) openPlayerBtn.addEventListener('click', () => {
        window.location.href = appUrl('/play?path=' + encodeURIComponent(path));
    });

    const dl = document.querySelector('[data-preview-download]');
    if (dl) dl.addEventListener('click', () => download(path));
}

/** Creates the modal shell used by all preview types. */
function showPreviewDialog(name, body) {
    closePreview();
    const overlay = document.createElement('div');
    overlay.id = 'preview-overlay';
    overlay.className = 'preview-overlay';
    overlay.innerHTML = `<section class="preview-dialog" role="dialog" aria-modal="true" aria-labelledby="preview-title"><header class="preview-header"><h2 id="preview-title">${esc(name)}</h2><button id="preview-close" class="preview-close" aria-label="Close preview">×</button></header><div id="preview-body" class="preview-body">${body}</div></section>`;
    document.body.appendChild(overlay);
    $('#preview-close').addEventListener('click', closePreview);
    overlay.addEventListener('click', e => {
        if (e.target === overlay) closePreview();
    });
    document.addEventListener('keydown', previewEscape);
}

/** Closes the preview and releases media elements/resources. */
function closePreview() {
    const overlay = $('#preview-overlay');
    if (overlay) overlay.remove();
    document.removeEventListener('keydown', previewEscape);
}

function previewEscape(event) {
    if (event.key === 'Escape') closePreview();
}

function esc(s) {
    return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function download(p) {
    const r = await api(`/api/files/download?path=${encodeURIComponent(p)}`);
    const blob = await r.blob(), u = URL.createObjectURL(blob), a = document.createElement('a');
    a.href = u;
    a.download = p.split('/').pop();
    a.click();
    URL.revokeObjectURL(u);
}

/**
 * Share dialog.
 *
 * A share link is a public URL: anyone holding it can view the file without an
 * account, so the link, its lifetime and the way to revoke it are all shown
 * explicitly rather than silently copied to the clipboard.
 */
const shareUI = {
    overlay: $('#share-overlay'),
    name: $('#share-file-name'),
    expiry: $('#share-expiry'),
    result: $('#share-result'),
    url: $('#share-url'),
    note: $('#share-expiry-note'),
    message: $('#share-message'),
    copy: $('#share-copy'),
    open: $('#share-open'),
    revoke: $('#share-revoke'),
    path: null,
    token: null
};

function shareStatus(type, text) {
    shareUI.message.className = `status-message ${type}`;
    shareUI.message.textContent = text;
    shareUI.message.hidden = false;
}

function shareReset() {
    shareUI.token = null;
    shareUI.result.hidden = true;
    shareUI.url.value = '';
    shareUI.note.textContent = '';
    shareUI.message.hidden = true;
    shareUI.copy.disabled = true;
    shareUI.open.hidden = true;
    shareUI.revoke.hidden = true;
}

function closeShare() {
    shareUI.overlay.hidden = true;
    shareUI.path = null;
    shareReset();
}

function formatExpiry(iso) {
    if (!iso) return 'This link never expires.';
    const when = new Date(iso);
    return Number.isNaN(when.getTime())
        ? 'This link expires.'
        : `This link expires on ${when.toLocaleString()}.`;
}

/**
 * Create a link, or fetch the one this file already has.
 *
 * `hours` omitted means "whatever the server already has, else the configured
 * default" -- the server reuses any live link for the file, so asking for a
 * specific lifetime on open would claim a lifetime the link may not have.
 */
async function shareCreate(hours) {
    shareUI.copy.disabled = true;
    shareStatus('', 'Creating link\u2026');
    try {
        const body = { filePath: shareUI.path };
        if (hours !== undefined) body.expiresInHours = hours;
        const d = await (await api('/api/shares/create', {
            method: 'POST',
            body
        })).json();
        shareUI.token = d.token;
        shareUI.url.value = d.url;
        shareUI.open.href = d.url;
        shareUI.note.textContent = formatExpiry(d.expiresAt);
        shareUI.result.hidden = false;
        shareUI.open.hidden = false;
        shareUI.revoke.hidden = false;
        shareUI.copy.disabled = false;
        shareUI.message.hidden = true;
    } catch (e) {
        shareReset();
        shareStatus('error', e.message);
    }
}

async function share(p) {
    shareUI.path = p;
    shareReset();
    shareUI.name.textContent = p.split('/').pop() || p;
    shareUI.expiry.value = '';
    shareUI.overlay.hidden = false;
    await shareCreate();
}

/**
 * Changing the lifetime replaces the link: the server reuses any live link for
 * a file, so the old token has to go before a new lifetime can take effect.
 * The previous URL stops working, which is the honest outcome to show.
 */
shareUI.expiry.addEventListener('change', async () => {
    if (!shareUI.path || shareUI.expiry.value === '') return;
    const hours = Number(shareUI.expiry.value) || 0;
    if (shareUI.token) {
        try {
            await api('/api/shares/revoke', { method: 'DELETE', body: { token: shareUI.token } });
        } catch {}
        shareUI.token = null;
    }
    await shareCreate(hours);
    shareUI.expiry.value = '';
});

shareUI.copy.addEventListener('click', async () => {
    const url = shareUI.url.value;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        shareStatus('success', 'Link copied to the clipboard.');
    } catch {
        // Clipboard access needs a secure context; selecting the text lets the
        // user copy it manually over plain http.
        shareUI.url.select();
        shareStatus('', 'Press Ctrl/Cmd+C to copy the selected link.');
    }
});

shareUI.revoke.addEventListener('click', async () => {
    if (!shareUI.token) return;
    if (!await askConfirm('Revoke share link', 'The existing link will stop working immediately. Continue?', 'Revoke')) return;
    try {
        await api('/api/shares/revoke', { method: 'DELETE', body: { token: shareUI.token } });
        shareReset();
        shareStatus('success', 'Share link revoked.');
    } catch (e) {
        shareStatus('error', e.message);
    }
});

$('#share-close').addEventListener('click', closeShare);
shareUI.overlay.addEventListener('click', e => {
    if (e.target === shareUI.overlay) closeShare();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !shareUI.overlay.hidden) closeShare();
});

function askConfirm(title, message, okText = 'Confirm') {
    return new Promise(resolve => {
        const overlay = $('#confirm-overlay');
        const dialog = $('#confirm-dialog');
        const cancelBtn = $('#confirm-cancel');
        $('#confirm-title').textContent = title;
        $('#confirm-message').textContent = message;
        $('#confirm-ok').textContent = okText;
        overlay.hidden = false;

        const onCancel = () => finish(false);
        const onSubmit = e => {
            e.preventDefault();
            finish(true);
        };
        const finish = v => {
            overlay.hidden = true;
            dialog.removeEventListener('submit', onSubmit);
            cancelBtn.removeEventListener('click', onCancel);
            resolve(v);
        };
        dialog.addEventListener('submit', onSubmit);
        cancelBtn.addEventListener('click', onCancel);
    });
}

function askInput(title, label, value = '') {
    return new Promise(resolve => {
        const overlay = $('#input-overlay');
        const dialog = $('#input-dialog');
        const cancelBtn = $('#input-cancel');
        const input = $('#dialog-input');

        $('#input-title').textContent = title;
        $('#input-label').firstChild.textContent = label;
        input.value = value;
        overlay.hidden = false;
        setTimeout(() => input.focus(), 0);

        const onCancel = () => finish('');
        const onSubmit = e => {
            e.preventDefault();
            finish(input.value.trim());
        };
        const finish = v => {
            overlay.hidden = true;
            dialog.removeEventListener('submit', onSubmit);
            cancelBtn.removeEventListener('click', onCancel);
            resolve(v);
        };
        dialog.addEventListener('submit', onSubmit);
        cancelBtn.addEventListener('click', onCancel);
    });
}

async function del(p) {
    const name = p.split('/').pop();
    // The wording depends on where the item actually goes, which the server
    // decides. Ask it once and remember, rather than promising either.
    const trashed = await trashEnabled();
    const warning = trashed ? 'It can be restored from the trash.' : 'This cannot be undone.';
    if (!await askConfirm('Delete item', `Delete "${name}"? ${warning}`, 'Delete')) return;
    try {
        const d = await (await api('/api/files/delete', { method: 'DELETE', body: { path: p } })).json();
        toast(d.message);
        await loadFiles();
    } catch (e) {
        toast(e.message);
    }
}

/**
 * Whether this installation moves deletions to the trash.
 *
 * Cached for the life of the tab: it comes from configuration, so it cannot
 * change between two clicks, and a delete should not cost two round trips.
 */
let trashEnabledCache = null;
async function trashEnabled() {
    if (trashEnabledCache !== null) return trashEnabledCache;
    try {
        const d = await (await api('/api/trash')).json();
        trashEnabledCache = !!d.enabled;
    } catch {
        trashEnabledCache = false;
    }
    return trashEnabledCache;
}

async function ren(p) {
    const old = p.split('/').pop();
    const n = await askInput('Rename item', 'New name', old);
    if (!n || n === old) return;
    const parent = p.substring(0, p.lastIndexOf('/'));
    try {
        await api('/api/files/rename', {
            method: 'POST',
            body: { oldPath: p, newPath: `${parent}/${n}` }
        });
        await loadFiles();
    } catch (e) {
        toast(e.message);
    }
}

async function makeFolder() {
    const n = await askInput('New folder', 'Folder name');
    if (!n) return;
    try {
        await api('/api/files/mkdir', {
            method: 'POST',
            body: { path: (S.path === '/' ? '' : S.path) + '/' + n }
        });
        await loadFiles();
    } catch (e) {
        toast(e.message);
    }
}

async function deleteSelected() {
    const files = [...S.selected];
    if (!files.length) return;
    const warning = await trashEnabled() ? 'They can be restored from the trash.' : 'This cannot be undone.';
    if (!await askConfirm('Delete selected', `Delete ${files.length} selected item${files.length === 1 ? '' : 's'}? ${warning}`, 'Delete')) return;
    let failed = 0;
    for (const path of files) {
        try {
            await api('/api/files/delete', {
                method: 'DELETE',
                body: { path }
            });
        } catch {
            failed++;
        }
    }
    await loadFiles();
    toast(failed ? `${failed} item(s) could not be deleted`
        : await trashEnabled() ? 'Selected items moved to trash' : 'Selected items deleted');
}

function showContextMenu(path, x, y) {
    const f = currentEntries().find(item => item.path === path);
    const menu = $('#file-context');
    if (!f) return;
    menu.innerHTML = `${f.isDirectory ? '<button data-cmd="open">Open</button>' : '<button data-cmd="preview">Preview</button><button data-cmd="download">Download</button><button data-cmd="share">Share</button>'}<button data-cmd="rename">Rename</button><button data-cmd="move">Move to…</button><button data-cmd="copy">Copy to…</button><button data-cmd="delete" class="danger-text">Delete</button>`;
    menu.hidden = false;
    menu.style.left = `${Math.min(x, window.innerWidth - 190)}px`;
    menu.style.top = `${Math.min(y, window.innerHeight - 240)}px`;
    menu.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        menu.hidden = true;
        const c = b.dataset.cmd;
        if (c === 'open') loadFiles(path);
        if (c === 'preview') openPreview(path);
        if (c === 'download') download(path);
        if (c === 'share') share(path);
        if (c === 'rename') ren(path);
        if (c === 'move') relocate([path], 'move');
        if (c === 'copy') relocate([path], 'copy');
        if (c === 'delete') del(path);
    }));
}

document.addEventListener('click', e => {
    if (!e.target.closest('#file-context') && !e.target.closest('[data-menu]')) $('#file-context').hidden = true;
});
/* ---- Search -------------------------------------------------------------
 *
 * Two modes behind one field. "This folder" filters the listing already in
 * memory, so it stays instant. "All folders" asks the server to walk the tree,
 * which is a request per query and therefore debounced.
 */
let searchTimer = 0;
let searchRun = 0;

function renderSearchStatus() {
    const box = $('#search-status');
    if (!S.results) { box.hidden = true; return; }
    const n = S.results.entries.length;
    box.textContent = n === 0
        ? `No matches for "${S.results.query}" anywhere under ${S.path === '/' ? 'Root' : S.path}`
        : `${n} match${n === 1 ? '' : 'es'} for "${S.results.query}"${S.results.truncated ? ' (showing the first ' + n + '; narrow the search for the rest)' : ''}`;
    box.hidden = false;
}

async function runSearch() {
    const q = $('#search').value.trim();
    if (S.scope !== 'all' || q.length < 2) {
        // Falling back to the local filter: drop any results still on screen
        // so the grid matches the mode the toggle is showing.
        if (S.results) { S.results = null; S.selected.clear(); }
        renderSearchStatus();
        renderFiles();
        return;
    }
    const run = ++searchRun;
    try {
        const d = await (await api(`/api/files/search?q=${encodeURIComponent(q)}&path=${encodeURIComponent(S.path)}`)).json();
        // A slower earlier request must not overwrite a newer one's results.
        if (run !== searchRun) return;
        S.results = { query: d.query, entries: d.results, truncated: d.truncated };
        S.selected.clear();
    } catch (e) {
        if (run !== searchRun) return;
        toast(e.message);
        return;
    }
    renderSearchStatus();
    renderFiles();
}

function setScope(scope) {
    S.scope = scope;
    $('#scope-folder').classList.toggle('active', scope === 'folder');
    $('#scope-all').classList.toggle('active', scope === 'all');
    runSearch();
}

$('#scope-folder').addEventListener('click', () => setScope('folder'));
$('#scope-all').addEventListener('click', () => setScope('all'));

/* ---- Destination picker -------------------------------------------------
 *
 * Move and copy need a folder, and folders are a tree, so the picker browses
 * one instead of asking the user to type a path. It lists directories only --
 * a file is never a valid destination.
 */
const picker = { resolve: null, path: '/' };

function pickFolder(summary, okLabel) {
    return new Promise(resolve => {
        picker.resolve = resolve;
        picker.path = S.path;
        $('#picker-summary').textContent = summary;
        $('#picker-ok').textContent = okLabel;
        $('#picker-overlay').hidden = false;
        renderPicker();
    });
}

function finishPick(value) {
    $('#picker-overlay').hidden = true;
    const done = picker.resolve;
    picker.resolve = null;
    if (done) done(value);
}

async function renderPicker() {
    const crumbBox = $('#picker-crumbs');
    const parts = picker.path.split('/').filter(Boolean);
    let cur = '';
    const items = ['<button data-p="/">Root</button>'];
    for (const part of parts) {
        cur += '/' + part;
        items.push(`<span aria-hidden="true">›</span><button data-p="${esc(cur)}">${esc(part)}</button>`);
    }
    crumbBox.innerHTML = items.join('');
    crumbBox.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        picker.path = b.dataset.p;
        renderPicker();
    }));

    const list = $('#picker-list');
    list.innerHTML = '<p class="muted">Loading…</p>';
    let entries;
    try {
        entries = await (await api(`/api/files/list?path=${encodeURIComponent(picker.path)}`)).json();
    } catch (e) {
        list.innerHTML = `<p class="error">${esc(e.message)}</p>`;
        return;
    }
    const folders = entries.filter(f => f.isDirectory);
    list.innerHTML = folders.length
        ? folders.map(f => `<button type="button" class="picker-row" data-p="${esc(f.path)}">📁 ${esc(f.name)}</button>`).join('')
        : '<p class="muted">No folders here. The items will go into this folder.</p>';
    list.querySelectorAll('.picker-row').forEach(b => b.addEventListener('click', () => {
        picker.path = b.dataset.p;
        renderPicker();
    }));
}

$('#picker-ok').addEventListener('click', () => finishPick(picker.path));
$('#picker-cancel').addEventListener('click', () => finishPick(null));
$('#picker-close').addEventListener('click', () => finishPick(null));

/* ---- Move and copy ------------------------------------------------------ */

async function relocate(paths, verb) {
    if (!paths.length) return;
    const noun = paths.length === 1 ? `"${paths[0].split('/').pop()}"` : `${paths.length} items`;
    const destination = await pickFolder(
        `${verb === 'move' ? 'Move' : 'Copy'} ${noun} into which folder?`,
        verb === 'move' ? 'Move here' : 'Copy here');
    if (destination === null) return;
    try {
        const d = await (await api(`/api/files/${verb}`, { method: 'POST', body: { paths, destination } })).json();
        // Per-item failures come back named, so say which ones rather than
        // reporting a bare success over a partial result.
        if (d.failed.length) {
            toast(`${d.completed} done, ${d.failed.length} failed: ${d.failed[0].message}`);
        } else {
            toast(`${d.completed} item${d.completed === 1 ? '' : 's'} ${verb === 'move' ? 'moved' : 'copied'}`);
        }
    } catch (e) {
        toast(e.message);
        return;
    }
    S.results = null;
    $('#search').value = '';
    await loadFiles();
}

const relocateSelected = verb => relocate([...S.selected], verb);
$('#selection-move').addEventListener('click', () => relocateSelected('move'));
$('#selection-copy').addEventListener('click', () => relocateSelected('copy'));

$('#mkdir').addEventListener('click', makeFolder);
$('#refresh').addEventListener('click', () => loadFiles());
$('#search').addEventListener('input', () => {
    if (S.scope === 'all') {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 250);
        return;
    }
    renderFiles();
});
$('#sort-files').value = S.sort;
$('#sort-files').addEventListener('change', e => {
    S.sort = e.target.value;
    localStorage.setItem('cfh_sort', S.sort);
    renderFiles();
});

function setView(v) {
    S.view = v;
    localStorage.setItem('cfh_view', v);
    $('#grid-view').classList.toggle('active', v === 'grid');
    $('#list-view').classList.toggle('active', v === 'list');
    renderFiles();
}

$('#grid-view').addEventListener('click', () => setView('grid'));
$('#list-view').addEventListener('click', () => setView('list'));
$('#grid-view').classList.toggle('active', S.view === 'grid');
$('#list-view').classList.toggle('active', S.view === 'list');
$('#select-all').addEventListener('click', () => {
    sortedFiles().forEach(f => S.selected.add(f.path));
    updateSelectionUI();
});
$('#clear-selection').addEventListener('click', () => {
    S.selected.clear();
    updateSelectionUI();
});
$('#delete-selected').addEventListener('click', deleteSelected);
$('#selection-delete').addEventListener('click', deleteSelected);

/**
 * Resumable upload controller.
 */
const uploadUI = {
    overlay: $('#upload-overlay'),
    form: $('#upload-form'),
    input: $('#upload-input'),
    submit: $('#upload-submit'),
    selection: $('#upload-selection'),
    message: $('#upload-message'),
    progressWrap: $('#upload-progress-wrap'),
    progress: $('#upload-progress'),
    percent: $('#upload-progress-percent'),
    label: $('#upload-progress-label'),
    bytes: $('#upload-progress-bytes'),
    conflict: $('#upload-conflict'),
    dropzone: $('#upload-dropzone'),
    queue: $('#upload-queue'),
    files: [],
    limits: window.CLOUDHUB_UPLOAD_LIMITS || {
        maxFiles: 20, maxMb: 2048, chunkMb: 8, retryCount: 3, conflict: 'rename'
    },
    xhr: null,
    cancelled: false,
    currentUploadId: null
};
uploadUI.conflict.value = uploadUI.limits.conflict || 'rename';

function resetUploadUI() {
    uploadUI.form.reset();
    uploadUI.files = [];
    uploadUI.queue.innerHTML = '';
    uploadUI.conflict.value = uploadUI.limits.conflict || 'rename';
    uploadUI.submit.disabled = true;
    uploadUI.selection.textContent = 'No files selected.';
    uploadUI.message.hidden = true;
    uploadUI.message.className = 'status-message';
    uploadUI.progressWrap.hidden = true;
    uploadUI.progress.value = 0;
    uploadUI.percent.textContent = '0%';
    uploadUI.bytes.textContent = '';
    uploadUI.cancelled = false;
    uploadUI.currentUploadId = null;
}

function openUpload() {
    resetUploadUI();
    $('#upload-target').textContent = S.path === '/' ? 'Root' : S.path;
    uploadUI.overlay.hidden = false;
    document.body.style.overflow = 'hidden';
}

async function closeUpload() {
    uploadUI.cancelled = true;
    if (uploadUI.xhr) {
        uploadUI.xhr.abort();
        uploadUI.xhr = null;
    }
    if (uploadUI.currentUploadId) {
        try {
            await api('/api/uploads/cancel', {
                method: 'DELETE',
                body: { id: uploadUI.currentUploadId }
            });
        } catch {}
        forgetUpload(uploadUI.currentUploadId);
        uploadUI.currentUploadId = null;
    }
    uploadUI.overlay.hidden = true;
    document.body.style.overflow = '';
}

function uploadStatus(type, message) {
    uploadUI.message.className = `status-message ${type}`;
    uploadUI.message.textContent = message;
    uploadUI.message.hidden = false;
}

function validateUploadFiles(files) {
    if (!files.length) return 'Choose at least one file.';
    if (files.length > uploadUI.limits.maxFiles) return `You can upload at most ${uploadUI.limits.maxFiles} files at once.`;
    const max = uploadUI.limits.maxMb * 1024 * 1024;
    const large = files.find(f => f.size > max);
    return large ? `${large.name} exceeds the ${fmt(max)} per-file limit.` : '';
}

function setUploadFiles(files) {
    const seen = new Set();
    uploadUI.files = [...files].filter(f => {
        const k = `${f.name}|${f.size}|${f.lastModified}`;
        if (seen.has(k)) return false;
        seen.add(k);
        return true;
    });
    renderUploadSelection();
}

function renderUploadSelection() {
    const files = uploadUI.files, problem = validateUploadFiles(files);
    uploadUI.selection.textContent = files.length ? `${files.length} file${files.length === 1 ? '' : 's'} queued` : 'No files selected.';
    uploadUI.queue.innerHTML = files.map((f, i) => `<div class="queue-item" data-q="${i}"><div class="queue-line"><span class="upload-file-name">${esc(f.name)}</span><span>${fmt(f.size)}</span></div><progress max="100" value="0"></progress><div class="queue-status">Waiting</div></div>`).join('');
    uploadUI.submit.disabled = !!problem || !files.length;
    if (problem) uploadStatus('error', problem);
    else uploadUI.message.hidden = true;
}

function uploadKey(file) {
    const raw = `${S.path}|${file.name}|${file.size}|${file.lastModified}`;
    let h1 = 2166136261, h2 = 2246822519;
    for (let i = 0; i < raw.length; i++) {
        const c = raw.charCodeAt(i);
        h1 = Math.imul(h1 ^ c, 16777619);
        h2 = Math.imul(h2 ^ c, 3266489917);
    }
    return `u_${(h1 >>> 0).toString(36)}_${(h2 >>> 0).toString(36)}_${file.size.toString(36)}`;
}

function rememberUpload(id, file) {
    localStorage.setItem(`cfh_upload_${id}`, JSON.stringify({
        name: file.name, size: file.size, path: S.path, modified: file.lastModified, at: Date.now()
    }));
}

function forgetUpload(id) {
    localStorage.removeItem(`cfh_upload_${id}`);
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

function sendChunk(id, offset, blob, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        uploadUI.xhr = xhr;
        xhr.open('PUT', appUrl(`/api/uploads/chunk?id=${encodeURIComponent(id)}`));
        xhr.withCredentials = true;
        xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        xhr.setRequestHeader('X-Upload-Offset', String(offset));
        if (S.csrf) xhr.setRequestHeader('X-CSRF-Token', S.csrf);
        xhr.responseType = 'json';
        xhr.upload.onprogress = e => {
            if (e.lengthComputable) onProgress(e.loaded, e.total);
        };
        xhr.onload = () => {
            uploadUI.xhr = null;
            const d = xhr.response || {};
            if (xhr.status >= 200 && xhr.status < 300) resolve(d);
            else reject(Object.assign(new Error(d.error?.message || `Chunk failed (HTTP ${xhr.status})`), { status: xhr.status }));
        };
        xhr.onerror = () => {
            uploadUI.xhr = null;
            reject(Object.assign(new Error('Network error while sending chunk.'), { status: 0 }));
        };
        xhr.onabort = () => {
            uploadUI.xhr = null;
            reject(Object.assign(new Error('Upload cancelled.'), { cancelled: true }));
        };
        xhr.send(blob);
    });
}

async function uploadOneFile(file, fileIndex, fileCount, totalBefore, totalBytes) {
    const id = uploadKey(file);
    uploadUI.currentUploadId = id;
    rememberUpload(id, file);
    let state = await (await api('/api/uploads/init', {
        method: 'POST',
        body: { uploadId: id, targetPath: S.path, name: file.name, size: file.size, conflict: uploadUI.conflict.value }
    })).json();
    const chunkBytes = state.chunkBytes || uploadUI.limits.chunkMb * 1024 * 1024;
    let offset = Math.min(state.received || 0, file.size);
    while (offset < file.size) {
        if (uploadUI.cancelled) throw new Error('Upload cancelled.');
        const end = Math.min(offset + chunkBytes, file.size);
        const blob = file.slice(offset, end);
        let attempt = 0;
        while (true) {
            try {
                const confirmedOffset = offset;
                state = await sendChunk(id, offset, blob, loaded => {
                    const overall = totalBefore + confirmedOffset + loaded;
                    const pct = totalBytes ? Math.min(99, Math.floor((overall / totalBytes) * 100)) : 0;
                    uploadUI.progress.value = pct;
                    uploadUI.percent.textContent = `${pct}%`;
                    uploadUI.label.textContent = `Uploading ${fileIndex + 1} of ${fileCount}: ${file.name}`;
                    uploadUI.bytes.textContent = `${fmt(overall)} of ${fmt(totalBytes)} · chunk ${fmt(loaded)} of ${fmt(blob.size)}`;
                    const row = uploadUI.queue.querySelector(`[data-q="${fileIndex}"]`);
                    if (row) {
                        const fp = file.size ? Math.min(100, Math.floor(((confirmedOffset + loaded) / file.size) * 100)) : 100;
                        row.querySelector('progress').value = fp;
                        row.querySelector('.queue-status').textContent = `Uploading · ${fp}%`;
                    }
                });
                offset = state.received;
                break;
            } catch (err) {
                if (err.cancelled || uploadUI.cancelled) throw err;
                attempt++;
                if (attempt > uploadUI.limits.retryCount) throw new Error(`Failed to upload ${file.name} after ${uploadUI.limits.retryCount} retries: ${err.message}`);
                uploadUI.label.textContent = `Retrying chunk for ${file.name}…`;
                const retryRow = uploadUI.queue.querySelector(`[data-q="${fileIndex}"]`);
                if (retryRow) retryRow.querySelector('.queue-status').textContent = `Retrying chunk…`;
                await sleep(Math.min(5000, 500 * Math.pow(2, attempt - 1)));
                try {
                    const status = await (await api('/api/uploads/status?id=' + encodeURIComponent(id))).json();
                    if (status.received !== offset) {
                        offset = status.received;
                        break;
                    }
                } catch {}
            }
        }
    }
    uploadUI.label.textContent = `Finalising ${file.name}…`;
    const done = await (await api('/api/uploads/complete', {
        method: 'POST',
        body: { id }
    })).json();
    forgetUpload(id);
    uploadUI.currentUploadId = null;
    return done;
}

async function uploadFilesResumable(files) {
    const totalBytes = files.reduce((n, f) => n + f.size, 0);
    let completedBytes = 0;
    const results = [];
    for (let i = 0; i < files.length; i++) {
        const result = await uploadOneFile(files[i], i, files.length, completedBytes, totalBytes);
        const row = uploadUI.queue.querySelector(`[data-q="${i}"]`);
        if (row) {
            row.querySelector('progress').value = 100;
            row.querySelector('.queue-status').textContent = 'Complete';
        }
        completedBytes += files[i].size;
        results.push(result);
    }
    return results;
}

$('#upload-btn').addEventListener('click', openUpload);
$('#upload-close').addEventListener('click', closeUpload);
$('#upload-cancel').addEventListener('click', closeUpload);
uploadUI.overlay.addEventListener('click', e => {
    if (e.target === uploadUI.overlay) closeUpload();
});
uploadUI.input.addEventListener('change', () => setUploadFiles(uploadUI.input.files));
['dragenter', 'dragover'].forEach(type => uploadUI.dropzone.addEventListener(type, e => {
    e.preventDefault();
    uploadUI.dropzone.classList.add('dragging');
}));
['dragleave', 'drop'].forEach(type => uploadUI.dropzone.addEventListener(type, e => {
    e.preventDefault();
    uploadUI.dropzone.classList.remove('dragging');
}));
uploadUI.dropzone.addEventListener('drop', e => setUploadFiles(e.dataTransfer.files));
uploadUI.form.addEventListener('submit', async e => {
    e.preventDefault();
    const files = uploadUI.files;
    const problem = validateUploadFiles(files);
    if (problem) {
        uploadStatus('error', problem);
        return;
    }
    uploadUI.cancelled = false;
    uploadUI.submit.disabled = true;
    uploadUI.input.disabled = true;
    uploadUI.conflict.disabled = true;
    uploadUI.progressWrap.hidden = false;
    uploadUI.message.hidden = true;
    uploadUI.progress.value = 0;
    uploadUI.percent.textContent = '0%';
    uploadUI.label.textContent = 'Preparing resumable upload…';
    try {
        const results = await uploadFilesResumable(files);
        uploadUI.progress.value = 100;
        uploadUI.percent.textContent = '100%';
        uploadUI.label.textContent = 'Upload complete';
        const renamed = results.filter((r, i) => r.name !== files[i].name);
        uploadStatus('success', `${files.length} file(s) uploaded successfully.${renamed.length ? ' ' + renamed.length + ' conflicting file(s) were renamed.' : ''}`);
        await loadFiles();
        setTimeout(() => {
            if (!uploadUI.xhr) closeUpload();
        }, 1600);
    } catch (err) {
        uploadStatus('error', err.message || 'Upload failed. You can retry to resume from the last confirmed chunk.');
        uploadUI.label.textContent = uploadUI.cancelled ? 'Upload cancelled' : 'Upload paused/failed';
    } finally {
        uploadUI.input.disabled = false;
        uploadUI.conflict.disabled = false;
        if (!uploadUI.overlay.hidden) uploadUI.submit.disabled = false;
    }
});

async function downloadSelected() {
    if (!S.selected.size) return toast('Select files first');
    const r = await api('/api/files/download-zip', {
        method: 'POST',
        body: { files: [...S.selected] }
    });
    const blob = await r.blob(), u = URL.createObjectURL(blob), a = document.createElement('a');
    a.href = u;
    a.download = 'download.zip';
    a.click();
    URL.revokeObjectURL(u);
}
$('#zip').addEventListener('click', downloadSelected);
$('#selection-download').addEventListener('click', downloadSelected);

async function servers() {
    const list = await (await api('/api/servers')).json();
    $('#server-list').innerHTML = list.map(s => `<div class="server"><strong>${esc(s.name)}</strong> <small>${s.type}${s.isDefault ? ' · default' : ''}${s.isActive ? ' · active' : ' · inactive'}</small><div class="actions"><button data-toggle="${s.id}">Toggle</button><button data-default="${s.id}">Set default</button><button data-sdel="${s.id}">Delete</button></div></div>`).join('');
    
    document.querySelectorAll('[data-toggle]').forEach(b => b.addEventListener('click', async () => {
        await api(`/api/servers/${b.dataset.toggle}/toggle`, { method: 'POST' });
        servers();
    }));
    document.querySelectorAll('[data-default]').forEach(b => b.addEventListener('click', async () => {
        await api(`/api/servers/${b.dataset.default}/set-default`, { method: 'POST' });
        servers();
    }));
    document.querySelectorAll('[data-sdel]').forEach(b => b.addEventListener('click', async () => {
        if (await askConfirm('Delete server', 'Delete this storage server?', 'Delete')) {
            await api(`/api/servers/${b.dataset.sdel}`, { method: 'DELETE' });
            servers();
        }
    }));
}

/**
 * Users panel.
 *
 * The roles have always been enforced server-side, but nothing could create an
 * account with one -- the CLI only ever made administrators. This is the
 * management surface for them. Every guard here is duplicated on the server;
 * hiding a control is a convenience, not the check.
 */
const ROLE_LABELS = { viewer: 'Viewer', editor: 'Editor', admin: 'Administrator' };

function userFormMessage(type, text) {
    const box = $('#user-form-message');
    box.className = `status-message ${type}`;
    box.textContent = text;
    box.hidden = false;
}

function resetUserForm() {
    $('#user-form').reset();
    $('#user-form').hidden = true;
    $('#user-form').dataset.editing = '';
    $('#user-form-title').textContent = 'New user';
    $('#user-password').placeholder = 'Password (minimum 12 characters)';
    $('#user-username').disabled = false;
    $('#user-form-message').hidden = true;
}

function openUserForm(user) {
    resetUserForm();
    const form = $('#user-form');
    form.hidden = false;
    if (!user) {
        $('#user-username').focus();
        return;
    }
    form.dataset.editing = String(user.id);
    $('#user-form-title').textContent = `Edit ${user.username}`;
    // The username is the account's identity; changing it is a different
    // operation than editing the account, so it is not offered here.
    $('#user-username').value = user.username;
    $('#user-username').disabled = true;
    $('#user-password').placeholder = 'New password (leave blank to keep current)';
    $('#user-role').value = user.role;
    $('#user-active').checked = user.isActive;
}

async function users() {
    let list;
    try {
        list = await (await api('/api/users')).json();
    } catch (e) {
        $('#user-list').innerHTML = `<div class="status-message error">${esc(e.message)}</div>`;
        return;
    }

    const when = iso => {
        if (!iso) return 'never';
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? 'unknown' : d.toLocaleDateString();
    };

    $('#user-list').innerHTML = list.map(u => `<div class="server">
        <strong>${esc(u.username)}</strong>
        <small>${esc(ROLE_LABELS[u.role] || u.role)}${u.isActive ? '' : ' · disabled'} · last signed in ${esc(when(u.lastLoginAt))}</small>
        <div class="actions">
            <button data-uedit="${u.id}">Edit</button>
            <button data-utoggle="${u.id}">${u.isActive ? 'Disable' : 'Enable'}</button>
            <button data-udel="${u.id}" class="danger-text">Delete</button>
        </div>
    </div>`).join('');

    const byId = id => list.find(u => String(u.id) === String(id));

    document.querySelectorAll('[data-uedit]').forEach(b => b.addEventListener('click', () => openUserForm(byId(b.dataset.uedit))));

    document.querySelectorAll('[data-utoggle]').forEach(b => b.addEventListener('click', async () => {
        const u = byId(b.dataset.utoggle);
        if (!u) return;
        try {
            await api(`/api/users/${u.id}`, { method: 'PATCH', body: { isActive: !u.isActive } });
            await users();
        } catch (e) { toast(e.message); }
    }));

    document.querySelectorAll('[data-udel]').forEach(b => b.addEventListener('click', async () => {
        const u = byId(b.dataset.udel);
        if (!u) return;
        if (!await askConfirm('Delete account', `Delete "${u.username}"? Files they uploaded are not removed.`, 'Delete')) return;
        try {
            await api(`/api/users/${u.id}`, { method: 'DELETE' });
            await users();
        } catch (e) { toast(e.message); }
    }));
}

$('#add-user').addEventListener('click', () => openUserForm(null));
$('#cancel-user').addEventListener('click', resetUserForm);

$('#user-form').addEventListener('submit', async e => {
    e.preventDefault();
    const editing = $('#user-form').dataset.editing;
    const password = $('#user-password').value;
    const role = $('#user-role').value;
    const isActive = $('#user-active').checked;

    try {
        if (editing) {
            const body = { role, isActive };
            if (password) body.password = password;
            await api(`/api/users/${editing}`, { method: 'PATCH', body });
        } else {
            await api('/api/users', {
                method: 'POST',
                body: { username: $('#user-username').value.trim(), password, role }
            });
        }
        resetUserForm();
        await users();
    } catch (e) {
        userFormMessage('error', e.message);
    }
});

/** Self-service password change, available to every signed-in account. */
function openPasswordDialog() {
    $('#password-dialog').reset();
    $('#password-message').hidden = true;
    $('#password-overlay').hidden = false;
    $('#password-current').focus();
}
function closePasswordDialog() { $('#password-overlay').hidden = true; }

$('#change-password').addEventListener('click', openPasswordDialog);
$('#password-close').addEventListener('click', closePasswordDialog);
$('#password-cancel').addEventListener('click', closePasswordDialog);
$('#password-overlay').addEventListener('click', e => {
    if (e.target === $('#password-overlay')) closePasswordDialog();
});
$('#password-dialog').addEventListener('submit', async e => {
    e.preventDefault();
    const box = $('#password-message');
    try {
        await api('/api/users/me/password', {
            method: 'POST',
            body: { currentPassword: $('#password-current').value, newPassword: $('#password-new').value }
        });
        box.className = 'status-message success';
        box.textContent = 'Password changed.';
        box.hidden = false;
        setTimeout(closePasswordDialog, 1200);
    } catch (err) {
        box.className = 'status-message error';
        box.textContent = err.message;
        box.hidden = false;
    }
});

$('#add-server').addEventListener('click', () => $('#server-form').hidden = false);
$('#cancel-server').addEventListener('click', () => $('#server-form').hidden = true);
$('#server-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = new FormData(e.target);
    try {
        await api('/api/servers', {
            method: 'POST',
            body: {
                name: f.get('name'),
                type: f.get('type'),
                config: JSON.parse(f.get('config')),
                isActive: f.get('isActive') === 'on',
                isDefault: f.get('isDefault') === 'on'
            }
        });
        e.target.reset();
        e.target.hidden = true;
        servers();
    } catch (x) {
        toast(x.message);
    }
});

/* ---- Trash --------------------------------------------------------------
 *
 * Deleting moves an item here; this screen is the other half of that promise.
 * Restore and purge need the write capability, so a viewer sees the list
 * without the buttons rather than buttons that always fail.
 */
async function loadTrash() {
    const box = $('#trash-list');
    const note = $('#trash-note');
    let d;
    try {
        d = await (await api('/api/trash')).json();
    } catch (e) {
        box.innerHTML = `<p class="error">${esc(e.message)}</p>`;
        return;
    }
    trashEnabledCache = !!d.enabled;

    note.textContent = !d.enabled
        ? 'This server deletes files permanently; nothing is kept here.'
        : d.retentionDays > 0
            ? `Deleted items are kept for ${d.retentionDays} days, then removed automatically.`
            : 'Deleted items are kept until someone empties the trash.';

    const canWrite = S.role === 'editor' || S.role === 'admin';
    $('#empty-trash').hidden = !canWrite || !d.entries.length;

    box.innerHTML = d.entries.length
        ? d.entries.map(e => `<div class="server">
            <strong>${esc(e.name)}</strong>
            <span class="muted">${e.isDirectory ? `Folder · ${e.files} file${e.files === 1 ? '' : 's'}` : fmt(e.bytes)} · from ${esc(parentLabel(e.originalPath))} · deleted ${new Date(e.deletedAt).toLocaleString()}${e.deletedBy ? ' by ' + esc(e.deletedBy) : ''}</span>
            ${canWrite ? `<div class="actions">
                <button data-restore="${esc(e.id)}">Restore</button>
                <button data-purge="${esc(e.id)}" class="danger-text">Delete permanently</button>
            </div>` : ''}
        </div>`).join('')
        : '<p class="muted">The trash is empty.</p>';

    box.querySelectorAll('[data-restore]').forEach(b => b.addEventListener('click', async () => {
        try {
            const r = await (await api('/api/trash/restore', { method: 'POST', body: { id: b.dataset.restore } })).json();
            toast(r.message);
        } catch (e) {
            toast(e.message);
        }
        loadTrash();
    }));
    box.querySelectorAll('[data-purge]').forEach(b => b.addEventListener('click', async () => {
        if (!await askConfirm('Delete permanently', 'This item cannot be recovered afterwards.', 'Delete')) return;
        try {
            const r = await (await api('/api/trash/purge', { method: 'POST', body: { id: b.dataset.purge } })).json();
            toast(r.message);
        } catch (e) {
            toast(e.message);
        }
        loadTrash();
    }));
}

$('#empty-trash').addEventListener('click', async () => {
    if (!await askConfirm('Empty trash', 'Everything in the trash is deleted permanently.', 'Empty trash')) return;
    try {
        const r = await (await api('/api/trash/purge', { method: 'POST', body: { all: true } })).json();
        toast(r.message);
    } catch (e) {
        toast(e.message);
    }
    loadTrash();
});

/* ---- Storage dashboard --------------------------------------------------
 *
 * Administrator-only, because it names the largest files in the store and how
 * much every account has uploaded. Measuring walks the whole tree, so the
 * server caches the figure and this screen says how old it is rather than
 * pretending it is live.
 */
function usageBar(used, limit) {
    if (!limit) return '';
    const pct = Math.min(100, Math.round(used / limit * 1000) / 10);
    const state = pct >= 90 ? ' critical' : pct >= 75 ? ' warning' : '';
    return `<div class="usage-bar${state}"><span style="width:${pct}%"></span></div>
        <p class="muted">${fmt(used)} of ${fmt(limit)} used (${pct}%)</p>`;
}

function usageTable(caption, rows, empty) {
    if (!rows.length) return `<div class="usage-block"><h3>${esc(caption)}</h3><p class="muted">${esc(empty)}</p></div>`;
    return `<div class="usage-block"><h3>${esc(caption)}</h3><table class="usage-table"><tbody>${
        // Long paths are clipped to keep the card narrow, so the full value
        // has to stay reachable on hover and to a screen reader.
        rows.map(r => `<tr><th scope="row" title="${esc(r[0])}">${esc(r[0])}</th><td>${esc(r[1])}</td></tr>`).join('')
    }</tbody></table></div>`;
}

async function loadStorage(refresh = false) {
    const summary = $('#usage-summary');
    const detail = $('#usage-detail');
    summary.innerHTML = '<p class="muted">Measuring…</p>';
    detail.innerHTML = '';
    let d;
    try {
        d = await (await api('/api/storage/usage' + (refresh ? '?refresh=1' : ''))).json();
    } catch (e) {
        summary.innerHTML = `<p class="error">${esc(e.message)}</p>`;
        return;
    }

    const measured = new Date(d.measuredAt).toLocaleString();
    $('#usage-note').textContent = d.cached
        ? `Measured ${measured}; recalculated at most every ${d.cacheSeconds} seconds.`
        : `Measured just now (${measured}).`;

    // Without a configured limit there is no percentage to show, so fall back
    // to what the disk itself reports.
    const diskUsed = d.diskTotal ? d.diskTotal - d.diskFree : 0;
    summary.innerHTML = `<div class="usage-summary">
        <div class="usage-figure"><span class="usage-value">${fmt(d.bytes)}</span><span class="muted">in ${d.files} file${d.files === 1 ? '' : 's'}</span></div>
        <div class="usage-figure"><span class="usage-value">${d.diskTotal ? fmt(d.diskFree) : '—'}</span><span class="muted">free on disk${d.diskTotal ? ' of ' + fmt(d.diskTotal) : ''}</span></div>
        <div class="usage-figure"><span class="usage-value">${fmt(d.trash.bytes)}</span><span class="muted">reclaimable from ${d.trash.entries} trash entr${d.trash.entries === 1 ? 'y' : 'ies'}</span></div>
    </div>
    ${d.storageLimitBytes
        ? usageBar(d.bytes, d.storageLimitBytes)
        : `<p class="muted">No store limit is configured (STORAGE_LIMIT_GB). Disk is ${d.diskTotal ? Math.round(diskUsed / d.diskTotal * 100) + '% full' : 'not measurable here'}.</p>`}`;

    detail.innerHTML = [
        usageTable('Folders', d.folders.map(f => [f.name, `${fmt(f.bytes)} · ${f.files} file${f.files === 1 ? '' : 's'}`]), 'No folders yet.'),
        usageTable('By type', Object.entries(d.byType).map(([k, v]) => [k, fmt(v)]), 'Nothing stored yet.'),
        usageTable('Largest files', d.largest.map(f => [f.path, fmt(f.bytes)]), 'Nothing stored yet.'),
        usageTable('Uploaded by account',
            d.byUser.map(u => [u.username || 'unattributed',
                fmt(u.bytes) + (d.userQuotaBytes ? ` of ${fmt(d.userQuotaBytes)}` : '')]),
            'No uploads have been attributed yet.')
    ].join('');
}

$('#recalculate-usage').addEventListener('click', () => loadStorage(true));

async function route() {
    const p = window.CLOUDHUB_ROUTE || new URLSearchParams(location.search).get('route') || '/';
    ['files', 'servers', 'browse', 'users', 'trash', 'storage'].forEach(x => $(`#${x}-page`).hidden = true);
    document.querySelectorAll('nav a').forEach(a => a.classList.toggle('active', (a.dataset.route || '/') === p));
    if (p === '/trash') {
        $('#trash-page').hidden = false;
        await loadTrash();
    } else if (p === '/storage') {
        $('#storage-page').hidden = false;
        await loadStorage();
    } else if (p === '/users') {
        $('#users-page').hidden = false;
        await users();
    } else if (p === '/servers') {
        $('#servers-page').hidden = false;
        await servers();
    } else if (p === '/browse') {
        $('#browse-page').hidden = false;
        const a = await (await api('/api/servers/active')).json();
        $('#active-servers').innerHTML = a.map(s => `<div class="server">${esc(s.name)} · ${s.type}</div>`).join('');
    } else {
        $('#files-page').hidden = false;
        await loadFiles();
    }
}

(async () => {
    try {
        const r = await fetch(appUrl('/api/auth/status'), { credentials: 'same-origin' });
        const d = await r.json();
        if (d.authenticated) {
            S.csrf = d.csrfToken || '';
            S.role = d.user?.role || 'viewer';
            // Convenience only: /api/users is administrator-gated server-side.
            $('#nav-users').hidden = S.role !== 'admin';
            $('#nav-storage').hidden = S.role !== 'admin';
            $('#login').style.display = 'none';
            await route();
        } else {
            $('#login').style.display = 'flex';
        }
    } catch {
        $('#login').style.display = 'flex';
    }
})();

