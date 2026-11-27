/**
 * Visual video picker for tt_content.vo_video_uuid (FormEngine element "videooptimizerVideoBrowser").
 *
 * <typo3-videooptimizer-video-browser data-config="{...}" data-value="uuid"><input type="hidden" name="data[...]"></typo3-...>
 *
 * Renders an inline card of the selected video and opens a TYPO3 modal with a searchable thumbnail
 * grid. All DOM is built with textContent / attributes (no innerHTML), no inline scripts or styles.
 */
import Modal from '@typo3/backend/modal.js';
import FormEngine from '@typo3/backend/form-engine.js';

const formatDuration = (seconds) => {
  if (typeof seconds !== 'number' || !isFinite(seconds) || seconds <= 0) {
    return '';
  }
  const total = Math.round(seconds);
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = String(total % 60).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
};

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

const withParams = (url, params) => {
  const u = new URL(url, window.location.href);
  Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, v));
  return u.toString();
};

async function getJson(url) {
  const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
  let data = null;
  try {
    data = await response.json();
  } catch (e) {
    data = null;
  }
  if (!response.ok || !data || data.error) {
    throw new Error((data && data.error) || `HTTP ${response.status}`);
  }
  return data;
}

class VideoBrowserElement extends HTMLElement {
  connectedCallback() {
    if (this.initialized) {
      return;
    }
    this.initialized = true;
    try {
      this.config = JSON.parse(this.dataset.config || '{}');
    } catch (e) {
      this.config = {};
    }
    this.labels = this.config.labels || {};
    this.input = this.querySelector('input[type="hidden"]');
    this.cache = null; // { libraries, videos }
    this.render();
    this.refreshCard();
  }

  get value() {
    return this.input ? this.input.value : '';
  }

  render() {
    this.card = el('div', 'vo-card');
    this.card.setAttribute('role', 'group');
    this.card.setAttribute('aria-label', this.labels.selected || '');

    const actions = el('div', 'vo-actions');
    this.chooseButton = el('button', 'btn btn-default', this.labels.choose);
    this.chooseButton.type = 'button';
    this.chooseButton.addEventListener('click', () => this.openModal());
    this.removeButton = el('button', 'btn btn-default', this.labels.remove);
    this.removeButton.type = 'button';
    this.removeButton.addEventListener('click', () => {
      this.setValue('', null);
      this.chooseButton.focus();
    });
    actions.append(this.chooseButton, this.removeButton);

    this.append(this.card, actions);
  }

  /** Inline card: thumbnail, title, duration, library, status badge (or the empty state). */
  renderCard(video, loading = false) {
    this.card.replaceChildren();
    this.removeButton.hidden = this.value === '';
    if (this.value === '') {
      this.card.classList.add('vo-card--empty');
      this.card.append(el('span', 'vo-card-empty', this.labels.empty));
      return;
    }
    this.card.classList.remove('vo-card--empty');
    const thumb = el('div', 'vo-card-thumb');
    this.fillThumb(thumb, video);
    const body = el('div', 'vo-card-body');
    if (!video) {
      body.append(el('strong', 'vo-card-title', loading ? '…' : this.labels.unknownVideo));
      body.append(el('span', 'vo-card-meta', this.value));
    } else {
      const title = video.title || video.uuid;
      body.append(el('strong', 'vo-card-title', title));
      const meta = [formatDuration(video.duration), video.library_name].filter(Boolean).join(' · ');
      if (meta) {
        body.append(el('span', 'vo-card-meta', meta));
      }
      const badge = this.statusBadge(video);
      if (badge) {
        body.append(badge);
      }
      if (video.error && video.error_message) {
        body.append(el('span', 'vo-card-meta', video.error_message));
      }
    }
    this.card.append(thumb, body);
  }

  fillThumb(container, video) {
    const src = video && (video.poster_url || video.thumbnail_url);
    if (src) {
      const img = el('img');
      img.src = src;
      img.alt = '';
      img.loading = 'lazy';
      img.decoding = 'async';
      container.append(img);
    } else {
      container.classList.add('vo-thumb--none');
    }
  }

