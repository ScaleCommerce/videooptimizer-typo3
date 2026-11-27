// Source handling of the native player: hls.js (loaded on demand), native HLS (Safari) or MP4.
// Plain ES module without dependencies on the page, so it can be unit-tested with node.

let loadHlsModule = () => import('./vendor/hls.light-1.7.4.min.js');
let hlsPromise = null;

/** Replaces the hls.js loader (tests) and forgets a loaded copy. */
export function setHlsLoader(loader) {
    loadHlsModule = loader;
    hlsPromise = null;
}

// hls.js is loaded once per page, and only when an HLS source is attached in a browser with
// Media Source Extensions.
function loadHls() {
    hlsPromise ??= Promise.resolve()
        .then(() => loadHlsModule())
        .then((module) => module.default)
        .catch((error) => {
            // The player falls back to native HLS / MP4; the cause is worth a line in the console
            // (e.g. a web server sending a wrong MIME type for the module).
            console.warn('VideoOptimizer: hls.js could not be loaded, falling back to MP4.', error);
            // A transient network error must not disable hls.js for the rest of the page.
            hlsPromise = null;
            return null;
        });
    return hlsPromise;
}

/**
 * Attaches a source to a <video>: hls.js (MSE), native HLS (Safari) or MP4. A fatal hls.js error or
 * a failing native source falls back to the MP4 once, continuing at the same position and keeping
 * the playing state; when nothing can play, `onUnplayable` is called so the caller can show the
 * poster and a notice instead of a black player.
 *
 * `autoplay` or `shouldPlay()` (read when the source is ready) start playback. `deferLoad` fetches
 * only the playlists until playback starts. The hls.js instance is kept on the element
 * (`video.__voHls`) for destroyVideoSource().
 */
export async function attachVideoSource(
    video,
    { hls = null, mp4 = null },
    { autoplay = false, deferLoad = false, shouldPlay = () => false, onUnplayable = () => {} } = {}
) {
    const play = () => {
        if (autoplay || shouldPlay()) {
            video.play().catch(() => {});
        }
    };
    const failOnError = () => video.addEventListener('error', () => onUnplayable(), { once: true });
    // `wasPlaying` and `resumeAt` are read before the failed source is torn down: switching the
    // source pauses the element, and nothing else would start it again.
    const useMp4 = (wasPlaying = false, resumeAt = 0) => {
        if (!mp4) {
            onUnplayable();
            return;
        }
        failOnError();
        video.src = mp4;
        if (resumeAt > 0) {
            video.addEventListener(
                'loadedmetadata',
                () => {
                    video.currentTime = resumeAt;
                },
                { once: true }
            );
        }
        if (wasPlaying) {
            video.play().catch(() => {});
        } else {
            play();
        }
    };

    // Set by destroyVideoSource(); the element may be torn down while hls.js is still loading.
    video.__voDestroyed = false;

    // hls.js needs Media Source Extensions. Without them (e.g. older iOS) it is not loaded at all and
    // native HLS / MP4 are attached synchronously - still within the click that started playback,
    // which iOS requires for playback with sound.
    const mse = !!(window.ManagedMediaSource || window.MediaSource);
    if (hls && mse) {
        const Hls = await loadHls();
        if (video.__voDestroyed) {
            return;
        }
        if (Hls && Hls.isSupported()) {
            try {
                attachHlsJs(Hls, video, hls, deferLoad, play, useMp4);
            } catch {
                const wasPlaying = !video.paused;
                const resumeAt = video.currentTime || 0;
                destroyVideoSource(video);
                useMp4(wasPlaying, resumeAt);
            }
            return;
        }
    }

    if (hls && video.canPlayType('application/vnd.apple.mpegurl')) {
        video.addEventListener('error', () => useMp4(!video.paused, video.currentTime || 0), { once: true });
        video.src = hls;
        play();
        return;
    }

    useMp4();
}

function attachHlsJs(Hls, video, hls, deferLoad, play, useMp4) {
    // hls.js ignores the video's preload hint and buffers right away; with deferLoad only the
    // playlists are fetched until playback starts. If the visitor already pressed play while hls.js
    // was loading, the "play" event is gone - start loading right away then.
    // capLevelToPlayerSize keeps small players from pulling the 4K rendition. The worker is off so a
    // strict Content-Security-Policy needs no worker-src.
    const playRequested = !video.paused;
    const deferred = deferLoad && !playRequested;
    const player = new Hls({
        autoStartLoad: !deferred,
        capLevelToPlayerSize: true,
        enableWorker: false,
    });
    video.__voHls = player;
    if (deferred) {
        video.addEventListener(
            'play',
            () => {
                if (video.__voHls === player) {
                    player.startLoad();
                }
            },
            { once: true }
        );
    }
    player.on(Hls.Events.ERROR, (event, data) => {
        if (!data.fatal) {
            return;
        }
        const wasPlaying = !video.paused;
        const resumeAt = video.currentTime || 0;
        destroyVideoSource(video);
        useMp4(wasPlaying, resumeAt);
    });
    player.on(Hls.Events.MANIFEST_PARSED, () => {
        play();
        // Attaching the media interrupts a play() requested while hls.js was loading; resume it.
        if (playRequested && video.paused) {
            video.play().catch(() => {});
        }
    });
    player.loadSource(hls);
    player.attachMedia(video);
}

