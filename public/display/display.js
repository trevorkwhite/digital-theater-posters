/*
 * Digital Theater Posters: display loop.
 *
 * Plain JS, no build step, no dependencies. Runs for weeks in Edge kiosk mode,
 * so it is defensive: it never shows a broken image, never blanks the screen
 * on backend errors, backs off exponentially, and reloads itself only when the
 * server is reachable.
 */
(function () {
    'use strict';

    var cfg = window.DTP_CONFIG || {};
    var endpoints = cfg.endpoints || {};

    var ROTATE_MS = Math.max(3000, (cfg.rotate_seconds || 30) * 1000);
    var TRANSITION = cfg.transition || 'crossfade';
    var TRANSITION_MS = Math.max(0, cfg.transition_ms == null ? 1500 : cfg.transition_ms);
    var POLL_MS = Math.max(250, cfg.state_poll_ms || 1000);
    var SHOW_TITLE = !!cfg.show_title;
    var MAX_UPTIME_MS = Math.max(1, cfg.max_uptime_hours || 24) * 3600 * 1000;
    var BOOT_VERSION = cfg.app_version || null;

    var REQUEST_TIMEOUT_MS = 10000;
    var IMAGE_TIMEOUT_MS = 30000;
    var HISTORY_SIZE = 25;          // posters kept for offline fallback
    var EXCLUDE_SIZE = 30;          // recent IDs sent as exclude[]
    var BACKOFF_BASE_MS = 2000;
    var BACKOFF_MAX_MS = 5 * 60 * 1000;
    var POLL_BACKOFF_MAX_MS = 30000;

    var bootedAt = Date.now();

    var stage = document.getElementById('stage');
    var dim = document.getElementById('dim');
    var titleEl = document.getElementById('title');
    var layers = Array.prototype.slice.call(stage.querySelectorAll('img.layer'));
    var front = 0;                  // index into layers of the visible layer
    var hasPoster = false;

    var current = null;             // poster currently shown {id, title, year, ...}
    var history = [];               // recently shown posters (most recent last)
    var recentIds = [];             // recently shown IDs for exclude[]

    var rotateTimer = null;
    var advancing = false;
    var failures = 0;
    var lastAdvanceAt = Date.now();

    var state = { mode: 'normal', brightness: 100, version: null };
    var stateKey = null;
    var paused = false;             // true while mode === black
    var pollFailures = 0;
    var blackPrepTimer = null;

    var reloadPending = false;

    /* ------------------------------------------------------------------ utils */

    function log() {
        if (window.console && console.log) {
            try { console.log.apply(console, ['[posters]'].concat([].slice.call(arguments))); } catch (e) { /* ignore */ }
        }
    }

    function wait(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function backoff(n, max) {
        return Math.min(max, BACKOFF_BASE_MS * Math.pow(2, Math.max(0, n - 1)));
    }

    function fetchJson(url, timeoutMs) {
        var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, timeoutMs || REQUEST_TIMEOUT_MS);

        return fetch(url, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (res) {
            if (!res.ok) {
                var err = new Error('HTTP ' + res.status);
                err.status = res.status;
                // Drain the body so the connection is released.
                return res.text().then(function () { throw err; }, function () { throw err; });
            }
            return res.json();
        }).finally(function () {
            clearTimeout(timer);
        });
    }

    /** Resolve with a fully loaded + decoded <img>, or reject. Never yields a broken image. */
    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            var settled = false;
            var timer = setTimeout(function () { fail(new Error('image timeout')); }, IMAGE_TIMEOUT_MS);

            function cleanup() {
                clearTimeout(timer);
                img.onload = null;
                img.onerror = null;
            }

            function fail(err) {
                if (settled) { return; }
                settled = true;
                cleanup();
                img.removeAttribute('src');
                reject(err);
            }

            function ok() {
                if (settled) { return; }
                if (!img.naturalWidth || !img.naturalHeight) { fail(new Error('empty image')); return; }
                settled = true;
                cleanup();
                resolve(img);
            }

            img.decoding = 'async';
            img.alt = '';
            img.draggable = false;
            img.onerror = function () { fail(new Error('image error')); };
            img.onload = function () {
                if (typeof img.decode === 'function') {
                    // decode() can reject spuriously; onload already proved the bytes are fine.
                    img.decode().then(ok, ok);
                } else {
                    ok();
                }
            };
            img.src = url;
        });
    }

    function remember(poster) {
        history = history.filter(function (p) { return p.id !== poster.id; });
        history.push(poster);
        if (history.length > HISTORY_SIZE) { history.shift(); }

        recentIds = recentIds.filter(function (id) { return id !== poster.id; });
        recentIds.push(poster.id);
        if (recentIds.length > EXCLUDE_SIZE) { recentIds.shift(); }
    }

    function nextUrl() {
        var url = new URL(endpoints.next || '/api/posters/next', window.location.href);
        recentIds.forEach(function (id) { url.searchParams.append('exclude[]', id); });
        return url.toString();
    }

    /* ------------------------------------------------------------ transitions */

    function setLayer(el, opacity, ms, z) {
        el.style.transition = ms > 0 ? 'opacity ' + ms + 'ms ease-in-out' : 'none';
        if (z != null) { el.style.zIndex = String(z); }
        el.style.opacity = String(opacity);
    }

    /** Put the preloaded <img> into the back layer and transition it in. */
    function show(img, poster, mode) {
        var backIndex = 1 - front;
        var oldBack = layers[backIndex];
        var oldFront = layers[front];

        img.className = 'layer';
        img.setAttribute('aria-hidden', 'true');
        setLayer(img, 0, 0, 2);
        // Replace (not append) so the DOM never grows; the old node is garbage collected.
        stage.replaceChild(img, oldBack);
        layers[backIndex] = img;
        oldFront.style.zIndex = '1';

        var transition = hasPoster ? (mode || TRANSITION) : 'cut';
        var ms = TRANSITION_MS;

        // Force a style flush so the transition starts from opacity 0.
        void img.offsetWidth;

        var done;
        if (transition === 'cut' || ms === 0) {
            setLayer(img, 1, 0);
            setLayer(oldFront, 0, 0);
            done = Promise.resolve();
        } else if (transition === 'fade') {
            var half = Math.round(ms / 2);
            setLayer(oldFront, 0, half);
            done = wait(half).then(function () {
                void img.offsetWidth;
                setLayer(img, 1, half);
                return wait(half);
            });
        } else {
            // crossfade: new poster (opaque background) fades in over the old one.
            setLayer(img, 1, ms);
            done = wait(ms).then(function () { setLayer(oldFront, 0, 0); });
        }

        front = backIndex;
        hasPoster = true;
        current = poster;
        remember(poster);
        updateTitle(poster);

        return done.then(function () {
            // Release the old image's decoded bitmap; it is hidden now.
            if (layers[1 - front] === oldFront) {
                oldFront.removeAttribute('src');
            }
        });
    }

    function updateTitle(poster) {
        if (!SHOW_TITLE || !titleEl) { return; }

        titleEl.hidden = false;
        titleEl.classList.remove('visible');

        setTimeout(function () {
            while (titleEl.firstChild) { titleEl.removeChild(titleEl.firstChild); }
            if (!poster || !poster.title) { return; }

            titleEl.appendChild(document.createTextNode(poster.title));

            var meta = [];
            if (poster.year) { meta.push(String(poster.year)); }
            if (poster.official_rating) { meta.push(String(poster.official_rating)); }
            if (poster.runtime_minutes) { meta.push(poster.runtime_minutes + ' min'); }
            if (meta.length) {
                var span = document.createElement('span');
                span.className = 'meta';
                span.textContent = meta.join('  ·  ');
                titleEl.appendChild(span);
            }
            titleEl.classList.add('visible');
        }, Math.min(800, TRANSITION_MS));
    }

    /* --------------------------------------------------------------- rotation */

    function schedule(ms) {
        clearTimeout(rotateTimer);
        rotateTimer = null;
        if (paused) { return; }
        rotateTimer = setTimeout(function () {
            rotateTimer = null;
            advance();
        }, Math.max(0, ms));
    }

    /** Try to show a recently shown poster (likely in the HTTP cache) when the API fails. */
    function showFromHistory(mode) {
        var candidates = history.filter(function (p) { return !current || p.id !== current.id; });
        if (!candidates.length) { return Promise.resolve(false); }

        var pick = candidates[Math.floor(Math.random() * candidates.length)];
        return loadImage(pick.image_url).then(function (img) {
            return show(img, pick, mode).then(function () { return true; });
        }, function () {
            // Keep it in history: the failure may be transient (server restarting).
            return false;
        });
    }

    function loadNext() {
        return fetchJson(nextUrl()).then(function (poster) {
            if (!poster || poster.id == null || !poster.image_url) {
                throw new Error('bad poster payload');
            }
            return loadImage(poster.image_url).then(function (img) {
                return { poster: poster, img: img };
            });
        });
    }

    /**
     * Show the next poster. opts.mode overrides the transition; opts.reschedule=false
     * does a one-off swap (used while blacked out).
     */
    function advance(opts) {
        opts = opts || {};
        var reschedule = opts.reschedule !== false;

        if (advancing) { return Promise.resolve(); }
        if (paused && reschedule) { return Promise.resolve(); }

        if (reloadPending && state.mode === 'normal' && reschedule) {
            return safeReload('scheduled');
        }

        advancing = true;
        lastAdvanceAt = Date.now();

        return loadNext().then(function (next) {
            failures = 0;
            if (current && next.poster.id === current.id && hasPoster) {
                next.img.removeAttribute('src');
                return true; // single-poster library: nothing to do
            }
            return show(next.img, next.poster, opts.mode).then(function () { return true; });
        }).catch(function (err) {
            failures++;
            log('next poster failed (' + failures + '):', err && err.message);
            return showFromHistory(opts.mode);
        }).then(function (shown) {
            advancing = false;
            if (!reschedule) {
                // A one-off swap finished; if we were un-paused meanwhile, resume rotating.
                if (!paused && !rotateTimer) { schedule(ROTATE_MS); }
                return;
            }
            // Normal cadence when something was shown; otherwise back off (keeping the current poster up).
            schedule(failures === 0 || shown ? ROTATE_MS : backoff(failures, BACKOFF_MAX_MS));
        }, function (err) {
            advancing = false;
            log('advance error:', err && err.message);
            if (reschedule) { schedule(backoff(Math.max(1, failures), BACKOFF_MAX_MS)); }
        });
    }

    /* ----------------------------------------------------------- display state */

    function applyState(s) {
        if (!s || typeof s.mode !== 'string') { return; }

        if (BOOT_VERSION && s.app_version && s.app_version !== BOOT_VERSION && !reloadPending) {
            log('new app version', s.app_version, '- reloading at the next poster change');
            reloadPending = true;
        }

        var brightness = s.mode === 'black' ? 0 : clamp(+s.brightness, 0, 100);
        var key = s.version + ':' + s.mode + ':' + brightness;
        if (key === stateKey) { return; }

        var wasBlack = state.mode === 'black';
        var first = stateKey === null;
        stateKey = key;
        state = { mode: s.mode, brightness: brightness, version: s.version };

        var fade = Math.max(0, +s.fade_ms || 0);
        dim.style.transitionDuration = fade + 'ms';
        dim.style.opacity = String(1 - brightness / 100);

        clearTimeout(blackPrepTimer);
        blackPrepTimer = null;

        if (s.mode === 'black') {
            paused = true;
            clearTimeout(rotateTimer);
            rotateTimer = null;
            // Once fully black, quietly swap in a fresh poster so the screen
            // comes back to something new when the movie ends.
            blackPrepTimer = setTimeout(function () {
                if (paused) { advance({ mode: 'cut', reschedule: false }); }
            }, fade + 1000);
        } else {
            paused = false;
            if ((wasBlack || first) && !rotateTimer && !advancing) {
                schedule(hasPoster ? ROTATE_MS : 0);
            }
        }
    }

    function clamp(v, min, max) {
        if (isNaN(v)) { return max; }
        return Math.min(max, Math.max(min, v));
    }

    function pollState() {
        fetchJson(endpoints.state || '/api/display/state', 5000).then(function (s) {
            pollFailures = 0;
            applyState(s);
        }, function (err) {
            pollFailures++;
            if (pollFailures === 1 || pollFailures % 30 === 0) {
                log('state poll failed (' + pollFailures + '):', err && err.message);
            }
            // Never got a state at all (server down at boot): show posters at full brightness.
            if (stateKey === null && pollFailures >= 3) {
                applyState({ mode: 'normal', brightness: 100, fade_ms: 1000, version: 'offline' });
            }
        }).then(function () {
            setTimeout(pollState, pollFailures ? Math.min(POLL_BACKOFF_MAX_MS, POLL_MS * Math.pow(2, pollFailures)) : POLL_MS);
        });
    }

    /* ------------------------------------------------------------ self-healing */

    var reloading = false;

    /** Reload only if the server answers; an error page would be worse than a stale poster. */
    function safeReload(reason) {
        if (reloading) { return Promise.resolve(); }
        reloading = true;

        return fetch(endpoints.health || '/up', { cache: 'no-store' }).then(function (res) {
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            log('reloading:', reason);
            window.location.reload();
        }).catch(function () {
            reloading = false;
            schedule(ROTATE_MS); // try again later, keep showing posters
        });
    }

    // Watchdog: uptime limit and stuck-loop recovery.
    setInterval(function () {
        if (!reloadPending && Date.now() - bootedAt > MAX_UPTIME_MS) {
            reloadPending = true;
        }
        if (!paused && !advancing && !rotateTimer) {
            schedule(0);
        }
        if (!paused && Date.now() - lastAdvanceAt > ROTATE_MS + BACKOFF_MAX_MS + 60000) {
            // Something hung (e.g. a promise never settled). Reset and carry on.
            advancing = false;
            schedule(0);
        }
    }, 60000);

    // Reload after repeated fatal JS errors (with a cap to avoid reload loops).
    var errorTimes = [];
    function onFatal(e) {
        var now = Date.now();
        errorTimes = errorTimes.filter(function (t) { return now - t < 10 * 60 * 1000; });
        errorTimes.push(now);
        log('error:', e && (e.message || (e.reason && e.reason.message) || e.reason));

        if (errorTimes.length < 5) { return; }

        var store = null;
        try { store = window.sessionStorage; } catch (x) { /* ignore */ }
        var recent = [];
        try { recent = JSON.parse((store && store.getItem('dtp-error-reloads')) || '[]'); } catch (x) { recent = []; }
        recent = recent.filter(function (t) { return now - t < 3600 * 1000; });
        if (recent.length >= 3) { return; } // already reloaded a lot this hour; keep running
        recent.push(now);
        try { if (store) { store.setItem('dtp-error-reloads', JSON.stringify(recent)); } } catch (x) { /* ignore */ }

        errorTimes = [];
        safeReload('repeated errors');
    }
    window.addEventListener('error', onFatal);
    window.addEventListener('unhandledrejection', onFatal);

    // Keep the screen awake where supported (secure contexts only, e.g. https or localhost).
    var wakeLock = null;
    function requestWakeLock() {
        if (!navigator.wakeLock || document.visibilityState !== 'visible') { return; }
        navigator.wakeLock.request('screen').then(function (lock) {
            wakeLock = lock;
        }, function () { /* not allowed here; Windows power settings handle it */ });
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !wakeLock) { requestWakeLock(); }
    });

    // No context menu, no drag, no keyboard scrolling.
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    document.addEventListener('dragstart', function (e) { e.preventDefault(); });
    document.addEventListener('wheel', function (e) { e.preventDefault(); }, { passive: false });

    /* ------------------------------------------------------------------ boot */

    function boot() {
        // Apply the state rendered into the page (if any) so a reload during a
        // movie stays black, then start polling and rotating.
        if (cfg.initial_state) {
            applyState(cfg.initial_state);
        }
        requestWakeLock();
        pollState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
