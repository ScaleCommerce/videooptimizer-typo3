import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { applyTracks, attachVideoSource, bindHostedToggle, bindPlaybackToggle, destroyVideoSource, motionReduced, setHlsLoader } from '../../Resources/Public/Js/video-source.js';
import { parseConfig, safeUrl } from '../../Resources/Public/Js/native-config.js';

globalThis.window = globalThis;

class FakeVideo extends EventTarget {
    paused = true;
    currentTime = 0;
    plays = 0;
    #src = '';
    nativeHls = false;
    get src() {
        return this.#src;
    }
    set src(value) {
        // Switching the source pauses the element, like a browser does.
        this.#src = value;
        this.paused = true;
    }
    canPlayType(type) {
        return this.nativeHls && type === 'application/vnd.apple.mpegurl' ? 'maybe' : '';
    }
    play() {
        this.plays += 1;
        this.paused = false;
        return Promise.resolve();
    }
}

let instances;
class FakeHls {
    static Events = { ERROR: 'error', MANIFEST_PARSED: 'parsed' };
    static isSupported() {
        return true;
    }
    handlers = {};
    constructor(config) {
        this.config = config;
        this.destroyed = false;
        instances.push(this);
    }
    on(name, handler) {
        this.handlers[name] = handler;
    }
    loadSource(url) {
        this.source = url;
    }
    attachMedia(video) {
        this.media = video;
    }
    startLoad() {
        this.started = true;
    }
    destroy() {
        this.destroyed = true;
    }
}

let loads;
beforeEach(() => {
    instances = [];
    loads = 0;
    window.MediaSource = class {};
    delete window.ManagedMediaSource;
    setHlsLoader(async () => {
        loads += 1;
        return { default: FakeHls };
    });
});

const sources = { hls: 'https://l.cdn.videooptimizer.eu/h/master.m3u8', mp4: 'https://l.cdn.videooptimizer.eu/h/720p.mp4' };

test('hls.js is used with Media Source Extensions and loaded only once', async () => {
    await attachVideoSource(new FakeVideo(), sources, { deferLoad: true });
    await attachVideoSource(new FakeVideo(), sources, { deferLoad: true });

    assert.equal(loads, 1);
    assert.equal(instances[0].source, sources.hls);
    assert.equal(instances[0].config.autoStartLoad, false);
    assert.equal(instances[0].config.enableWorker, false);
});

test('deferred loading starts with the first play event', async () => {
    const video = new FakeVideo();
    await attachVideoSource(video, sources, { deferLoad: true });
    assert.equal(instances[0].started, undefined);
    video.dispatchEvent(new Event('play'));
    assert.equal(instances[0].started, true);
});

test('hls.js is not loaded when there are no Media Source Extensions: native HLS, else MP4', async () => {
    delete window.MediaSource;
    const safari = new FakeVideo();
    safari.nativeHls = true;
    await attachVideoSource(safari, sources, { autoplay: true });
    assert.equal(safari.src, sources.hls);
    assert.equal(safari.plays, 1);

    const old = new FakeVideo();
    await attachVideoSource(old, sources);
    assert.equal(old.src, sources.mp4);
    assert.equal(loads, 0);
});

test('a source without HLS never loads hls.js', async () => {
    const video = new FakeVideo();
    await attachVideoSource(video, { mp4: sources.mp4 });
    assert.equal(video.src, sources.mp4);
    assert.equal(loads, 0);
});

test('a fatal hls.js error falls back to the MP4 and keeps position and playing state', async () => {
    const video = new FakeVideo();
    await attachVideoSource(video, sources);
    video.paused = false;
    video.currentTime = 12;

    instances[0].handlers.error('error', { fatal: true });

    assert.equal(instances[0].destroyed, true);
    assert.equal(video.src, sources.mp4);
    assert.equal(video.paused, false, 'playback continues on the MP4');
    video.dispatchEvent(new Event('loadedmetadata'));
    assert.equal(video.currentTime, 12);
});

test('non-fatal hls.js errors are ignored', async () => {
    const video = new FakeVideo();
    await attachVideoSource(video, sources);
    instances[0].handlers.error('error', { fatal: false });
    assert.equal(video.src, '');
    assert.equal(instances[0].destroyed, false);
});

test('a failing native HLS source falls back to the MP4', async () => {
    delete window.MediaSource;
    const video = new FakeVideo();
    video.nativeHls = true;
    await attachVideoSource(video, sources);
    video.dispatchEvent(new Event('error'));
    assert.equal(video.src, sources.mp4);
});