export function destroyVideoSource(video) {
    video.__voDestroyed = true;
    if (video.__voHls) {
        video.__voHls.destroy();
        video.__voHls = null;
    }
}

/**
 * Calls onChange(true|false) when the element enters or leaves the viewport (25 % visible). Returns
 * a function that stops observing. Without IntersectionObserver the element counts as visible.
 */
export function observeVisibility(element, onChange, rootMargin = '0px', threshold = 0.25) {
    if (typeof window.IntersectionObserver !== 'function') {
        onChange(true);
        return () => {};
    }
    const observer = new window.IntersectionObserver(
        (entries) => entries.forEach((entry) => onChange(entry.isIntersecting)),
        { threshold, rootMargin }
    );
    observer.observe(element);
    return () => observer.disconnect();
}

/**
 * Plays a muted, autoplaying video only while it is visible and pauses it when it scrolls out of
 * view. Once the visitor pauses it themselves it is not resumed automatically.
 */
export function autoplayWhileVisible(video, observed = video) {
    let pausedForViewport = false;
    let pausedByUser = false;
    const onPause = () => {
        if (pausedForViewport) {
            pausedForViewport = false;
            return;
        }
        if (!video.ended) {
            pausedByUser = true;
        }
    };
    const onPlay = () => {
        pausedByUser = false;
    };
    video.addEventListener('pause', onPause);
    video.addEventListener('play', onPlay);
    const stop = observeVisibility(observed, (visible) => {
        // A finished non-looping video is not restarted when it scrolls back into view.
        if (visible && video.paused && !pausedByUser && !video.ended) {
            video.play().catch(() => {});
        } else if (!visible && !video.paused) {
            pausedForViewport = true;
            video.pause();
        }
    });
    return () => {
        stop();
        video.removeEventListener('pause', onPause);
        video.removeEventListener('play', onPlay);
    };
}

/**
 * Marks a player wrapper as unavailable: the poster stays, controls are removed and the label is
 * shown (CSS ::after from data-vo-unavailable).
 */
export function markUnavailable(wrapper, video, label) {
    video.controls = false;
    video.removeAttribute('src');
    wrapper.classList.add('vo-native--unavailable');
    wrapper.setAttribute('data-vo-unavailable', label || 'Video unavailable');
}

/**
 * Adds <track> elements for the subtitle tracks and sets crossorigin="anonymous" on the video (the
 * WebVTT files come from the CDN; without it browsers refuse to load them). Must run before the
 * source is attached. Tracks that are already present (rendered by the server) are left alone.
 */
export function applyTracks(video, tracks, doc = document) {
    if (!tracks || tracks.length === 0 || video.querySelector('track')) {
        return;
    }
    video.crossOrigin = 'anonymous';
    for (const track of tracks) {
        const element = doc.createElement('track');
        element.kind = track.kind;
        element.srclang = track.srclang;
        element.label = track.label;
        element.src = track.src;
        if (track.default) {
            element.default = true;
        }
        video.appendChild(element);
    }
}

/**
 * True when the visitor asked for less motion or the browser's data saver is on: background videos
 * must not start by themselves then.
 */
export function motionReduced(win = window) {
    const reduce = win.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    return !!reduce || win.navigator?.connection?.saveData === true;
}

/**
 * Pause/play button for a background video (WCAG 2.2.2). The button follows the video's state
 * (aria-label, title, `is-paused`). Until the source is attached (hls.js may still be loading) a
 * click cannot play anything; it is remembered and honoured once `attached` resolves. Returns
 * `{ shouldPlay, unbind }`: `shouldPlay()` is for attachVideoSource (consumed once).
 */
export function bindPlaybackToggle(video, button, attached, labels) {
    let ready = false;
    let playRequested = false;
    const update = (paused) => {
        const label = paused ? labels.play : labels.pause;
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.classList.toggle('is-paused', paused);
    };
    const onClick = () => {
        if (!ready) {
            playRequested = !playRequested;
            update(!playRequested);
            return;
        }
        // From here on the video's own state is the truth.
        playRequested = false;
        if (video.paused) {
            video.play().catch(() => {});
        } else {
            video.pause();
        }
    };
    const sync = () => update(video.paused);
    button.addEventListener('click', onClick);
    video.addEventListener('play', sync);
    video.addEventListener('pause', sync);
    update(true);
    button.removeAttribute('hidden');
    attached.then(() => {
        ready = true;
    });

    return {
        shouldPlay: () => {
            const requested = playRequested;
            playRequested = false;
            return requested;
        },
        unbind: () => {
            button.removeEventListener('click', onClick);
            video.removeEventListener('play', sync);
            video.removeEventListener('pause', sync);
        },
    };
}
