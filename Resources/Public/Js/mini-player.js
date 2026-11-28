// Floating mini player: once the visitor started playback (any play, unmute or seek the plugin did not
// make itself, or a press on a scene button; not autoplay and not play while scrolling) and the player scrolls out of view, it is
// pinned bottom right with CSS only (class on the wrapper, a placeholder keeps the page height). The
// video or iframe is never moved in the DOM (an iframe would reload). No imports (the entry loads this
// with the version stamp); the DOM objects are passed in so the logic can be unit-tested with node.

export const MINI_QUERY = '(min-width: 600px)';
export const HIDDEN_RATIO = 0.01;
const PINNED = 'vo-media--pinned';
const DISMISSED = 'data-vo-mini-dismissed';
const STARTED = 'data-vo-mini-started';

/** Whether the player should be pinned now. `ratio` is the visible share of the placeholder. */
export function shouldPin({ started, dismissed, ratio, wide, modalOpen }) {
    return started && !dismissed && wide && !modalOpen && ratio < HIDDEN_RATIO;
}

/**
 * True when a mini player element would pin a playing video instead of letting it pause when it leaves
 * the viewport: play while scrolling combined with the mini player, once the visitor took over the
 * video (before that the video pauses as usual).
 */
export function keepsPlayingWhenHidden(media, win = window) {
    return (
        !!media &&
        media.hasAttribute('data-vo-mini') &&
        media.hasAttribute(STARTED) &&
        !media.hasAttribute(DISMISSED) &&
        !!win.matchMedia?.(MINI_QUERY).matches
    );
}

/** How long after a press in the player (or on a scene button) a play/unmute/seek counts as the visitor's. */
export const GESTURE_MS = 8000;
/** How long a change the plugin made itself (play(), muted, currentTime) is expected to echo as an event. */
export const EXPECT_MS = 1500;

/**
 * Wraps the video's own `play()` and its `muted` and `currentTime` setters so that every change the
 * plugin makes through them is reported to `expect(type)`. Changes from the browser's control bar do
 * not pass through these (and send no page events either), so the events they cause are the visitor's.
 */
function watchProgrammatic(video, expect) {
    const play = video.play;
    if (typeof play === 'function') {
        video.play = function (...args) {
            expect('play');
            return play.apply(this, args);
        };
    }
    for (const [name, type] of [['muted', 'volumechange'], ['currentTime', 'seeking']]) {
        let descriptor = null;
        for (let object = video; object && !descriptor; object = Object.getPrototypeOf(object)) {
            descriptor = Object.getOwnPropertyDescriptor(object, name) ?? null;
        }
        if (!descriptor) {
            continue;
        }
        if (descriptor.get && descriptor.set) {
            Object.defineProperty(video, name, {
                configurable: true,
                get: () => descriptor.get.call(video),
                set: (value) => {
                    expect(type);
                    descriptor.set.call(video, value);
                },
            });
        } else if (descriptor.writable) {
            let stored = descriptor.value;
            Object.defineProperty(video, name, {
                configurable: true,
                get: () => stored,
                set: (value) => {
                    expect(type);
                    stored = value;
                },
            });
        }
    }
}

/**
 * Wires one mini player wrapper (`.vo-media[data-vo-mini]` with `[data-vo-box]` around the player and a
 * `[data-vo-mini-close]` button). Native: `video`; hosted: `frame` plus its source `hostedSrc` (the
 * visitor's start is detected through the focus the iframe takes, or a scene click that dispatches
 * `vo-start` on the wrapper). A lightbox opening (`vo:lightbox-open` on the document) unpins and stops it.
 */