test('nothing playable calls onUnplayable', async () => {
    let unplayable = 0;
    const video = new FakeVideo();
    await attachVideoSource(video, { hls: sources.hls }, { onUnplayable: () => (unplayable += 1) });
    instances[0].handlers.error('error', { fatal: true });
    assert.equal(unplayable, 1);
});

test('a failed hls.js load falls back and is retried later', async () => {
    setHlsLoader(async () => {
        loads += 1;
        throw new Error('network');
    });
    const warn = console.warn;
    const warnings = [];
    console.warn = (...args) => warnings.push(args);
    const first = new FakeVideo();
    try {
        await attachVideoSource(first, sources);
    } finally {
        console.warn = warn;
    }
    assert.equal(first.src, sources.mp4);
    assert.equal(warnings.length, 1, 'a failed hls.js import is logged');
    assert.match(String(warnings[0][0]), /hls\.js could not be loaded/);

    setHlsLoader(async () => ({ default: FakeHls }));
    await attachVideoSource(new FakeVideo(), sources);
    assert.equal(instances.length, 1);
});

test('destroying while hls.js is still loading attaches nothing', async () => {
    const video = new FakeVideo();
    const pending = attachVideoSource(video, sources);
    destroyVideoSource(video);
    await pending;
    assert.equal(instances.length, 0);
    assert.equal(video.src, '');
});

test('safeUrl only allows https on videooptimizer.eu', () => {
    assert.equal(safeUrl('https://poster.videooptimizer.eu/a.jpg'), 'https://poster.videooptimizer.eu/a.jpg');
    assert.equal(safeUrl('https://videooptimizer.eu/a'), 'https://videooptimizer.eu/a');
    assert.equal(safeUrl('http://poster.videooptimizer.eu/a.jpg'), '');
    assert.equal(safeUrl('https://evilvideooptimizer.eu/a'), '');
    assert.equal(safeUrl('https://videooptimizer.eu.evil.com/a'), '');
    assert.equal(safeUrl('javascript:alert(1)'), '');
    assert.equal(safeUrl('/relative'), '');
});

test('parseConfig validates the server data', () => {
    const cfg = parseConfig(JSON.stringify({
        hls: sources.hls, mp4: sources.mp4, poster: 'https://evil.example/p.jpg', controls: false, autoplay: true,
        muted: true, loop: true, start: 12, cover: true, title: 'T', unavailable: 'U', preload: 'auto', accent: '#C72876',
    }));
    assert.deepEqual(
        { ...cfg },
        {
            hls: sources.hls, mp4: sources.mp4, poster: '', controls: false, autoplay: true, muted: true, loop: true,
            start: 12, cover: true, title: 'T', unavailable: 'U', preload: 'auto', accent: '#C72876', tracks: [], scroll: false,
        }
    );
    assert.equal(parseConfig(JSON.stringify({ mp4: sources.mp4, preload: 'x', accent: 'red;}</style>' })).preload, 'metadata');
    assert.equal(parseConfig(JSON.stringify({ mp4: sources.mp4, accent: 'red;}</style>' })).accent, '');
    assert.equal(parseConfig(JSON.stringify({ mp4: sources.mp4, accent: 'rgba(0,0,0,0.7)' })).accent, 'rgba(0,0,0,0.7)');
    assert.equal(parseConfig('not json'), null);
    assert.equal(parseConfig(''), null);
    assert.equal(parseConfig(JSON.stringify({ hls: 'https://evil.example/x.m3u8' })), null, 'no allowed source');
    assert.equal(parseConfig(JSON.stringify({ mp4: sources.mp4 })).controls, true, 'controls default on');
});

class FakeElement {
    children = [];
    crossOrigin = '';
    querySelector(selector) {
        return this.children.find((child) => child.tag === selector) ?? null;
    }
    appendChild(child) {
        this.children.push(child);
    }
}
const fakeDoc = { createElement: (tag) => ({ tag }) };

test('applyTracks adds <track> elements and crossorigin before the source is attached', () => {
    const video = new FakeElement();
    applyTracks(
        video,
        [
            { src: 'https://l.cdn.videooptimizer.eu/de.vtt', srclang: 'de', label: 'Deutsch', kind: 'subtitles', default: true },
            { src: 'https://l.cdn.videooptimizer.eu/en.vtt', srclang: 'en', label: 'English', kind: 'captions', default: false },
        ],
        fakeDoc
    );

    assert.equal(video.crossOrigin, 'anonymous');
    assert.deepEqual(
        video.children.map((t) => [t.kind, t.srclang, t.label, t.src, t.default === true]),
        [
            ['subtitles', 'de', 'Deutsch', 'https://l.cdn.videooptimizer.eu/de.vtt', true],
            ['captions', 'en', 'English', 'https://l.cdn.videooptimizer.eu/en.vtt', false],
        ]
    );
});

