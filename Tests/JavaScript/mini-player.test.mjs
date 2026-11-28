import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { bindMiniPlayer, keepsPlayingWhenHidden, shouldPin } from '../../Resources/Public/Js/mini-player.js';

class FakeElement extends EventTarget {
    classes = new Set();
    attrs = new Map();
    styles = new Map();
    classList = { add: (c) => this.classes.add(c), remove: (c) => this.classes.delete(c), contains: (c) => this.classes.has(c) };
    style = { setProperty: (k, v) => this.styles.set(k, v), removeProperty: (k) => this.styles.delete(k) };
    children = {};
    setAttribute(name, value) {
        this.attrs.set(name, value);
    }
    getAttribute(name) {
        return this.attrs.get(name) ?? null;
    }
    removeAttribute(name) {
        this.attrs.delete(name);
    }
    hasAttribute(name) {
        return this.attrs.has(name);
    }
    getBoundingClientRect() {
        return { height: 180 };
    }
    querySelector(selector) {
        return this.children[selector] ?? null;
    }
}

let observer;
let mediaQuery;
let modal;
let clock;
let win;
let doc;

class FakeObserver {
    constructor(callback) {
        this.callback = callback;
        observer = this;
    }
    observe() {}
    disconnect() {}
    report(ratio) {
        this.callback([{ intersectionRatio: ratio, isIntersecting: ratio > 0 }]);
    }
}

class FakeVideo extends FakeElement {
    paused = true;
    muted = false;
    loop = false;
    currentTime = 0;
    play() {
        // Like a browser: the play event follows a play() call that starts playback.
        this.paused = false;
        this.dispatchEvent(new Event('play'));
        return Promise.resolve();
    }
    focused = 0;
    focus() {
        this.focused += 1;
    }
    pause() {
        this.paused = true;
    }
}

function press(box, type = 'pointerdown') {
    box.dispatchEvent(new Event(type));
}

function setUp({ withVideo = true, gesture = false } = {}) {
    const media = new FakeElement();
    const box = new FakeElement();
    const close = new FakeElement();
    media.children['[data-vo-box]'] = box;
    media.children['[data-vo-mini-close]'] = close;
    const video = withVideo ? new FakeVideo() : null;
    const frame = withVideo ? null : new FakeElement();
    frame?.setAttribute('src', 'https://player.videooptimizer.eu/embed/a');
    const bound = bindMiniPlayer(media, { video, frame, hostedSrc: 'https://player.videooptimizer.eu/embed/a' }, win, doc);
    if (gesture) {
        // The visitor pressed in the player (a scene button sends the same event).
        media.dispatchEvent(new Event('vo-gesture'));
    }

    return { media, box, close, video, frame, bound };
}

beforeEach(() => {
    mediaQuery = { matches: true, listeners: [], addEventListener(_, fn) { this.listeners.push(fn); }, removeEventListener() {} };
    modal = false;
    clock = 1000;
    win = Object.assign(new EventTarget(), {
        performance: { now: () => clock },
        IntersectionObserver: FakeObserver,
        matchMedia: () => mediaQuery,
        setTimeout: (fn) => fn(),
    });
    doc = Object.assign(new EventTarget(), { querySelector: () => (modal ? {} : null), activeElement: null });
});

test('a player is pinned only after playback started, out of view, on a wide viewport and without a modal', () => {
    const base = { started: true, dismissed: false, ratio: 0, wide: true, modalOpen: false };
    assert.equal(shouldPin(base), true);
    assert.equal(shouldPin({ ...base, started: false }), false);
    assert.equal(shouldPin({ ...base, dismissed: true }), false);
    assert.equal(shouldPin({ ...base, ratio: 0.3 }), false);
    assert.equal(shouldPin({ ...base, wide: false }), false);
    assert.equal(shouldPin({ ...base, modalOpen: true }), false);
});

