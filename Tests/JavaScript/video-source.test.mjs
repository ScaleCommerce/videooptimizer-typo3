import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import { attachVideoSource, destroyVideoSource, setHlsLoader } from '../../Resources/Public/Js/video-source.js';
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
    const first = new FakeVideo();
    await attachVideoSource(first, sources);
    assert.equal(first.src, sources.mp4);

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
        muted: true, loop: true, start: 12, cover: true, title: 'T', unavailable: 'U',
    }));
    assert.deepEqual(
        { ...cfg },
        {
            hls: sources.hls, mp4: sources.mp4, poster: '', controls: false, autoplay: true, muted: true, loop: true,
            start: 12, cover: true, title: 'T', unavailable: 'U',
        }
    );
    assert.equal(parseConfig('not json'), null);
    assert.equal(parseConfig(''), null);
    assert.equal(parseConfig(JSON.stringify({ hls: 'https://evil.example/x.m3u8' })), null, 'no allowed source');
    assert.equal(parseConfig(JSON.stringify({ mp4: sources.mp4 })).controls, true, 'controls default on');
});