  statusBadge(video) {
    if (video.error) {
      const failed = el('span', 'badge badge-danger vo-badge', this.labels.errorStatus);
      if (video.error_message) {
        failed.title = video.error_message;
      }
      return failed;
    }
    if (!video.ready) {
      return el('span', 'badge badge-warning vo-badge', this.labels.processing);
    }
    return null;
  }

  async refreshCard() {
    const uuid = this.value;
    this.renderCard(null, true);
    if (uuid === '') {
      return;
    }
    const known = this.cache && this.cache.videos.find((v) => v.uuid === uuid);
    if (known) {
      this.renderCard(known);
      return;
    }
    try {
      const data = await getJson(withParams(this.config.videoUrl, { uuid }));
      if (this.value === uuid) {
        this.renderCard(data.video || null);
      }
    } catch (e) {
      if (this.value === uuid) {
        this.renderCard(null);
      }
    }
  }

  setValue(uuid, video) {
    this.input.value = uuid;
    this.input.dispatchEvent(new Event('change', { bubbles: true }));
    FormEngine.markFieldAsChanged(this.input);
    this.renderCard(video);
    if (uuid !== '' && !video) {
      this.refreshCard();
    }
  }

  /** The form's vo_library_id hidden input (rendered by the library picker element). */
  get libraryField() {
    const name = this.config.libraryFieldName;
    const form = FormEngine.formElement;
    if (!name || !form) {
      return null;
    }
    const field = form.elements.namedItem(name);
    return field instanceof HTMLInputElement ? field : null;
  }

  /**
   * Mirror the video's library into the vo_library_id hidden input and tell the library picker
   * (which updates its highlight) via the bubbling "videooptimizer:library-change" event.
   */
  syncLibrary(video) {
    const field = this.libraryField;
    if (!field || !video || !video.library_id || field.value === video.library_id) {
      return;
    }
    field.value = video.library_id;
    FormEngine.markFieldAsChanged(field);
    this.dispatchEvent(new CustomEvent('videooptimizer:library-change', {
      bubbles: true,
      detail: { libraryId: video.library_id },
    }));
  }

  async loadData() {
    if (!this.cache) {
      const data = await getJson(withParams(this.config.videosUrl, { library: '' }));
      this.cache = { libraries: data.libraries || [], videos: data.videos || [] };
    }
    return this.cache;
  }