test('a mini player element keeps playing out of view only on wide viewports until it is dismissed', () => {
    const media = new FakeElement();
    assert.equal(keepsPlayingWhenHidden(media, win), false, 'no mini player');
    media.setAttribute('data-vo-mini', '');
    assert.equal(keepsPlayingWhenHidden(media, win), false, 'autoplay that nobody took over still pauses');
    media.setAttribute('data-vo-mini-started', '');
    assert.equal(keepsPlayingWhenHidden(media, win), true);
    mediaQuery.matches = false;
    assert.equal(keepsPlayingWhenHidden(media, win), false);
    mediaQuery.matches = true;
    media.setAttribute('data-vo-mini-dismissed', '');
    assert.equal(keepsPlayingWhenHidden(media, win), false);
});

test('a native player is pinned when it scrolls out after play and unpinned when it comes back', () => {
    const { media, video } = setUp();
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), false, 'not started');

    video.dispatchEvent(new Event('play'));
    observer.report(1);
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);
    assert.equal(media.styles.get('min-height'), '180px', 'the placeholder keeps the height');

    observer.report(0.5);
    assert.equal(media.classList.contains('vo-media--pinned'), false);
    assert.equal(media.styles.has('min-height'), false);
});

test('a narrow viewport and an open lightbox unpin', () => {
    const { media, video } = setUp();
    video.dispatchEvent(new Event('play'));
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);

    mediaQuery.matches = false;
    mediaQuery.listeners.forEach((fn) => fn());
    assert.equal(media.classList.contains('vo-media--pinned'), false);

    mediaQuery.matches = true;
    modal = true;
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), false);
});

test('the close button unpins and pauses a native video; playing again allows pinning again', () => {
    const { media, video, close } = setUp();
    video.paused = false;
    video.dispatchEvent(new Event('play'));
    observer.report(0);
    close.dispatchEvent(new Event('click'));

    assert.equal(media.classList.contains('vo-media--pinned'), false);
    assert.equal(video.paused, true);
    assert.equal(media.hasAttribute('data-vo-mini-dismissed'), true);
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), false, 'dismissed stays closed');

    video.dispatchEvent(new Event('play'));
    assert.equal(media.hasAttribute('data-vo-mini-dismissed'), false);
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);
});

test('Escape inside the pinned player closes it', () => {
    const { media, video, box } = setUp();
    video.dispatchEvent(new Event('play'));
    observer.report(0);
    box.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Escape' }));
    assert.equal(media.classList.contains('vo-media--pinned'), false);
});

test('a finished video is not pinned', () => {
    const { media, video } = setUp();
    video.dispatchEvent(new Event('play'));
    video.dispatchEvent(new Event('ended'));
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), false);
});

test('a hosted player counts as started when the iframe takes the focus; closing removes and later restores the source', () => {
    const { media, frame, close } = setUp({ withVideo: false });
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), false);

    doc.activeElement = frame;
    win.dispatchEvent(new Event('blur'));
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);

    close.dispatchEvent(new Event('click'));
    assert.equal(media.classList.contains('vo-media--pinned'), false);
    assert.equal(frame.hasAttribute('src'), false, 'the player stops');

    observer.report(0.8);
    assert.equal(frame.getAttribute('src'), 'https://player.videooptimizer.eu/embed/a');
    assert.equal(media.hasAttribute('data-vo-mini-dismissed'), false);
});

test('the plugin starting playback itself (autoplay, play while scrolling) never counts, whatever the sound state', () => {
    const { media, video } = setUp();
    video.muted = true; // the plugin mutes an autoplaying video
    video.currentTime = 12; // and applies a start time
    video.play();
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), false);
    assert.equal(media.hasAttribute('data-vo-mini-started'), false);
    video.muted = false; // an unmuted theme autoplay is still not a visitor start
    video.play();
    assert.equal(media.hasAttribute('data-vo-mini-started'), false);
});

test("the native control bar sends no page events: a play the plugin did not make is the visitor's, even for a muted video", () => {
    const { media, video } = setUp();
    video.muted = true; // editor-muted
    clock += 5000;
    video.dispatchEvent(new Event('play')); // the visitor presses the control bar's play button
    assert.equal(media.hasAttribute('data-vo-mini-started'), true);
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);
});

