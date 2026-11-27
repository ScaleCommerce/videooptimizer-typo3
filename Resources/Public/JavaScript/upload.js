/**
 * VideoOptimizer backend module: browser upload and custom poster.
 *
 *  - form[data-vo-upload]: presigned multipart upload straight from the browser to storage
 *    (progress, retry with backoff, cancel), then status polling until the video is ready or
 *    failed (the API's `error` is shown). Without JavaScript the form still uploads via the server.
 *  - [data-vo-poster]: upload a poster image (or take one from the TYPO3 file list), poll the
 *    poster state, remove the custom poster.
 *
 * The API token never reaches the browser: the module's AJAX routes (TYPO3 route token in the URL,
 * module permissions checked server-side) issue initiate/complete; the browser only gets presigned
 * storage URLs. All DOM is built with textContent / attributes (no innerHTML, no inline styles).
 */
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import {
  UploadAbort, createPosterWatcher, format, formatBytes, pollVideo, putBlob, refreshList, requestJson, uploadParts, withParams,
} from '@scalecommerce/videooptimizer/upload-core.js';

const el = (tag, className, text) => {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
};

// ---------------------------------------------------------------------------------------------
// Video upload
// ---------------------------------------------------------------------------------------------

class VideoUpload {
  constructor(form, config) {
    this.form = form;
    this.config = config;
    this.labels = config.labels || {};
    this.running = false;
    this.bypass = false;
    form.addEventListener('submit', (event) => this.onSubmit(event));
  }

  onSubmit(event) {
    if (this.bypass || this.running) {
      if (this.running) {
        event.preventDefault();
      }
      return;
    }
    const input = this.form.querySelector('input[type="file"]');
    const file = input?.files?.[0];
    if (!file || !window.XMLHttpRequest) {
      return; // plain server-side upload
    }
    event.preventDefault();
    this.start(file);
  }

  field(name) {
    const node = this.form.elements.namedItem(name);
    return node && 'value' in node ? node.value : '';
  }

  buildPanel() {
    this.panel?.remove();
    this.panel = el('div', 'vo-upload-panel vstack gap-2');
    this.message = el('div', '');
    this.message.setAttribute('role', 'status');
    this.message.setAttribute('aria-live', 'polite');
    this.progress = el('progress', 'w-100');
    this.progress.max = 100;
    this.progress.value = 0;
    this.actions = el('div', 'd-flex flex-wrap gap-2');
    this.panel.append(this.message, this.progress, this.actions);
    this.form.after(this.panel);
  }

  setMessage(text, severity = 'info') {
    this.message.className = severity === 'info' ? '' : `alert alert-${severity} mb-0`;
    this.message.textContent = text;
  }

  addAction(label, className, handler) {
    const button = el('button', `btn ${className}`, label);
    button.type = 'button';
    button.addEventListener('click', handler);
    this.actions.append(button);
    return button;
  }

