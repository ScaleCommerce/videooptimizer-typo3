/**
 * VideoOptimizer backend module:
 *  - confirmation dialogs (TYPO3 Modal) for forms carrying data-vo-confirm="<text>"
 *  - reload the video list every 10 s while videos are encoding, but never while the
 *    editor is working (focused/edited form field, open <details>).
 */
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.voConfirm) {
        return;
    }
    event.preventDefault();

    const modal = Modal.confirm(form.dataset.voConfirmTitle ?? '', form.dataset.voConfirm, SeverityEnum.warning);
    modal.addEventListener('confirm.button.cancel', () => {
        modal.hideModal();
    });
    modal.addEventListener('confirm.button.ok', () => {
        modal.hideModal();
        // form.submit() does not re-trigger the submit event, so there is no confirm loop.
        form.submit();
    });
});

if (document.querySelector('[data-vo-auto-refresh="1"]') !== null) {
    let dirty = false;
    const markDirty = (event) => {
        const target = event.target;
        if (target instanceof Element && target.closest('form')) {
            dirty = true;
        }
    };
    document.addEventListener('input', markDirty);
    document.addEventListener('change', markDirty);

    const busy = () => {
        const active = document.activeElement;
        if (active && active !== document.body && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) {
            return true;
        }
        return dirty || document.querySelector('details[open]') !== null;
    };

    const tick = () => {
        if (document.hidden || busy()) {
            window.setTimeout(tick, 10000);
            return;
        }
        window.location.reload();
    };

    window.setTimeout(tick, 10000);
}