test('applyTracks leaves videos without tracks and server-rendered tracks alone', () => {
    const none = new FakeElement();
    applyTracks(none, [], fakeDoc);
    assert.equal(none.crossOrigin, '');
    assert.equal(none.children.length, 0);

    const rendered = new FakeElement();
    rendered.children.push({ tag: 'track' });
    applyTracks(rendered, [{ src: 'https://l.cdn.videooptimizer.eu/x.vtt', srclang: 'de', label: 'x', kind: 'subtitles' }], fakeDoc);
    assert.equal(rendered.children.length, 1);
});

test('parseConfig keeps only valid tracks', () => {
    const cfg = parseConfig(JSON.stringify({
        mp4: sources.mp4,
        tracks: [
            { src: 'https://l.cdn.videooptimizer.eu/de.vtt', srclang: 'de', label: 'Deutsch', kind: 'captions', default: true },
            { src: 'https://evil.example/x.vtt', srclang: 'en', label: 'x' },
            { src: 'https://l.cdn.videooptimizer.eu/y.vtt', srclang: '"><x', label: 'x' },
        ],
    }));
    assert.deepEqual(cfg.tracks, [
        { src: 'https://l.cdn.videooptimizer.eu/de.vtt', srclang: 'de', label: 'Deutsch', kind: 'captions', default: true },
    ]);
    assert.deepEqual(parseConfig(JSON.stringify({ mp4: sources.mp4 })).tracks, []);

    const tags = (list) =>
        parseConfig(JSON.stringify({ mp4: sources.mp4, tracks: list.map((srclang) => ({ src: 'https://l.cdn.videooptimizer.eu/x.vtt', srclang })) })).tracks.map((t) => t.srclang);
    assert.deepEqual(tags(['de', 'de-DE', 'de-x-simple', 'zh-Hant-TW']), ['de', 'de-DE', 'de-x-simple', 'zh-Hant-TW']);
    assert.deepEqual(tags(['d', 'de_DE', 'de-', 'de--x']), []);
});

class FakeButton extends EventTarget {
    attributes = { hidden: '' };
    classes = new Set();
    classList = { toggle: (name, on) => (on ? this.classes.add(name) : this.classes.delete(name)) };
    setAttribute(name, value) {
        this.attributes[name] = value;
    }
    removeAttribute(name) {
        delete this.attributes[name];
    }
    click() {
        this.dispatchEvent(new Event('click'));
    }
}
const labels = { play: 'Play', pause: 'Pause' };

test('the toggle button is shown, labelled and follows the video state', async () => {
    const video = new FakeVideo();
    const button = new FakeButton();
    bindPlaybackToggle(video, button, Promise.resolve(), labels);

    assert.equal('hidden' in button.attributes, false);
    assert.equal(button.attributes['aria-label'], 'Play');
    assert.equal(button.classes.has('is-paused'), true);

    await Promise.resolve();
    button.click();
    assert.equal(video.paused, false);
    video.dispatchEvent(new Event('play'));
    assert.equal(button.attributes['aria-label'], 'Pause');
    assert.equal(button.classes.has('is-paused'), false);

    video.pause = () => {
        video.paused = true;
    };
    button.click();
    video.dispatchEvent(new Event('pause'));
    assert.equal(video.paused, true);
    assert.equal(button.attributes['aria-label'], 'Play');
});

test('a click before the source is ready is remembered once and consumed by shouldPlay', async () => {
    const video = new FakeVideo();
    const button = new FakeButton();
    let resolve;
    const toggle = bindPlaybackToggle(video, button, new Promise((r) => (resolve = r)), labels);

    button.click();
    assert.equal(video.plays, 0, 'nothing to play yet');
    assert.equal(button.attributes['aria-label'], 'Pause', 'the button already shows the requested state');
    assert.equal(toggle.shouldPlay(), true);
    assert.equal(toggle.shouldPlay(), false, 'consumed once');

    button.click();
    button.click();
    assert.equal(toggle.shouldPlay(), false, 'a second click cancels the request');
    resolve();
});

test('unbind removes the listeners', async () => {
    const video = new FakeVideo();
    const button = new FakeButton();
    const toggle = bindPlaybackToggle(video, button, Promise.resolve(), labels);
    await Promise.resolve();
    toggle.unbind();
    button.click();
    assert.equal(video.plays, 0);
});