  setBusy(busy) {
    this.running = busy;
    this.form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = busy; });
    if (busy) {
      window.addEventListener('beforeunload', this.guard);
    } else {
      window.removeEventListener('beforeunload', this.guard);
    }
  }

  guard = (event) => {
    event.preventDefault();
    event.returnValue = '';
  };

  async start(file) {
    this.buildPanel();
    this.setBusy(true);
    this.abort = new AbortController();
    const cancel = this.addAction(this.labels.cancel, 'btn-default', () => this.abort.abort());
    this.setMessage(this.labels.preparing);

    let uuid = '';
    try {
      const session = await requestJson('POST', this.config.urls.initiate, {
        libraryId: this.config.libraryId,
        filename: file.name,
        contentType: file.type,
        fileSize: file.size,
      });
      uuid = session.uuid;

      const parts = await uploadParts(file, session, {
        signal: this.abort.signal,
        labels: this.labels,
        onProgress: (bytes) => {
          const percent = Math.min(100, Math.floor((bytes / file.size) * 100));
          this.progress.value = percent;
          this.setMessage(format(this.labels.progress, percent, formatBytes(Math.min(bytes, file.size)), formatBytes(file.size)));
        },
        onRetry: (part, attempt, max) => this.setMessage(format(this.labels.retry, part, attempt, max), 'warning'),
      });

      this.progress.removeAttribute('value');
      this.setMessage(this.labels.finishing);
      cancel.remove();
      await requestJson('POST', this.config.urls.complete, {
        libraryId: this.config.libraryId,
        session: { uuid: session.uuid, key: session.key, uploadId: session.uploadId },
        parts,
        title: this.field('title'),
        tags: this.field('tags'),
        metadata: this.field('metadata'),
      });
      await this.refresh(); // the new video shows up as "processing" right away
      await this.watch(uuid);
    } catch (error) {
      cancel.remove();
      this.failed(error);
    } finally {
      this.setBusy(false);
    }
  }

  async watch(uuid) {
    this.progress.removeAttribute('value');
    this.setMessage(this.labels.processing);
    const cancelPoll = this.addAction(this.labels.showList, 'btn-default', () => window.location.reload());
    this.running = false; // processing continues on the platform; the form may be used again
    this.form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = false; });
    window.removeEventListener('beforeunload', this.guard);
    try {
      const video = await pollVideo(this.config.urls.status, uuid, (v) => v.status === 'ready' || v.status === 'failed' || v.ready === true);
      this.progress.value = 100;
      cancelPoll.remove();
      await this.refresh();
      if (video.status === 'failed') {
        this.setMessage(video.error ? format(this.labels.failed, video.error) : this.labels.failedNoReason, 'danger');
      } else {
        this.setMessage(this.labels.ready, 'success');
      }
      this.addAction(this.labels.showList, 'btn-primary', () => window.location.reload());
    } catch (error) {
      cancelPoll.remove();
      this.progress.remove();
      this.setMessage(this.labels.pollLost, 'warning');
      this.addAction(this.labels.showList, 'btn-primary', () => window.location.reload());
    }
  }

  /** Swap the fresh video list into the page (no reload); failures are not fatal. */
  async refresh() {
    try {
      await refreshList(window.location.href, document);
    } catch (e) {
      // the "Show videos" button still reloads the page
    }
  }

  failed(error) {
    this.progress.remove();
    if (error instanceof UploadAbort) {
      this.setMessage(this.labels.cancelled, 'warning');
      return;
    }
    this.setMessage(format(this.labels.error, error.message === 'network' ? 'network error' : error.message), 'danger');
    // The server-side form upload stays available as fallback (e.g. storage not reachable from this browser).
    this.addAction(this.labels.serverFallback, 'btn-default', () => {
      this.bypass = true;
      this.form.requestSubmit();
    });
  }
}

// ---------------------------------------------------------------------------------------------
// Custom poster
// ---------------------------------------------------------------------------------------------

const POSTER_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

class PosterEditor {
  constructor(root, config) {
    this.root = root;
    this.config = config;
    this.labels = config.labels || {};
    this.message = root.querySelector('[data-vo-poster-message]');
    this.busy = false;

    root.querySelector('[data-vo-poster-file]')?.addEventListener('change', (event) => {
      const file = event.target.files?.[0];
      event.target.value = '';
      if (file) {
        this.uploadFile(file);
      }
    });
    root.querySelector('[data-vo-poster-files]')?.addEventListener('click', () => this.openFileBrowser());
    root.querySelector('[data-vo-poster-remove]')?.addEventListener('click', () => this.remove());
  }

  setMessage(text, severity = 'info') {
    this.message.className = severity === 'info' ? 'text-body-secondary' : `alert alert-${severity} mb-0`;
    this.message.textContent = text;
  }

  setBusy(busy) {
    this.busy = busy;
    this.root.querySelectorAll('button, input').forEach((node) => { node.disabled = busy; });
  }

  async uploadFile(file) {
    if (this.busy) {
      return;
    }
    if (!POSTER_TYPES.includes(file.type)) {
      this.setMessage(this.labels.badType, 'danger');
      return;
    }
    this.setBusy(true);
    try {
      this.setMessage(this.labels.uploading);
      const session = await requestJson('POST', this.config.urls.initiate, {
        uuid: this.config.videoUuid,
        contentType: file.type,
        fileSize: file.size,
      });
      await putBlob(session.uploadUrl, file, { contentType: file.type });
      await requestJson('POST', this.config.urls.complete, { uuid: this.config.videoUuid, key: session.key });
      await this.waitForPoster();
    } catch (error) {
      this.setMessage(format(this.labels.error, error.message === 'network' ? 'network error' : error.message), 'danger');
      this.setBusy(false);
    }
  }

