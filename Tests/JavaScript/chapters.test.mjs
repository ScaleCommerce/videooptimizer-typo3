import assert from 'node:assert/strict';
import { test } from 'node:test';
import { safeUrl } from '../../Resources/Public/Js/native-config.js';
import { activeSceneIndex, bindActiveScene, embedUrlAt, seekAndPlay } from '../../Resources/Public/Js/chapters.js';

class FakeVideo extends EventTarget {
    paused = true;
    readyState = 0;
    currentTime = 0;
    plays = 0;
    play() {
        this.plays += 1;
        this.paused = false;
        return Promise.resolve();
    }
}

class FakeButton {
    attrs = new Map();
    setAttribute(name, value) {
        this.attrs.set(name, value);
    }
    removeAttribute(name) {
        this.attrs.delete(name);
    }
}

test('the active scene is the last one that started', () => {
    const starts = [10, 30, 60];
    assert.equal(activeSceneIndex(starts, 0), -1);
    assert.equal(activeSceneIndex(starts, 10), 0);
    assert.equal(activeSceneIndex(starts, 45), 1);
    assert.equal(activeSceneIndex(starts, 600), 2);
});

test('the hosted URL gets the start time and autoplay, other parameters stay', () => {
    const url = embedUrlAt('https://player.videooptimizer.eu/embed/abc?muted=0&t=5&autoplay=0', 42, safeUrl);
    const params = new URL(url).searchParams;
    assert.equal(params.get('t'), '42');
    assert.equal(params.get('autoplay'), '1');
    assert.equal(params.get('muted'), '0');
    assert.equal(embedUrlAt('https://evil.example/embed/abc', 42, safeUrl), '');
    assert.equal(embedUrlAt('', 42, safeUrl), '');
});

test('seeking sets the position and plays; before metadata the position is applied again once it loads', async () => {
    const video = new FakeVideo();
    await seekAndPlay(video, 30, Promise.resolve());

    assert.equal(video.currentTime, 30);
    assert.equal(video.plays, 1);
    video.currentTime = 0; // the player started from the beginning
    video.dispatchEvent(new Event('loadedmetadata'));
    assert.equal(video.currentTime, 30);
});

test('a loaded video is not corrected after metadata', async () => {
    const video = new FakeVideo();
    video.readyState = 4;
    await seekAndPlay(video, 30, Promise.resolve());
    video.currentTime = 31;
    video.dispatchEvent(new Event('loadedmetadata'));
    assert.equal(video.currentTime, 31);
});

test('the playing scene is marked with aria-current and unmarked when paused', () => {
    const video = new FakeVideo();
    const buttons = [new FakeButton(), new FakeButton(), new FakeButton()];
    const bound = bindActiveScene(video, buttons, [10, 30, 60]);

    video.paused = false;
    video.currentTime = 35;
    video.dispatchEvent(new Event('timeupdate'));
    assert.equal(buttons[1].attrs.get('aria-current'), 'true');
    assert.equal(buttons[0].attrs.has('aria-current'), false);

    video.currentTime = 70;
    video.dispatchEvent(new Event('timeupdate'));
    assert.equal(buttons[1].attrs.has('aria-current'), false);
    assert.equal(buttons[2].attrs.get('aria-current'), 'true');

    video.paused = true;
    video.dispatchEvent(new Event('pause'));
    assert.equal(buttons[2].attrs.has('aria-current'), false);
    bound.unbind();
});
