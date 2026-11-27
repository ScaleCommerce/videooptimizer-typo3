import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { autoplayWhileVisible, observeVisibility } from '../../Resources/Public/Js/video-source.js';
import { parseConfig } from '../../Resources/Public/Js/native-config.js';

globalThis.window = globalThis;

let observer;
class FakeObserver {
    constructor(callback, options) {
        this.callback = callback;
        this.options = options;
        observer = this;
    }
    observe() {}
    disconnect() {
        this.disconnected = true;
    }
    report(ratio, intersecting = ratio > 0) {
        this.callback([{ isIntersecting: intersecting, intersectionRatio: ratio }]);
    }
}
globalThis.IntersectionObserver = FakeObserver;

class FakeVideo extends EventTarget {
    paused = true;
    ended = false;
    plays = 0;
    play() {
        this.plays += 1;
        this.paused = false;
        this.dispatchEvent(new Event('play'));
        return Promise.resolve();
    }
    pause() {
        this.paused = true;
        this.dispatchEvent(new Event('pause'));
    }
}

afterEach(() => {
    observer = null;
});

test('visibility counts only from the threshold on, although the element still overlaps the viewport', () => {
    const states = [];
    observeVisibility({}, (visible) => states.push(visible), '0px', 0.5);

    observer.report(0.6);
    observer.report(0.4);
    observer.report(0, false);
    assert.deepEqual(states, [true, false, false]);
    assert.equal(observer.options.threshold, 0.5);
});

test('play while scrolling starts at half visible and pauses below it', () => {
    const video = new FakeVideo();
    autoplayWhileVisible(video, {}, 0.5);

    observer.report(0.3);
    assert.equal(video.plays, 0);
    observer.report(0.8);
    assert.equal(video.plays, 1);
    observer.report(0.3);
    assert.equal(video.paused, true);
});

test("a visitor's pause sticks when the player comes back into view", () => {
    const video = new FakeVideo();
    autoplayWhileVisible(video, {}, 0.5);

    observer.report(1);
    video.pause(); // the visitor pauses
    observer.report(0.2);
    observer.report(1);
    assert.equal(video.plays, 1);
});

test('the scroll flag of the player configuration is read', () => {
    const base = { mp4: 'https://l.cdn.videooptimizer.eu/h/720p.mp4' };
    assert.equal(parseConfig(JSON.stringify({ ...base, scroll: true })).scroll, true);
    assert.equal(parseConfig(JSON.stringify({ ...base, scroll: 'yes' })).scroll, false);
    assert.equal(parseConfig(JSON.stringify(base)).scroll, false);
});