  /** Poll until the custom poster is processed and active (or failed), then reload. */
  async waitForPoster() {
    this.setMessage(this.labels.processing);
    const watcher = createPosterWatcher(this.config.state);
    const video = await pollVideo(this.config.urls.status, this.config.videoUuid, (v, attempt) => {
      const verdict = watcher(v, attempt);
      if (verdict === 'select') {
        requestJson('POST', this.config.urls.select, { uuid: this.config.videoUuid, source: 'custom' }).catch(() => {});
      }
      return verdict === 'done' || verdict === 'failed';
    }, { delays: [2000, 5000], maxMs: 2 * 60 * 1000 });

    if (video.poster?.customStatus === 'failed') {
      this.setMessage(this.labels.failed, 'danger');
      this.setBusy(false);
      return;
    }
    this.setMessage(this.labels.ready, 'success');
    window.setTimeout(() => window.location.reload(), 800);
  }

  remove() {
    if (this.busy) {
      return;
    }
    const modal = Modal.confirm('', this.labels.removeConfirm, SeverityEnum.warning);
    modal.addEventListener('confirm.button.cancel', () => modal.hideModal());
    modal.addEventListener('confirm.button.ok', async () => {
      modal.hideModal();
      this.setBusy(true);
      try {
        await requestJson('POST', this.config.urls.remove, { uuid: this.config.videoUuid });
        this.setMessage(this.labels.removed, 'success');
        window.setTimeout(() => window.location.reload(), 800);
      } catch (error) {
        this.setMessage(format(this.labels.error, error.message), 'danger');
        this.setBusy(false);
      }
    });
  }

  openFileBrowser() {
    if (this.busy) {
      return;
    }
    const content = el('div', 'vo-file-browser');
    const modal = Modal.advanced({
      title: this.labels.filesTitle,
      content,
      size: Modal.sizes.large,
      buttons: [],
    });
    const load = async (folder) => {
      content.replaceChildren(el('div', 'text-body-secondary', '…'));
      try {
        const data = await requestJson('GET', withParams(this.config.urls.files, { folder }));
        this.renderFiles(content, data, load, modal);
      } catch (error) {
        content.replaceChildren(el('div', 'alert alert-danger', format(this.labels.filesError, error.message)));
      }
    };
    load('');
  }

  renderFiles(content, data, load, modal) {
    const list = el('div', 'list-group');
    if (data.folder) {
      const up = el('button', 'list-group-item list-group-item-action', `↑ ${this.labels.filesUp}`);
      up.type = 'button';
      up.addEventListener('click', () => load(data.parent || ''));
      list.append(up);
    }
    data.folders.forEach((folder) => {
      const item = el('button', 'list-group-item list-group-item-action', `${folder.name}/`);
      item.type = 'button';
      item.addEventListener('click', () => load(folder.id));
      list.append(item);
    });
    data.files.forEach((file) => {
      const item = el('button', 'list-group-item list-group-item-action d-flex justify-content-between gap-3');
      item.type = 'button';
      item.append(el('span', 'text-break', file.name), el('span', 'text-body-secondary text-nowrap', formatBytes(file.size)));
      item.addEventListener('click', () => {
        modal.hideModal();
        this.useFile(file.id);
      });
      list.append(item);
    });
    const nodes = [list];
    if (data.folder && data.files.length === 0) {
      nodes.push(el('p', 'text-body-secondary mt-2', this.labels.filesEmpty));
    }
    content.replaceChildren(...nodes);
  }

  async useFile(fileId) {
    this.setBusy(true);
    try {
      this.setMessage(this.labels.uploading);
      await requestJson('POST', this.config.urls.fromFile, { uuid: this.config.videoUuid, file: fileId });
      await this.waitForPoster();
    } catch (error) {
      this.setMessage(format(this.labels.error, error.message), 'danger');
      this.setBusy(false);
    }
  }
}

const parseConfig = (node, attribute) => {
  try {
    return JSON.parse(node.getAttribute(attribute) || '{}');
  } catch (e) {
    return null;
  }
};

document.querySelectorAll('form[data-vo-upload]').forEach((form) => {
  const config = parseConfig(form, 'data-vo-upload');
  if (config && config.urls) {
    new VideoUpload(form, config);
  }
});

document.querySelectorAll('[data-vo-poster]').forEach((root) => {
  const config = parseConfig(root, 'data-vo-poster');
  if (config && config.urls) {
    new PosterEditor(root, config);
  }
});
