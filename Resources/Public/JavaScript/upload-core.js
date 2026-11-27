/**
 * Browser-side core of the VideoOptimizer upload (no TYPO3 imports, unit-tested with `node --test`):
 * label formatting, JSON requests to the module's AJAX routes, presigned part uploads with retry
 * and cancel, and status polling.
 */

const PART_ATTEMPTS = 3;
const POLL_FAST_MS = 5000;
const POLL_SLOW_MS = 15000;
const POLL_MAX_MS = 30 * 60 * 1000;

/** sprintf-light for the XLF labels: %s, %d, %1$s, %2$d and %%. */
export const format = (text, ...args) => {
  let next = 0;
  return String(text).replace(/%(?:(\d+)\$)?([sd%])/g, (match, position, type) => {
    if (type === '%') {
      return '%';
    }
    const index = position ? Number(position) - 1 : next++;
    return args[index] === undefined ? '' : String(args[index]);
  });
};

export const formatBytes = (bytes) => {
  if (bytes < 1024 * 1024) {
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  }
  if (bytes < 1024 * 1024 * 1024) {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
};

export const sleep = (ms, signal) => new Promise((resolve, reject) => {
  const timer = setTimeout(resolve, ms);
  if (signal) {
    signal.addEventListener('abort', () => {
      clearTimeout(timer);
      reject(new UploadAbort());
    }, { once: true });
  }
});

export class UploadAbort extends Error {
  constructor() {
    super('aborted');
    this.name = 'UploadAbort';
  }
}

export async function requestJson(method, url, body) {
  const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }
  const response = await fetch(url, options);
  let data = null;
  try {
    data = await response.json();
  } catch (e) {
    data = null;
  }
  if (!response.ok || !data || data.error) {
    const error = new Error((data && data.error) || `HTTP ${response.status}`);
    error.code = data && data.code;
    throw error;
  }
  return data;
}

export const withParams = (url, params) => {
  const u = new URL(url, globalThis.location?.href);
  Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, v));
  return u.toString();
};

/**
 * PUT a blob to a presigned URL with upload progress. Resolves with the ETag header (or '').
 * `onXhr` receives the XMLHttpRequest so the caller can abort it.
 */
export function putBlob(url, blob, { contentType, onProgress, onXhr } = {}) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    if (onXhr) {
      onXhr(xhr);
    }
    xhr.open('PUT', url);
    if (contentType) {
      xhr.setRequestHeader('Content-Type', contentType);
    }
    xhr.upload.addEventListener('progress', (event) => {
      if (onProgress && event.lengthComputable) {
        onProgress(event.loaded);
      }
    });
    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(xhr.getResponseHeader('ETag') || '');
      } else {
        reject(new Error(`HTTP ${xhr.status}`));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('network')));
    xhr.addEventListener('abort', () => reject(new UploadAbort()));
    xhr.send(blob);
  });
}

/**
 * Upload the parts one after another, each with retries and backoff (1 s, 2 s).
 * Returns [{partNumber, etag}]. Throws UploadAbort when cancelled.
 */
export async function uploadParts(file, session, { signal, onProgress, onRetry, labels, putPart = putBlob, backoffMs = 1000 } = {}) {
  const parts = [];
  let finished = 0;
  let current = null;
  signal?.addEventListener('abort', () => current?.abort(), { once: true });

  for (const part of session.parts) {
    const start = (part.partNumber - 1) * session.partSize;
    const blob = file.slice(start, start + session.partSize);
    let etag = '';
    for (let attempt = 1; ; attempt += 1) {
      if (signal?.aborted) {
        throw new UploadAbort();
      }
      try {
        etag = await putPart(part.url, blob, {
          onXhr: (xhr) => { current = xhr; },
          onProgress: (loaded) => onProgress?.(finished + loaded),
        });
        if (!etag) {
          const missing = new Error(labels?.noEtag || 'Missing ETag');
          missing.noRetry = true;
          throw missing;
        }
        break;
      } catch (error) {
        if (error instanceof UploadAbort || signal?.aborted) {
          throw new UploadAbort();
        }
        if (error.noRetry || attempt >= PART_ATTEMPTS) {
          throw error;
        }
        onRetry?.(part.partNumber, attempt + 1, PART_ATTEMPTS);
        await sleep(backoffMs * 2 ** (attempt - 1), signal);
      }
    }
    finished += blob.size;
    onProgress?.(finished);
    parts.push({ partNumber: part.partNumber, etag });
  }

  return parts;
}

/** Poll the video status until `done(video, attempt)` is true. Rejects on abort or timeout. */
export async function pollVideo(statusUrl, uuid, done, { signal, delays = [POLL_FAST_MS, POLL_SLOW_MS], maxMs = POLL_MAX_MS, onUpdate, request = requestJson } = {}) {
  const started = Date.now();
  for (let attempt = 0; ; attempt += 1) {
    await sleep(attempt < 12 ? delays[0] : delays[1], signal);
    let video = null;
    try {
      video = await request('GET', withParams(statusUrl, { uuid }));
    } catch (error) {
      // A single failed poll is not fatal (network blip, rate limit): keep trying until the timeout.
      video = null;
    }
    if (video) {
      onUpdate?.(video);
      if (done(video, attempt)) {
        return video;
      }
    }
    if (Date.now() - started > maxMs) {
      throw new Error('timeout');
    }
  }
}


/**
 * Decides when an uploaded custom poster counts as done. The API activates a processed poster by
 * itself (`poster.source` becomes "custom"); if it is ready but still not active after a few polls,
 * the caller selects it once as fallback. An earlier custom poster may still report "ready" on the
 * first polls, so a new upload only counts after it was seen processing (or its URL changed), with
 * a short grace period for processing that finished between two polls.
 *
 * check(video, attempt) returns 'wait', 'select', 'done' or 'failed'.
 */
export function createPosterWatcher(previous = {}) {
  let sawProcessing = !['ready', 'failed'].includes(previous.customStatus);
  let selected = false;

  return (video, attempt) => {
    const status = video.poster?.customStatus;
    if (status === 'processing' || (previous.posterUrl && video.posterUrl && video.posterUrl !== previous.posterUrl)) {
      sawProcessing = true;
    }
    if (status === 'processing') {
      return 'wait';
    }
    if (!sawProcessing && attempt < 3) {
      return 'wait';
    }
    if (status === 'failed') {
      return 'failed';
    }
    if (status !== 'ready') {
      return 'wait';
    }
    if (video.poster?.source === 'custom') {
      return 'done';
    }
    if (attempt >= 5 && !selected) {
      selected = true;
      return 'select';
    }

    return 'wait';
  };
}
