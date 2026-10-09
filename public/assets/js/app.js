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
let toastTimer = 0;
const toast = (m, ms = 2200) => {
    const t = $('#toast');
    t.textContent = m;
    t.style.display = 'block';
    // Each message gets its full time on screen: an earlier message's timer
    // used to hide the one that replaced it, so "Preparing…" cut a quick
    // error short.
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.style.display = 'none', ms);
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
        let m = `HTTP ${r.status}`, code = 'HTTP_ERROR', requestId = '', details = null;
        try {
            const d = await r.json();
            m = d.error?.message || m;
            code = d.error?.code || code;
            requestId = d.requestId || '';
            details = d.error?.details || null;
        } catch {}
        const e = Error(m);
        e.code = code;
        e.status = r.status;
        e.requestId = requestId;
        // What a refusal adds, such as how long to wait before asking again.
        e.details = details;
        throw e;
    }
    return r;
}

/**
 * Make the page the signed-in account's: its token, its role's navigation,
 * and the sign-in overlay put away -- back on its password step for next time.
 */
function applySignIn(d) {
    S.csrf = d.csrfToken || '';
    S.role = d.user?.role || 'viewer';
    S.user = d.user || null;
    $('#nav-users').hidden = S.role !== 'admin';
    $('#nav-storage').hidden = S.role !== 'admin';
    signedIn();
    showPasswordStep();
    $('#login').style.display = 'none';
}

async function login(u, p) {
    const r = await fetch(appUrl('/api/auth/login'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: u, password: p })
    });
    const d = await r.json().catch(() => ({}));
    // The password was right; the account wants an emailed code as well. The
    // session is not signed in until that is checked.
    if (d.error?.code === 'TWO_FACTOR_REQUIRED') {
        S.csrf = d.csrfToken || '';
        showTwoFactorStep(d.twoFactor || {});
        return;
    }
    if (!r.ok) throw Error(d.error?.message || 'Sign in failed');
    applySignIn(d);
    await openRoute();
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
    showPasswordStep();
    $('#login').style.display = 'flex';
});

/* ---- two-step verification: shared pieces ---------------------------------- */

/** A button that stays disabled, counting down, until a wait is over. */
function countdownButton(control, label) {
    let timer = 0;
    const stop = () => {
        clearInterval(timer);
        timer = 0;
        control.disabled = false;
        control.textContent = label;
    };
    return {
        start(seconds) {
            clearInterval(timer);
            const until = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
            const tick = () => {
                const left = Math.ceil((until - Date.now()) / 1000);
                if (left <= 0) return stop();
                control.disabled = true;
                control.textContent = `${label} (${left} s)`;
            };
            tick();
            if (until > Date.now()) timer = setInterval(tick, 1000);
        },
        stop
    };
}

/* ---- two-step verification at sign-in -------------------------------------- */

/**
 * The second step for an account with two-step verification, once its
 * password was right. Until the code is checked the session counts as signed
 * out everywhere else. These calls go to /api/auth directly rather than
 * through api(): a 401 here means "start again with the password", not the
 * expired session api() takes it for.
 */
const signInCode = { info: null, recovery: false, resend: null };

async function authPost(route, body = {}) {
    const r = await fetch(appUrl(route), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': S.csrf || '' },
        body: JSON.stringify(body)
    });
    return { ok: r.ok, status: r.status, data: await r.json().catch(() => ({})) };
}

function showPasswordStep() {
    signInCode.resend?.stop();
    $('#two-factor-form').hidden = true;
    $('#login-form').hidden = false;
}

function showTwoFactorStep(info) {
    signInCode.info = info;
    // With no email to send, a recovery code is the only way in.
    signInCode.recovery = !info.emailAvailable;
    signInCode.resend ??= countdownButton($('#two-factor-resend'), 'Send a new code');
    $('#login-form').hidden = true;
    $('#two-factor-form').hidden = false;
    $('#two-factor-code').value = '';
    $('#two-factor-recovery').value = '';
    $('#two-factor-error').textContent = '';
    $('#login-error').textContent = '';
    $('#login').style.display = 'flex';
    renderSignInCode();
    if (!info.emailAvailable) {
        signInStatus(info.emailHint
            ? 'This server cannot send email right now. Use one of your recovery codes, or ask your administrator.'
            : 'Sign-in codes now come by email, and this account has no address for them yet. Use one of your recovery codes, then add an address under Security.');
    } else if (info.codeSent) {
        // Back on this step after a reload: the code already sent still works.
        signInStatus('A code was already sent, and still works.');
        signInCode.resend.start(info.resendIn);
    } else {
        sendSignInCode();
    }
}

function renderSignInCode() {
    const recovery = signInCode.recovery;
    const info = signInCode.info || {};
    $('#two-factor-code-label').hidden = recovery;
    $('#two-factor-recovery-label').hidden = !recovery;
    $('#two-factor-resend').hidden = recovery || !info.emailAvailable;
    $('#two-factor-switch').hidden = recovery && !info.emailAvailable;
    $('#two-factor-switch').textContent = recovery ? 'Use an emailed code instead' : 'Use a recovery code instead';
    $('#two-factor-intro').textContent = recovery
        ? 'Enter one of the recovery codes you saved when you turned on two-step verification. Each one works once.'
        : `Enter the ${info.codeLength || 6}-digit code we sent to ${info.emailHint || 'your email'}.`;
    setTimeout(() => (recovery ? $('#two-factor-recovery') : $('#two-factor-code')).focus(), 0);
}

function signInStatus(text) {
    $('#two-factor-status').textContent = text || '';
}

async function sendSignInCode() {
    $('#two-factor-error').textContent = '';
    signInStatus('Sending a code…');
    $('#two-factor-resend').disabled = true;
    let answer;
    try {
        answer = await authPost('/api/auth/two-factor/send');
    } catch {
        signInStatus('');
        $('#two-factor-error').textContent = 'Could not reach the server.';
        signInCode.resend.start(0);
        return;
    }
    const { ok, status, data } = answer;
    if (status === 401) return signInCodeExpired(data);
    if (ok) {
        const minutes = Math.max(1, Math.round((data.expiresIn || 300) / 60));
        signInStatus(`Code sent. It works for ${minutes} minute${minutes === 1 ? '' : 's'}. Not there? Check your spam folder.`);
        signInCode.resend.start(data.resendIn);
        return;
    }
    signInStatus('');
    $('#two-factor-error').textContent = data.error?.message || 'The code could not be sent.';
    signInCode.resend.start(data.error?.details?.retryAfter || 0);
}

function signInCodeExpired(data) {
    showPasswordStep();
    $('#login-error').textContent = data?.error?.message || 'Your sign-in timed out. Enter your password again.';
    $('#password').focus();
}

