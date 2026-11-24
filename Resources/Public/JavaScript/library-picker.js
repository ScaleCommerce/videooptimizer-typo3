/**
 * Visual library picker for tt_content.vo_library_id (FormEngine element "videooptimizerLibraryPicker").
 *
 * The tile grid is rendered server-side; this module only adds behavior:
 * selection (click / Enter / Space / arrow keys), highlight, name filter and the
 * "videooptimizer:library-change" event ({ libraryId }) shared with the video browser.
 */
import FormEngine from '@typo3/backend/form-engine.js';

const EVENT = 'videooptimizer:library-change';

class LibraryPickerElement extends HTMLElement {
  connectedCallback() {
    if (this.initialized) {
      return;
    }
    this.initialized = true;
    this.input = this.querySelector('input[type="hidden"]');
    this.grid = this.querySelector('.vo-libpicker-grid');
    this.emptyNote = this.querySelector('.vo-libpicker-empty');
    this.filter = this.querySelector('[data-vo-libpicker-filter]');
    if (!this.input) {
      return;
    }

    this.onLibraryChange = (event) => this.handleExternalChange(event);
    document.addEventListener(EVENT, this.onLibraryChange);

    if (!this.grid) {
      return; // API unavailable: warning only, value stays untouched
    }
    this.grid.addEventListener('click', (event) => {
      const tile = event.target.closest('.vo-lib-tile');
      if (tile && this.grid.contains(tile)) {
        this.select(tile.dataset.libraryId || '');
        tile.focus();
      }
    });
    this.grid.addEventListener('keydown', (event) => this.handleKey(event));
    if (this.filter) {
      this.filter.addEventListener('input', () => this.applyFilter());
      // Enter in the filter box must not submit the surrounding record form.
      this.filter.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
        }
      });
    }
  }

  disconnectedCallback() {
    if (this.onLibraryChange) {
      document.removeEventListener(EVENT, this.onLibraryChange);
    }
    this.initialized = false;
  }

  get tiles() {
    return this.grid ? Array.from(this.grid.querySelectorAll('.vo-lib-tile')) : [];
  }

  get visibleTiles() {
    return this.tiles.filter((tile) => !tile.hidden);
  }

  /** User selection: write value, mark changed, notify. */
  select(libraryId) {
    if (this.input.value !== libraryId) {
      this.input.value = libraryId;
      FormEngine.markFieldAsChanged(this.input);
    }
    this.highlight(libraryId);
    this.dispatchEvent(new CustomEvent(EVENT, { bubbles: true, detail: { libraryId } }));
  }

  /** Update highlight and roving tabindex only (no event, so no loops). */
  highlight(libraryId) {
    const tiles = this.tiles;
    let selected = tiles.find((tile) => (tile.dataset.libraryId || '') === libraryId);
    tiles.forEach((tile) => {
      const on = tile === selected;
      tile.classList.toggle('is-selected', on);
      tile.setAttribute('aria-checked', on ? 'true' : 'false');
      tile.tabIndex = -1;
    });
    const stop = selected && !selected.hidden ? selected : (this.visibleTiles[0] || null);
    if (stop) {
      stop.tabIndex = 0;
    }
  }

  /** Change made elsewhere (video browser): only react for our own field's form. */
  handleExternalChange(event) {
    if (event.target === this || !this.input || !this.grid) {
      return;
    }
    const libraryId = event.detail && typeof event.detail.libraryId === 'string' ? event.detail.libraryId : null;
    if (libraryId === null || (event.target instanceof Node && this.closest('form') !== event.target.closest('form'))) {
      return;
    }
    if (this.input.value !== libraryId) {
      this.input.value = libraryId;
      FormEngine.markFieldAsChanged(this.input);
    }
    this.highlight(libraryId);
  }

  handleKey(event) {
    const tile = event.target.closest('.vo-lib-tile');
    if (!tile) {
      return;
    }
    if (event.key === ' ' || event.key === 'Enter') {
      event.preventDefault(); // no form submit, no page scroll
      this.select(tile.dataset.libraryId || '');
      return;
    }
    const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
    if (event.key in keys || event.key === 'Home' || event.key === 'End') {
      const tiles = this.visibleTiles;
      const index = tiles.indexOf(tile);
      if (index === -1) {
        return;
      }
      event.preventDefault();
      let next = index;
      if (event.key === 'Home') {
        next = 0;
      } else if (event.key === 'End') {
        next = tiles.length - 1;
      } else {
        next = (index + keys[event.key] + tiles.length) % tiles.length;
      }
      this.select(tiles[next].dataset.libraryId || '');
      tiles[next].focus();
    }
  }

  applyFilter() {
    const query = this.filter.value.trim().toLowerCase();
    let visible = 0;
    this.tiles.forEach((tile) => {
      const name = (tile.dataset.libraryName || '').toLowerCase();
      // "All libraries" (empty id) stays visible only while the filter is empty.
      const show = query === '' || (tile.dataset.libraryId !== '' && name.includes(query));
      tile.hidden = !show;
      if (show) {
        visible += 1;
      }
    });
    if (this.emptyNote) {
      this.emptyNote.hidden = visible > 0;
    }
    this.highlight(this.input.value);
  }
}

if (!customElements.get('typo3-videooptimizer-library-picker')) {
  customElements.define('typo3-videooptimizer-library-picker', LibraryPickerElement);
}
