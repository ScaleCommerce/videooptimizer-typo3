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
    pause() {
        this.paused = true;
    }
}

function setUp({ withVideo = true, startedAtLoad = false } = {}) {
    const media = new FakeElement();
    const box = new FakeElement();
    const close = new FakeElement();
    media.children['[data-vo-box]'] = box;
    media.children['[data-vo-mini-close]'] = close;
    const video = withVideo ? new FakeVideo() : null;
    const frame = withVideo ? null : new FakeElement();
    frame?.setAttribute('src', 'https://player.videooptimizer.eu/embed/a');
    const bound = bindMiniPlayer(media, { video, frame, hostedSrc: 'https://player.videooptimizer.eu/embed/a', startedAtLoad }, win, doc);
    return { media, box, close, video, frame, bound };
}

beforeEach(() => {
    mediaQuery = { matches: true, listeners: [], addEventListener(_, fn) { this.listeners.push(fn); }, removeEventListener() {} };
    modal = false;
    win = Object.assign(new EventTarget(), {
        IntersectionObserver: FakeObserver,
        matchMedia: () => mediaQuery,
        setTimeout: (fn) => fn(),
    });
    doc = { querySelector: () => (modal ? {} : null), activeElement: null };
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

test('a hosted autoplay player is started at once', () => {
    const { media } = setUp({ withVideo: false, startedAtLoad: true });
    observer.report(0);
    assert.equal(media.classList.contains('vo-media--pinned'), true);
});