$('#two-factor-resend').addEventListener('click', () => sendSignInCode());
$('#two-factor-switch').addEventListener('click', () => {
    signInCode.recovery = !signInCode.recovery;
    $('#two-factor-error').textContent = '';
    renderSignInCode();
});
$('#two-factor-back').addEventListener('click', async () => {
    showPasswordStep();
    // The code that was sent stops working.
    try { await authPost('/api/auth/two-factor/cancel'); } catch {}
    $('#password').value = '';
    $('#password').focus();
});
$('#two-factor-form').addEventListener('submit', async e => {
    e.preventDefault();
    const recovery = signInCode.recovery;
    const input = recovery ? $('#two-factor-recovery') : $('#two-factor-code');
    const error = $('#two-factor-error');
    const value = input.value.trim();
    if (!value) {
        error.textContent = recovery ? 'Enter a recovery code.' : 'Enter the code from the email.';
        return;
    }
    const submit = $('#two-factor-submit');
    submit.disabled = true;
    submit.textContent = 'Verifying…';
    error.textContent = '';
    try {
        const { ok, status, data } = await authPost('/api/auth/two-factor/verify', recovery ? { recoveryCode: value } : { code: value });
        if (ok) {
            applySignIn(data);
            if (typeof data.recoveryCodesLeft === 'number') {
                toast(`Signed in with a recovery code; ${data.recoveryCodesLeft} left. If you cannot get into your email, change the address under Security.`, 8000);
            }
            await openRoute();
            return;
        }
        if (status === 401) return signInCodeExpired(data);
        error.textContent = data.error?.message || 'That did not work.';
        input.select();
    } catch {
        error.textContent = 'Could not reach the server.';
    } finally {
        submit.disabled = false;
        submit.textContent = 'Verify';
    }
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

function updateSelectionCount() {
    const n = S.selected.size;
    $('#selection-bar').hidden = n === 0;
    $('#selection-count').textContent = `${n} selected`;
}

/** Full resync. Needed after a re-render or select-all, not after one toggle. */
function updateSelectionUI() {
    updateSelectionCount();
    document.querySelectorAll('[data-sel]').forEach(c => c.checked = S.selected.has(decodeURIComponent(c.dataset.sel)));
    document.querySelectorAll('.file').forEach(card => card.classList.toggle('selected', S.selected.has(decodeURIComponent(card.dataset.path))));
}

function toggleSelection(path, checked) {
    checked ? S.selected.add(path) : S.selected.delete(path);
    // Only the toggled card changed, so the full sweep -- two document-wide
    // querySelectorAll passes plus a decodeURIComponent per node -- is not
    // needed here. Ticking fifty files by hand ran it fifty times.
    updateSelectionCount();
    const card = document.querySelector(`.file[data-path="${CSS.escape(encodeURIComponent(path))}"]`);
    if (card) card.classList.toggle('selected', checked);
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

/**
 * Note that this browser could not decode a video, on its card.
 *
 * Advisory and nothing more: the card keeps working and stays clickable, so
 * somebody who knows better than the browser can still try it, and the player
 * will name the codec if it fails again. A listing that hid files on this basis
 * would be worse than the problem -- the verdict is per-browser, and a file
 * this one refuses may play perfectly in the next.
 */
function markUndecodable(button, status) {
    button.classList.add('undecodable');
    button.title = 'This browser cannot decode this video. Open it to see which codec it is.';
    if (status) status.textContent = 'Not playable here';
    const card = button.closest('.file');
    if (card && !card.querySelector('.codec-warning')) {
        const note = document.createElement('span');
        note.className = 'codec-warning';
        note.textContent = 'not playable in this browser';
        card.querySelector('.file-info')?.append(note);
    }
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
         * Tell "this browser cannot decode the file" apart from "the canvas
         * step failed". The <video> element has already tried, so its own error
         * is the authority -- codes 3 and 4 mean the decode never happened, and
         * the fallback below would fail for the same reason.
         *
         * Marking it here costs nothing: this pass already loads every visible
         * video lazily, so the answer was being produced and thrown away.
         */
        const code = video.error?.code;
        if (code === MediaError.MEDIA_ERR_DECODE || code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED) {
            markUndecodable(button, status);
            return;
        }

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

let videoThumbObserver = null;

function initVideoThumbnails() {
    // Every render used to create another IntersectionObserver and leave the
    // previous one observing nodes that renderFiles() had already thrown away,
    // so filtering a folder left one live observer per keystroke.
    if (videoThumbObserver) { videoThumbObserver.disconnect(); videoThumbObserver = null; }

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
        const observer = videoThumbObserver = new IntersectionObserver(entries => {
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

    // Frames for files no longer listed can go; the rest stay cached so a
    // search keystroke or sort change does not re-decode them.
    releaseStaleVideoThumbs(new Set(currentEntries().map(videoThumbKey)));

    initVideoThumbnails();
    updateSelectionUI();
}

/*
 * The file list's listeners, bound once to the list itself.
 *
 * renderFiles() used to attach six or seven listeners to every card each time
 * it ran -- every debounced keystroke, sort change and view toggle -- after a
 * document-wide query for each kind: on a 3,200-file folder, some 20,000
 * closures per render. The markup is still rebuilt; the listeners are not.
 */
const fileList = $('#file-list');
fileList.addEventListener('click', e => {
    const b = e.target.closest('[data-open],[data-preview],[data-down],[data-share],[data-menu]');
    if (!b || !fileList.contains(b)) return;
    // As the per-button listeners did: the click goes no further, so the
    // document's handler that closes the context menu does not see it.
    e.stopPropagation();
    const d = b.dataset;
    if (d.open !== undefined) loadFiles(decodeURIComponent(d.open));
    else if (d.preview !== undefined) openPreview(decodeURIComponent(d.preview));
    else if (d.down !== undefined) download(decodeURIComponent(d.down));
    else if (d.share !== undefined) share(decodeURIComponent(d.share));
    else showContextMenu(decodeURIComponent(d.menu), e.clientX, e.clientY);
});
fileList.addEventListener('change', e => {
    const c = e.target.closest('[data-sel]');
    if (c && fileList.contains(c)) toggleSelection(decodeURIComponent(c.dataset.sel), c.checked);
});
fileList.addEventListener('contextmenu', e => {
    const card = e.target.closest('.file');
    if (!card || !fileList.contains(card)) return;
    e.preventDefault();
    showContextMenu(decodeURIComponent(card.dataset.path), e.clientX, e.clientY);
});
fileList.addEventListener('dblclick', e => {
    const card = e.target.closest('.file');
    if (!card || !fileList.contains(card)) return;
    const p = decodeURIComponent(card.dataset.path);
    const f = currentEntries().find(x => x.path === p);
    f?.isDirectory ? loadFiles(p) : openPreview(p);
});
// Images still use the authenticated server-side image thumbnail endpoint. A
// load error does not bubble, so it is caught on the way down instead.
fileList.addEventListener('error', e => {
    const img = e.target;
    if (!(img instanceof HTMLImageElement)) return;
    const button = img.closest('.thumb-preview');
    if (!button || button.classList.contains('video-thumb')) return;
    button.replaceWith(Object.assign(document.createElement('span'), {
        className: 'file-icon',
        textContent: '🖼️'
    }));
}, true);

/** Bumped per preview, so a slow response cannot fill a dialog opened after it. */
let previewRun = 0;

/**
 * Opens the integrated file preview dialog.
 */
async function openPreview(path) {
    const run = ++previewRun;
    const name = path.split('/').pop() || path;
    const ext = (name.includes('.') ? name.split('.').pop() : '').toLowerCase();
    const url = appUrl('/api/files/preview?path=' + encodeURIComponent(path));
    // SVG is handled separately below: the server returns it as text.
    const imageExt = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif']);
    const videoExt = new Set(['mp4', 'webm', 'ogv', 'mov', 'm4v']);
    const audioExt = new Set(['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac']);
    const textExt = new Set([
        'txt', 'md', 'log', 'json', 'xml', 'csv', 'html', 'htm', 'css', 'js',
        'mjs', 'ts', 'tsx', 'jsx', 'php', 'sql', 'ini', 'env', 'yml', 'yaml',
        'sh', 'bat', 'ps1', 'py', 'java', 'kt', 'c', 'h', 'cpp', 'hpp'
    ]);

    let body = '';
    if (ext === 'svg') body = '<div class="preview-loading">Loading preview…</div>';
    else if (imageExt.has(ext)) body = `<img class="preview-image" src="${url}" alt="${esc(name)}">`;
    else if (videoExt.has(ext)) body = `<div class="preview-unsupported preview-media-gate"><div class="preview-file-icon">🎬</div><p>Video playback is restricted to the cfh-player.</p><button data-open-player>Play</button><button data-preview-download>Download file</button></div>`;
    else if (audioExt.has(ext)) body = `<div class="preview-audio-wrap"><div class="preview-file-icon">🎵</div><audio class="preview-audio" src="${url}" controls preload="metadata"></audio></div>`;
    else if (ext === 'pdf') body = `<iframe class="preview-frame" src="${url}" title="${esc(name)}"></iframe>`;
    else if (textExt.has(ext)) {
        body = '<div class="preview-loading">Loading preview…</div>';
    } else {
        body = `<div class="preview-unsupported"><div class="preview-file-icon">📄</div><p>No inline preview is available for this file type.</p><button data-preview-download>Download file</button></div>`;
    }

    showPreviewDialog(name, body);

    // Where an asynchronous preview may still write: gone if the dialog was
    // closed, or replaced by a newer preview, while the request was running.
    const previewTarget = () => (run === previewRun ? $('#preview-body') : null);
    const previewFailed = error => {
        const target = previewTarget();
        if (target) target.innerHTML = `<div class="preview-error"><strong>Preview failed</strong><p>${esc(error.message)}</p></div>`;
    };

    if (ext === 'svg') {
        // Served back as plain text: as an image document on this origin an
        // SVG could run script. Drawn through an <img> from a blob it renders
        // as a picture and nothing in it can execute.
        try {
            const text = await (await api('/api/files/preview?path=' + encodeURIComponent(path))).text();
            const target = previewTarget();
            if (target) {
                const blobUrl = URL.createObjectURL(new Blob([text], { type: 'image/svg+xml' }));
                const img = Object.assign(document.createElement('img'), { className: 'preview-image', alt: name });
                const release = () => URL.revokeObjectURL(blobUrl);
                img.addEventListener('load', release, { once: true });
                img.addEventListener('error', release, { once: true });
                img.src = blobUrl;
                target.replaceChildren(img);
            }
        } catch (error) {
            previewFailed(error);
        }
    }

    if (textExt.has(ext)) {
        // Only the first 512 KB is asked for, because that is all the dialog
        // shows: fetching a multi-gigabyte log in full just to display its
        // start froze the tab. Small files are fetched whole, which also keeps
        // an empty file from being an unsatisfiable range.
        const limit = 524288;
        const known = currentEntries().find(f => f.path === path);
        const ranged = !known || (known.size || 0) > limit;
        try {
            const response = await api('/api/files/preview?path=' + encodeURIComponent(path),
                ranged ? { headers: { Range: `bytes=0-${limit - 1}` } } : {});
            const text = await response.text();
            const total = Number((response.headers.get('Content-Range') || '').split('/')[1]) || text.length;
            const truncated = response.status === 206 && total > limit;
            const target = previewTarget();
            if (target) target.innerHTML = `<pre class="preview-text">${esc(text)}${truncated ? '\n\n[Preview truncated at 512 KB]' : ''}</pre>`;
        } catch (error) {
            // A ranged request for an empty file is unsatisfiable, not a failure.
            if (error.status === 416) {
                const target = previewTarget();
                if (target) target.innerHTML = '<pre class="preview-text"></pre>';
            } else previewFailed(error);
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

/**
 * Save a file the server sends as an attachment.
 *
 * Left to the browser's own download manager, which streams it to disk.
 * Fetching it into a Blob first held the whole file in the page's memory --
 * up to the upload limit -- before a byte was saved, which is where a phone
 * gave up on a large video. A HEAD goes first so a missing file or an expired
 * session is reported rather than saved as a "file" holding a JSON error. The
 * session cookie is scoped to "/", so the plain navigation carries it on a
 * subdirectory install too.
 */
async function saveFromServer(url, name) {
    try {
        await api(url, { method: 'HEAD' });
    } catch (error) {
        // A HEAD answer has no body to carry the server's message.
        throw error.status === 404 ? Error('That file is no longer there') : error;
    }
    clickDownload(appUrl(url), name);
}

/** Save bytes already in the page, releasing the URL once the save has begun. */
function saveBlob(blob, name) {
    const url = URL.createObjectURL(blob);
    clickDownload(url, name);
    // Revoked straight after click(), Firefox and Safari could cancel the
    // download before it had started.
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}

function clickDownload(href, name) {
    const a = Object.assign(document.createElement('a'), { href, download: name });
    document.body.appendChild(a);
    a.click();
    a.remove();
}

async function download(p) {
    try {
        await saveFromServer(`/api/files/download?path=${encodeURIComponent(p)}`, p.split('/').pop());
    } catch (error) {
        toast(error.message);
    }
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

/**
 * askInput() for a password: masked, offered to password managers, and not
 * left behind in the field once answered.
 */
async function askPassword(title, label) {
    const input = $('#dialog-input');
    input.type = 'password';
    input.autocomplete = 'current-password';
    try {
        return await askInput(title, label, '');
    } finally {
        input.value = '';
        input.type = 'text';
        input.removeAttribute('autocomplete');
    }
}

async function del(p) {
    const name = p.split('/').pop();
    // The wording depends on where the item actually goes, which the server
    // decides. Ask it once and remember, rather than promising either.
    const trashed = await trashEnabled();
    const warning = trashed ? 'It can be restored from the trash.' : 'This cannot be undone.';
    if (!await askConfirm('Delete item', `Delete "${name}"? ${warning}`, 'Delete')) return;
    try {
        // "auto": a large folder, when the trash is off, is deleted by a task
        // rather than inside this request. Anything else is as it was.
        const d = await (await api('/api/files/delete', { method: 'DELETE', body: { path: p, background: 'auto' } })).json();
        if (d.queued) taskQueued(d.job);
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
            const d = await (await api('/api/files/delete', {
                method: 'DELETE',
                body: { path, background: 'auto' }
            })).json();
            if (d.queued) taskQueued(d.job);
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
    const writer = canWrite();
    const isZip = !f.isDirectory && /\.zip$/i.test(f.name);
    menu.innerHTML = `${f.isDirectory ? '<button data-cmd="open">Open</button><button data-cmd="zipdown">Download as ZIP</button>' : '<button data-cmd="preview">Preview</button><button data-cmd="download">Download</button><button data-cmd="share">Share</button>'}<button data-cmd="rename">Rename</button><button data-cmd="move">Move to…</button><button data-cmd="copy">Copy to…</button>`
        + (writer ? '<button data-cmd="compress">Compress to ZIP</button>' : '')
        + (writer && isZip ? '<button data-cmd="extract">Extract here</button>' : '')
        + (f.isDirectory ? '' : '<button data-cmd="checksum">Checksum (SHA-256)</button>')
        + (writer && f.isDirectory ? '<button data-cmd="thumbs">Make thumbnails</button>' : '')
        + '<button data-cmd="delete" class="danger-text">Delete</button>';
    menu.hidden = false;
    menu.style.left = `${Math.min(x, window.innerWidth - 190)}px`;
    // Measured, now that it can hold more or fewer entries per item.
    menu.style.top = `${Math.max(8, Math.min(y, window.innerHeight - menu.offsetHeight - 8))}px`;
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
        if (c === 'zipdown') downloadAsArchive([path]);
        if (c === 'compress') compress([path]);
        if (c === 'extract') extract(path);
        if (c === 'checksum') checksum([path]);
        if (c === 'thumbs') makeThumbnails(path);
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
    const matches = `${n} match${n === 1 ? '' : 'es'} for "${S.results.query}"`;
    // "No matches anywhere" is only true when the whole tree was searched: a
    // search that stopped at a bound, or is still going, says so instead.
    box.textContent = S.results.incomplete
        ? `${matches} so far — still searching…`
        : n === 0
            ? (S.results.truncated
                ? `No matches for "${S.results.query}" in the first ${S.results.scanned} items; the search stopped there`
                : `No matches for "${S.results.query}" anywhere under ${S.path === '/' ? 'Root' : S.path}`)
            : `${matches}${S.results.truncated ? ' (showing the first ' + n + '; narrow the search for the rest)' : ''}`;
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
    // A large tree is searched in slices: each answer is what has been found so
    // far, `incomplete` says there is more, and asking again carries on where
    // the server stopped. Results appear after the first slice rather than when
    // the whole walk is done. A slice that got no further ends the loop.
    let scanned = -1;
    try {
        for (;;) {
            const d = await (await api(`/api/files/search?q=${encodeURIComponent(q)}&path=${encodeURIComponent(S.path)}&budget=2500`)).json();
            // A slower earlier request must not overwrite a newer one's results.
            if (run !== searchRun) return;
            S.results = { query: d.query, entries: d.results, truncated: d.truncated,
                incomplete: !!d.incomplete && d.scanned > scanned, scanned: d.scanned };
            S.selected.clear();
            renderSearchStatus();
            renderFiles();
            if (!S.results.incomplete) return;
            scanned = d.scanned;
        }
    } catch (e) {
        if (run !== searchRun) return;
        toast(e.message);
    }
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
        // A large copy is offered to the server as a task ("auto"): it answers
        // with the task instead of making this request wait for every byte.
        const body = verb === 'copy' ? { paths, destination, background: 'auto' } : { paths, destination };
        const d = await (await api(`/api/files/${verb}`, { method: 'POST', body })).json();
        if (d.queued) {
            taskQueued(d.job);
            toast(`${d.message} — follow it under Tasks`);
            return;
        }
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
$('#selection-compress').addEventListener('click', () => compress([...S.selected]));

$('#mkdir').addEventListener('click', makeFolder);
$('#refresh').addEventListener('click', () => loadFiles());
$('#search').addEventListener('input', () => {
    // Both paths are debounced by the same 250ms. Filtering in "this folder"
    // mode used to re-render on every character, and a render rebuilds the
    // whole list -- every card, every <img>, and a fresh set of listeners --
    // so typing an eight-character name rebuilt it eight times.
    clearTimeout(searchTimer);
    searchTimer = setTimeout(S.scope === 'all' ? runSearch : renderFiles, 250);
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
        maxFiles: 150, maxMb: 5120, chunkMb: 8, retryCount: 3, conflict: 'rename'
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
    // 0 -- and anything unparseable -- means no limit. Files go up one at a
    // time through the resumable protocol, so a long queue costs patience
    // rather than server load, and the cap is policy an admin chooses.
    const maxFiles = Number(uploadUI.limits.maxFiles) || 0;
    if (maxFiles > 0 && files.length > maxFiles) return `You can upload at most ${maxFiles} file${maxFiles === 1 ? '' : 's'} at once.`;
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
                uploadTiming.serverMs += Number(state.serverMs) || 0;
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

/*
 * How much of an upload was the server and how much was the network.
 *
 * Every chunk reply carries the milliseconds PHP spent on it, so the two can be
 * reported side by side instead of argued about. A transfer that is mostly wall
 * clock and barely any server time is transport-bound, and no amount of work on
 * this end will change it.
 */
const uploadTiming = { serverMs: 0, startedAt: 0 };

function describeUploadTiming(bytes) {
    const wallMs = Date.now() - uploadTiming.startedAt;
    if (wallMs <= 0) return '';
    const secs = ms => ms >= 60000
        ? `${Math.floor(ms / 60000)}m${String(Math.round((ms % 60000) / 1000)).padStart(2, '0')}s`
        : `${(ms / 1000).toFixed(1)}s`;
    const rate = bytes / (wallMs / 1000);
    return `${fmt(bytes)} in ${secs(wallMs)} (${fmt(rate)}/s) · ${secs(uploadTiming.serverMs)} in the server, ${secs(Math.max(0, wallMs - uploadTiming.serverMs))} in transfer`;
}

async function uploadFilesResumable(files) {
    const totalBytes = files.reduce((n, f) => n + f.size, 0);
    let completedBytes = 0;
    const results = [];
    uploadTiming.serverMs = 0;
    uploadTiming.startedAt = Date.now();
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
    uploadUI.bytes.textContent = describeUploadTiming(totalBytes);
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
    const paths = [...S.selected];
    const entries = paths.map(p => currentEntries().find(f => f.path === p));
    const bytes = entries.reduce((n, f) => n + (f?.size || 0), 0);
    // A folder's size is unknown here and a large selection takes a while to
    // pack, so those are built by a task and downloaded when ready. A few
    // small files are still packed while the browser waits, as before.
    if (entries.some(f => !f || f.isDirectory) || bytes > 100 * 1048576 || paths.length > 50) return downloadAsArchive(paths);
    // Still through a Blob: the archive is built by a POST, which the browser
    // cannot hand to its download manager with the CSRF header attached.
    // Caught here because this runs straight from a click: a refusal (nothing
    // readable, too many files) used to vanish as an unhandled rejection,
    // leaving the button looking as if it did nothing.
    try {
        toast('Preparing the archive…');
        const r = await api('/api/files/download-zip', {
            method: 'POST',
            body: { files: [...S.selected] }
        });
        saveBlob(await r.blob(), 'download.zip');
    } catch (e) {
        toast(e.message);
    }
}
$('#zip').addEventListener('click', downloadSelected);
$('#selection-download').addEventListener('click', downloadSelected);

async function servers() {
    let list;
    try {
        list = await (await api('/api/servers')).json();
    } catch (e) {
        $('#server-list').innerHTML = `<p class="error">${esc(e.message)}</p>`;
        return;
    }
    $('#server-list').innerHTML = list.map(s => `<div class="server"><strong>${esc(s.name)}</strong> <small>${esc(String(s.type))}${s.isDefault ? ' · default' : ''}${s.isActive ? ' · active' : ' · inactive'}</small><div class="actions"><button data-toggle="${s.id}">Toggle</button><button data-default="${s.id}">Set default</button><button data-sdel="${s.id}">Delete</button></div></div>`).join('');

    // A failed action is reported rather than left as an unhandled rejection,
    // and the list is redrawn either way so it shows what the server holds.
    const act = async (url, method) => {
        try {
            await api(url, { method });
        } catch (e) {
            toast(e.message);
        }
        servers();
    };
    document.querySelectorAll('[data-toggle]').forEach(b => b.addEventListener('click', () => act(`/api/servers/${b.dataset.toggle}/toggle`, 'POST')));
    document.querySelectorAll('[data-default]').forEach(b => b.addEventListener('click', () => act(`/api/servers/${b.dataset.default}/set-default`, 'POST')));
    document.querySelectorAll('[data-sdel]').forEach(b => b.addEventListener('click', async () => {
        if (await askConfirm('Delete server', 'Delete this storage server?', 'Delete')) act(`/api/servers/${b.dataset.sdel}`, 'DELETE');
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
        <small>${esc(ROLE_LABELS[u.role] || u.role)}${u.isActive ? '' : ' · disabled'}${u.twoFactorEnabled ? ' · two-step on' : ''} · last signed in ${esc(when(u.lastLoginAt))}</small>
        <div class="actions">
            <button data-uedit="${u.id}">Edit</button>
            <button data-utoggle="${u.id}">${u.isActive ? 'Disable' : 'Enable'}</button>
            ${u.twoFactorEnabled && u.id !== S.user?.id ? `<button data-utf="${u.id}">Reset two-step</button>` : ''}
            <button data-udel="${u.id}" class="danger-text">Delete</button>
        </div>
    </div>`).join('');

    const byId = id => list.find(u => String(u.id) === String(id));

    document.querySelectorAll('[data-uedit]').forEach(b => b.addEventListener('click', () => openUserForm(byId(b.dataset.uedit))));

    // For someone who has lost access to their email and their recovery codes.
    // Asks for the administrator's own password; the server emails the owner.
    document.querySelectorAll('[data-utf]').forEach(b => b.addEventListener('click', async () => {
        const u = byId(b.dataset.utf);
        if (!u) return;
        if (!await askConfirm('Reset two-step verification',
            `Turn off two-step verification for "${u.username}"? They will sign in with their password alone until they turn it on again, and get an email saying so.`,
            'Continue')) return;
        const password = await askPassword('Confirm it is you', 'Your password');
        if (!password) return;
        try {
            const d = await (await api(`/api/users/${u.id}/two-factor`, { method: 'DELETE', body: { currentPassword: password } })).json();
            toast(d.message || 'Two-step verification reset');
            await users();
        } catch (e) { toast(e.message, 5000); }
    }));

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

/* ---- Security: two-step verification settings ------------------------------ */

/**
 * The account's own two-step verification. A change is two calls the server
 * answers: start (the password, and for 'email' the new address) and confirm
 * (the emailed code, or for the current address a recovery code). Moving to a
 * new address can take two codes -- the current address's first -- and the
 * server's answer says which address it is waiting on.
 */
const security = { overview: null, flow: null, recovery: false, codes: null, resend: null };

function securityMessage(type, text) {
    const box = $('#tf-message');
    box.hidden = !text;
    box.className = `status-message ${type || ''}`;
    box.textContent = text || '';
}

/** Show one step of a change (or none: the summary and its buttons). */
function securityStep(visible) {
    ['#tf-start', '#tf-verify', '#tf-codes-step'].forEach(id => $(id).hidden = id !== visible);
    $('#tf-actions').hidden = visible !== null;
    if (visible !== '#tf-verify') security.resend?.stop();
}

async function openSecurity() {
    security.resend ??= countdownButton($('#tf-resend'), 'Send a new code');
    security.flow = null;
    security.codes = null;
    securityMessage(null);
    securityStep(null);
    $('#security-overlay').hidden = false;
    await loadSecurity();
}

async function closeSecurity() {
    if (security.codes && !await askConfirm('Close without saving?',
        'Your new recovery codes will not be shown again. You can create new ones later under Security.', 'Close')) return;
    backToSecurity();
    security.codes = null;
    $('#tf-code-list').innerHTML = '';
    $('#security-overlay').hidden = true;
}

/** Leave a change half-way. The server forgets it too, so its code stops working. */
function backToSecurity(cancelOnServer = true) {
    if (cancelOnServer && security.flow) api('/api/users/me/two-factor/cancel', { method: 'POST' }).catch(() => {});
    security.flow = null;
    securityStep(null);
}

async function loadSecurity() {
    $('#tf-summary').textContent = 'Loading…';
    try {
        security.overview = await (await api('/api/users/me/two-factor')).json();
    } catch (e) {
        $('#tf-summary').textContent = '';
        securityMessage('error', e.message);
        return;
    }
    renderSecurity();
}

function renderSecurity() {
    const o = security.overview || {};
    $('#tf-badge').textContent = o.enabled ? 'On' : 'Off';
    $('#tf-badge').classList.toggle('on', !!o.enabled);
    let summary;
    if (o.enabled) {
        const left = o.recoveryCodesLeft;
        summary = o.emailHint
            ? `Signing in asks for a code sent to ${o.emailHint}. ${left} recovery code${left === 1 ? '' : 's'} left.`
            : `Signing in asks for a code, but there is no email address to send it to yet (codes used to come by text message): add one. ${left} recovery code${left === 1 ? '' : 's'} left.`;
        if (!o.emailAvailable) summary += ' This server cannot send email right now: sign in with a recovery code until it can.';
        else if (left <= 2) summary += ' Create new ones soon.';
    } else if (!o.schemaReady) {
        summary = 'Not available yet: the server’s database needs updating (php database/migrate.php).';
    } else if (!o.emailAvailable) {
        summary = 'Not available: no mail server is set up on this server. An administrator can configure one.';
    } else {
        summary = 'Off. Turn it on to be asked, after your password, for a code sent to your email.';
    }
    $('#tf-summary').textContent = summary;
    $('#tf-enable').hidden = !!o.enabled || !o.available;
    $('#tf-change').hidden = !o.enabled;
    $('#tf-change').textContent = o.emailHint ? 'Change email' : 'Add email';
    $('#tf-change').disabled = !o.emailAvailable;
    $('#tf-codes').hidden = !o.enabled;
    $('#tf-disable').hidden = !o.enabled;
}

function startSecurityChange(action) {
    const o = security.overview || {};
    security.flow = { action };
    security.recovery = false;
    securityMessage(null);
    // Turned on when codes came by text message: no address to send to, so
    // the current step is answered with a recovery code.
    const current = o.emailHint
        ? `We will email a code to ${o.emailHint}`
        : 'There is no email address for codes yet, so use a recovery code below';
    $('#tf-start-intro').textContent = {
        email: o.enabled
            ? 'Enter the new email address and your password. We will email a code to the new address, and first to your current one unless you have just used it.'
            : 'Enter the email address to send codes to, and your password. We will email a code to that address to make sure it is yours.',
        disable: `Enter your password. ${current} to confirm it is you.`,
        recovery: `Enter your password. ${current}. Your current recovery codes then stop working.`
    }[action];
    $('#tf-email-label').hidden = action !== 'email';
    $('#tf-email').value = '';
    $('#tf-password').value = '';
    // Mailbox out of reach: the current address is answered with a recovery code, and nothing is sent to it.
    $('#tf-start-recovery').hidden = !o.enabled;
    securityStep('#tf-start');
    setTimeout(() => (action === 'email' ? $('#tf-email') : $('#tf-password')).focus(), 0);
}

async function submitSecurityStart(method) {
    const flow = security.flow;
    if (!flow) return;
    const body = { action: flow.action, currentPassword: $('#tf-password').value, method };
    if (flow.action === 'email') {
        body.email = $('#tf-email').value.trim();
        if (!body.email.includes('@')) return securityMessage('error', 'Enter the email address to send codes to.');
        // Shown in full on the code step, where a typo is easiest to spot.
        flow.address = body.email;
    }
    if (!body.currentPassword) return securityMessage('error', 'Enter your current password.');
    const button = method === 'recovery' ? $('#tf-start-recovery') : $('#tf-start-submit');
    const label = button.textContent;
    $('#tf-start-submit').disabled = $('#tf-start-recovery').disabled = true;
    button.textContent = method === 'recovery' ? 'Checking…' : 'Sending…';
    securityMessage(null);
    try {
        const d = await (await api('/api/users/me/two-factor/start', { method: 'POST', body })).json();
        $('#tf-password').value = '';
        showSecurityCode(d, method === 'recovery');
    } catch (e) {
        securityMessage('error', e.message);
    } finally {
        $('#tf-start-submit').disabled = $('#tf-start-recovery').disabled = false;
        button.textContent = label;
    }
}

/** The code step, for whichever address the server is waiting on. */
function showSecurityCode(d, recovery = false) {
    security.flow = { ...security.flow, ...d };
    security.recovery = recovery && !!d.recoveryAllowed;
    $('#tf-code').value = '';
    $('#tf-recovery').value = '';
    securityStep('#tf-verify');
    renderSecurityCode();
    if (d.error) securityMessage('error', d.error.message);
    if (!security.recovery) security.resend.start(d.sent ? d.resendIn : (d.error?.retryAfter || 0));
}

function renderSecurityCode() {
    const d = security.flow || {};
    const recovery = security.recovery;
    $('#tf-code-label').hidden = recovery;
    $('#tf-recovery-label').hidden = !recovery;
    $('#tf-resend').hidden = recovery;
    $('#tf-switch').hidden = !d.recoveryAllowed;
    $('#tf-switch').textContent = recovery ? 'Use an emailed code instead' : 'Use a recovery code instead';
    $('#tf-verify-intro').textContent = recovery
        ? 'Enter one of your recovery codes in place of a code from your current email address.'
        : d.stage === 'current'
            ? `Enter the code we sent to your current address, ${d.emailHint || 'your email'}.`
            : `Enter the code we sent to ${d.address || d.emailHint}. Not there? Check your spam folder, or the address.`;
    setTimeout(() => (recovery ? $('#tf-recovery') : $('#tf-code')).focus(), 0);
}

async function resendSecurityCode() {
    $('#tf-resend').disabled = true;
    securityMessage(null);
    try {
        const d = await (await api('/api/users/me/two-factor/resend', { method: 'POST' })).json();
        security.flow = { ...security.flow, ...d };
        security.resend.start(d.resendIn);
        securityMessage('success', `A new code is on its way to ${d.emailHint}.`);
    } catch (e) {
        securityMessage('error', e.message);
        if (e.code === 'TWO_FACTOR_NO_PENDING_CHANGE') return backToSecurity(false);
        security.resend.start(e.details?.retryAfter || 0);
    }
}

async function submitSecurityCode() {
    const recovery = security.recovery;
    const input = recovery ? $('#tf-recovery') : $('#tf-code');
    const value = input.value.trim();
    if (!value) return securityMessage('error', recovery ? 'Enter a recovery code.' : 'Enter the code from the email.');
    const submit = $('#tf-verify-submit');
    submit.disabled = true;
    submit.textContent = 'Verifying…';
    securityMessage(null);
    try {
        const d = await (await api('/api/users/me/two-factor/confirm', {
            method: 'POST', body: recovery ? { recoveryCode: value } : { code: value }
        })).json();
        if (!d.done) {
            // The current address is proven; now the new address's own code.
            showSecurityCode(d);
            if (!d.error) securityMessage('success', 'Thanks. Now enter the code we sent to your new address.');
            return;
        }
        const wasOn = !!security.overview?.enabled;
        security.flow = null;
        await loadSecurity();
        if (d.recoveryCodes) {
            showRecoveryCodes(d.recoveryCodes);
            securityMessage('success', wasOn ? 'New recovery codes are ready. The old ones no longer work.'
                : 'Two-step verification is on. Signing in will now ask for a code sent to your email.');
        } else {
            securityStep(null);
            securityMessage('success', d.enabled
                ? `Done. Codes now go to ${d.emailHint}.`
                : 'Two-step verification is off. Signing in takes your password only.');
        }
    } catch (e) {
        securityMessage('error', e.message);
        if (e.code === 'TWO_FACTOR_NO_PENDING_CHANGE') backToSecurity(false);
        else input.select();
    } finally {
        submit.disabled = false;
        submit.textContent = 'Verify';
    }
}

function showRecoveryCodes(codes) {
    security.codes = codes;
    $('#tf-code-list').innerHTML = codes.map(c => `<li><code>${esc(c)}</code></li>`).join('');
    securityStep('#tf-codes-step');
}

$('#account-security').addEventListener('click', openSecurity);
$('#security-close').addEventListener('click', closeSecurity);
$('#security-overlay').addEventListener('click', e => {
    if (e.target === $('#security-overlay')) closeSecurity();
});
document.addEventListener('keydown', e => {
    // The password, confirmation and input dialogs open over this one and keep their own keys.
    if (e.key === 'Escape' && !$('#security-overlay').hidden && $('#password-overlay').hidden
        && $('#confirm-overlay').hidden && $('#input-overlay').hidden) closeSecurity();
});
$('#tf-enable').addEventListener('click', () => startSecurityChange('email'));
$('#tf-change').addEventListener('click', () => startSecurityChange('email'));
$('#tf-codes').addEventListener('click', () => startSecurityChange('recovery'));
$('#tf-disable').addEventListener('click', () => startSecurityChange('disable'));
$('#tf-start').addEventListener('submit', e => {
    e.preventDefault();
    submitSecurityStart('email');
});
$('#tf-start-recovery').addEventListener('click', () => submitSecurityStart('recovery'));
$('#tf-start-cancel').addEventListener('click', () => backToSecurity());
$('#tf-verify').addEventListener('submit', e => {
    e.preventDefault();
    submitSecurityCode();
});
$('#tf-verify-cancel').addEventListener('click', () => backToSecurity());
$('#tf-resend').addEventListener('click', resendSecurityCode);
$('#tf-switch').addEventListener('click', () => {
    security.recovery = !security.recovery;
    securityMessage(null);
    renderSecurityCode();
});
$('#tf-codes-copy').addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText((security.codes || []).join('\n'));
        toast('Recovery codes copied');
    } catch {
        // No clipboard access (plain http, an older browser): select them for a manual copy.
        getSelection().selectAllChildren($('#tf-code-list'));
        toast('Codes selected: copy them now');
    }
});
$('#tf-codes-download').addEventListener('click', () => {
    const text = `Recovery codes for ${S.user?.username || 'your account'} (${location.host})\n`
        + 'Each signs you in once in place of an emailed code. Keep them somewhere safe.\n\n'
        + (security.codes || []).join('\n') + '\n';
    saveBlob(new Blob([text], { type: 'text/plain' }), 'recovery-codes.txt');
});
$('#tf-codes-done').addEventListener('click', () => {
    security.codes = null;
    $('#tf-code-list').innerHTML = '';
    securityStep(null);
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
            const r = await (await api('/api/trash/purge', { method: 'POST', body: { id: b.dataset.purge, background: 'auto' } })).json();
            if (r.queued) taskQueued(r.job);
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
        const r = await (await api('/api/trash/purge', { method: 'POST', body: { all: true, background: 'auto' } })).json();
        if (r.queued) taskQueued(r.job);
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

/* ---- Duplicates ---------------------------------------------------------
 *
 * The scan runs as a background task, so it finishes with this page closed.
 * The page reads the finder's progress (GET /api/duplicates/scan) while the
 * task runs, which shows the groups as they are confirmed, and "Stop" cancels
 * the task. POST /api/duplicates/scan -- a slice per request, polled -- is
 * still there for other clients.
 *
 * Deletion goes through the ordinary /api/files/delete route, once per file,
 * exactly as the file list's bulk delete does. That route already moves items
 * to the trash, honours ALLOW_DELETE, forgets the ledger row and writes the
 * audit entry; a second path to the same dangerous operation is the last thing
 * this feature should add.
 */
const dupeState = { groups: [], selected: new Set(), scanning: false, job: null, timer: 0 };

function dupeKept(group) {
    // The copy to keep by default: shallowest path, then oldest, then by name,
    // so the original in place beats the copy in a backup folder.
    return [...group.files].sort((a, b) => {
        const depth = a.path.split('/').length - b.path.split('/').length;
        if (depth) return depth;
        const age = (a.mtime || 0) - (b.mtime || 0);
        if (age) return age;
        return a.path.localeCompare(b.path);
    })[0].path;
}

function renderDuplicates() {
    const wrap = $('#dupe-groups');
    const groups = dupeState.groups;
    $('#dupe-actions').hidden = groups.length === 0;

    if (!groups.length) {
        wrap.innerHTML = dupeState.scanning ? '' : '<p class="muted">No duplicates found.</p>';
        return;
    }

    wrap.innerHTML = groups.map((g, gi) => {
        const keep = dupeKept(g);
        const rows = g.files.map(f => {
            const checked = dupeState.selected.has(f.path) ? ' checked' : '';
            const isKeep = f.path === keep ? '<span class="dupe-keep">suggested keep</span>' : '';
            return `<li class="dupe-file">
                <label>
                    <input type="checkbox" data-dupe="${esc(encodeURIComponent(f.path))}"${checked}>
                    <img class="dupe-thumb" loading="lazy" alt="" src="${esc(appUrl('/api/thumbnail?path=' + encodeURIComponent(f.path)))}">
                    <span class="dupe-meta">
                        <span class="dupe-path">${esc(f.path)}</span>
                        <span class="muted">${fmt(f.bytes)}${f.mtime ? ' · ' + new Date(f.mtime * 1000).toLocaleDateString() : ''} ${isKeep}</span>
                    </span>
                </label>
            </li>`;
        }).join('');
        return `<section class="dupe-group">
            <h3>${g.count} copies · ${fmt(g.bytes)} each · ${fmt(g.reclaimable)} reclaimable</h3>
            <ul>${rows}</ul>
        </section>`;
    }).join('');

    wrap.querySelectorAll('[data-dupe]').forEach(box => {
        box.addEventListener('change', () => {
            const path = decodeURIComponent(box.dataset.dupe);
            box.checked ? dupeState.selected.add(path) : dupeState.selected.delete(path);
            updateDupeSummary();
        });
    });
    updateDupeSummary();
}

function updateDupeSummary() {
    const groups = dupeState.groups;
    let bytes = 0;
    for (const g of groups) for (const f of g.files) if (dupeState.selected.has(f.path)) bytes += g.bytes;
    const total = groups.reduce((n, g) => n + g.reclaimable, 0);
    $('#dupe-summary').innerHTML = groups.length
        ? `<div class="usage-summary">
             <div class="usage-figure"><span class="usage-value">${groups.length}</span><span class="muted">group${groups.length === 1 ? '' : 's'}</span></div>
             <div class="usage-figure"><span class="usage-value">${fmt(total)}</span><span class="muted">reclaimable in total</span></div>
             <div class="usage-figure"><span class="usage-value">${dupeState.selected.size}</span><span class="muted">selected · ${fmt(bytes)}</span></div>
           </div>`
        : '';
    $('#dupe-delete').disabled = dupeState.selected.size === 0;
}

function applyDupeProgress(d) {
    dupeState.groups = d.groups || [];
    // Drop selections for files that are no longer in a group, so the count
    // never claims something that is not on screen.
    const live = new Set(dupeState.groups.flatMap(g => g.files.map(f => f.path)));
    for (const p of [...dupeState.selected]) if (!live.has(p)) dupeState.selected.delete(p);

    const pct = d.toHash ? Math.min(100, Math.floor((d.hashed / d.toHash) * 100)) : (d.done ? 100 : 0);
    $('#dupe-bar').value = pct;
    $('#dupe-status').textContent = d.done
        ? `Scanned ${d.scanned} file${d.scanned === 1 ? '' : 's'}${d.truncated ? ' (stopped at the file limit)' : ''}.`
        : `Comparing ${d.hashed} of ${d.toHash} candidate${d.toHash === 1 ? '' : 's'} from ${d.scanned} file${d.scanned === 1 ? '' : 's'}…`;
}

async function scanDuplicates() {
    if (dupeState.scanning) {
        if (!dupeState.job) return;
        try {
            await api(`/api/jobs/${dupeState.job}/cancel`, { method: 'POST' });
            $('#dupe-status').textContent += ' Stopping…';
        } catch (e) {
            toast(e.message);
        }
        return;
    }
    const path = $('#dupe-path').value.trim() || '/';
    try {
        const job = await queueTask('duplicates', { path });
        dupeState.selected.clear();
        $('#dupe-groups').innerHTML = '';
        followDuplicateScan(job.id);
    } catch (e) {
        $('#dupe-progress').hidden = false;
        $('#dupe-status').textContent = e.message;
    }
}

/**
 * Show a running scan until it ends: the finder's state for progress and the
 * groups found so far, and the task for whether it is still going.
 */
function followDuplicateScan(jobId) {
    dupeState.scanning = true;
    dupeState.job = jobId;
    $('#dupe-scan').textContent = jobId ? 'Stop' : 'Scan';
    $('#dupe-scan').disabled = !jobId;
    $('#dupe-progress').hidden = false;
    clearTimeout(dupeState.timer);
    const step = async () => {
        let job = null;
        try {
            if (jobId) job = (await (await api(`/api/jobs/${jobId}`)).json()).job;
            const d = await (await api('/api/duplicates/scan')).json();
            if (d.started !== false) { applyDupeProgress(d); renderDuplicates(); }
            if (job?.status === 'pending') $('#dupe-status').textContent = 'Waiting to start…';
            const over = jobId ? !['pending', 'processing'].includes(job?.status) : d.done;
            if (!over) { dupeState.timer = setTimeout(step, 1500); return; }
            if (job?.status === 'cancelled') $('#dupe-status').textContent += ' Stopped.';
            if (job?.status === 'failed') $('#dupe-status').textContent = job.error || 'The scan failed.';
        } catch (e) {
            $('#dupe-status').textContent = e.message;
        }
        dupeState.scanning = false;
        dupeState.job = null;
        $('#dupe-scan').textContent = 'Scan';
        $('#dupe-scan').disabled = false;
    };
    step();
}

$('#dupe-scan').addEventListener('click', scanDuplicates);

$('#dupe-select-extras').addEventListener('click', () => {
    dupeState.selected.clear();
    for (const g of dupeState.groups) {
        const keep = dupeKept(g);
        for (const f of g.files) if (f.path !== keep) dupeState.selected.add(f.path);
    }
    renderDuplicates();
});

$('#dupe-clear').addEventListener('click', () => {
    dupeState.selected.clear();
    renderDuplicates();
});

$('#dupe-delete').addEventListener('click', async () => {
    const paths = [...dupeState.selected];
    if (!paths.length) return;

    // Refuse to empty a group. The server cannot enforce this -- these are
    // ordinary delete calls, and the file list can already delete anything --
    // but nothing in this page should be the thing that talks somebody into
    // deleting every copy of a photo.
    const emptied = dupeState.groups.filter(g => g.files.every(f => dupeState.selected.has(f.path)));
    if (emptied.length) {
        toast(`Leave at least one copy in each group (${emptied.length} group${emptied.length === 1 ? ' has' : 's have'} every copy selected).`);
        return;
    }

    let bytes = 0;
    for (const g of dupeState.groups) for (const f of g.files) if (dupeState.selected.has(f.path)) bytes += g.bytes;
    const warning = await trashEnabled() ? 'They can be restored from the trash.' : 'This cannot be undone.';
    if (!await askConfirm('Delete duplicates',
        `Delete ${paths.length} duplicate file${paths.length === 1 ? '' : 's'}, freeing ${fmt(bytes)}? ${warning}`, 'Delete')) return;

    let failed = 0;
    for (const path of paths) {
        try {
            await api('/api/files/delete', { method: 'DELETE', body: { path } });
            dupeState.selected.delete(path);
        } catch {
            failed++;
        }
    }
    // Re-scan rather than patching the list: the groups on screen describe a
    // tree that has just changed underneath them.
    toast(failed ? `${failed} file(s) could not be deleted` : 'Duplicates moved to trash');
    await scanDuplicates();
});

/* ---- Background tasks -----------------------------------------------------
 *
 * Long operations run on the server as tasks, and carry on with this page
 * closed. The page only watches: while anything is queued or running it asks
 * how things stand every couple of seconds, and when nothing is, it stops
 * asking. The Tasks link carries the number still running.
 *
 * Where the server has no worker process it runs tasks itself after answering
 * POST /api/jobs/run, which this page sends whenever something is waiting.
 * That request is fire-and-forget: the server has already answered it before
 * the work begins, or is holding it open while it works; nothing here waits.
 */
const tasks = {
    active: 0,
    runner: '',
    timer: 0,
    polling: false,
    watched: new Set(),
    jobs: [],
    kickedAt: 0,
    onDone: new Map()
};
const canWrite = () => S.role === 'editor' || S.role === 'admin';

/** What depends on who signed in: controls only writers can use, and the task indicator. */
function signedIn() {
    document.querySelectorAll('[data-writer]').forEach(el => el.hidden = !canWrite());
    watchTasks();
}
const TASK_STATUS = { pending: 'Queued', processing: 'Running', completed: 'Completed', failed: 'Failed', cancelled: 'Cancelled' };
/** Store-changing types: the folder on screen is reloaded when one finishes. */
const TASKS_THAT_CHANGE_FILES = new Set(['copy', 'extract', 'archive', 'purge']);

/** Archives to download as soon as they are ready, kept for the life of the tab. */
const autoDownloads = {
    read() { try { return JSON.parse(sessionStorage.getItem('cfh_autodl') || '[]'); } catch { return []; } },
    add(id) { try { sessionStorage.setItem('cfh_autodl', JSON.stringify([...new Set([...this.read(), id])])); } catch {} },
    take(id) {
        const ids = this.read();
        if (!ids.includes(id)) return false;
        try { sessionStorage.setItem('cfh_autodl', JSON.stringify(ids.filter(x => x !== id))); } catch {}
        return true;
    }
};

function kickQueue(force = false) {
    if (!force && tasks.runner !== 'inline') return;
    if (!force && Date.now() - tasks.kickedAt < 5000) return;
    tasks.kickedAt = Date.now();
    api('/api/jobs/run', { method: 'POST' }).catch(() => {});
}

function renderTaskBadge() {
    const label = `${tasks.active} task${tasks.active === 1 ? '' : 's'} queued or running`;
    $('#tasks-badge').textContent = tasks.active ? String(tasks.active) : '';
    $('#tasks-indicator').hidden = !tasks.active;
    $('#tasks-indicator').title = label;
    $('#tasks-indicator').setAttribute('aria-label', label);
}

/** Start watching, or look again now if already watching. */
function watchTasks() {
    clearTimeout(tasks.timer);
    tasks.timer = setTimeout(pollTasks, 0);
}

async function pollTasks() {
    // A look asked for while one is in flight happens straight after it, so a
    // task queued meanwhile is never missed by a poll that had already read
    // "nothing active".
    if (tasks.polling) { tasks.again = true; return; }
    tasks.polling = true;
    tasks.again = false;
    const onPage = !$('#tasks-page').hidden;
    let d;
    try {
        d = await (await api('/api/jobs' + (onPage ? '' : '?active=1'))).json();
    } catch {
        tasks.polling = false;
        if (tasks.active || tasks.again) tasks.timer = setTimeout(pollTasks, 10000);
        return;
    }
    tasks.active = d.active;
    tasks.runner = d.runner;
    const current = new Set(d.jobs.filter(j => j.status === 'pending' || j.status === 'processing').map(j => j.id));
    // A task that was running and is not any more has finished: find out how.
    const finished = [...tasks.watched].filter(id => !current.has(id));
    tasks.watched = current;
    if (onPage) { tasks.jobs = d.jobs; renderTasks(); }
    renderTaskBadge();
    for (const id of finished) {
        const job = d.jobs.find(j => j.id === id)
            || await api(`/api/jobs/${id}`).then(r => r.json()).then(x => x.job).catch(() => null);
        if (job) taskFinished(job);
    }
    // Downloads asked for before a page change, finished while it loaded.
    if (onPage) for (const job of d.jobs) if (job.hasDownload && autoDownloads.take(job.id)) downloadTaskResult(job);

    if (d.jobs.some(j => j.status === 'pending') && !d.jobs.some(j => j.status === 'processing')) kickQueue();
    tasks.polling = false;
    if (d.active || tasks.again) tasks.timer = setTimeout(pollTasks, tasks.again ? 0 : d.runner === 'none' ? 5000 : 1500);
}

function taskFinished(job) {
    const done = tasks.onDone.get(job.id);
    tasks.onDone.delete(job.id);
    if (job.status === 'completed') {
        toast(`Done: ${job.label}`);
        if (job.hasDownload && autoDownloads.take(job.id)) downloadTaskResult(job);
    } else if (job.status === 'failed') {
        toast(`Failed: ${job.label}${job.error ? ' — ' + job.error : ''}`);
    }
    if (TASKS_THAT_CHANGE_FILES.has(job.type) && !$('#files-page').hidden) loadFiles();
    if (done) done(job);
}

function downloadTaskResult(job) {
    clickDownload(appUrl(`/api/jobs/${job.id}/download`), job.result?.name || 'download.zip');
}

/** A task the server queued, from /api/jobs or from a route that chose to queue. */
function taskQueued(job, onDone = null) {
    if (onDone) tasks.onDone.set(job.id, onDone);
    tasks.watched.add(job.id);
    tasks.active++;
    renderTaskBadge();
    kickQueue(true);
    watchTasks();
}

async function queueTask(type, params, onDone = null) {
    const d = await (await api('/api/jobs', { method: 'POST', body: { type, params } })).json();
    taskQueued(d.job, onDone);
    return d.job;
}

function taskProgress(j) {
    const p = j.progress;
    if (j.status === 'pending') return 'Waiting to start';
    if (!p.total) return j.status === 'processing' ? 'Starting…' : '';
    const amount = p.unit === 'bytes' ? `${fmt(p.done)} of ${fmt(p.total)}` : `${p.done} of ${p.total}`;
    return `${amount}${p.percent !== null ? ` · ${p.percent}%` : ''}`;
}

function taskResult(j) {
    const r = j.result || {};
    const failed = (r.failed || []).map(f => `<li>${esc(f.path)}: ${esc(f.message)}</li>`).join('');
    const failures = failed ? `<ul class="task-failures">${failed}</ul>` : '';
    switch (j.type) {
        case 'copy': return r.copied !== undefined ? `<p>${r.copied} of ${r.of} copied into ${esc(r.destination)}</p>${failures}` : failures;
        case 'archive': return r.name ? `<p>${esc(r.path || r.name)} · ${fmt(r.bytes)} · ${r.files} file${r.files === 1 ? '' : 's'}</p>` : '';
        case 'extract': return r.path ? `<p>Extracted ${r.files} file${r.files === 1 ? '' : 's'} into ${esc(r.path)}${r.skipped ? ` · ${r.skipped} link${r.skipped === 1 ? '' : 's'} skipped` : ''}</p>` : '';
        case 'purge': return r.files !== undefined ? `<p>${r.files} file${r.files === 1 ? '' : 's'} deleted</p>` : '';
        case 'thumbnails': return r.made !== undefined ? `<p>${r.made} made · ${r.existing} already there${r.skipped ? ` · ${r.skipped} skipped` : ''}</p>` : '';
        case 'duplicates': return r.groups !== undefined
            ? `<p>${r.groups} group${r.groups === 1 ? '' : 's'} of duplicates · ${fmt(r.reclaimable)} reclaimable · <a href="${esc(appUrl('/duplicates'))}">Review</a></p>` : '';
        case 'checksum': return r.files ? `<pre class="task-checksums">${r.files.map(f => `${esc(f.hash)}  ${esc(f.path)}`).join('\n')}</pre>` : '';
        default: return '';
    }
}

function renderTasks() {
    const box = $('#tasks-list');
    $('#tasks-note').textContent = tasks.runner === 'none' && tasks.jobs.some(j => j.status === 'pending')
        ? 'No background worker is running, so queued tasks are waiting. An administrator can start one with: php tools/worker.php'
        : 'Large copies, archives, extraction and deletions run here, on the server. They carry on if you close this page.';
    $('#tasks-clear').hidden = !tasks.jobs.some(j => j.canRemove);
    if (!tasks.jobs.length) {
        box.innerHTML = '<p class="muted">No background tasks.</p>';
        return;
    }
    box.innerHTML = tasks.jobs.map(j => {
        const when = [
            `queued ${new Date(j.createdAt).toLocaleString()}`,
            j.startedAt ? `started ${new Date(j.startedAt).toLocaleTimeString()}` : '',
            j.finishedAt ? `finished ${new Date(j.finishedAt).toLocaleTimeString()}` : ''
        ].filter(Boolean).join(' · ');
        const running = j.status === 'processing' || j.status === 'pending';
        // No bar while queued: an indeterminate one would say it is busy.
        const bar = j.status === 'processing' || j.status === 'completed'
            ? `<progress max="100"${j.progress.percent !== null ? ` value="${j.progress.percent}"` : ''}></progress>` : '';
        return `<article class="task task-${esc(j.status)}" data-task="${esc(j.id)}">
            <div class="task-head">
                <strong class="task-label">${esc(j.label)}</strong>
                <span class="task-status">${esc(j.cancelRequested && running ? 'Stopping…' : TASK_STATUS[j.status] || j.status)}</span>
            </div>
            ${j.target ? `<div class="muted">in ${esc(j.target)}</div>` : ''}
            ${bar}
            <div class="task-progress muted">${esc(taskProgress(j))}${j.currentItem ? ` · <span class="task-current">${esc(j.currentItem)}</span>` : ''}</div>
            ${j.error ? `<p class="error">${esc(j.error)}</p>` : ''}
            ${taskResult(j)}
            <div class="muted">${esc(when)}${j.attempts > 1 ? ` · attempt ${j.attempts}` : ''}</div>
            <div class="actions">
                ${j.hasDownload ? `<button data-task-download="${esc(j.id)}" class="primary-button">Download</button>` : ''}
                ${j.canCancel ? `<button data-task-cancel="${esc(j.id)}">Cancel</button>` : ''}
                ${j.canRetry ? `<button data-task-retry="${esc(j.id)}">Retry</button>` : ''}
                ${j.canRemove ? `<button data-task-remove="${esc(j.id)}">Remove</button>` : ''}
            </div>
        </article>`;
    }).join('');
}

$('#tasks-list').addEventListener('click', async e => {
    const b = e.target.closest('[data-task-download],[data-task-cancel],[data-task-retry],[data-task-remove]');
    if (!b) return;
    const d = b.dataset;
    try {
        if (d.taskDownload) {
            const job = tasks.jobs.find(j => j.id === d.taskDownload);
            if (job) await saveFromServer(`/api/jobs/${job.id}/download`, job.result?.name || 'download.zip');
            return;
        }
        if (d.taskCancel) {
            const r = await (await api(`/api/jobs/${d.taskCancel}/cancel`, { method: 'POST' })).json();
            toast(r.status === 'cancelled' ? 'Cancelled' : 'Stopping…');
        } else if (d.taskRetry) {
            const r = await (await api(`/api/jobs/${d.taskRetry}/retry`, { method: 'POST' })).json();
            taskQueued(r.job);
        } else if (d.taskRemove) {
            await api(`/api/jobs/${d.taskRemove}`, { method: 'DELETE' });
        }
    } catch (x) {
        toast(x.message);
    }
    watchTasks();
});

$('#tasks-clear').addEventListener('click', async () => {
    try {
        const r = await (await api('/api/jobs/clear', { method: 'POST' })).json();
        toast(`${r.removed} task${r.removed === 1 ? '' : 's'} removed`);
    } catch (x) {
        toast(x.message);
    }
    watchTasks();
});

/** Compress items into a ZIP saved beside them. */
async function compress(paths) {
    if (!paths.length) return;
    const suggested = paths.length === 1 ? paths[0].split('/').pop().replace(/\.[^./]+$/, '') || 'Archive' : 'Archive';
    const name = await askInput('Compress to ZIP', 'Archive name', `${suggested}.zip`);
    if (!name) return;
    try {
        const job = await queueTask('archive', { paths, mode: 'save', name });
        toast(`Queued: ${job.label}`);
    } catch (x) {
        toast(x.message);
    }
}

async function extract(path) {
    try {
        const job = await queueTask('extract', { path });
        toast(`Queued: ${job.label}`);
    } catch (x) {
        toast(x.message);
    }
}

async function checksum(paths) {
    try {
        const job = await queueTask('checksum', { paths, algorithm: 'sha256' }, j => {
            if (j.status === 'completed' && j.result?.files?.length === 1) {
                toast(`SHA-256 ${j.result.files[0].hash}`);
            }
        });
        toast(`Queued: ${job.label} — the result appears under Tasks`);
    } catch (x) {
        toast(x.message);
    }
}

async function makeThumbnails(path) {
    try {
        const job = await queueTask('thumbnails', { path });
        toast(`Queued: ${job.label}`);
    } catch (x) {
        toast(x.message);
    }
}

/** A ZIP of items, built by the server and downloaded once it is ready. */
async function downloadAsArchive(paths) {
    try {
        const job = await queueTask('archive', { paths, mode: 'download' });
        autoDownloads.add(job.id);
        toast('Preparing the archive in the background; it downloads when ready (see Tasks)');
    } catch (x) {
        toast(x.message);
    }
}

async function route() {
    const p = window.CLOUDHUB_ROUTE || new URLSearchParams(location.search).get('route') || '/';
    ['files', 'servers', 'browse', 'users', 'trash', 'storage', 'duplicates', 'tasks'].forEach(x => $(`#${x}-page`).hidden = true);
    document.querySelectorAll('nav a').forEach(a => a.classList.toggle('active', (a.dataset.route || '/') === p));
    if (p === '/trash') {
        $('#trash-page').hidden = false;
        await loadTrash();
    } else if (p === '/storage') {
        $('#storage-page').hidden = false;
        await loadStorage();
    } else if (p === '/duplicates') {
        $('#duplicates-page').hidden = false;
        // Show whatever the last scan found without starting a new one: a scan
        // reads files, and opening a tab should not. A scan still running --
        // this account's task, or anyone's -- is followed until it ends.
        try {
            const d = await (await api('/api/duplicates/scan')).json();
            if (d.started !== false) { applyDupeProgress(d); renderDuplicates(); }
            const mine = (await (await api('/api/jobs?active=1')).json()).jobs.find(j => j.type === 'duplicates');
            if (mine || (d.started !== false && !d.done)) followDuplicateScan(mine?.id || null);
        } catch { /* nothing scanned yet */ }
    } else if (p === '/tasks') {
        $('#tasks-page').hidden = false;
        await pollTasks();
    } else if (p === '/users') {
        $('#users-page').hidden = false;
        await users();
    } else if (p === '/servers') {
        $('#servers-page').hidden = false;
        await servers();
    } else if (p === '/browse') {
        $('#browse-page').hidden = false;
        try {
            const a = await (await api('/api/servers/active')).json();
            $('#active-servers').innerHTML = a.map(s => `<div class="server">${esc(s.name)} · ${esc(String(s.type))}</div>`).join('');
        } catch (e) {
            $('#active-servers').innerHTML = `<p class="error">${esc(e.message)}</p>`;
        }
    } else {
        $('#files-page').hidden = false;
        await loadFiles();
    }
}

/**
 * Open the page the URL names, reporting a failure as what it is.
 *
 * The two callers used to share a catch with the sign-in itself, so a server
 * error opening the first folder put the sign-in form in front of somebody who
 * was signed in -- or, straight after signing in, wrote the error into a form
 * that had just been hidden.
 */
async function openRoute() {
    try {
        await route();
    } catch (e) {
        toast(e.message);
    }
}

(async () => {
    let d;
    try {
        const r = await fetch(appUrl('/api/auth/status'), { credentials: 'same-origin' });
        d = await r.json();
    } catch {
        d = null;
    }
    // Reloaded on the code step: the password was right and the code still
    // works, so carry on there rather than asking for the password again.
    if (!d?.authenticated && d?.twoFactor) {
        S.csrf = d.csrfToken || '';
        showTwoFactorStep(d.twoFactor);
        return;
    }
    if (!d?.authenticated) {
        $('#login').style.display = 'flex';
        return;
    }
    S.csrf = d.csrfToken || '';
    S.role = d.user?.role || 'viewer';
    S.user = d.user || null;
    // Convenience only: /api/users is administrator-gated server-side.
    $('#nav-users').hidden = S.role !== 'admin';
    $('#nav-storage').hidden = S.role !== 'admin';
    signedIn();
    $('#login').style.display = 'none';
    await openRoute();
})();