test('an expected change only covers a short time: a later unmute is the visitor', () => {
    const { media, video } = setUp();
    video.play();
    clock += 5000;
    video.dispatchEvent(new Event('volumechange'));
    assert.equal(media.hasAttribute('data-vo-mini-started'), true);
});

test('the looping restart of a video is not a visitor seek', () => {
    const { media, video } = setUp();
    video.loop = true;
    video.currentTime = 0.1;
    clock += 5000;
    video.dispatchEvent(new Event('seeking'));
    assert.equal(media.hasAttribute('data-vo-mini-started'), false);
});

test('a press in the player or on a scene button makes the following programmatic play the visitor\'s', () => {
    const { media, video, box } = setUp();
    press(box); // the plugin's own pause/play button
    video.play();
    assert.equal(media.hasAttribute('data-vo-mini-started'), true);

    const scene = setUp();
    scene.media.dispatchEvent(new Event('vo-gesture')); // a scene button seeks and plays through the plugin
    scene.video.play();
    assert.equal(scene.media.hasAttribute('data-vo-mini-started'), true);
});

test('a gesture expires', () => {
    const { media, video, box } = setUp();
    press(box, 'keydown');
    clock += 20000;
    video.play();
    assert.equal(media.hasAttribute('data-vo-mini-started'), false);
});

test('a hosted player (also with autoplay in its URL) is not started until the visitor acts', () => {
    const { media, frame } = setUp({ withVideo: false });
    frame.setAttribute('src', 'https://player.videooptimizer.eu/embed/a?autoplay=1');
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), false);
});

test('a scene click starts a hosted player for the mini player', () => {
    const { media } = setUp({ withVideo: false });
    media.dispatchEvent(new Event('vo-start'));
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);
});

test('opening a lightbox unpins and pauses the mini player', () => {
    const { media, video } = setUp();
    video.paused = false;
    video.dispatchEvent(new Event('play'));
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);

    doc.dispatchEvent(new Event('vo:lightbox-open'));
    assert.equal(media.classList.contains('vo-media--pinned'), false);
    assert.equal(video.paused, true);
    assert.equal(video.focused, 0, 'the lightbox takes the focus');
});

test('closing the mini player moves the focus to the video, or to the box for a hosted player', () => {
    const { video, close } = setUp();
    video.dispatchEvent(new Event('play'));
    observer.report(0);
    close.dispatchEvent(new Event('click'));
    assert.equal(video.focused, 1);

    const hosted = setUp({ withVideo: false });
    hosted.media.dispatchEvent(new Event('vo-start'));
    observer.report(0);
    let focused = 0;
    hosted.box.focus = () => {
        focused += 1;
    };
    hosted.box.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Escape' }));
    assert.equal(focused, 1);
    assert.equal(hosted.box.getAttribute('tabindex'), '-1');
});

test('unmuting or seeking an autoplaying video by hand is the visitor taking over', () => {
    for (const event of ['volumechange', 'seeking']) {
        const { media, video } = setUp();
        video.play(); // autoplay
        assert.equal(media.hasAttribute('data-vo-mini-started'), false);
        clock += 5000;
        video.dispatchEvent(new Event(event)); // the control bar
        assert.equal(media.hasAttribute('data-vo-mini-started'), true, event);
        observer.report(0);
        assert.equal(media.classList.contains('vo-media--pinned'), true, event);
    }
});

test('opening a lightbox stops a pinned hosted player (no double audio) and restores it on return', () => {
    const { media, frame } = setUp({ withVideo: false });
    media.dispatchEvent(new Event('vo-start'));
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);

    doc.dispatchEvent(new Event('vo:lightbox-open'));
    assert.equal(media.classList.contains('vo-media--pinned'), false);
    assert.equal(frame.hasAttribute('src'), false);
    observer.report(0.8);
    assert.equal(frame.hasAttribute('src'), true);
});
