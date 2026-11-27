import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  UploadAbort, format, formatBytes, pollVideo, uploadParts, withParams,
} from '../../Resources/Public/JavaScript/upload-core.js';

const file = (size) => ({ size, slice: (start, end) => ({ size: Math.min(end, size) - start }) });
const session = (count, partSize = 10) => ({
  partSize,
  parts: Array.from({ length: count }, (_, i) => ({ partNumber: i + 1, url: `https://s.example/p${i + 1}` })),
});

test('format replaces positional and plain placeholders', () => {
  assert.equal(format('Uploading: %1$d%% (%2$s of %3$s)', 42, '1 MB', '2 MB'), 'Uploading: 42% (1 MB of 2 MB)');
  assert.equal(format('Failed: %s', 'boom'), 'Failed: boom');
  assert.equal(format('%2$s %1$s', 'a', 'b'), 'b a');
});

test('formatBytes picks a sensible unit', () => {
  assert.equal(formatBytes(10), '1 KB');
  assert.equal(formatBytes(5 * 1024 * 1024), '5.0 MB');
  assert.equal(formatBytes(3 * 1024 * 1024 * 1024), '3.00 GB');
});

test('withParams keeps the route token and adds parameters', () => {
  const url = withParams('https://x.example/typo3/ajax/status?token=abc', { uuid: 'u1' });
  assert.equal(new URL(url).searchParams.get('token'), 'abc');
  assert.equal(new URL(url).searchParams.get('uuid'), 'u1');
});

test('uploadParts uploads every part in order and collects the ETags', async () => {
  const calls = [];
  const progress = [];
  const parts = await uploadParts(file(25), session(3), {
    putPart: async (url, blob, { onProgress }) => {
      calls.push([url, blob.size]);
      onProgress(blob.size);
      return `"etag-${calls.length}"`;
    },
    onProgress: (bytes) => progress.push(bytes),
  });

  assert.deepEqual(calls, [['https://s.example/p1', 10], ['https://s.example/p2', 10], ['https://s.example/p3', 5]]);
  assert.deepEqual(parts, [
    { partNumber: 1, etag: '"etag-1"' }, { partNumber: 2, etag: '"etag-2"' }, { partNumber: 3, etag: '"etag-3"' },
  ]);
  assert.equal(progress.at(-1), 25);
});

test('uploadParts retries a failing part with backoff and reports it', async () => {
  let attempts = 0;
  const retries = [];
  const parts = await uploadParts(file(10), session(1), {
    backoffMs: 1,
    putPart: async () => {
      attempts += 1;
      if (attempts < 3) {
        throw new Error('network');
      }
      return '"ok"';
    },
    onRetry: (part, attempt, max) => retries.push([part, attempt, max]),
  });

  assert.equal(attempts, 3);
  assert.deepEqual(retries, [[1, 2, 3], [1, 3, 3]]);
  assert.deepEqual(parts, [{ partNumber: 1, etag: '"ok"' }]);
});

test('uploadParts gives up after three attempts', async () => {
  let attempts = 0;
  await assert.rejects(
    uploadParts(file(10), session(1), { backoffMs: 1, putPart: async () => { attempts += 1; throw new Error('HTTP 500'); } }),
    /HTTP 500/,
  );
  assert.equal(attempts, 3);
});

test('a missing ETag is a configuration problem and is not retried', async () => {
  let attempts = 0;
  await assert.rejects(
    uploadParts(file(10), session(1), { labels: { noEtag: 'expose ETag' }, backoffMs: 1, putPart: async () => { attempts += 1; return ''; } }),
    /expose ETag/,
  );
  assert.equal(attempts, 1);
});

test('cancelling stops the upload before the next part', async () => {
  const controller = new AbortController();
  let calls = 0;
  await assert.rejects(
    uploadParts(file(30), session(3), {
      signal: controller.signal,
      putPart: async () => {
        calls += 1;
        controller.abort();
        return '"e"';
      },
    }),
    UploadAbort,
  );
  assert.equal(calls, 1);
});

test('cancelling during the retry backoff aborts immediately', async () => {
  const controller = new AbortController();
  const started = Date.now();
  await assert.rejects(
    uploadParts(file(10), session(1), {
      signal: controller.signal,
      backoffMs: 60000,
      putPart: async () => { setTimeout(() => controller.abort(), 5); throw new Error('network'); },
    }),
    UploadAbort,
  );
  assert.ok(Date.now() - started < 2000);
});

test('pollVideo returns once the video is ready and tolerates failed polls', async () => {
  const states = [new Error('blip'), { status: 'processing' }, { status: 'ready', ready: true }];
  const seen = [];
  const video = await pollVideo('https://x.example/status?token=t', 'u1', (v) => v.status === 'ready', {
    delays: [0, 0],
    request: async (method, url) => {
      seen.push(new URL(url).searchParams.get('uuid'));
      const next = states.shift();
      if (next instanceof Error) {
        throw next;
      }
      return next;
    },
  });

  assert.equal(video.status, 'ready');
  assert.deepEqual(seen, ['u1', 'u1', 'u1']);
});

test('pollVideo surfaces the failure reason of a failed video', async () => {
  const video = await pollVideo('https://x.example/s', 'u1', (v) => v.status === 'failed', {
    delays: [0, 0],
    request: async () => ({ status: 'failed', error: 'Unsupported codec' }),
  });

  assert.equal(video.error, 'Unsupported codec');
});

test('pollVideo times out', async () => {
  await assert.rejects(
    pollVideo('https://x.example/s', 'u1', () => false, { delays: [1, 1], maxMs: 20, request: async () => ({ status: 'processing' }) }),
    /timeout/,
  );
});

test('pollVideo stops when cancelled', async () => {
  const controller = new AbortController();
  setTimeout(() => controller.abort(), 5);
  await assert.rejects(
    pollVideo('https://x.example/s', 'u1', () => false, { signal: controller.signal, delays: [60000, 60000], request: async () => ({}) }),
    UploadAbort,
  );
});
