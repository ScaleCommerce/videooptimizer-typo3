import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import * as nativeConfig from '../../Resources/Public/Js/native-config.js';
import * as videoSource from '../../Resources/Public/Js/video-source.js';

// The entry module needs a DOM and cannot be imported here; this guards its import list instead
// (a name used but not imported is a ReferenceError that only shows up in the browser).
const entry = readFileSync(new URL('../../Resources/Public/Js/videooptimizer.js', import.meta.url), 'utf8');
const importList = entry.slice(entry.indexOf('const [') , entry.indexOf('] = await Promise.all'));

test('the entry imports every sub-module function it calls', () => {
    for (const name of [...Object.keys(videoSource), ...Object.keys(nativeConfig)]) {
        const used = new RegExp(`(?<![.\\w])${name}\\(`).test(entry.slice(entry.indexOf('] = await Promise.all')));
        if (used) {
            assert.match(importList, new RegExp(`\\b${name}\\b`), `${name} is used but not imported`);
        }
    }
});

test('the entry imports only what the sub-modules export', () => {
    const exported = new Set([...Object.keys(videoSource), ...Object.keys(nativeConfig)]);
    const names = importList.replace(/[\[\]{}\s]/g, ' ').split(/[\s,]+/).filter((n) => /^\w+$/.test(n) && n !== 'const');
    for (const name of names) {
        assert.ok(exported.has(name), `${name} is imported but not exported`);
    }
});

test('the sub-modules are imported with the server version stamp, falling back to the entry query', () => {
    assert.match(entry, /data-vo-version/);
    assert.match(entry, /new URL\(import\.meta\.url\)\.search/);
    assert.match(entry, /import\('\.\/video-source\.js' \+ version\)/);
    assert.match(entry, /import\('\.\/native-config\.js' \+ version\)/);
});
