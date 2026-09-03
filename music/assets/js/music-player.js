/**
 * MusicPlayer — persistent playback core for the music plugin.
 *
 * Lives outside #app-content (see base.html.twig), loaded once, never
 * torn down by engine.js's idiomorph morphing on navigation — this file
 * itself only runs once at initial page load.
 *
 * IMPORTANT: because #app-content gets replaced wholesale on every
 * in-app navigation, this script uses EVENT DELEGATION (listeners bound
 * to `document`, matching data-attributes) rather than binding directly
 * to elements inside page content — direct bindings would silently stop
 * working the moment idiomorph morphs the page they were attached to.
 * Phase 3/4 templates should use these data-attributes on any
 * clickable track/album/playlist element:
 *   data-play-track='{"id":1,"title":"...","artist":"...","artistUrl":"/music/artist/x",
 *                      "cover":"/...","audio":"/music/stream/1","duration":180}'
 *   data-queue-track='{...same shape...}'   → adds to queue without playing
 *
 * Phase 2 built play/pause/seek/volume/like-button UI and the queue
 * drawer shell. Phase 4 (this revision) adds real shuffle (a separate
 * playOrder permutation over state.queue, not just a UI toggle), repeat
 * semantics, and the play-count beacon (fired once per track after a
 * play-enough threshold, POSTed with the CSRF token read from the hidden
 * #mp-csrf-source form — see base.html.twig for why that form exists).
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'music_player_state_v1';

    const els = {
        bar: document.getElementById('mp-player-bar'),
        audio: document.getElementById('mp-audio'),
        cover: document.getElementById('mp-now-cover'),
        title: document.getElementById('mp-now-title'),
        artist: document.getElementById('mp-now-artist'),
        likeBtn: document.getElementById('mp-like-btn'),
        playBtn: document.getElementById('mp-play-btn'),
        prevBtn: document.getElementById('mp-prev-btn'),
        nextBtn: document.getElementById('mp-next-btn'),
        shuffleBtn: document.getElementById('mp-shuffle-btn'),
        repeatBtn: document.getElementById('mp-repeat-btn'),
        seek: document.getElementById('mp-seek'),
        timeCurrent: document.getElementById('mp-time-current'),
        timeDuration: document.getElementById('mp-time-duration'),
        volume: document.getElementById('mp-volume'),
        queueBtn: document.getElementById('mp-queue-btn'),
        queueDrawer: document.getElementById('mp-queue-drawer'),
        queueClose: document.getElementById('mp-queue-close'),
        queueList: document.getElementById('mp-queue-list'),
    };

    if (!els.bar || !els.audio) {
        // Base template not present on this render for some reason —
        // fail quietly rather than throwing on every page.
        return;
    }

    const state = {
        current: null,     // the track object currently loaded
        queue: [],          // tracks in original (insertion) order
        playOrder: [],        // array of indices into `queue` — linear or shuffled
        orderPos: -1,          // position within playOrder; playOrder[orderPos] is the current track's index in queue
        shuffle: false,
        repeat: 'off',        // 'off' | 'all' | 'one'
        isPlaying: false,
        beaconSent: false,   // reset per-track; guards against firing more than once
    };

    /** Fisher-Yates shuffle of [0..n-1], optionally forcing `keepFirst` to stay at position 0. */
    function buildPlayOrder(n, keepFirst) {
        const order = [];
        for (let i = 0; i < n; i++) order.push(i);
        if (!state.shuffle) return order;

        for (let i = order.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [order[i], order[j]] = [order[j], order[i]];
        }
        if (typeof keepFirst === 'number') {
            const pos = order.indexOf(keepFirst);
            if (pos > 0) {
                [order[0], order[pos]] = [order[pos], order[0]];
            }
        }
        return order;
    }

    function currentQueueIndex() {
        return (state.orderPos >= 0 && state.orderPos < state.playOrder.length)
            ? state.playOrder[state.orderPos]
            : -1;
    }

    function formatTime(totalSeconds) {
        totalSeconds = Math.max(0, Math.floor(totalSeconds || 0));
        const m = Math.floor(totalSeconds / 60);
        const s = totalSeconds % 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    function updateNowPlayingUI() {
        const t = state.current;
        if (!t) {
            els.bar.dataset.state = 'empty';
            els.bar.setAttribute('aria-hidden', 'true');
            return;
        }
        els.bar.dataset.state = 'active';
        els.bar.setAttribute('aria-hidden', 'false');
        els.cover.src = t.cover || '';
        els.cover.alt = t.title || '';
        els.title.textContent = t.title || '';
        els.title.href = t.url || '#';
        els.artist.textContent = t.artist || '';
        els.artist.href = t.artistUrl || '#';
        els.likeBtn.dataset.trackId = t.id || '';
        setLikeButtonUI(!!t.liked);
    }

    function setLikeButtonUI(liked) {
        const icon = els.likeBtn.querySelector('i');
        if (!icon) return;
        icon.className = liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart';
        els.likeBtn.classList.toggle('active', liked);
        els.likeBtn.setAttribute('aria-label', liked ? 'Unlike' : 'Like');
    }

    function updatePlayButtonUI() {
        const icon = els.playBtn.querySelector('i');
        if (!icon) return;
        icon.className = state.isPlaying ? 'fa-solid fa-pause' : 'fa-solid fa-play';
        els.playBtn.setAttribute('aria-label', state.isPlaying ? 'Pause' : 'Play');
    }

    function persistState() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                current: state.current,
                queue: state.queue,
                playOrder: state.playOrder,
                orderPos: state.orderPos,
                shuffle: state.shuffle,
                repeat: state.repeat,
                position: els.audio.currentTime || 0,
                volume: els.audio.volume,
            }));
        } catch (e) { /* storage full/unavailable — non-fatal, just skip persistence */ }
    }

    function restoreState() {
        let saved = null;
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            saved = raw ? JSON.parse(raw) : null;
        } catch (e) { return; }
        if (!saved || !saved.current) return;

        state.queue = Array.isArray(saved.queue) ? saved.queue : [];
        state.playOrder = Array.isArray(saved.playOrder) ? saved.playOrder : [];
        state.orderPos = typeof saved.orderPos === 'number' ? saved.orderPos : -1;
        state.shuffle = !!saved.shuffle;
        state.repeat = saved.repeat || 'off';

        // Load but don't autoplay — browsers block unrequested audio
        // playback anyway, and a silently-resumed track on an unrelated
        // page would be a bad surprise. Position/volume are restored so
        // hitting play picks up where the visitor left off.
        loadTrack(saved.current, { autoplay: false, resumeAt: saved.position || 0 });
        if (typeof saved.volume === 'number') {
            els.audio.volume = saved.volume;
            els.volume.value = String(Math.round(saved.volume * 100));
        }
        setShuffleUI();
        setRepeatUI();
    }

    function loadTrack(track, opts) {
        opts = opts || {};
        state.current = track;
        state.beaconSent = false;
        els.audio.src = track.audio;
        updateNowPlayingUI();

        if (opts.resumeAt) {
            const onLoaded = function () {
                els.audio.currentTime = opts.resumeAt;
                els.audio.removeEventListener('loadedmetadata', onLoaded);
            };
            els.audio.addEventListener('loadedmetadata', onLoaded);
        }

        if (opts.autoplay !== false) {
            play();
        } else {
            state.isPlaying = false;
            updatePlayButtonUI();
        }
    }

    function play() {
        if (!state.current) return;
        els.audio.play().then(function () {
            state.isPlaying = true;
            updatePlayButtonUI();
        }).catch(function () {
            // Autoplay blocked or similar — reflect actual (paused) state.
            state.isPlaying = false;
            updatePlayButtonUI();
        });
    }

    function pause() {
        els.audio.pause();
        state.isPlaying = false;
        updatePlayButtonUI();
    }

    function togglePlay() {
        if (!state.current) return;
        state.isPlaying ? pause() : play();
    }

    function setShuffleUI() {
        els.shuffleBtn.classList.toggle('active', state.shuffle);
    }

    function setRepeatUI() {
        els.repeatBtn.dataset.mode = state.repeat;
        els.repeatBtn.classList.toggle('active', state.repeat !== 'off');
    }

    function cycleRepeat() {
        state.repeat = state.repeat === 'off' ? 'all' : (state.repeat === 'all' ? 'one' : 'off');
        setRepeatUI();
        persistState();
    }

    function playNext() {
        if (state.queue.length === 0 || state.orderPos < 0) return;
        const next = state.orderPos + 1;
        if (next < state.playOrder.length) {
            state.orderPos = next;
            loadTrack(state.queue[currentQueueIndex()]);
            renderQueue();
        } else if (state.repeat === 'all' && state.playOrder.length > 0) {
            // Reshuffle for the next lap if shuffle is on, so repeat-all
            // doesn't just replay the exact same shuffled order forever.
            state.playOrder = buildPlayOrder(state.queue.length);
            state.orderPos = 0;
            loadTrack(state.queue[currentQueueIndex()]);
            renderQueue();
        }
    }

    function playPrevious() {
        // Standard media-player convention: restart the current track if
        // more than a few seconds in, otherwise go to the previous track.
        if (els.audio.currentTime > 3 || state.orderPos <= 0) {
            els.audio.currentTime = 0;
            return;
        }
        state.orderPos -= 1;
        loadTrack(state.queue[currentQueueIndex()]);
        renderQueue();
    }

    function onTrackEnded() {
        if (state.repeat === 'one') {
            els.audio.currentTime = 0;
            play();
            return;
        }
        playNext();
    }

    function renderQueue() {
        els.queueList.innerHTML = '';
        if (state.playOrder.length === 0) {
            els.queueList.innerHTML = '<p class="mp-help" style="padding:12px;">Queue is empty.</p>';
            return;
        }
        state.playOrder.forEach(function (queueIdx, orderPos) {
            const t = state.queue[queueIdx];
            const item = document.createElement('div');
            item.className = 'mp-queue-item' + (orderPos === state.orderPos ? ' playing' : '');
            item.innerHTML =
                '<img src="' + (t.cover || '') + '" alt="" width="40" height="40">' +
                '<div class="mp-queue-item__meta">' +
                '<div class="mp-queue-item__title">' + escapeHtml(t.title) + '</div>' +
                '<div class="mp-queue-item__artist">' + escapeHtml(t.artist) + '</div>' +
                '</div>';
            item.addEventListener('click', function () {
                state.orderPos = orderPos;
                loadTrack(t);
                renderQueue();
            });
            els.queueList.appendChild(item);
        });
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    /**
     * Reads core's auto-injected _csrf_token from the hidden
     * #mp-csrf-source form (see base.html.twig) — the play-beacon is a
     * plain fetch() POST, not a real form submission, so it has no
     * natural form of its own to pull the token from otherwise.
     */
    async function csrfToken() {
        const token = await window.behaviour.createCsrfToken();
        return token;
    }

    /**
     * Fires once per track, after a "played enough" threshold (30s or
     * half the track's duration, whichever is smaller — standard
     * streaming-platform convention, avoids counting accidental clicks
     * as real plays). Silently no-ops on failure (e.g. logged-out visitor
     * gets a 403 from the _permission gate) — a missed play count isn't
     * worth surfacing an error over.
     */
    async function maybeSendBeacon() {
        if (state.beaconSent || !state.current || !els.audio.duration) return;

        const threshold = Math.min(30, els.audio.duration / 2);
        if (els.audio.currentTime < threshold) return;

        state.beaconSent = true;
        const body = new FormData();
        body.append('_csrf_token', await csrfToken());

        fetch('/music/track/' + state.current.id + '/play-beacon', {
            method: 'POST',
            body: body,
        }).catch(function () { /* non-fatal, see docblock above */ });
    }

    // ── Public API — Phase 3/4 page templates call into this ──────────
    window.MusicPlayer = {
        loadTrack: loadTrack,
        play: play,
        pause: pause,
        togglePlay: togglePlay,
        playNext: playNext,
        playPrevious: playPrevious,

        /** Replace the whole queue and start playing at `startIndex` (index into `tracks`, not play order). */
        playQueue: function (tracks, startIndex) {
            state.queue = tracks.slice();
            startIndex = startIndex || 0;
            state.playOrder = buildPlayOrder(state.queue.length, startIndex);
            state.orderPos = state.playOrder.indexOf(startIndex);
            loadTrack(state.queue[currentQueueIndex()]);
            renderQueue();
        },

        /** Append a single track to the end of the queue without playing it. */
        enqueue: function (track) {
            const newIndex = state.queue.length;
            state.queue.push(track);
            state.playOrder.push(newIndex);
            if (state.orderPos < 0) state.orderPos = 0;
            renderQueue();
        },

        getState: function () { return Object.assign({}, state); },
    };

    // ── Wire up static controls ─────────────────────────────────────
    els.playBtn.addEventListener('click', togglePlay);
    els.nextBtn.addEventListener('click', playNext);
    els.prevBtn.addEventListener('click', playPrevious);
    els.repeatBtn.addEventListener('click', cycleRepeat);
    els.likeBtn.addEventListener('click', async function () {
        const trackId = els.likeBtn.dataset.trackId;
        if (!trackId) return;

        const body = new FormData();
        body.append('_csrf_token', await csrfToken());

        fetch('/music/like/track/' + trackId, { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    setLikeButtonUI(!!data.liked);
                    if (state.current) state.current.liked = !!data.liked;
                }
            })
            .catch(function () { /* non-fatal — like state just won't update visually */ });
    });
    els.shuffleBtn.addEventListener('click', function () {
        state.shuffle = !state.shuffle;
        setShuffleUI();
        if (state.queue.length > 0) {
            const keepIdx = currentQueueIndex();
            state.playOrder = buildPlayOrder(state.queue.length, keepIdx);
            state.orderPos = state.playOrder.indexOf(keepIdx);
            renderQueue();
        }
        persistState();
    });

    els.audio.addEventListener('timeupdate', function () {
        if (!els.audio.duration) return;
        els.seek.value = String(Math.round((els.audio.currentTime / els.audio.duration) * 100));
        els.timeCurrent.textContent = formatTime(els.audio.currentTime);
        els.timeDuration.textContent = formatTime(els.audio.duration);
        maybeSendBeacon();
    });
    els.seek.addEventListener('input', function () {
        if (!els.audio.duration) return;
        els.audio.currentTime = (Number(els.seek.value) / 100) * els.audio.duration;
    });
    els.volume.addEventListener('input', function () {
        els.audio.volume = Number(els.volume.value) / 100;
    });
    els.audio.addEventListener('ended', onTrackEnded);
    els.audio.addEventListener('play', function () { state.isPlaying = true; updatePlayButtonUI(); });
    els.audio.addEventListener('pause', function () { state.isPlaying = false; updatePlayButtonUI(); });

    els.queueBtn.addEventListener('click', function () {
        els.queueDrawer.classList.toggle('open');
        els.queueDrawer.setAttribute('aria-hidden', els.queueDrawer.classList.contains('open') ? 'false' : 'true');
    });
    els.queueClose.addEventListener('click', function () {
        els.queueDrawer.classList.remove('open');
        els.queueDrawer.setAttribute('aria-hidden', 'true');
    });

    // Event delegation for track elements anywhere in #app-content. Order
    // matters: data-queue-track buttons are nested INSIDE data-play-track
    // rows (see partials/track_row.html.twig) so a click on the queue
    // button would also match .closest('[data-play-track]') on the
    // surrounding row — checking the more specific target first, and
    // returning immediately, is what makes clicking "add to queue"
    // actually add to queue instead of also triggering play.
    document.addEventListener('click', function (e) {
        const queueEl = e.target.closest('[data-queue-track]');
        if (queueEl) {
            e.preventDefault();
            try {
                const track = JSON.parse(queueEl.getAttribute('data-queue-track'));
                window.MusicPlayer.enqueue(track);
            } catch (err) { console.error('Invalid data-queue-track payload', err); }
            return;
        }
        const playEl = e.target.closest('[data-play-track]');
        if (playEl) {
            e.preventDefault();
            try {
                const track = JSON.parse(playEl.getAttribute('data-play-track'));
                window.MusicPlayer.playQueue([track], 0);
            } catch (err) { console.error('Invalid data-play-track payload', err); }
        }
    });

    // Periodically persist so a refresh mid-song doesn't lose position.
    setInterval(persistState, 5000);
    window.addEventListener('beforeunload', persistState);

    restoreState();
})();
