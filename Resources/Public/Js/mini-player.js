// Floating mini player: once the visitor started playback and the player scrolls out of view, it is
// pinned bottom right with CSS only (class on the wrapper, a placeholder keeps the page height). The
// video or iframe is never moved in the DOM (an iframe would reload). No imports (the entry loads this
// with the version stamp); the DOM objects are passed in so the logic can be unit-tested with node.

export const MINI_QUERY = '(min-width: 600px)';
export const HIDDEN_RATIO = 0.01;
const PINNED = 'vo-media--pinned';
const DISMISSED = 'data-vo-mini-dismissed';

/** Whether the player should be pinned now. `ratio` is the visible share of the placeholder. */
export function shouldPin({ started, dismissed, ratio, wide, modalOpen }) {
    return started && !dismissed && wide && !modalOpen && ratio < HIDDEN_RATIO;
}

/**
 * True when a mini player element would pin a playing video instead of letting it pause when it leaves
 * the viewport (play while scrolling combined with the mini player).
 */
export function keepsPlayingWhenHidden(media, win = window) {
    return (
        !!media &&
        media.hasAttribute('data-vo-mini') &&
        !media.hasAttribute(DISMISSED) &&
        !!win.matchMedia?.(MINI_QUERY).matches
    );
}

/**
 * Wires one mini player wrapper (`.vo-media[data-vo-mini]` with `[data-vo-box]` around the player and a
 * `[data-vo-mini-close]` button). Native: `video`; hosted: `frame` plus its source `hostedSrc` (the
 * visitor's start is detected through the focus the iframe takes; `startedAtLoad` for autoplay).
 */
export function bindMiniPlayer(media, { video = null, frame = null, hostedSrc = '', startedAtLoad = false }, win = window, doc = document) {
    const box = media.querySelector('[data-vo-box]');
    const close = media.querySelector('[data-vo-mini-close]');
    if (!box || (!video && !frame)) {
        return { unbind: () => {} };
    }
    const query = win.matchMedia?.(MINI_QUERY) ?? { matches: false, addEventListener() {}, removeEventListener() {} };
    let started = startedAtLoad && !!frame;
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
    const dismiss = () => {
        setDismissed(true);
        started = false;
        if (video) {
            video.pause();
        } else {
            frame.removeAttribute('src');
            stopped = true;
        }
        evaluate();
    };

    const onPlay = () => {
        started = true;
        setDismissed(false);
        evaluate();
    };
    const onEnded = () => {
        started = false;
        evaluate();
    };
    const onBlur = () => {
        // Clicking into a cross-origin iframe blurs the window: the visitor started it.
        win.setTimeout(() => {
            if (frame && doc.activeElement === frame) {
                started = true;
                evaluate();
            }
        }, 0);
    };
    const onKeydown = (event) => {
        if (event.key === 'Escape' && pinned) {
            event.preventDefault?.();
            dismiss();
        }
    };

    close?.addEventListener('click', dismiss);
    box.addEventListener('keydown', onKeydown);
    query.addEventListener?.('change', evaluate);
    if (video) {
        video.addEventListener('play', onPlay);
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
            close?.removeEventListener('click', dismiss);
            box.removeEventListener('keydown', onKeydown);
            query.removeEventListener?.('change', evaluate);
            video?.removeEventListener('play', onPlay);
            video?.removeEventListener('ended', onEnded);
            win.removeEventListener?.('blur', onBlur);
            setPinned(false);
        },
    };
}