test('motionReduced follows prefers-reduced-motion and data saver', () => {
    const win = (reduce, saveData) => ({
        matchMedia: () => ({ matches: reduce }),
        navigator: { connection: { saveData } },
    });
    assert.equal(motionReduced(win(true, false)), true);
    assert.equal(motionReduced(win(false, true)), true);
    assert.equal(motionReduced(win(false, false)), false);
    assert.equal(motionReduced({}), false);
});

test('an autoplaying video shows the pause state from the start; a pause before the attach sticks', async () => {
    const video = new FakeVideo();
    const button = new FakeButton();
    let resolve;
    const toggle = bindPlaybackToggle(video, button, new Promise((r) => (resolve = r)), labels, true);

    assert.equal(button.attributes['aria-label'], 'Pause');
    assert.equal(button.classes.has('is-paused'), false);
    assert.equal(toggle.wantsPause(), false);

    button.click();
    assert.equal(button.attributes['aria-label'], 'Play');
    assert.equal(toggle.wantsPause(), true, 'the caller must not start autoplay');
    assert.equal(toggle.shouldPlay(), false);
    assert.equal(video.plays, 0);

    resolve();
    await Promise.resolve();
    button.click();
    assert.equal(video.plays, 1, 'after the attach the button plays');
});

test('two clicks before the attach cancel each other and autoplay proceeds', () => {
    const video = new FakeVideo();
    const button = new FakeButton();
    const toggle = bindPlaybackToggle(video, button, new Promise(() => {}), labels, true);
    button.click();
    button.click();
    assert.equal(toggle.wantsPause(), false);
    assert.equal(button.attributes['aria-label'], 'Pause');
});

test('after the attach the button state follows play and pause events, also those not caused by the button', async () => {
    const video = new FakeVideo();
    const button = new FakeButton();
    bindPlaybackToggle(video, button, Promise.resolve(), labels, true);
    await Promise.resolve();

    video.paused = true;
    video.dispatchEvent(new Event('pause'));
    assert.equal(button.attributes['aria-label'], 'Play', 'scrolled out of view or paused by the keyboard');
    video.paused = false;
    video.dispatchEvent(new Event('play'));
    assert.equal(button.attributes['aria-label'], 'Pause');
});

class FakeFrame {
    attrs = {};
    parentNode = null;
    setAttribute(name, value) {
        this.attrs[name] = value;
    }
    removeAttribute(name) {
        delete this.attrs[name];
    }
    remove() {
        this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
    }
}
function fakeHeroDom() {
    const parent = {
        children: [],
        insertBefore(node, ref) {
            this.children.splice(this.children.indexOf(ref), 0, node);
        },
    };
    const frame = new FakeFrame();
    frame.parentNode = parent;
    parent.children.push(frame);
    const doc = {
        createComment: () => ({
            after(node) {
                node.parentNode = parent;
                parent.children.splice(parent.children.indexOf(this) + 1, 0, node);
            },
        }),
    };
    return { parent, frame, doc };
}

test('hosted hero: the pause button swaps the iframe for the poster, play puts it back', () => {
    const { parent, frame, doc } = fakeHeroDom();
    const button = new FakeButton();
    bindHostedToggle(frame, button, 'https://player.videooptimizer.eu/embed/x?autoplay=1', labels, true, doc);

    assert.equal(frame.attrs.src, 'https://player.videooptimizer.eu/embed/x?autoplay=1');
    assert.ok(parent.children.includes(frame));
    assert.equal(button.attributes['aria-label'], 'Pause');
    assert.equal('hidden' in button.attributes, false);

    button.click();
    assert.equal(parent.children.includes(frame), false, 'iframe removed');
    assert.equal('src' in frame.attrs, false, 'and its source cleared, so nothing plays');
    assert.equal(button.attributes['aria-label'], 'Play');

    button.click();
    assert.ok(parent.children.includes(frame));
    assert.equal(frame.attrs.src, 'https://player.videooptimizer.eu/embed/x?autoplay=1');
});

test('hosted hero with reduced motion never loads the iframe before play', () => {
    const { parent, frame, doc } = fakeHeroDom();
    const button = new FakeButton();
    bindHostedToggle(frame, button, 'https://player.videooptimizer.eu/embed/x', labels, false, doc);

    assert.equal(parent.children.includes(frame), false);
    assert.equal('src' in frame.attrs, false);
    assert.equal(button.attributes['aria-label'], 'Play');
    button.click();
    assert.equal(frame.attrs.src, 'https://player.videooptimizer.eu/embed/x');
});
