// Chapters and scenes: a row of scene previews under the video; a click jumps to the scene and plays.
// Plain ES module without imports (the entry passes the URL check; a relative import here would bypass the
// version stamp of the sub-module imports), so the logic can be unit-tested with node.

/** Index of the last scene that started at or before `time`; -1 before the first one. */
export function activeSceneIndex(starts, time) {
    let active = -1;
    starts.forEach((start, index) => {
        if (time >= start) {
            active = index;
        }
    });
    return active;
}

/** Hosted embed URL that starts at `seconds` and plays at once ('' when the URL is not allowed). */
export function embedUrlAt(base, seconds, safeUrl) {
    const safe = safeUrl(base);
    if (!safe) {
        return '';
    }
    const url = new URL(safe);
    url.searchParams.set('t', String(Math.max(0, Math.floor(seconds))));
    url.searchParams.set('autoplay', '1');
    return url.href;
}

/**
 * Jumps a native video to `seconds` and plays it. `ready` resolves when the source is attached. A video
 * without metadata yet (hls.js loads segments only on play) takes the position from the first
 * loadedmetadata again, in case the player starts from the beginning.
 */
export async function seekAndPlay(video, seconds, ready = Promise.resolve()) {
    await ready;
    video.currentTime = seconds;
    if (video.readyState === 0) {
        video.addEventListener(
            'loadedmetadata',
            () => {
                if (Math.abs(video.currentTime - seconds) > 1) {
                    video.currentTime = seconds;
                }
            },
            { once: true }
        );
    }
    await video.play().catch(() => {});
}

/** Marks the scene that is playing with aria-current; nothing is marked while paused. */
export function bindActiveScene(video, buttons, starts) {
    const update = () => {
        const active = video.paused ? -1 : activeSceneIndex(starts, video.currentTime);
        buttons.forEach((button, index) => {
            if (index === active) {
                button.setAttribute('aria-current', 'true');
            } else {
                button.removeAttribute('aria-current');
            }
        });
    };
    for (const event of ['timeupdate', 'play', 'pause', 'ended', 'seeked']) {
        video.addEventListener(event, update);
    }
    return {
        update,
        unbind: () => {
            for (const event of ['timeupdate', 'play', 'pause', 'ended', 'seeked']) {
                video.removeEventListener(event, update);
            }
        },
    };
}

/**
 * Wires one chapters element: `root` contains the player (native `.vo-native video` or hosted
 * `iframe.vo-player`) and the scene buttons (`[data-vo-scene]` = start seconds). A native video's source
 * is attached on demand through `wrap.__voStart()` (set by the entry module). `safeUrl` is the entry's URL
 * allowlist check.
 */
export function initChapters(root, safeUrl) {
    const buttons = [...root.querySelectorAll('[data-vo-scene]')];
    if (buttons.length === 0 || root.hasAttribute('data-vo-chapters-ready')) {
        return;
    }
    root.setAttribute('data-vo-chapters-ready', '');
    const starts = buttons.map((button) => Number(button.getAttribute('data-vo-scene')) || 0);
    const wrap = root.querySelector('.vo-native[data-vo-native]');
    const video = wrap?.querySelector('video') ?? null;
    const frame = root.querySelector('iframe.vo-player');
    const embed = root.getAttribute('data-vo-embed') || '';
    const active = video ? bindActiveScene(video, buttons, starts) : null;

    buttons.forEach((button, index) => {
        button.addEventListener('click', () => {
            // A scene click is the visitor's gesture for a mini player (native playback follows it).
            root.querySelector('.vo-media')?.dispatchEvent(new Event('vo-gesture'));
            if (video) {
                const teardown = wrap.__voStart?.();
                seekAndPlay(video, starts[index], teardown?.attached ?? Promise.resolve());
            } else if (frame) {
                const src = embedUrlAt(embed || frame.getAttribute('src') || '', starts[index], safeUrl);
                if (src) {
                    frame.setAttribute('src', src);
                    buttons.forEach((other) => other.removeAttribute('aria-current'));
                    button.setAttribute('aria-current', 'true');
                    // The visitor started playback: lets a mini player know (the iframe cannot tell).
                    root.querySelector('.vo-media')?.dispatchEvent(new Event('vo-start'));
                }
            }
        });
    });
    active?.update();
}