export function bindMiniPlayer(media, { video = null, frame = null, hostedSrc = '' }, win = window, doc = document) {
    const box = media.querySelector('[data-vo-box]');
    const close = media.querySelector('[data-vo-mini-close]');
    if (!box || (!video && !frame)) {
        return { unbind: () => {} };
    }
    const query = win.matchMedia?.(MINI_QUERY) ?? { matches: false, addEventListener() {}, removeEventListener() {} };
    let started = false;
    // Mirrored on the wrapper so play while scrolling knows the visitor took over.
    const setStarted = (on) => {
        started = on;
        if (on) {
            media.setAttribute(STARTED, '');
        } else {
            media.removeAttribute(STARTED);
        }
    };
    let dismissed = false;
    let pinned = false;
    let stopped = false; // hosted: the source was removed by the close button
    let ratio = 1;

    const setPinned = (on) => {
        if (on === pinned) {
            return;
        }
        pinned = on;
        if (on) {
            // The placeholder keeps the height the player had, so the page does not jump.
            media.style.setProperty('min-height', Math.round(box.getBoundingClientRect().height) + 'px');
            media.classList.add(PINNED);
        } else {
            media.classList.remove(PINNED);
            media.style.removeProperty('min-height');
        }
    };
    const evaluate = () => {
        setPinned(shouldPin({
            started,
            dismissed,
            ratio,
            wide: query.matches,
            modalOpen: !!doc.querySelector('dialog.vo-lightbox[open]'),
        }));
    };
    const setDismissed = (on) => {
        dismissed = on;
        if (on) {
            media.setAttribute(DISMISSED, '');
        } else {
            media.removeAttribute(DISMISSED);
        }
    };
    const dismiss = (restoreFocus = false) => {
        setDismissed(true);
        setStarted(false);
        if (video) {
            video.pause();
        } else {
            frame.removeAttribute('src');
            stopped = true;
        }
        evaluate();
        if (restoreFocus === true) {
            // The close button disappears: keep the keyboard focus at the player.
            const target = video ?? box;
            if (!target.hasAttribute('tabindex') && target === box) {
                target.setAttribute('tabindex', '-1');
            } else if (video && !video.controls && !video.hasAttribute('tabindex')) {
                video.setAttribute('tabindex', '-1');
            }
            target.focus?.({ preventScroll: true });
        }
    };

    // Playing, unmuting or seeking an autoplaying video by hand is the visitor taking over; autoplay
    // itself never counts.
    // The native control bar sends no page events, so a visitor is recognized by exclusion: a play,
    // unmute or seek the plugin made itself (autoplay, play while scrolling, start time) is expected and
    // ignored; anything else is the visitor. A press in the player (the plugin's own pause/play button)
    // or on a scene button (`vo-gesture`) makes even the plugin's play() the visitor's.
    let lastGesture = -Infinity;
    const expectedUntil = {};
    const now = () => win.performance?.now?.() ?? Date.now();
    const onGesture = () => {
        lastGesture = now();
    };
    const onInteraction = (event) => {
        const type = event.type;
        if (now() - lastGesture > GESTURE_MS) {
            if (now() < (expectedUntil[type] ?? -Infinity)) {
                return;
            }
            // Looping restarts the video with a seek of its own.
            if (type === 'seeking' && video.loop && video.currentTime < 0.5) {
                return;
            }
        }
        setStarted(true);
        setDismissed(false);
        evaluate();
    };
    const onEnded = () => {
        setStarted(false);
        evaluate();
    };
    const onBlur = () => {
        // Clicking into a cross-origin iframe blurs the window: the visitor started it.
        win.setTimeout(() => {
            if (frame && doc.activeElement === frame) {
                setStarted(true);
                evaluate();
            }
        }, 0);
    };
    const onKeydown = (event) => {
        if (event.key === 'Escape' && pinned) {
            event.preventDefault?.();
            dismiss(true);
        }
    };
    const onClose = () => dismiss(true);
    // A scene click of a hosted chapters element counts as the visitor's start.
    const onStart = () => {
        setStarted(true);
        stopped = false;
        setDismissed(false);
        evaluate();
    };
    const onLightbox = () => {
        if (pinned) {
            dismiss(false);
        }
    };

    box.addEventListener('pointerdown', onGesture, true);
    box.addEventListener('keydown', onGesture, true);
    media.addEventListener('vo-gesture', onGesture);
    if (video) {
        watchProgrammatic(video, (type) => {
            expectedUntil[type] = now() + EXPECT_MS;
        });
    }
    close?.addEventListener('click', onClose);
    media.addEventListener('vo-start', onStart);
    doc.addEventListener?.('vo:lightbox-open', onLightbox);
    box.addEventListener('keydown', onKeydown);
    query.addEventListener?.('change', evaluate);
    if (video) {
        for (const event of ['play', 'volumechange', 'seeking']) {
            video.addEventListener(event, onInteraction);
        }
        video.addEventListener('ended', onEnded);
    } else {
        win.addEventListener('blur', onBlur);
    }
    const observer =
        typeof win.IntersectionObserver === 'function'
            ? new win.IntersectionObserver(
                (entries) => {
                    ratio = entries[entries.length - 1].intersectionRatio;
                    if (frame && stopped && ratio >= HIDDEN_RATIO) {
                        // Back in view after the close button: the player is restored, not playing.
                        frame.setAttribute('src', hostedSrc);
                        stopped = false;
                        setDismissed(false);
                    }
                    evaluate();
                },
                { threshold: [0, HIDDEN_RATIO, 0.5, 1] }
            )
            : null;
    observer?.observe(media);

    return {
        unbind: () => {
            observer?.disconnect();
            box.removeEventListener('pointerdown', onGesture, true);
            box.removeEventListener('keydown', onGesture, true);
            media.removeEventListener('vo-gesture', onGesture);
            close?.removeEventListener('click', onClose);
            media.removeEventListener('vo-start', onStart);
            doc.removeEventListener?.('vo:lightbox-open', onLightbox);
            box.removeEventListener('keydown', onKeydown);
            query.removeEventListener?.('change', evaluate);
            for (const event of ['play', 'volumechange', 'seeking']) {
                video?.removeEventListener(event, onInteraction);
            }
            video?.removeEventListener('ended', onEnded);
            win.removeEventListener?.('blur', onBlur);
            setPinned(false);
        },
    };
}