  openModal() {
    const content = el('div', 'vo-modal');
    const link = document.createElement('link'); // the modal lives in the top window
    link.rel = 'stylesheet';
    link.href = this.config.cssUrl;
    content.append(link);

    const state = {
      library: this.libraryField ? this.libraryField.value : '',
      query: '',
      readyOnly: false,
    };

    const toolbar = el('div', 'vo-toolbar');
    const libId = 'vo-lib-' + Math.random().toString(36).slice(2);
    const searchId = 'vo-search-' + Math.random().toString(36).slice(2);
    const readyId = 'vo-ready-' + Math.random().toString(36).slice(2);

    const libWrap = el('div', 'vo-field');
    const libLabel = el('label', 'form-label', this.labels.library);
    libLabel.htmlFor = libId;
    const libSelect = el('select', 'form-select');
    libSelect.id = libId;
    libWrap.append(libLabel, libSelect);

    const searchWrap = el('div', 'vo-field vo-field--grow');
    const searchLabel = el('label', 'form-label', this.labels.search);
    searchLabel.htmlFor = searchId;
    const search = el('input', 'form-control');
    search.type = 'search';
    search.id = searchId;
    search.placeholder = this.labels.searchPlaceholder;
    search.autocomplete = 'off';
    searchWrap.append(searchLabel, search);

    const readyWrap = el('div', 'form-check form-switch vo-ready');
    const ready = el('input', 'form-check-input');
    ready.type = 'checkbox';
    ready.id = readyId;
    ready.setAttribute('role', 'switch');
    const readyLabel = el('label', 'form-check-label', this.labels.readyOnly);
    readyLabel.htmlFor = readyId;
    readyWrap.append(ready, readyLabel);

    toolbar.append(libWrap, searchWrap, readyWrap);

    const status = el('div', 'vo-status');
    status.setAttribute('role', 'status');
    const grid = el('ul', 'vo-grid');
    content.append(toolbar, status, grid);

    const modal = Modal.advanced({
      title: this.labels.modalTitle,
      content,
      size: Modal.sizes.large,
      buttons: [{
        text: this.labels.close,
        btnClass: 'btn-default',
        name: 'close',
        trigger: (event, m) => m.hideModal(),
      }],
    });
    modal.addEventListener('typo3-modal-hidden', () => this.chooseButton.focus());
    modal.addEventListener('typo3-modal-shown', () => search.focus());

    const paint = () => {
      const q = state.query.trim().toLowerCase();
      const videos = this.cache.videos.filter((v) => (
        (state.library === '' || v.library_id === state.library)
        && (!state.readyOnly || (v.ready && !v.error))
        && (q === '' || (v.title || v.uuid).toLowerCase().includes(q))
      ));
      grid.replaceChildren(...videos.map((v) => this.tile(v, modal)));
      status.textContent = videos.length === 0 ? this.labels.noResults : '';
    };

    const fillLibraries = () => {
      libSelect.replaceChildren();
      const all = el('option', null, this.labels.allLibraries);
      all.value = '';
      libSelect.append(all);
      this.cache.libraries.forEach((l) => {
        const o = el('option', null, l.name);
        o.value = l.id;
        libSelect.append(o);
      });
      if (!this.cache.libraries.some((l) => l.id === state.library)) {
        state.library = '';
      }
      libSelect.value = state.library;
    };

    const load = async () => {
      status.textContent = this.labels.loading;
      grid.replaceChildren();
      try {
        await this.loadData();
        fillLibraries();
        paint();
      } catch (e) {
        status.textContent = this.labels.error + ' ';
        const retry = el('button', 'btn btn-default btn-sm', this.labels.retry);
        retry.type = 'button';
        retry.addEventListener('click', load);
        status.append(retry);
      }
    };

    libSelect.addEventListener('change', () => { state.library = libSelect.value; paint(); });
    search.addEventListener('input', () => { state.query = search.value; paint(); });
    ready.addEventListener('change', () => { state.readyOnly = ready.checked; paint(); });
    // Enter in the search box must not submit the surrounding record form.
    search.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); } });

    load();
  }

  tile(video, modal) {
    const li = el('li', 'vo-grid-item');
    const button = el('button', 'vo-tile');
    button.type = 'button';
    const title = video.title || video.uuid;
    const duration = formatDuration(video.duration);
    const badge = this.statusBadge(video);
    button.setAttribute('aria-label', [title, duration, video.library_name, badge ? badge.textContent : '', video.error ? video.error_message : ''].filter(Boolean).join(', '));
    if (video.uuid === this.value) {
      button.setAttribute('aria-pressed', 'true');
      button.classList.add('is-selected');
    }

    const thumb = el('span', 'vo-tile-thumb');
    this.fillThumb(thumb, video);
    if (duration) {
      thumb.append(el('span', 'vo-tile-duration', duration));
    }
    if (badge) {
      badge.classList.add('vo-tile-badge');
      thumb.append(badge);
    }
    button.append(thumb, el('span', 'vo-tile-title', title));
    button.addEventListener('click', () => {
      this.setValue(video.uuid, video);
      this.syncLibrary(video);
      modal.hideModal();
    });
    li.append(button);
    return li;
  }
}

if (!customElements.get('typo3-videooptimizer-video-browser')) {
  customElements.define('typo3-videooptimizer-video-browser', VideoBrowserElement);
}
